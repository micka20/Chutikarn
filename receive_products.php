<?php
session_start();

// ตรวจสอบว่าผู้ใช้ล็อกอินอยู่หรือไม่
if(!isset($_SESSION['user_username'])) {
    echo '<script>alert("กรุณาล็อกอินก่อนใช้งาน"); window.location.href = "login.php";</script>';
    exit();
}

// ตรวจสอบว่ามีข้อมูลส่งมาหรือไม่
if(!isset($_POST['buypd_id']) || empty($_POST['buypd_id'])) {
    echo '<script>alert("ไม่พบรหัสใบสั่งซื้อ"); window.history.back();</script>';
    exit();
}

$buypd_id = $_POST['buypd_id'];
$pd_names = $_POST['pd_name'];
$received_qtys = $_POST['received_qty'];
$ordered_qtys = $_POST['ordered_qty'];
$received_by = $_SESSION['user_username']; // ใช้ username ผู้รับสินค้า

// ตรวจสอบว่ามีสินค้าที่รับเข้าอย่างน้อย 1 รายการ
$hasReceivedItems = false;
foreach ($received_qtys as $qty) {
    if ($qty > 0) {
        $hasReceivedItems = true;
        break;
    }
}

if (!$hasReceivedItems) {
    echo '<script>
            alert("กรุณากรอกจำนวนสินค้าที่รับเข้าอย่างน้อย 1 รายการ");
            window.history.back();
          </script>';
    exit();
}

// การเชื่อมต่อฐานข้อมูล
$servername = "localhost";
$username = "root";
$password = "12345678";
$dbname = "chutikarnceramic1";

$conn = new mysqli($servername, $username, $password, $dbname);
$conn->set_charset("utf8");

if ($conn->connect_error) {
    echo '<script>alert("เกิดข้อผิดพลาดในการเชื่อมต่อฐานข้อมูล"); window.history.back();</script>';
    exit();
}

// เริ่ม Transaction
$conn->begin_transaction();

try {
    // ตรวจสอบสถานะการรับสินค้า
    $allReceived = true;
    $someReceived = false;
    $totalReceived = 0;
    $totalOrdered = 0;

    for($i = 0; $i < count($pd_names); $i++) {
        $received_qty = intval($received_qtys[$i]);
        $ordered_qty = intval($ordered_qtys[$i]);
        
        if($received_qty > 0) {
            $someReceived = true;
        }
        
        if($received_qty != $ordered_qty) {
            $allReceived = false;
        }
        
        $totalReceived += $received_qty;
        $totalOrdered += $ordered_qty;
    }

    // อัพเดทสถานะตามจำนวนที่รับ
    if($allReceived) {
        $status = 'received_complete';
        $status_th = 'รับครบ';
    } elseif($someReceived) {
        $status = 'received_partial';
        $status_th = 'รับบางส่วน';
    } else {
        $status = 'pending';
        $status_th = 'รอรับ';
    }

    // อัพเดทสถานะใบสั่งซื้อ
    $sql_update_order = "UPDATE buypd SET buypd_status = ?, receive_date = NOW() WHERE buypd_ID = ?";
    $stmt_update_order = $conn->prepare($sql_update_order);
    $stmt_update_order->bind_param("ss", $status, $buypd_id);
    $stmt_update_order->execute();
    
    // เพิ่มสินค้าลงในสต็อกและบันทึกประวัติ
    for($i = 0; $i < count($pd_names); $i++) {
        $pd_name = $pd_names[$i];
        $received_qty = intval($received_qtys[$i]);
        
        if($received_qty > 0) {
            // ตรวจสอบว่าสินค้ามีในสต็อกหรือไม่
            $sql_check_stock = "SELECT * FROM stock WHERE pd_name = ?";
            $stmt_check_stock = $conn->prepare($sql_check_stock);
            $stmt_check_stock->bind_param("s", $pd_name);
            $stmt_check_stock->execute();
            $result_check_stock = $stmt_check_stock->get_result();
            
            if($result_check_stock->num_rows > 0) {
                // อัพเดทสต็อกที่มีอยู่
                $sql_update_stock = "UPDATE stock SET stock_qty = stock_qty + ?, last_updated = NOW() WHERE pd_name = ?";
                $stmt_update_stock = $conn->prepare($sql_update_stock);
                $stmt_update_stock->bind_param("is", $received_qty, $pd_name);
                $stmt_update_stock->execute();
            } else {
                // เพิ่มสินค้าใหม่ในสต็อก
                $sql_insert_stock = "INSERT INTO stock (pd_name, stock_qty) VALUES (?, ?)";
                $stmt_insert_stock = $conn->prepare($sql_insert_stock);
                $stmt_insert_stock->bind_param("si", $pd_name, $received_qty);
                $stmt_insert_stock->execute();
            }
            
            // บันทึกประวัติการรับสินค้า
            $sql_insert_receive = "INSERT INTO receive_history (buypd_id, pd_name, received_qty, received_by) VALUES (?, ?, ?, ?)";
            $stmt_insert_receive = $conn->prepare($sql_insert_receive);
            $stmt_insert_receive->bind_param("ssis", $buypd_id, $pd_name, $received_qty, $received_by);
            $stmt_insert_receive->execute();
        }
    }
    
    // Commit Transaction
    $conn->commit();
    
    $message = "รับสินค้าเข้าเรียบร้อยแล้ว\\n";
    $message .= "สถานะ: $status_th\\n";
    $message .= "รับแล้ว: $totalReceived ชิ้น จาก $totalOrdered ชิ้น";
    
    echo '<script>
            alert("' . $message . '");
            window.location.href = "order_list.php";
          </script>';
    
} catch (Exception $e) {
    // Rollback Transaction ถ้าเกิดข้อผิดพลาด
    $conn->rollback();
    echo '<script>
            alert("เกิดข้อผิดพลาดในการรับสินค้า: ' . $e->getMessage() . '");
            window.history.back();
          </script>';
}

$conn->close();
?>