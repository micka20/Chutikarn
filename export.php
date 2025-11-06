<?php
session_start();

if (!isset($_SESSION['user_username'])) {
    die("Access Denied.");
}

// เชื่อมต่อฐานข้อมูล
$conn = new mysqli("localhost", "root", "12345678", "chutikarnceramic1");
$conn->set_charset("utf8");
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$report_type = $_GET['report'] ?? '';

// ตั้งค่าชื่อไฟล์และหัวตารางเริ่มต้น
$filename = "report-" . date('Y-m-d') . ".csv";
$headers = [];
$query = "";

// สร้าง Query และตั้งค่าตามประเภทรายงาน
switch ($report_type) {
    case 'stock':
        $filename = "stock_report_" . date('Y-m-d') . ".csv";
        $headers = ["รหัสสินค้า", "ชื่อสินค้า", "ประเภท", "จำนวน", "หน่วย", "ต้นทุน/หน่วย", "มูลค่ารวม"];
        $query = "
            SELECT p.pd_ID, p.pd_name, t.typepd_name, p.pd_number, p.pd_unit, p.pd_costprice, (p.pd_number * p.pd_costprice)
            FROM product p
            LEFT JOIN typepd t ON p.typepd_ID = t.typepd_ID
            ORDER BY p.pd_ID ASC
        ";
        break;

    case 'received':
        $start_date = $_GET['start_date'] ?? date('Y-m-01');
        $end_date = $_GET['end_date'] ?? date('Y-m-t');
        $filename = "received_report_{$start_date}_to_{$end_date}.csv";
        $headers = ["เลขที่ใบสั่งซื้อ", "วันที่รับ", "ร้านค้า", "ผู้รับ", "ยอดรวม (บาท)"];
        $stmt = $conn->prepare("
            SELECT b.buypd_ID, b.buypd_date, s.shop_name, u.user_name, b.buypd_total
            FROM buypd b
            JOIN shop s ON b.shop_ID = s.shop_ID
            JOIN user u ON b.user_ID = u.user_ID
            WHERE b.buypd_status = 'received' AND b.buypd_date BETWEEN ? AND ?
            ORDER BY b.buypd_date DESC
        ");
        $stmt->bind_param("ss", $start_date, $end_date);
        break;

    case 'user':
        $filename = "user_report_" . date('Y-m-d') . ".csv";
        $headers = ["รหัสผู้ใช้", "ชื่อ-สกุล", "ตำแหน่ง", "ประเภท", "จำนวน PO ที่สร้าง"];
        $query = "
            SELECT u.user_ID, u.user_name, u.user_position, u.user_type, COUNT(b.buypd_ID)
            FROM user u
            LEFT JOIN buypd b ON u.user_ID = b.user_ID
            GROUP BY u.user_ID, u.user_name, u.user_position, u.user_type
            ORDER BY u.user_ID ASC
        ";
        break;
        
    case 'type':
        $filename = "product_type_report_" . date('Y-m-d') . ".csv";
        $headers = ["รหัสประเภท", "ชื่อประเภท", "จำนวนรายการ (SKU)", "จำนวนสินค้าทั้งหมด (ชิ้น)"];
        $query = "
            SELECT t.typepd_ID, t.typepd_name, COUNT(p.pd_ID), SUM(p.pd_number)
            FROM typepd t
            LEFT JOIN product p ON t.typepd_ID = p.typepd_ID
            GROUP BY t.typepd_ID, t.typepd_name
            ORDER BY t.typepd_ID ASC
        ";
        break;
        
    default:
        die("Invalid report type.");
}

// ส่วนของการสร้างและดาวน์โหลดไฟล์ CSV
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

// เปิด output stream ของ PHP
$output = fopen('php://output', 'w');

// เพิ่ม BOM เพื่อให้ Excel เปิดไฟล์ภาษาไทยได้ถูกต้อง
fputs($output, "\xEF\xBB\xBF");

// เขียนหัวตาราง
fputcsv($output, $headers);

// ดึงข้อมูลและเขียนลงไฟล์
if (isset($stmt)) { // สำหรับ query ที่มี bind_param
    $stmt->execute();
    $result = $stmt->get_result();
} else { // สำหรับ query ทั่วไป
    $result = $conn->query($query);
}

if ($result) {
    while ($row = $result->fetch_assoc()) {
        fputcsv($output, $row);
    }
}

fclose($output);
$conn->close();
exit();