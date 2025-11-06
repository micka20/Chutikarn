<?php
session_start();
header('Content-Type: application/json');

// เชื่อมต่อฐานข้อมูล
$servername = "localhost";
$username = "root";
$password = "12345678";
$dbname = "chutikarnceramic1";

$conn = new mysqli($servername, $username, $password, $dbname);
$conn->set_charset("utf8");

if ($conn->connect_error) {
    die(json_encode(['error' => 'Connection failed']));
}

// ดึงข้อมูลสินค้าใกล้หมด
$reorder_point = 10;
$settings_query = $conn->query("SELECT setting_value FROM settings WHERE setting_key = 'reorder_point'");
if ($settings_query && $settings_query->num_rows > 0) {
    $setting_row = $settings_query->fetch_assoc();
    $reorder_point = intval($setting_row['setting_value']);
}

$low_stock_query = $conn->query("
    SELECT COUNT(*) as count 
    FROM product 
    WHERE pd_number <= $reorder_point
");
$low_stock_count = $low_stock_query->fetch_assoc()['count'];

// ดึงข้อมูลสินค้าเข้าใหม่
$recent_received_query = $conn->query("
    SELECT COUNT(*) as count
    FROM buypd 
    WHERE buypd_status = 'received' 
    AND buypd_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
");
$recent_received_count = $recent_received_query->fetch_assoc()['count'];

echo json_encode([
    'low_stock_count' => $low_stock_count,
    'recent_received_count' => $recent_received_count
]);

$conn->close();
?>