<?php
session_start();

// ตรวจสอบว่าผู้ใช้ล็อกอินอยู่หรือไม่
if(!isset($_SESSION['user_username'])) {
    header("Location: login.php");
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
    die("Connection failed: " . $conn->connect_error);
}

// --- ดึงข้อมูลผู้ใช้ที่ล็อกอิน (สำหรับ Header) ---
$user_username_session = $_SESSION['user_username'];
$user_sql = "SELECT user_username, user_type FROM user WHERE user_username = ?";
$stmt_user = $conn->prepare($user_sql);
$stmt_user->bind_param("s", $user_username_session);
$stmt_user->execute();
$user_result = $stmt_user->get_result();
$user_data = $user_result->fetch_assoc();
$user_username = $user_data['user_username'] ?? 'ผู้ใช้งาน';
$user_type = $user_data['user_type'] ?? 'ผู้เยี่ยมชม';
$stmt_user->close();

// === ดึงข้อมูลสำหรับแจ้งเตือน ===
$low_stock_products = [];
$low_stock_sql = "SELECT pd_ID, pd_name, pd_number, pd_unit FROM product WHERE pd_number <= 10 AND pd_number > 0 ORDER BY pd_number ASC";
$low_stock_result = $conn->query($low_stock_sql);
if ($low_stock_result->num_rows > 0) {
    while($row = $low_stock_result->fetch_assoc()) {
        $low_stock_products[] = $row;
    }
}
$low_stock_count = count($low_stock_products);

