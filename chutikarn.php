<?php
$host = "localhost";
$username = "root"; // เปลี่ยนเป็น username ของ phpMyAdmin ถ้าไม่ใช่ root
$password = "12345678"; // เปลี่ยนเป็น password ของ phpMyAdmin ถ้ามี
$database = "chutikarnceramic1";

// สร้างการเชื่อมต่อ
$conn = new mysqli($host, $username, $password, $database);

// ตรวจสอบการเชื่อมต่อ
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// ตั้งค่าการเข้ารหัสอักขระ
$conn->set_charset("utf8");
?>