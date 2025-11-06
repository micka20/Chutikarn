<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

// ลบข้อมูลสินค้าใกล้หมดจาก session หลังจากโหลดเสร็จ
if (isset($_SESSION['prefilled_products'])) {
    unset($_SESSION['prefilled_products']);
    echo json_encode(['status' => 'success', 'message' => 'Session cleared']);
} else {
    echo json_encode(['status' => 'success', 'message' => 'No session to clear']);
}
?>