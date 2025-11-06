<?php
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

// ดึงข้อมูลสินค้าใกล้หมด
$low_stock_products = [];
$low_stock_sql = "SELECT pd_ID, pd_name, pd_number, pd_unit, pd_costprice 
                  FROM product 
                  WHERE pd_number <= 10 AND pd_number > 0 
                  ORDER BY pd_number ASC";
$low_stock_result = $conn->query($low_stock_sql);

if ($low_stock_result->num_rows > 0) {
    while ($row = $low_stock_result->fetch_assoc()) {
        // คำนวณจำนวนที่ควรสั่งซื้อ (แนะนำให้สั่ง 20 ชิ้น หรือ 2 เท่าของจำนวนคงเหลือ)
        $recommended_quantity = max(20, $row['pd_number'] * 2);
        
        $low_stock_products[] = [
            'pd_ID' => $row['pd_ID'],
            'pd_name' => $row['pd_name'],
            'current_stock' => $row['pd_number'],
            'pd_unit' => $row['pd_unit'],
            'pd_costprice' => $row['pd_costprice'],
            'recommended_quantity' => $recommended_quantity
        ];
    }
    
    // บันทึกลง session
    $_SESSION['prefilled_products'] = $low_stock_products;
    
    echo json_encode(['status' => 'success', 'count' => count($low_stock_products)]);
} else {
    echo json_encode(['status' => 'error', 'message' => 'ไม่มีสินค้าใกล้หมด']);
}

$conn->close();
?>