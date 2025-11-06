<?php
session_start();
if (!isset($_SESSION['user_username'])) {
    header("Location: login.php");
    exit();
}

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['buypd_id'])) {
    $buypd_id = $_POST['buypd_id'];

    $conn = new mysqli("localhost", "root", "12345678", "chutikarnceramic1");
    $conn->set_charset("utf8");
    if ($conn->connect_error) {
        die("Connection failed: " . $conn->connect_error);
    }

    // อัปเดตสถานะเป็น 'Awaiting Stock Update'
    $sql = "UPDATE buypd SET buypd_status = 'Awaiting Stock Update' WHERE buypd_ID = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $buypd_id);
    
    if ($stmt->execute()) {
        // หากสำเร็จ ให้ redirect ไปยังหน้ารับสินค้า
        $_SESSION['success_message'] = "ยืนยันใบสั่งซื้อ " . htmlspecialchars($buypd_id) . " เรียบร้อยแล้ว";
        header("Location: receive_products.php");
        exit();
    } else {
        // หากไม่สำเร็จ ให้กลับไปหน้ารายการพร้อมข้อความ error
        $_SESSION['error_message'] = "เกิดข้อผิดพลาดในการยืนยันใบสั่งซื้อ";
        header("Location: Order.php");
        exit();
    }

    $stmt->close();
    $conn->close();

} else {
    // ถ้าเข้ามาหน้านี้โดยตรง ให้กลับไปหน้าหลัก
    header("Location: Order.php");
    exit();
}
?>