<?php
session_start();

// ตรวจสอบว่าผู้ใช้ล็อกอินอยู่หรือไม่
if(!isset($_SESSION['user_username'])) {
    header("Location: login.php");
    exit();
}

// เชื่อมต่อฐานข้อมูล
$servername = "localhost";
$username = "root";
$password = "12345678";
$dbname = "chutikarnceramic1";

// สร้างการเชื่อมต่อ
$conn = new mysqli($servername, $username, $password, $dbname);
$conn->set_charset("utf8");

// ตรวจสอบการเชื่อมต่อ
if ($conn->connect_error) {
    die(json_encode(["success" => false, "message" => "Connection failed: " . $conn->connect_error]));
}

// รับข้อมูลจากฟอร์ม
$zone_id = $_POST['zone_id'] ?? '';
$zone_name = trim($_POST['zone_name'] ?? '');
$zone_description = trim($_POST['zone_description'] ?? '');

// ตรวจสอบข้อมูล
if (empty($zone_name)) {
    echo json_encode(["success" => false, "message" => "กรุณากรอกชื่อโซนจัดเก็บ"]);
    exit;
}

try {
    if (empty($zone_id)) {
        // เพิ่มโซนใหม่
        // สร้าง zone_id ใหม่
        $result = $conn->query("SELECT zone_id FROM storage_zones ORDER BY zone_id DESC LIMIT 1");
        if ($result->num_rows > 0) {
            $last_zone = $result->fetch_assoc();
            $last_number = intval(substr($last_zone['zone_id'], 2));
            $new_number = $last_number + 1;
            $zone_id = "Z-" . str_pad($new_number, 3, '0', STR_PAD_LEFT);
        } else {
            $zone_id = "Z-001";
        }

        $stmt = $conn->prepare("INSERT INTO storage_zones (zone_id, zone_name, zone_description) VALUES (?, ?, ?)");
        $stmt->bind_param("sss", $zone_id, $zone_name, $zone_description);
        
        if ($stmt->execute()) {
            echo json_encode(["success" => true, "message" => "เพิ่มโซนจัดเก็บสำเร็จ"]);
        } else {
            throw new Exception("ไม่สามารถเพิ่มโซนจัดเก็บได้");
        }
    } else {
        // แก้ไขโซนที่มีอยู่
        $stmt = $conn->prepare("UPDATE storage_zones SET zone_name = ?, zone_description = ? WHERE zone_id = ?");
        $stmt->bind_param("sss", $zone_name, $zone_description, $zone_id);
        
        if ($stmt->execute()) {
            echo json_encode(["success" => true, "message" => "แก้ไขโซนจัดเก็บสำเร็จ"]);
        } else {
            throw new Exception("ไม่สามารถแก้ไขโซนจัดเก็บได้");
        }
    }
} catch (Exception $e) {
    echo json_encode(["success" => false, "message" => $e->getMessage()]);
}

$conn->close();
?>