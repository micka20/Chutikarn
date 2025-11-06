<?php
session_start();

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
$zone_id = $_GET['zone_id'] ?? '';

if (empty($zone_id)) {
    echo json_encode(["success" => false, "message" => "Zone ID is required"]);
    exit;
}

// ดึงข้อมูลโซน
$zone_sql = "SELECT * FROM storage_zones WHERE zone_id = ?";
$stmt = $conn->prepare($zone_sql);
$stmt->bind_param("s", $zone_id);
$stmt->execute();
$zone_result = $stmt->get_result();

if ($zone_result->num_rows === 0) {
    echo json_encode(["success" => false, "message" => "Zone not found"]);
    exit;
}

$zone = $zone_result->fetch_assoc();
$stmt->close();

// ดึงข้อมูลสินค้าในโซนนี้
$products_sql = "
    SELECT p.pd_ID, p.pd_name, p.pd_number, p.pd_unit, t.typepd_name
    FROM product p
    LEFT JOIN typepd t ON p.typepd_ID = t.typepd_ID
    WHERE p.zone_id = ?
    ORDER BY p.pd_name
";
$stmt = $conn->prepare($products_sql);
$stmt->bind_param("s", $zone_id);
$stmt->execute();
$products_result = $stmt->get_result();

$products = [];
if ($products_result->num_rows > 0) {
    while($row = $products_result->fetch_assoc()) {
        $products[] = $row;
    }
}
$stmt->close();

$conn->close();

// ส่งข้อมูลกลับเป็น JSON
echo json_encode([
    "success" => true,
    "zone" => $zone,
    "products" => $products
]);
?>