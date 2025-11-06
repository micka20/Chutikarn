<?php
session_start();
if (!isset($_SESSION['user_username'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'กรุณาเข้าสู่ระบบก่อน']);
    exit();
}

// เชื่อมต่อฐานข้อมูล
$conn = new mysqli("localhost", "root", "12345678", "chutikarnceramic1");
$conn->set_charset("utf8");

if ($conn->connect_error) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'การเชื่อมต่อฐานข้อมูลล้มเหลว']);
    exit();
}

// รับค่า ID จาก URL parameter
$buypd_ID = isset($_GET['id']) ? $_GET['id'] : '';

if (empty($buypd_ID)) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'ไม่พบรหัสใบสั่งซื้อ']);
    exit();
}

try {
    // เริ่ม transaction
    $conn->begin_transaction();
    
    // 1. ลบข้อมูลในตาราง orderdetails ก่อน (เนื่องจากมี foreign key constraint)
    $delete_orderdetails_sql = "DELETE FROM orderdetails WHERE buypd_ID = ?";
    $stmt_orderdetails = $conn->prepare($delete_orderdetails_sql);
    $stmt_orderdetails->bind_param("s", $buypd_ID);
    $stmt_orderdetails->execute();
    $stmt_orderdetails->close();
    
    // 2. ลบข้อมูลในตาราง buypd
    $delete_buypd_sql = "DELETE FROM buypd WHERE buypd_ID = ?";
    $stmt_buypd = $conn->prepare($delete_buypd_sql);
    $stmt_buypd->bind_param("s", $buypd_ID);
    $result = $stmt_buypd->execute();
    $stmt_buypd->close();
    
    if ($result) {
        // Commit transaction
        $conn->commit();
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true, 
            'message' => 'ลบใบสั่งซื้อ ' . $buypd_ID . ' เรียบร้อยแล้ว'
        ]);
    } else {
        // Rollback transaction
        $conn->rollback();
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false, 
            'message' => 'ไม่สามารถลบใบสั่งซื้อได้: ' . $conn->error
        ]);
    }
    
} catch (Exception $e) {
    // Rollback transaction ในกรณีเกิด error
    $conn->rollback();
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false, 
        'message' => 'เกิดข้อผิดพลาด: ' . $e->getMessage()
    ]);
} finally {
    $conn->close();
}
?>