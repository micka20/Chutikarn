<?php
session_start();
if (!isset($_SESSION['user_username'])) {
    header("Location: login.php");
    exit();
}

$conn = new mysqli("localhost", "root", "12345678", "chutikarnceramic1");
$conn->set_charset("utf8");
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// ดึงข้อมูลใบสั่งซื้อทั้งหมด
$order_sql = "SELECT b.buypd_ID, b.buypd_date, b.buypd_total, s.shop_name, u.user_name, 
                     b.buypd_status
              FROM buypd b
              LEFT JOIN shop s ON b.shop_ID = s.shop_ID
              LEFT JOIN user u ON b.user_ID = u.user_ID
              ORDER BY 
                CAST(SUBSTRING(b.buypd_ID, 4) AS UNSIGNED) ASC, 
                b.buypd_date DESC";
$order_result = $conn->query($order_sql);

// ตั้งค่า header สำหรับไฟล์ Excel
header('Content-Type: application/vnd.ms-excel; charset=utf-8');
header('Content-Disposition: attachment; filename="ใบสั่งซื้อ_' . date('Y-m-d_H-i') . '.xls"');
header('Pragma: no-cache');
header('Expires: 0');

// เริ่มต้นสร้างไฟล์ Excel
echo "<html>";
echo "<head>";
echo "<meta charset='UTF-8'>";
echo "<style>";
echo "table { border-collapse: collapse; width: 100%; font-family: 'Kanit', sans-serif; }";
echo "th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }";
echo "th { background-color: #f2f2f2; font-weight: bold; }";
echo "tr:nth-child(even) { background-color: #f9f9f9; }";
echo ".header { background-color: #A44A3F; color: white; padding: 10px; text-align: center; }";
echo ".summary { background-color: #e8f5e8; font-weight: bold; }";
echo "</style>";
echo "</head>";
echo "<body>";

echo "<div class='header'>";
echo "<h2>รายงานใบสั่งซื้อ - Chutikarn Ceramic</h2>";
echo "<p>วันที่ส่งออก: " . date('d/m/Y H:i:s') . "</p>";
echo "</div>";

echo "<table>";
echo "<thead>";
echo "<tr>";
echo "<th>เลขที่ใบสั่งซื้อ</th>";
echo "<th>วันที่</th>";
echo "<th>สั่งซื้อจากร้านค้า</th>";
echo "<th>ผู้จัดทำ</th>";
echo "<th>ยอดรวม (บาท)</th>";
echo "<th>สถานะ</th>";
echo "</tr>";
echo "</thead>";
echo "<tbody>";

$total_amount = 0;
$new_count = 0;
$completed_count = 0;
$pending_count = 0;
$cancelled_count = 0;

if ($order_result->num_rows > 0) {
    while ($row = $order_result->fetch_assoc()) {
        // กำหนดสถานะ
        $status_text = 'ใหม่';
        if (!empty($row['buypd_status'])) {
            switch($row['buypd_status']) {
                case 'completed':
                    $status_text = 'เสร็จสิ้น';
                    $completed_count++;
                    break;
                case 'cancelled':
                    $status_text = 'ยกเลิก';
                    $cancelled_count++;
                    break;
                case 'pending':
                    $status_text = 'รอดำเนินการ';
                    $pending_count++;
                    break;
                default:
                    $status_text = 'ใหม่';
                    $new_count++;
            }
        } else {
            $new_count++;
        }
        
        echo "<tr>";
        echo "<td>" . htmlspecialchars($row['buypd_ID']) . "</td>";
        echo "<td>" . date("d/m/Y", strtotime($row['buypd_date'])) . "</td>";
        echo "<td>" . htmlspecialchars($row['shop_name'] ?? 'ไม่ระบุ') . "</td>";
        echo "<td>" . htmlspecialchars($row['user_name'] ?? 'ไม่ระบุ') . "</td>";
        echo "<td>" . number_format($row['buypd_total'], 2) . "</td>";
        echo "<td>" . $status_text . "</td>";
        echo "</tr>";
        
        $total_amount += $row['buypd_total'];
    }
    
    // แสดงสรุปยอดรวม
    echo "<tr class='summary'>";
    echo "<td colspan='4' style='text-align: right;'>ยอดรวมทั้งหมด:</td>";
    echo "<td>" . number_format($total_amount, 2) . "</td>";
    echo "<td></td>";
    echo "</tr>";
} else {
    echo "<tr><td colspan='6' style='text-align: center;'>ไม่พบข้อมูลใบสั่งซื้อ</td></tr>";
}

echo "</tbody>";
echo "</table>";

// สรุปสถิติ
echo "<br>";
echo "<h3>สรุปสถิติ</h3>";
echo "<table>";
echo "<tr><th>รายการ</th><th>จำนวน</th></tr>";
echo "<tr><td>ทั้งหมด</td><td>" . $order_result->num_rows . "</td></tr>";
echo "<tr><td>รายการใหม่</td><td>" . $new_count . "</td></tr>";
echo "<tr><td>เสร็จสิ้น</td><td>" . $completed_count . "</td></tr>";
echo "<tr><td>รอดำเนินการ</td><td>" . $pending_count . "</td></tr>";
echo "<tr><td>ยกเลิก</td><td>" . $cancelled_count . "</td></tr>";
echo "</table>";

echo "</body>";
echo "</html>";

$conn->close();
exit();
?>