<?php
session_start();

// ตรวจสอบการล็อกอิน
if(!isset($_SESSION['user_username'])) {
    echo json_encode(["success" => false, "message" => "กรุณาล็อกอินก่อนใช้งาน"]);
    exit();
}

// ตรวจสอบข้อมูลที่ส่งมา
if(!isset($_POST['buypd_ID']) || !isset($_POST['buypd_status'])) {
    echo json_encode(["success" => false, "message" => "ข้อมูลไม่ครบถ้วน"]);
    exit();
}

$buypd_id = $_POST['buypd_ID'];
$buypd_status = $_POST['buypd_status'];

// การเชื่อมต่อฐานข้อมูล
$servername = "localhost";
$username = "root";
$password = "12345678";
$dbname = "chutikarnceramic1";

$conn = new mysqli($servername, $username, $password, $dbname);
$conn->set_charset("utf8");

if ($conn->connect_error) {
    echo json_encode(["success" => false, "message" => "เกิดข้อผิดพลาดในการเชื่อมต่อฐานข้อมูล"]);
    exit();
}

// เริ่ม transaction
$conn->begin_transaction();

try {
    // 1. อัพเดทสถานะใบสั่งซื้อ
    $sql_update = "UPDATE buypd SET buypd_status = ? WHERE buypd_ID = ?";
    $stmt_update = $conn->prepare($sql_update);
    $stmt_update->bind_param("ss", $buypd_status, $buypd_id);
    
    if (!$stmt_update->execute()) {
        throw new Exception("ไม่สามารถอัพเดทสถานะใบสั่งซื้อได้");
    }
    
    // 2. ถ้าเป็นสถานะ received ให้อัพเดทจำนวนสินค้าในสต๊อก
    if ($buypd_status === 'received') {
        // ดึงรายละเอียดสินค้าจากใบสั่งซื้อ
        $sql_details = "SELECT pd_name, OD_number FROM orderdetails WHERE buypd_ID = ?";
        $stmt_details = $conn->prepare($sql_details);
        $stmt_details->bind_param("s", $buypd_id);
        $stmt_details->execute();
        $result_details = $stmt_details->get_result();
        
        while ($row = $result_details->fetch_assoc()) {
            $product_name = $row['pd_name'];
            $quantity = $row['OD_number'];
            
            // อัพเดทจำนวนสินค้าในตาราง product
            $sql_update_product = "UPDATE product SET pd_number = pd_number + ? WHERE pd_name = ?";
            $stmt_update_product = $conn->prepare($sql_update_product);
            $stmt_update_product->bind_param("is", $quantity, $product_name);
            
            if (!$stmt_update_product->execute()) {
                throw new Exception("ไม่สามารถอัพเดทจำนวนสินค้า: " . $product_name);
            }
            
            $stmt_update_product->close();
        }
        
        $stmt_details->close();
    }
    
    // commit transaction
    $conn->commit();
    
    echo json_encode(["success" => true, "message" => "อัพเดทสถานะเรียบร้อยแล้ว"]);
    
} catch (Exception $e) {
    // rollback transaction ถ้าเกิดข้อผิดพลาด
    $conn->rollback();
    echo json_encode(["success" => false, "message" => $e->getMessage()]);
}

$stmt_update->close();
$conn->close();
?>