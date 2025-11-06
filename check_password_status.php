<?php
// check_password_status.php - สำหรับตรวจสอบว่ารหัสผ่านเป็น hash หรือยัง
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

echo "<!DOCTYPE html>
<html>
<head>
    <title>ตรวจสอบสถานะรหัสผ่าน</title>
    <meta charset='UTF-8'>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        table { border-collapse: collapse; width: 100%; margin: 20px 0; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background-color: #f2f2f2; }
        .hashed { color: green; }
        .plain { color: red; }
    </style>
</head>
<body>
    <h2>สถานะรหัสผ่านผู้ใช้</h2>";

$result = $conn->query("SELECT user_ID, user_username, user_password FROM user");

echo "<table>
        <tr>
            <th>User ID</th>
            <th>Username</th>
            <th>สถานะรหัสผ่าน</th>
            <th>ตัวอย่างรหัสผ่าน</th>
        </tr>";

while ($row = $result->fetch_assoc()) {
    $password = $row['user_password'];
    $is_hashed = preg_match('/^\$2[ayb]\$.{56}$/', $password);
    
    echo "<tr>
            <td>{$row['user_ID']}</td>
            <td>{$row['user_username']}</td>
            <td class='" . ($is_hashed ? 'hashed' : 'plain') . "'>" . 
                ($is_hashed ? 'Hashed (ปลอดภัย)' : 'Plain Text (ไม่ปลอดภัย)') . 
            "</td>
            <td>" . substr($password, 0, 20) . "...</td>
          </tr>";
}

echo "</table>";

echo "<p><a href='update_passwords.php'>คลิกที่นี่เพื่อแปลงรหัสผ่านเป็น Hash</a></p>";
echo "<p><a href='settings.php'>กลับไปที่หน้าตั้งค่า</a></p>";

$conn->close();
echo "</body></html>";
?>