<?php
header('Content-Type: application/json');

if (!isset($_GET['shop_id'])) {
    echo json_encode([]);
    exit();
}

$shop_id = $_GET['shop_id'];

// ควรจะดึงข้อมูลสินค้าตามร้านค้า แต่ใน schema ของคุณไม่มีตารางเชื่อม
// ดังนั้น เราจะดึงสินค้าทั้งหมดมาแสดงก่อน (เพื่อเป็นตัวอย่าง)
$conn = new mysqli("localhost", "root", "12345678", "chutikarnceramic1");
$conn->set_charset("utf8");

// **ในอนาคต: ควรมีตารางเชื่อมระหว่าง Product กับ Shop**
// SELECT p.* FROM product p JOIN product_shop ps ON p.pd_ID = ps.pd_ID WHERE ps.shop_ID = ?
// แต่ตอนนี้จะดึงสินค้าทั้งหมดมาแสดงแทน
$sql = "SELECT pd_ID, pd_name, pd_costprice FROM product ORDER BY pd_name ASC";
$stmt = $conn->prepare($sql);
// $stmt->bind_param("s", $shop_id); // เปิดใช้งานเมื่อมีตารางเชื่อม
$stmt->execute();
$result = $stmt->get_result();

$products = [];
while ($row = $result->fetch_assoc()) {
    $products[] = $row;
}

$stmt->close();
$conn->close();

echo json_encode($products);
?>