// ดึงข้อมูลสำหรับแจ้งเตือนสินค้าเข้าใหม่ (7 วันที่ผ่านมา)
$recent_received_orders = [];
$recent_received_query = $conn->query("
    SELECT b.buypd_ID, b.buypd_date, s.shop_name, b.buypd_total
    FROM buypd b
    JOIN shop s ON b.shop_ID = s.shop_ID
    WHERE b.buypd_status = 'received' 
    AND b.buypd_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
    ORDER BY b.buypd_date DESC
");

$recent_received_count = 0;
if ($recent_received_query) {
    while($row = $recent_received_query->fetch_assoc()){
        $recent_received_orders[] = $row;
        $recent_received_count++;
    }
}

// === (ปรับปรุงใหม่) ประมวลผลการรับสินค้า ===
if(isset($_POST['receive_order'])) {
    $buypd_id = $_POST['buypd_id'];
    $received_items = isset($_POST['received_items']) ? $_POST['received_items'] : [];
    
    try {
        // เริ่ม transaction
        $conn->autocommit(FALSE);
        
        // ดึงรายการสินค้า "ที่ยังไม่ได้รับ" จากใบสั่งซื้อ
        $sql_details = "SELECT pd_name, OD_number FROM orderdetails WHERE buypd_ID = ? AND OD_status = 'pending'";
        $stmt = $conn->prepare($sql_details);
        if (!$stmt) {
             throw new Exception("Prepare failed (sql_details): " . $conn->error);
        }
        $stmt->bind_param("s", $buypd_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $received_products = [];
        
        while($row = $result->fetch_assoc()) {
            $product_name = $row['pd_name'];
            $quantity = $row['OD_number'];
            
            // ตรวจสอบว่ารายการนี้ถูกติ๊กว่าได้รับแล้วหรือไม่
            if(in_array($product_name, $received_items)) {
                // ตรวจสอบว่าสินค้าอยู่ในตาราง product หรือไม่
                $check_sql = "SELECT pd_ID, pd_number FROM product WHERE pd_name = ?";
                $check_stmt = $conn->prepare($check_sql);
                if (!$check_stmt) {
                    throw new Exception("Prepare failed (check_sql): " . $conn->error);
                }
                $check_stmt->bind_param("s", $product_name);
                $check_stmt->execute();
                $check_result = $check_stmt->get_result();
                
                if($check_result->num_rows > 0) {
                    // สินค้าเก่า - เพิ่มจำนวน
                    $product_data = $check_result->fetch_assoc();
                    $new_amount = $product_data['pd_number'] + $quantity;
                    
                    $update_sql = "UPDATE product SET pd_number = ? WHERE pd_ID = ?";
                    $update_stmt = $conn->prepare($update_sql);
                    if (!$update_stmt) {
                        throw new Exception("Prepare failed (update_sql): " . $conn->error);
                    }
                    $update_stmt->bind_param("is", $new_amount, $product_data['pd_ID']);
                    $update_stmt->execute();
                    $update_stmt->close();
                } else {
                    // สินค้าใหม่ - เพิ่มลงตาราง product
                    $insert_sql = "INSERT INTO product (pd_name, pd_number, pd_unit, pd_details, pd_costprice, pd_saleprice, pd_pointofpurchase, typepd_ID, zonepd) 
                                   VALUES (?, ?, 'ชิ้น', 'รับสินค้าเข้าจากใบสั่งซื้อ', 0, 0, '5', 'T-001', 'โซนรับเข้า')";
                    $insert_stmt = $conn->prepare($insert_sql);
                    if (!$insert_stmt) {
                        throw new Exception("Prepare failed (insert_sql): " . $conn->error);
                    }
                    $insert_stmt->bind_param("si", $product_name, $quantity);
                    $insert_stmt->execute();
                    $insert_stmt->close();
                }
                $check_stmt->close();

                // (ใหม่) อัพเดทสถานะใน orderdetails ว่ารับรายการนี้แล้ว
                $update_od_sql = "UPDATE orderdetails SET OD_status = 'received' WHERE buypd_ID = ? AND pd_name = ?";
                $update_od_stmt = $conn->prepare($update_od_sql);
                if (!$update_od_stmt) {
                    throw new Exception("Prepare failed (update_od_sql): " . $conn->error);
                }
                $update_od_stmt->bind_param("ss", $buypd_id, $product_name);
                if (!$update_od_stmt->execute()) {
                    throw new Exception("Execute failed (update_od_sql): " . $update_od_stmt->error);
                }
                $update_od_stmt->close();
                
                $received_products[] = $product_name;
            }
        }
        $stmt->close();
        
        // (ใหม่) --- ตรวจสอบสถานะใหม่ของใบสั่งซื้อ ---
        // ตรวจสอบว่ายังมีสินค้ารายการอื่นที่ 'pending' ในใบสั่งนี้หรือไม่
        $check_pending_sql = "SELECT COUNT(*) as pending_count FROM orderdetails WHERE buypd_ID = ? AND OD_status = 'pending'";
        $check_pending_stmt = $conn->prepare($check_pending_sql);
        if (!$check_pending_stmt) {
            throw new Exception("Prepare failed (check_pending_sql): " . $conn->error);
        }
        $check_pending_stmt->bind_param("s", $buypd_id);
        $check_pending_stmt->execute();
        $check_pending_result = $check_pending_stmt->get_result();
        $pending_count = $check_pending_result->fetch_assoc()['pending_count'];
        $check_pending_stmt->close();

        // อัพเดทสถานะใบสั่งซื้อ
        $update_status_sql = null;
        if ($pending_count == 0) {
            // ไม่มีรายการ pending เหลือ -> รับครบถ้วน
            $update_status_sql = "UPDATE buypd SET buypd_status = 'received' WHERE buypd_ID = ?";
            $success_message = "รับสินค้าครบถ้วนทั้งหมดแล้ว!";
        } else {
            // ยังมีรายการ pending เหลือ
            if (count($received_products) > 0) {
                // แต่ได้รับของบางส่วนในครั้งนี้ -> partially_received
                $update_status_sql = "UPDATE buypd SET buypd_status = 'partially_received' WHERE buypd_ID = ?";
                $success_message = "รับสินค้าบางส่วนสำเร็จ! (" . count($received_products) . " รายการ) ใบสั่งซื้อยังคงมีรายการรอดำเนินการ";
            } else {
                // ไม่ได้เลือกรับอะไรเลยในครั้งนี้
                // ไม่ต้องอัพเดทสถานะ (เพราะมันยังเป็น 'printed' หรือ 'partially_received' อยู่แล้ว)
                $success_message = "ไม่ได้เลือกรายการใดเพื่อรับสินค้า";
            }
        }
        
        if ($update_status_sql) {
            $update_status_stmt = $conn->prepare($update_status_sql);
            if (!$update_status_stmt) {
                throw new Exception("Prepare failed (update_status_sql): " . $conn->error);
            }
            $update_status_stmt->bind_param("s", $buypd_id);
            $update_status_stmt->execute();
            $update_status_stmt->close();
        }
        
        // Commit transaction
        $conn->commit();
        
    } catch(Exception $e) {
        // Rollback transaction
        $conn->rollback();
        $error_message = "เกิดข้อผิดพลาด: " . $e->getMessage();
    }
    
    $conn->autocommit(TRUE);
}
// === สิ้นสุดการประมวลผล ===

// ดึงข้อมูลทั้งสองแท็บพร้อมกัน
$pending_orders = [];
$received_history = [];

// ดึงข้อมูลใบสั่งซื้อที่รอรับ
$sql_pending = "SELECT b.buypd_ID, b.buypd_date, b.buypd_total, s.shop_name, u.user_name, b.buypd_status
        FROM buypd b
        JOIN shop s ON b.shop_ID = s.shop_ID
        JOIN user u ON b.user_ID = u.user_ID
        WHERE b.buypd_status IN ('printed', 'partially_received')
        ORDER BY 
            CASE 
                WHEN b.buypd_status = 'partially_received' THEN 1
                WHEN b.buypd_status = 'printed' THEN 2
                ELSE 3
            END,
            CAST(SUBSTRING(b.buypd_ID, 4) AS UNSIGNED) ASC";

$result_pending = $conn->query($sql_pending);
if ($result_pending && $result_pending->num_rows > 0) {
    while($row = $result_pending->fetch_assoc()) {
        $pending_orders[] = $row;
    }
}

// ดึงข้อมูลประวัติการรับสินค้า
$sql_history = "SELECT b.buypd_ID, b.buypd_date, b.buypd_total, s.shop_name, u.user_name, b.buypd_status
                FROM buypd b
                JOIN shop s ON b.shop_ID = s.shop_ID
                JOIN user u ON b.user_ID = u.user_ID
                WHERE b.buypd_status = 'received'
                ORDER BY b.buypd_date DESC, CAST(SUBSTRING(b.buypd_ID, 4) AS UNSIGNED) DESC";

$result_history = $conn->query($sql_history);
if ($result_history && $result_history->num_rows > 0) {
    while($row = $result_history->fetch_assoc()) {
        $received_history[] = $row;
    }
}

$conn->close();

// ตรวจสอบว่าผู้ใช้ต้องการดูแท็บไหน
$active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'receive';
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>รับสินค้าเข้า</title>
    <link href="https://fonts.googleapis.com/css2?family=Kanit:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
        html { scroll-behavior: smooth; }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { background-color: #f8f9fa;}
        
        .top-header { 
            font-family: 'Microsoft Sans Serif'; 
            display: flex; 
            justify-content: space-between; 
            align-items: center; 
            border: 2.6px solid #ffffff; 
            padding: 10px 20px; 
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1); 
            background-color: #fff; 
            position: sticky;
            top: 0;
            z-index: 1000;
        }
        
        .logo-section { display: flex; align-items: center; gap: 0px; }
        .user-section { display: flex; align-items: center; gap: 20px; }
        .user-info { text-align: right; }
        .user-username { font-size: 16px; font-weight: bold; }
        .user-role { font-size: 14px; color: #666; }
        .user-avatar { font-size: 25px; width: 40px; height: 40px; border-radius: 50%; background: #f0f0f0; display: flex; align-items: center; justify-content: center; cursor: pointer; }
        .dropdown { position: relative; display: inline-block; }
        .dropdown-content { display: none; position: absolute; right: 0; margin-top: 8px; background-color: #f9f9f9; min-width: 160px; box-shadow: 0px 8px 16px 0px rgba(0,0,0,0.2); z-index: 10; border-radius: 4px; overflow: hidden; }
        .dropdown-content a { color: black; padding: 12px 16px; text-decoration: none; display: block; font-size: 14px; }
        .dropdown-content a:hover { background-color: #f1f1f1; }
        .dropdown-content .logout { color: #e74c3c !important; }
        .show { display: block; }
        
        .notification-container {
            position: relative;
            display: inline-block;
        }
        .notification-badge {
            position: absolute;
            top: -5px;
            right: -5px;
            background: #ff4444;
            color: white;
            border-radius: 50%;
            width: 18px;
            height: 18px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            font-weight: bold;
            z-index: 10;
        }
        .bell-icon {
            font-size: 22px;
            cursor: pointer;
        }
        
        .main-container { 
            display: flex; 
            min-height: calc(100vh - 84px); 
        }
        
        .sidebar { 
            width: 250px; 
            background: #ffffff; 
            padding: 0; 
            box-shadow: 2px 0 15px rgba(0,0,0,0.1); 
            position: sticky;
            top: 84px;
            height: calc(100vh - 84px);
            overflow-y: auto;
        }
        
        .menu-item { display: flex; align-items: center; gap: 1rem; padding: 1rem 2rem; color: #000000; text-decoration: none; transition: background 0.3s; }
        .menu-item:hover { background: rgba(244, 212, 199, 0.5); }
        .menu-item span { flex: 1; text-align: left; font-size: 18px; }
        .menu-item.active { background: linear-gradient(90deg, #f4d4c7 0%, #edd5c7 100%); }
        .menu-item.active span, .menu-item.active div { color: #A44A3F; }
        .icon-medium { font-size: 25px; }
        
        .content { 
            flex: 1; 
            padding: 40px; 
        }
        .page-header { color: #A44A3F; font-size: 30px; font-weight: bold; margin-bottom: 30px; }
        
        .alert { padding: 15px; margin-bottom: 20px; border-radius: 5px; }
        .alert-success { background-color: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .alert-danger { background-color: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        
        .orders-container { background-color: #fff; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); overflow: hidden; }
        
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 15px; text-align: left; border-bottom: 1px solid #dee2e6; }
        th { background-color: #f8f9fa; font-weight: 600; color: #495057; }
        tr:hover { background-color: #f8f9fa; }
        
        .btn { padding: 8px 16px; border: none; border-radius: 4px; cursor: pointer; text-decoration: none; font-size: 14px; display: inline-block; transition: all 0.3s; }
        .btn-primary { background-color: #c0392b; color: white; }
        .btn-primary:hover { background-color: #a93226; }
        .btn-success { background-color: #28a745; color: white; }
        .btn-success:hover { background-color: #1e7e34; }
        .btn-info { background-color: #17a2b8; color: white; }
        .btn-info:hover { background-color: #117a8b; }
        .btn-warning { background-color: #ffc107; color: #212529; }
        
        .text-center { text-align: center; }
        .text-right { text-align: right; }
        .empty-state { text-align: center; padding: 50px; color: #6c757d; }
        .empty-state .icon { font-size: 64px; margin-bottom: 20px; opacity: 0.3; }
        
        .order-actions { display: flex; gap: 5px; justify-content: center; }
        
        .modal { display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5); }
        .modal-content { background-color: #fff; margin: 5% auto; padding: 30px; border-radius: 8px; width: 80%; max-width: 800px; max-height: 80vh; overflow-y: auto; }
        .modal-header { margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; }
        .modal-header h3 { color: #A44A3F; margin: 0; }
        .close { color: #aaa; font-size: 28px; font-weight: bold; cursor: pointer; }
        .close:hover { color: #000; }
        
        .order-details { margin-bottom: 20px; }
        .order-details p { margin-bottom: 10px; }
        .order-details strong { color: #495057; }
        
        .modal-actions { text-align: right; margin-top: 20px; }
        .modal-actions .btn { margin-left: 10px; }
        
        .items-table { width: 100%; margin-top: 20px; }
        .items-table th { background-color: #e9ecef; }
        
        .status-badge { padding: 5px 10px; border-radius: 15px; font-size: 12px; font-weight: bold; }
        .status-printed { background-color: #d1ecf1; color: #0c5460; }
        .status-partially { background-color: #fff3cd; color: #856404; }
        .status-received { background-color: #d1f1e1; color: #0c6e3c; }
        
        .order-number { 
            font-weight: bold; 
            color: #000000;
        }

        .confirm-modal { display: none; position: fixed; z-index: 1001; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5); }
        .confirm-modal-content { 
            background-color: #fff; 
            margin: 15% auto; 
            padding: 30px; 
            border-radius: 8px; 
            width: 80%; 
            max-width: 500px; 
            box-shadow: 0 4px 8px rgba(0,0,0,0.2);
        }
        .confirm-modal-header { 
            margin-bottom: 20px; 
            display: flex; 
            align-items: center;
            gap: 15px;
        }
        .confirm-modal-icon {
            font-size: 24px;
            color: #f39c12;
            background-color: #fef5e7;
            width: 50px;
            height: 50px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .confirm-modal-title {
            color: #A44A3F;
            margin: 0;
            font-size: 20px;
        }
        .confirm-modal-body {
            margin-bottom: 25px;
            padding: 0 10px;
            color: #495057;
            line-height: 1.5;
        }
        .confirm-modal-actions { 
            text-align: right; 
            display: flex;
            justify-content: flex-end;
            gap: 10px;
        }
        .btn-cancel {
            background-color: #6c757d;
            color: white;
        }
        .btn-cancel:hover {
            background-color: #5a6268;
        }
        
        .notification-modal { display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5); }
        .notification-modal-content { background-color: #fefefe; margin: 5% auto; padding: 20px; border-radius: 8px; width: 80%; max-width: 600px; box-shadow: 0 4px 8px rgba(0,0,0,0.2); }
        .notification-section { margin-bottom: 20px; }
        .notification-section h3 { color: #A44A3F; margin-bottom: 10px; border-bottom: 1px solid #eee; padding-bottom: 5px; }
        .notification-item { padding: 8px 0; border-bottom: 1px solid #f0f0f0; }
        .notification-item:last-child { border-bottom: none; }
        .no-notifications { color: #777; font-style: italic; }
        
        .tabs-container { margin-bottom: 20px; }
        .tabs { display: flex; border-bottom: 1px solid #dee2e6; }
        .tab { padding: 12px 24px; cursor: pointer; font-weight: 500; color: #6c757d; border-bottom: 2px solid transparent; transition: all 0.3s; }
        .tab:hover { color: #495057; }
        .tab.active { color: #A44A3F; border-bottom-color: #A44A3F; }
        
        .history-table th:nth-child(1) { width: 15%; }
        .history-table th:nth-child(2) { width: 15%; }
        .history-table th:nth-child(3) { width: 20%; }
        .history-table th:nth-child(4) { width: 15%; }
        .history-table th:nth-child(5) { width: 15%; }
        .history-table th:nth-child(6) { width: 10%; }
        .history-table th:nth-child(7) { width: 10%; }
        
        /* สไตล์สำหรับฟอร์มรับสินค้า */
        .receive-items-container {
            max-height: 400px;
            overflow-y: auto;
            margin: 20px 0;
            border: 1px solid #dee2e6;
            border-radius: 5px;
            padding: 15px;
        }
        .receive-item {
            display: flex;
            align-items: center;
            padding: 10px;
            border-bottom: 1px solid #f0f0f0;
            background-color: #f8f9fa;
            margin-bottom: 8px;
            border-radius: 4px;
        }
        .receive-item:last-child {
            border-bottom: none;
            margin-bottom: 0;
        }
        .receive-item-checkbox {
            margin-right: 15px;
            transform: scale(1.2);
            cursor: pointer;
        }
        .receive-item-info {
            flex: 1;
        }
        .receive-item-name {
            font-weight: bold;
            margin-bottom: 5px;
            color: #212529;
        }
        .receive-item-quantity {
            color: #6c757d;
            font-size: 14px;
        }
        .select-all-section {
            padding: 10px 0;
            margin-bottom: 10px;
            border-bottom: 1px solid #dee2e6;
            display: flex;
            align-items: center;
        }
        .select-all-checkbox {
            margin-right: 10px;
            transform: scale(1.1);
            cursor: pointer;
        }
        .select-all-label {
            font-weight: bold;
            color: #495057;
        }
        .receive-summary {
            background-color: #e9f7ef;
            border: 1px solid #d1f1e1;
            border-radius: 5px;
            padding: 15px;
            margin: 15px 0;
        }
        .receive-summary h4 {
            color: #0c6e3c;
            margin-bottom: 10px;
        }
        .summary-item {
            display: flex;
            justify-content: space-between;
            margin-bottom: 5px;
        }
        .summary-total {
            font-weight: bold;
            border-top: 1px solid #d1f1e1;
            padding-top: 8px;
            margin-top: 8px;
            color: #0c6e3c;
        }
        .receive-actions {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            margin-top: 20px;
            padding-top: 15px;
            border-top: 1px solid #dee2e6;
        }
        .btn-confirm {
            background-color: #28a745;
            color: white;
            padding: 10px 20px;
            font-weight: bold;
        }
        .btn-confirm:hover {
            background-color: #1e7e34;
        }
        .btn-confirm:disabled {
            background-color: #6c757d;
            cursor: not-allowed;
        }

        /* สไตล์สำหรับแท็บ */
        .tab-content {
            display: none;
        }
        .tab-content.active {
            display: block;
        }

        /* สไตล์สำหรับรายละเอียด */
        .details-grid { 
            display: grid; 
            grid-template-columns: 1fr 1fr; 
            gap: 15px; 
            margin-bottom: 20px; 
        }
        .detail-item { 
            display: flex; 
            flex-direction: column; 
        }
        .detail-item strong { 
            color: #495057; 
            margin-bottom: 5px; 
            font-size: 14px; 
        }
        .detail-item span { 
            font-size: 16px; 
            color: #212529; 
        }
        .products-table { 
            width: 100%; 
            border-collapse: collapse; 
            margin-top: 15px; 
        }
        .products-table th, 
        .products-table td { 
            padding: 10px; 
            text-align: left; 
            border-bottom: 1px solid #dee2e6; 
        }
        .products-table th { 
            background-color: #f8f9fa; 
            font-weight: 600; 
        }
        .text-center { text-align: center; }
        .text-right { text-align: right; }
        .summary-box {
            background-color: #f8f9fa;
            padding: 15px;
            border-radius: 5px;
            margin-top: 15px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .total-amount {
            font-weight: bold;
            color: #c0392b;
            font-size: 1.2rem;
        }
        hr { 
            margin: 20px 0; 
            border: none; 
            border-top: 1px solid #dee2e6; 
        }

        /* (ใหม่) สไตล์สำหรับสถานะในตารางรายละเอียด */
        .status-item-received {
            color: #28a745;
            font-weight: bold;
        }
        .status-item-pending {
            color: #ffc107;
            font-weight: bold;
        }
    </style>
</head>
<body>
    <h1 class="top-header" style="font-size:25px;">
        <div class="logo-section">
            <img src="logo1.png" width="50" height="50">
            <span>Chutikarn</span>
        </div>
        
        <div class="user-section">
            <div class="notification-container">
                <div class="bell-icon" onclick="showNotificationModal()">&#128276;</div>
                <?php if ($low_stock_count > 0 || $recent_received_count > 0): ?>
                    <div class="notification-badge"><?php echo $low_stock_count + $recent_received_count; ?></div>
                <?php endif; ?>
            </div>
            
            <div class="dropdown">
                <div class="user-avatar" onclick="toggleDropdown()" style="font-size:25px; width: 40px; height: 40px; border-radius: 50%; background: #f0f0f0; display: flex; align-items: center; justify-content: center; cursor: pointer;">&#128100;</div>
                <div id="userDropdown" class="dropdown-content">
                    <a href="settings.php">ข้อมูลส่วนตัว</a>
                    <a href="Login.php" class="logout">Logout</a>
                </div>
            </div>
            
            <div class="user-info" style="text-align: right;">
                <div class="user-username" style="font-size:16px; font-weight: bold;"><?php echo htmlspecialchars($user_username); ?></div>
                <div class="user-role" style="font-size:16px; color: #666;"><?php echo htmlspecialchars($user_type); ?></div>
            </div>
        </div>
    </h1>
    
    <div class="main-container">
        <div class="sidebar">
            <a href="Dashboard.php" class="menu-item"><div class="icon-medium">&#127968;</div><span>หน้าแรก</span></a>
            <a href="Manage_products.php" class="menu-item"><div class="icon-medium">&#128230;</div><span>จัดการสินค้า</span></a>
            <a href="manage_types.php" class="menu-item"><div class="icon-medium">&#128193;</div><span>จัดการประเภทสินค้า</span></a>
            <a href="receive_stock.php" class="menu-item active"><div class="icon-medium">&#128229;</div><span>รับสินค้าเข้า</span></a>
            <a href="Order.php" class="menu-item"><div class="icon-medium">&#128722;</div><span>สั่งซื้อสินค้า</span></a>
            <a href="storage.php" class="menu-item"><div class="icon-medium">&#128190;</div><span>จัดเก็บสินค้า</span></a>
            <a href="Employee_Account.php" class="menu-item"><div class="icon-medium">&#128101;</div><span>จัดการพนักงาน</span></a>
            <a href="shop.php" class="menu-item"><div class="icon-medium">&#127978;</div><span>จัดการร้านค้า</span></a>
            <a href="reports.php" class="menu-item"><div class="icon-medium">&#128200;</div><span>รายงาน</span></a>
            <a href="settings.php" class="menu-item"><div class="icon-medium">⚙️</div><span>ตั้งค่าระบบ</span></a>
        </div>
        
        <div class="content">
            <h2 class="page-header">รับสินค้าเข้า</h2>
            
            <?php if(isset($success_message)): ?>
                <div class="alert alert-success">
                    <strong>สำเร็จ!</strong> <?php echo $success_message; ?>
                </div>
            <?php endif; ?>
            
            <?php if(isset($error_message)): ?>
                <div class="alert alert-danger">
                    <strong>เกิดข้อผิดพลาด!</strong> <?php echo $error_message; ?>
                </div>
            <?php endif; ?>
            
            <div class="tabs-container">
                <div class="tabs">
                    <div class="tab <?php echo $active_tab == 'receive' ? 'active' : ''; ?>" onclick="switchTab('receive')">
                        รับสินค้าเข้า
                    </div>
                    <div class="tab <?php echo $active_tab == 'history' ? 'active' : ''; ?>" onclick="switchTab('history')">
                        ประวัติรับสินค้าเข้า
                    </div>
                </div>
            </div>
            
            <div id="receive-tab" class="tab-content <?php echo $active_tab == 'receive' ? 'active' : ''; ?>">
                <div class="orders-container">
                    <?php if(empty($pending_orders)): ?>
                        <div class="empty-state">
                            <div class="icon">📦</div>
                            <h3>ไม่มีใบสั่งซื้อที่รอรับสินค้า</h3>
                            <p>เมื่อมีการพิมพ์ใบสั่งซื้อ จะมีรายการแสดงที่นี่</p>
                            <a href="Order.php" class="btn btn-primary" style="margin-top: 20px;">ไปหน้าสั่งซื้อสินค้า</a>
                        </div>
                    <?php else: ?>
                        <table>
                            <thead>
                                <tr>
                                    <th style="width: 15%;">เลขที่ใบสั่งซื้อ</th>
                                    <th style="width: 15%;">วันที่สั่งซื้อ</th>
                                    <th style="width: 20%;">ร้านค้า</th>
                                    <th style="width: 15%;">ผู้จัดทำ</th>
                                    <th style="width: 15%;" class="text-right">ยอดรวม (บาท)</th>
                                    <th style="width: 10%;" class="text-center">สถานะ</th>
                                    <th style="width: 10%;" class="text-center">จัดการ</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($pending_orders as $order): ?>
                                    <tr>
                                        <td><strong class="order-number"><?php echo htmlspecialchars($order['buypd_ID']); ?></strong></td>
                                        <td><?php echo date("d/m/Y", strtotime($order['buypd_date'])); ?></td>
                                        <td><?php echo htmlspecialchars($order['shop_name']); ?></td>
                                        <td><?php echo htmlspecialchars($order['user_name']); ?></td>
                                        <td class="text-right" style="font-weight: bold;">฿<?php echo number_format($order['buypd_total'], 2); ?></td>
                                        <td class="text-center">
                                            <?php if($order['buypd_status'] == 'printed'): ?>
                                                <span class="status-badge status-printed">รอรับสินค้า</span>
                                            <?php elseif($order['buypd_status'] == 'partially_received'): ?>
                                                <span class="status-badge status-partially">รับบางส่วน</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center">
                                            <div class="order-actions">
                                                <button class="btn btn-info" onclick="viewOrderDetails('<?php echo $order['buypd_ID']; ?>')">
                                                    รายละเอียด
                                                </button>
                                                <button class="btn btn-success" onclick="showReceiveModal('<?php echo $order['buypd_ID']; ?>')">
                                                    &#10004; รับสินค้า
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
            
            <div id="history-tab" class="tab-content <?php echo $active_tab == 'history' ? 'active' : ''; ?>">
                <div class="orders-container">
                    <?php if(empty($received_history)): ?>
                        <div class="empty-state">
                            <div class="icon">📋</div>
                            <h3>ไม่มีประวัติการรับสินค้า</h3>
                            <p>เมื่อมีการรับสินค้าเข้า จะมีประวัติแสดงที่นี่</p>
                        </div>
                    <?php else: ?>
                        <table class="history-table">
                            <thead>
                                <tr>
                                    <th>เลขที่ใบสั่งซื้อ</th>
                                    <th>วันที่รับสินค้า</th>
                                    <th>ร้านค้า</th>
                                    <th>ผู้จัดทำ</th>
                                    <th class="text-right">ยอดรวม (บาท)</th>
                                    <th class="text-center">สถานะ</th>
                                    <th class="text-center">จัดการ</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($received_history as $order): ?>
                                    <tr>
                                        <td><strong class="order-number"><?php echo htmlspecialchars($order['buypd_ID']); ?></strong></td>
                                        <td><?php echo date("d/m/Y", strtotime($order['buypd_date'])); ?></td>
                                        <td><?php echo htmlspecialchars($order['shop_name']); ?></td>
                                        <td><?php echo htmlspecialchars($order['user_name']); ?></td>
                                        <td class="text-right" style="font-weight: bold;">฿<?php echo number_format($order['buypd_total'], 2); ?></td>
                                        <td class="text-center">
                                            <span class="status-badge status-received">รับสินค้าแล้ว</span>
                                        </td>
                                        <td class="text-center">
                                            <button class="btn btn-info" onclick="viewOrderDetails('<?php echo $order['buypd_ID']; ?>')">
                                                รายละเอียด
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div id="orderDetailsModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>รายละเอียดใบสั่งซื้อ</h3>
                <span class="close" onclick="closeModal()">&times;</span>
            </div>
            <div id="orderDetailsContent">
                </div>
        </div>
    </div>

    <div id="receiveModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>รับสินค้าเข้า</h3>
                <span class="close" onclick="closeReceiveModal()">&times;</span>
            </div>
            <div id="receiveModalContent">
                </div>
        </div>
    </div>

    <div id="notificationModal" class="notification-modal">
        <div class="notification-modal-content">
            <span class="close" onclick="closeNotificationModal()">&times;</span>
            <h2 style="color: #A44A3F; margin-bottom: 20px;">การแจ้งเตือน</h2>
            
            <div class="notification-section">
                <h3>📦 สินค้าใกล้หมด (<?php echo $low_stock_count; ?> รายการ)</h3>
                <?php if ($low_stock_count > 0): ?>
                    <?php foreach ($low_stock_products as $item): ?>
                    <div class="notification-item">
                        <strong><?php echo htmlspecialchars($item['pd_name']); ?></strong> 
                        (เหลือ <?php echo $item['pd_number']; ?> <?php echo htmlspecialchars($item['pd_unit']); ?>)
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="no-notifications">ไม่มีสินค้าใกล้หมด</div>
                <?php endif; ?>
            </div>
            
            <div class="notification-section">
                <h3>📥 สินค้าเข้าใหม่ (<?php echo $recent_received_count; ?> รายการ)</h3>
                <?php if ($recent_received_count > 0): ?>
                    <?php foreach ($recent_received_orders as $order): ?>
                    <div class="notification-item">
                        <strong>ใบสั่งซื้อ: <?php echo htmlspecialchars($order['buypd_ID']); ?></strong> 
                        จาก <?php echo htmlspecialchars($order['shop_name']); ?> 
                        (<?php echo date("d/m/Y", strtotime($order['buypd_date'])); ?>)
                        - ฿<?php echo number_format($order['buypd_total'], 2); ?>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="no-notifications">ไม่มีสินค้าเข้าใหม่ใน 7 วันที่ผ่านมา</div>
                <?php endif; ?>
            </div>
            
        </div>
    </div>

    <form id="receiveForm" method="POST" style="display: none;">
        <input type="hidden" name="receive_order" value="1">
        <input type="hidden" name="buypd_id" id="receive_buypd_id" value="">
        </form>

    <script>
        // ฟังก์ชันสำหรับสลับแท็บ
        function switchTab(tabName) {
            // อัพเดท URL โดยไม่ต้องโหลดหน้าใหม่
            const url = new URL(window.location);
            url.searchParams.set('tab', tabName);
            window.history.pushState({}, '', url);
            
            // ซ่อนแท็บทั้งหมด
            document.querySelectorAll('.tab-content').forEach(tab => {
                tab.classList.remove('active');
            });
            
            // แสดงแท็บที่เลือก
            document.getElementById(tabName + '-tab').classList.add('active');
            
            // อัพเดทสถานะแท็บที่ active
            document.querySelectorAll('.tab').forEach(tab => {
                tab.classList.remove('active');
            });
            // ใช้ event.currentTarget แทน event.target เพื่อความแน่นอน
            event.currentTarget.classList.add('active');
        }

        // ฟังก์ชันสำหรับแสดง modal รับสินค้า
        function showReceiveModal(buypd_id) {
            document.getElementById('receiveModal').style.display = 'block';
            document.getElementById('receiveModalContent').innerHTML = '<p style="text-align: center; padding: 20px;">กำลังโหลด...</p>';
            
            // ใช้ fetch API เพื่อดึงรายการสินค้า
            fetch('get_order_items.php?id=' + buypd_id)
                .then(response => response.text())
                .then(data => {
                    document.getElementById('receiveModalContent').innerHTML = data;
                    document.getElementById('receive_buypd_id').value = buypd_id;
                    // อัพเดทสรุปผลทันทีที่โหลดเสร็จ (เผื่อมี 0 รายการ)
                    updateReceiveSummary(); 
                })
                .catch(error => {
                    document.getElementById('receiveModalContent').innerHTML = '<p style="text-align: center; padding: 20px; color: red;">เกิดข้อผิดพลาดในการโหลดข้อมูล</p>';
                });
        }

        // ฟังก์ชันสำหรับปิด modal รับสินค้า
        function closeReceiveModal() {
            document.getElementById('receiveModal').style.display = 'none';
        }

        // ฟังก์ชันสำหรับเลือก/ไม่เลือกทั้งหมด
        function toggleSelectAll(checkbox) {
            const itemCheckboxes = document.querySelectorAll('.receive-item-checkbox');
            itemCheckboxes.forEach(itemCheckbox => {
                itemCheckbox.checked = checkbox.checked;
            });
            updateReceiveSummary();
        }

        // ฟังก์ชันสำหรับอัพเดทสรุปการรับสินค้า
        function updateReceiveSummary() {
            const checkboxes = document.querySelectorAll('.receive-item-checkbox');
            const selectedItems = document.querySelectorAll('.receive-item-checkbox:checked');
            
            // อัพเดทจำนวนรายการที่เลือก
            const selectedCountEl = document.getElementById('selectedCount');
            const totalCountEl = document.getElementById('totalCount');
            if (selectedCountEl && totalCountEl) {
                selectedCountEl.textContent = selectedItems.length;
                totalCountEl.textContent = checkboxes.length;
            }
            
            // อัพเดทปุ่มยืนยัน
            const confirmBtn = document.getElementById('confirmReceiveBtn');
            if (confirmBtn) {
                confirmBtn.disabled = selectedItems.length === 0;
            }

            // (ใหม่) ตรวจสอบว่า checkbox 'เลือกทั้งหมด' ควรถูกติ๊กหรือไม่
            const selectAllCheckbox = document.getElementById('selectAll');
            if (selectAllCheckbox) {
                if (checkboxes.length > 0 && selectedItems.length === checkboxes.length) {
                    selectAllCheckbox.checked = true;
                } else {
                    selectAllCheckbox.checked = false;
                }
            }
        }

        // ฟังก์ชันสำหรับส่งฟอร์มรับสินค้า
        function submitReceiveForm() {
            const form = document.getElementById('receiveForm');
            const buypdId = document.getElementById('receive_buypd_id').value;
            const checkboxes = document.querySelectorAll('.receive-item-checkbox:checked');
            
            // ล้างรายการที่ได้รับก่อนหน้า
            const existingItems = document.querySelectorAll('input[name="received_items[]"]');
            existingItems.forEach(item => item.remove());
            
            // เพิ่มรายการที่ได้รับใหม่
            checkboxes.forEach(checkbox => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'received_items[]';
                input.value = checkbox.value;
                form.appendChild(input);
            });
            
            // ส่งฟอร์ม
            form.submit();
        }

        function viewOrderDetails(buypd_id) {
            // เปิด modal และโหลดรายละเอียด
            document.getElementById('orderDetailsModal').style.display = 'block';
            document.getElementById('orderDetailsContent').innerHTML = '<p style="text-align: center; padding: 20px;">กำลังโหลด...</p>';
            
            // ใช้ fetch API เพื่อดึงรายละเอียด
            fetch('get_order_details.php?id=' + buypd_id)
                .then(response => response.text())
                .then(data => {
                    document.getElementById('orderDetailsContent').innerHTML = data;
                })
                .catch(error => {
                    document.getElementById('orderDetailsContent').innerHTML = '<p style="text-align: center; padding: 20px; color: red;">เกิดข้อผิดพลาดในการโหลดข้อมูล</p>';
                });
        }

        function closeModal() {
            document.getElementById('orderDetailsModal').style.display = 'none';
        }

        function toggleDropdown() {
            document.getElementById("userDropdown").classList.toggle("show");
        }
        
        function showNotificationModal() {
            document.getElementById("notificationModal").style.display = "block";
        }
        
        function closeNotificationModal() {
            document.getElementById("notificationModal").style.display = "none";
        }
        
        // ปิด modal เมื่อคลิกนอก modal
        window.onclick = function(event) {
            var modal = document.getElementById('orderDetailsModal');
            var receiveModal = document.getElementById('receiveModal');
            var notificationModal = document.getElementById('notificationModal');
            
            if (event.target == modal) {
                modal.style.display = 'none';
            }
            
            if (event.target == receiveModal) {
                receiveModal.style.display = 'none';
            }
            
            if (event.target == notificationModal) {
                notificationModal.style.display = 'none';
            }
            
            if (!event.target.closest('.user-avatar') && !event.target.closest('.dropdown')) {
                var dropdowns = document.getElementsByClassName("dropdown-content");
                for (var i = 0; i < dropdowns.length; i++) {
                    var openDropdown = dropdowns[i];
                    if (openDropdown.classList.contains('show')) {
                        openDropdown.classList.remove('show');
                    }
                }
            }
        }
    </script>
</body>
</html>