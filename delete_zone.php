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

// รับ zone_id จากพารามิเตอร์
$zone_id = $_POST['zone_id'] ?? '';

if (empty($zone_id)) {
    echo json_encode(["success" => false, "message" => "Zone ID is required"]);
    exit;
}

try {
    // เริ่ม transaction
    $conn->begin_transaction();

    // 1. อัพเดทสินค้าทั้งหมดที่อยู่ในโซนนี้ให้เป็น NULL
    $update_products = $conn->prepare("UPDATE product SET zone_id = NULL WHERE zone_id = ?");
    $update_products->bind_param("s", $zone_id);
    $update_products->execute();

    // 2. ลบโซนจัดเก็บ
    $delete_zone = $conn->prepare("DELETE FROM storage_zones WHERE zone_id = ?");
    $delete_zone->bind_param("s", $zone_id);
    
    if ($delete_zone->execute()) {
        $conn->commit();
        echo json_encode(["success" => true, "message" => "ลบโซนจัดเก็บสำเร็จ"]);
    } else {
        throw new Exception("ไม่สามารถลบโซนจัดเก็บได้");
    }
} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(["success" => false, "message" => $e->getMessage()]);
}

$conn->close();
?>