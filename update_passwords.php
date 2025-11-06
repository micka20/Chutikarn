<?php
// update_passwords.php - สำหรับแปลงรหัสผ่าน plain text เป็น hash
session_start();

// อนุญาตให้รันได้เฉพาะผู้ดูแลระบบ
if (!isset($_SESSION['user_username']) || $_SESSION['user_type'] !== 'ผู้ดูแลระบบ') {
    die("<h2 style='color: red;'>ไม่มีสิทธิ์ในการเข้าถึงหน้านี้</h2>");
}

$conn = new mysqli("localhost", "root", "12345678", "chutikarnceramic1");
$conn->set_charset("utf8");

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

echo "<!DOCTYPE html>
<html>
<head>
    <title>อัปเดตรหัสผ่าน</title>
    <meta charset='UTF-8'>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        .success { color: green; }
        .error { color: red; }
        .info { color: blue; }
    </style>
</head>
<body>
    <h2>กำลังแปลงรหัสผ่านเป็น Hash...</h2>";

// ดึงผู้ใช้ทั้งหมด
$result = $conn->query("SELECT user_ID, user_username, user_password FROM user");

$updated_count = 0;
$error_count = 0;
$already_hashed = 0;

while ($row = $result->fetch_assoc()) {
    $user_id = $row['user_ID'];
    $username = $row['user_username'];
    $current_password = $row['user_password'];
    
    // ตรวจสอบว่ารหัสผ่านเป็น hash อยู่แล้วหรือไม่
    if (password_verify('test', $current_password) === false && 
        !preg_match('/^\$2[ayb]\$.{56}$/', $current_password)) {
        
        // แปลงเป็น hash
        $hashed_password = password_hash($current_password, PASSWORD_BCRYPT);
        
        // อัปเดตฐานข้อมูล
        $stmt = $conn->prepare("UPDATE user SET user_password = ? WHERE user_ID = ?");
        $stmt->bind_param("ss", $hashed_password, $user_id);
        
        if ($stmt->execute()) {
            echo "<p class='success'>✓ อัปเดตรหัสผ่านสำหรับ $username (ID: $user_id) สำเร็จ</p>";
            $updated_count++;
        } else {
            echo "<p class='error'>✗ ข้อผิดพลาดในการอัปเดต $username (ID: $user_id) - " . $stmt->error . "</p>";
            $error_count++;
        }
        
        $stmt->close();
    } else {
        echo "<p class='info'>→ $username (ID: $user_id) มีรหัสผ่านเป็น hash อยู่แล้ว</p>";
        $already_hashed++;
    }
}

echo "<h3>สรุปผลการแปลงรหัสผ่าน:</h3>";
echo "<p class='success'>อัปเดตสำเร็จ: $updated_count รายการ</p>";
echo "<p class='info'>เป็น hash อยู่แล้ว: $already_hashed รายการ</p>";
echo "<p class='error'>เกิดข้อผิดพลาด: $error_count รายการ</p>";
echo "<p><strong>การแปลงรหัสผ่านเสร็จสิ้น!</strong></p>";
echo "<p><a href='settings.php'>กลับไปที่หน้าตั้งค่า</a></p>";

$conn->close();
echo "</body></html>";
?>