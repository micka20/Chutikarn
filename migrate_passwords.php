<?php
// migrate_passwords.php - รันครั้งเดียวเพื่อแปลงรหัสผ่านเป็น hash
session_start();

// อนุญาตให้รันได้เฉพาะผู้ดูแลระบบ
if (!isset($_SESSION['user_username']) || $_SESSION['user_type'] !== 'ผู้ดูแลระบบ') {
    die("ไม่มีสิทธิ์ในการเข้าถึง");
}

$conn = new mysqli("localhost", "root", "12345678", "chutikarnceramic1");
$conn->set_charset("utf8");

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

echo "<h2>กำลังแปลงรหัสผ่านเป็น Hash...</h2>";

// ดึงผู้ใช้ทั้งหมด
$result = $conn->query("SELECT user_ID, user_password FROM user");

$updated_count = 0;
$error_count = 0;

while ($row = $result->fetch_assoc()) {
    $user_id = $row['user_ID'];
    $plain_password = $row['user_password'];
    
    // ตรวจสอบว่ารหัสผ่านเป็น hash อยู่แล้วหรือไม่
    if (password_needs_rehash($plain_password, PASSWORD_DEFAULT) || !password_verify('test', $plain_password)) {
        // แปลงเป็น hash
        $hashed_password = password_hash($plain_password, PASSWORD_DEFAULT);
        
        // อัปเดตฐานข้อมูล
        $stmt = $conn->prepare("UPDATE user SET user_password = ? WHERE user_ID = ?");
        $stmt->bind_param("ss", $hashed_password, $user_id);
        
        if ($stmt->execute()) {
            echo "<p style='color: green;'>✓ อัปเดตรหัสผ่านสำหรับ user ID: $user_id สำเร็จ</p>";
            $updated_count++;
        } else {
            echo "<p style='color: red;'>✗ ข้อผิดพลาดในการอัปเดต user ID: $user_id - " . $stmt->error . "</p>";
            $error_count++;
        }
        
        $stmt->close();
    } else {
        echo "<p style='color: blue;'>→ user ID: $user_id มีรหัสผ่านเป็น hash อยู่แล้ว</p>";
    }
}

echo "<h3>สรุปผลการแปลงรหัสผ่าน:</h3>";
echo "<p>อัปเดตสำเร็จ: $updated_count รายการ</p>";
echo "<p>เกิดข้อผิดพลาด: $error_count รายการ</p>";
echo "<p><strong>การแปลงรหัสผ่านเสร็จสิ้น!</strong></p>";

$conn->close();
?>