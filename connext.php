<?php
// ตั้งค่าการเชื่อมต่อฐานข้อมูล
define('DB_SERVER', 'localhost');
define('DB_USERNAME', 'root');
define('DB_PASSWORD', '12345678');
define('DB_NAME', 'chutikarn');

// สร้างการเชื่อมต่อ
$conn = new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);

// ตรวจสอบการเชื่อมต่อ
if ($conn->connect_error) {
    die("การเชื่อมต่อล้มเหลว: " . $conn->connect_error);
}

// ตั้งค่าการเข้ารหัสอักขระ
$conn->set_charset("utf8");
?>