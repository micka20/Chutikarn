<?php
session_start();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['order_id'])) {
    header("Location: receive_stock.php");
    exit();
}

$buypd_id = $_POST['order_id'];
$conn = new mysqli("localhost", "root", "12345678", "chutikarnceramic1");
$conn->set_charset("utf8");

if ($conn->connect_error) {
    $_SESSION['stock_update_error'] = "การเชื่อมต่อฐานข้อมูลล้มเหลว.";
    header("Location: receive_stock.php");
    exit();
}

$conn->begin_transaction();

try {
    $sql_details = "SELECT pd_name, OD_number FROM orderdetails WHERE buypd_ID = ?";
    $stmt_details = $conn->prepare($sql_details);
    $stmt_details->bind_param("s", $buypd_id);
    $stmt_details->execute();
    $result_details = $stmt_details->get_result();
    
    if ($result_details->num_rows === 0) throw new Exception("ไม่พบรายการสินค้า.");

    $update_stock_sql = "UPDATE product SET pd_number = pd_number + ? WHERE pd_name = ?";
    $stmt_update_stock = $conn->prepare($update_stock_sql);
    
    $check_product_sql = "SELECT pd_ID FROM product WHERE pd_name = ?";
    $stmt_check_product = $conn->prepare($check_product_sql);

    while ($item = $result_details->fetch_assoc()) {
        $pd_name = $item['pd_name'];
        $od_number = (int)$item['OD_number'];

        $stmt_check_product->bind_param("s", $pd_name);
        $stmt_check_product->execute();
        $stmt_check_product->store_result();

        if ($stmt_check_product->num_rows > 0) {
            $stmt_update_stock->bind_param("is", $od_number, $pd_name);
            if (!$stmt_update_stock->execute()) throw new Exception("อัปเดตสต็อกล้มเหลวสำหรับ: " . $pd_name);
        } else {
            $new_pd_id = "NEW-" . time();
            $insert_sql = "INSERT INTO product (pd_ID, pd_name, pd_number, pd_unit, pd_details, pd_costprice, pd_saleprice, typepd_ID, zonepd) VALUES (?, ?, ?, 'หน่วย', '', 0.00, 0.00, 'T-001', 'ยังไม่ได้จัดเก็บ')";
            $stmt_insert = $conn->prepare($insert_sql);
            $stmt_insert->bind_param("ssi", $new_pd_id, $pd_name, $od_number);
            if (!$stmt_insert->execute()) throw new Exception("เพิ่มสินค้าใหม่ล้มเหลว: " . $pd_name);
            $stmt_insert->close();
        }
    }

    $sql_update_order = "UPDATE buypd SET buypd_status = 'completed' WHERE buypd_ID = ?";
    $stmt_update_order = $conn->prepare($sql_update_order);
    $stmt_update_order->bind_param("s", $buypd_id);
    if (!$stmt_update_order->execute()) throw new Exception("อัปเดตสถานะใบสั่งซื้อล้มเหลว.");

    $conn->commit();
    $_SESSION['stock_update_success'] = "รับสินค้าจากใบสั่งซื้อ " . htmlspecialchars($buypd_id) . " เรียบร้อยแล้ว!";

} catch (Exception $e) {
    $conn->rollback();
    $_SESSION['stock_update_error'] = "เกิดข้อผิดพลาด: " . $e->getMessage();
} finally {
    if (isset($stmt_details)) $stmt_details->close();
    if (isset($stmt_update_stock)) $stmt_update_stock->close();
    if (isset($stmt_check_product)) $stmt_check_product->close();
    if (isset($stmt_update_order)) $stmt_update_order->close();
    $conn->close();
}

header("Location: receive_stock.php");
exit();
?>