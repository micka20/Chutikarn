<?php
// เริ่ม session
session_start();

// เชื่อมต่อฐานข้อมูล
$servername = "localhost";
$username = "root";
$password = "12345678";
$dbname = "chutikarnceramic1";

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// ตั้งค่า charset เป็น utf8
$conn->set_charset("utf8");

// === ส่วนเพิ่มเติม: ดึงข้อมูลสำหรับแจ้งเตือน (เหมือนในหน้า จัดการประเภทสินค้า) ===
// ดึงข้อมูลสินค้าใกล้หมด (Reorder Point)
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

// ตัวแปรสำหรับแสดงผลแจ้งเตือน
$success_message = "";
$error_messages = [];

// 🔧 สร้างรหัสร้านค้าใหม่แบบไล่ลำดับ
function generateNewShopID($conn) {
    $sql_last_shop = "SELECT shop_ID FROM shop ORDER BY CAST(SUBSTRING(shop_ID, 2) AS UNSIGNED) DESC LIMIT 1";
    $result_last_shop = $conn->query($sql_last_shop);

    if ($result_last_shop->num_rows > 0) {
        $row_last_shop = $result_last_shop->fetch_assoc();
        $last_shop_id = $row_last_shop['shop_ID'];
        // ดึงตัวเลขจากรหัสร้านค้า (รูปแบบ S001)
        $last_number = intval(substr($last_shop_id, 1)); 
        $new_number = $last_number + 1;
    } else {
        $new_number = 1;
    }

    // จัดรูปแบบให้เป็น S + ตัวเลข 3 หลัก เช่น S001
    return 'S' . sprintf('%03d', $new_number);
}

// สร้างรหัสร้านค้าครั้งแรกเมื่อเปิดหน้า
$shop_ID = generateNewShopID($conn);

// กำหนดค่าเริ่มต้นสำหรับตัวแปรฟอร์ม
$shop_name = '';
$shop_address = '';
$shop_tel = '';

// 📝 ประมวลผลการบันทึกข้อมูล
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // รับข้อมูลจากฟอร์ม
    $shop_name = trim($_POST['shop_name'] ?? '');
    $shop_address = trim($_POST['shop_address'] ?? '');
    $shop_tel = trim($_POST['shop_tel'] ?? '');

    // 🛡️ ตรวจสอบความถูกต้องของข้อมูล
    if (empty($shop_name)) {
        $error_messages[] = "กรุณาใส่ชื่อร้านค้า";
    }
    if (empty($shop_tel) || strlen($shop_tel) != 10 || !is_numeric($shop_tel)) {
        $error_messages[] = "กรุณาใส่เบอร์โทรศัพท์ 10 หลัก";
    }
    if (empty($shop_address)) {
        $error_messages[] = "กรุณาใส่ที่อยู่ร้านค้า";
    }

    // ตรวจสอบว่าร้านค้าซ้ำหรือไม่
    if (!empty($shop_name)) {
        $check_shop = "SELECT shop_name FROM shop WHERE shop_name = ?";
        $stmt_check = $conn->prepare($check_shop);
        $stmt_check->bind_param("s", $shop_name);
        $stmt_check->execute();
        $result_check = $stmt_check->get_result();
        
        if ($result_check->num_rows > 0) {
            $error_messages[] = "มีร้านค้านี้อยู่ในระบบแล้ว";
        }
        $stmt_check->close();
    }

    // 💾 บันทึกข้อมูลหากไม่มีข้อผิดพลาด
    if (empty($error_messages)) {
        // สร้างรหัสร้านค้าใหม่อีกครั้งเพื่อความแน่ใจ
        $new_shop_ID = generateNewShopID($conn);
        
        $sql_insert = "INSERT INTO shop (shop_ID, shop_name, shop_address, shop_tel) 
                       VALUES (?, ?, ?, ?)";
        
        $stmt = $conn->prepare($sql_insert);
        
        if ($stmt) {
            $stmt->bind_param("ssss", 
                $new_shop_ID, 
                $shop_name, 
                $shop_address, 
                $shop_tel
            );
            
            if ($stmt->execute()) {
                $success_message = "บันทึกร้านค้าสำเร็จ! รหัสร้านค้า: " . $new_shop_ID;
                
                // เคลียร์ข้อมูลฟอร์มหลังบันทึกสำเร็จ
                $shop_name = $shop_address = $shop_tel = '';
                
                // สร้างรหัสใหม่สำหรับการเพิ่มครั้งต่อไป
                $shop_ID = generateNewShopID($conn);
                
            } else {
                $error_messages[] = "เกิดข้อผิดพลาดในการบันทึกข้อมูล: " . $stmt->error;
            }
            
            $stmt->close();
        } else {
            $error_messages[] = "เกิดข้อผิดพลาดในการเตรียม SQL: " . $conn->error;
        }
    }
}

// 🔍 ดึงข้อมูลผู้ใช้ปัจจุบันสำหรับแสดงใน header
// ตรวจสอบว่ามีการล็อกอินและมี username ใน session หรือไม่
if(isset($_SESSION['user_username'])) {
    $logged_in_username = $_SESSION['user_username'];
    
    // ใช้ Prepared Statement เพื่อป้องกัน SQL Injection
    $current_user_sql = "SELECT user_username, user_type FROM user WHERE user_username = ?";
    $stmt_current = $conn->prepare($current_user_sql);
    $stmt_current->bind_param("s", $logged_in_username);
    $stmt_current->execute();
    $current_user_result = $stmt_current->get_result();

    $current_username = "ไม่พบข้อมูล";
    $current_user_type = "ไม่พบข้อมูล";

    if ($current_user_result->num_rows > 0) {
        $current_user_row = $current_user_result->fetch_assoc();
        $current_username = $current_user_row["user_username"];
        $current_user_type = $current_user_row["user_type"];
    }
    
    $stmt_current->close();
} else {
    // ถ้ายังไม่ได้ล็อกอิน
    $current_username = "ผู้ใช้ไม่ได้ล็อกอิน";
    $current_user_type = "กรุณาล็อกอิน";
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>หน้าเพิ่มร้านค้า</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        h1 {
            border: 2.6px solid #ffffff;
            padding: 15px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            background-color: #fff;
            position: fixed; /* เปลี่ยนจาก sticky เป็น fixed */
            top: 0;
            left: 0;
            width: 100%;
            z-index: 1000;
        }

        .menu {
            float: left;
            width: 20%;
            text-align: center;
        }

        .menu a {
            display: block;
            background-color: #ffffff;
            margin: 0;
            padding: 25px;
            margin-top: 1px;
            display: block;
            width: 100%;
            color: black;
            text-decoration: none;
        }

        .menu a:hover {
            background-color: #e0e0e0;
        }
        
        .menu a:last-child {
            border-bottom: none;
        }

        .right {
            background-color: #A44A3F;
            float: left;
            width: 20%;
            padding: 15px;
            margin-top: 7px;
            text-align: center;
        }

        /* แถบเมนู */
        .main-container {
            display: flex;
            min-height: calc(100vh - 80px);
            margin-top: 84px; /* เพิ่ม margin-top เท่ากับความสูงของ header */
        }

        
        .sidebar {
            width: 250px;
            background: linear-gradient(180deg, #ffffff 0%, #ffffff 100%);
            padding: 0;
            box-shadow: 2px 0 15px rgba(0,0,0,0.1);
            position: fixed; /* เปลี่ยนเป็น fixed */
            top: 84px; /* วางด้านล่าง header */
            left: 0;
            height: calc(100vh - 84px); /* ความสูงเท่ากับหน้าจอลบความสูง header */
            overflow-y: auto; /* ให้เลื่อนได้เมื่อมีเนื้อหาเกิน */
            z-index: 999; /* ให้อยู่ด้านล่าง header */
        }

        .menu-item {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 1rem 2rem;
            color: #000000;
            text-decoration: none;
            transition: all 0.3s ease;
            border-left: 4px solid transparent;
            position: relative;
            justify-content: flex-start;
        }

        .menu-item span {
            flex: 1;
            text-align: left;
            font-size: 18px;
            color: #000000;
            transition: color 0.3s ease;
        }

        .menu-item:hover {
            background: rgba(244, 212, 199, 0.5); /* Lighter hover */
            border-left-color: transparent;
            transform: translateX(1.5px);
        }

        .menu-item:hover span {
            color: #A44A3F;
        }

        .menu-item.active {
            background: linear-gradient(90deg, #f4d4c7 0%, #edd5c7 100%);
            border-left-color: transparent;
            color: #A44A3F;
            justify-content: flex-start;
        }
        
        .menu-item.active span {
            color: #A44A3F;
        }

        .menu-item.active .icon-medium {
            color: #A44A3F;
        }

        .menu-icon {
            width: 20px;
            height: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .icon-medium { 
            font-size: 25px; 
            color: #000000;
            transition: color 0.3s ease;
        }
        .icon-medium2 { font-size: 65px; }
        .icon-container {
        display: flex;
        gap: 20px;
        align-items: center;
        margin: 20px;
        }

        /* สไตล์สำหรับกระดิ่งแจ้งเตือน */
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
            z-index: 10;
        }

        .bell-icon {
            font-size: 20px;
            cursor: pointer;
        }

        .content {
            flex: 1;
            padding: 2.5rem;
            position: relative;
            display: flex;
            flex-direction: column;
            align-items: center;
            margin-left: 250px; /* เพิ่ม margin-left เท่ากับความกว้างของ sidebar */
            width: calc(100% - 250px); /* ปรับความกว้างของ content */
        }

        .page-header {
            position: absolute;
            top: 30px;
            left: 30px;
            color: #A44A3F;
            font-size: 30px;
            margin: 0;
            text-align: left;
        }

        .shop-form-container {
            width: 100%;
            max-width: 1000px;
            margin-top: 80px;
        }

        .shop-form {
            background-color: #fff;
            border: 1px solid #ccc;
            border-radius: 10px;
            padding: 30px;
            margin-bottom: 30px;
        }

        .form-section {
            margin-bottom: 20px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 1px;
            font-size: 16px;
        }

        .form-control {
            width: 100%;
            padding: 10px;
            border: 1px solid #ccc;
            border-radius: 8px;
            font-size: 16px;
            box-sizing: border-box;
        }

        textarea.form-control {
            min-height: 120px;
            resize: vertical;
        }

        .form-actions {
            display: flex;
            justify-content: flex-end;
            gap: 15px;
            margin-top: 30px;
            padding: 0 2.5rem 2.5rem; /* เพิ่ม padding ด้านล่างให้เท่ากับ content */
        }

        .btn {
            padding: 12px 24px;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            border: none;
        }

        .btn-primary {
            background: linear-gradient(135deg, #e74c3c, #c0392b);
            color: white;
            box-shadow: 0 4px 15px rgba(50, 26, 60, 0.3);
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(50, 26, 60, 0.4);
            background: linear-gradient(135deg, #c0392b, #a93226);
        }

        .btn-secondary {
            background: #f0f0f0;
            color: #333;
        }

        .btn-secondary:hover {
            background: #e0e0e0;
        }

        select {
            padding: 10px;
            border: 1px solid #ccc;
            border-radius: 8px;
            font-size: 16px;
            width: 100%;
        }

        select option:first-child {
            color: #aaa;
        }

        .input-group {
            display: flex;
            justify-content: space-between;
            gap: 20px;
            margin-bottom: 20px;
            flex-wrap: nowrap;
        }

        .input-field {
            display: flex;
            flex-direction: column;
            width: 100%;
        }

        .input-field label {
            font-size: 16px;
            margin-bottom: 1px;
        }

        .input-field input {
            padding: 10px;
            border: 1px solid #ccc;
            border-radius: 8px;
            font-size: 16px;
            width: 100%;
            box-sizing: border-box;
        }

        .dropdown {
            position: relative;
            display: inline-block;
        }

        .dropdown-content {
            display: none;
            position: absolute;
            right: 0;
            background-color: #f9f9f9;
            min-width: 160px;
            box-shadow: 0px 8px 16px 0px rgba(0,0,0,0.2);
            z-index: 1;
            border-radius: 4px;
            overflow: hidden;
        }

        .dropdown-content a {
            color: black;
            padding: 12px 16px;
            text-decoration: none;
            display: block;
            font-size: 14px;
        }

        .dropdown-content a:hover {
            background-color: #f1f1f1;
        }

        .show {
            display: block;
        }

        .logout {
            color: #e74c3c !important;
        }

        .shop-id-display {
            background-color: #f0f0f0;
            border-radius: 8px;
            padding: 10px;
            font-size: 16px;
            margin-bottom: 10px;
        }

        .alert {
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%) translateX(150%);
            padding: 20px 30px;
            border-radius: 8px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.2);
            z-index: 1000;
            display: none;
            align-items: center;
            gap: 15px;
            max-width: 80%;
            text-align: center;
            transition: transform 0.3s ease-out;
            background-color: #F3FFF4;
            border: 1px solid #055D19;
            color: #055D19;
        }

        .alert.show {
            transform: translate(-50%, -50%) translateX(0);
            display: flex;
        }

        .alert.error {
            background-color: #FFF5F5;
            border: 1px solid #E53E3E;
            color: #E53E3E;
        }

        .alert-icon {
            font-size: 24px;
        }

        .alert-close {
            background: none;
            border: none;
            cursor: pointer;
            color: inherit;
            font-size: 18px;
            margin-left: 15px;
        }

        .section-title {
            font-size: 20px;
            font-family: 'Microsoft Sans Serif';
            text-decoration: underline;
            margin-bottom: 15px;
        }

        @media (max-width: 768px) {
            .input-group {
                flex-direction: column;
                gap: 15px;
            }
            
            .shop-form {
                padding: 20px;
            }
            
            .content {
                padding: 1rem;
                margin-left: 0;
                width: 100%;
            }

            .sidebar {
                width: 100%;
                position: relative;
                height: auto;
            }

            .main-container {
                flex-direction: column;
                margin-top: 84px;
            }
            
            .alert {
                max-width: 90%;
                padding: 15px 20px;
            }
        }

        /* === ส่วนเพิ่มเติม: Modal สำหรับแจ้งเตือน (เหมือนในหน้า จัดการประเภทสินค้า) === */
        .notification-modal { 
            display: none; 
            position: fixed; 
            z-index: 1000; 
            left: 0; 
            top: 0; 
            width: 100%; 
            height: 100%; 
            background-color: rgba(0,0,0,0.5); 
        }
        .notification-modal-content { 
            background-color: #fefefe; 
            margin: 5% auto; 
            padding: 20px; 
            border-radius: 8px; 
            width: 80%; 
            max-width: 600px; 
            box-shadow: 0 4px 8px rgba(0,0,0,0.2); 
        }
        .close { 
            color: #aaa; 
            float: right; 
            font-size: 28px; 
            font-weight: bold; 
            cursor: pointer; 
        }
        .close:hover { 
            color: black; 
        }
        .notification-section { 
            margin-bottom: 20px; 
        }
        .notification-section h3 { 
            color: #A44A3F; 
            margin-bottom: 10px; 
            border-bottom: 1px solid #eee; 
            padding-bottom: 5px; 
        }
        .notification-item { 
            padding: 8px 0; 
            border-bottom: 1px solid #f0f0f0; 
        }
        .notification-item:last-child { 
            border-bottom: none; 
        }
        .no-notifications { 
            color: #777; 
            font-style: italic; 
        }
        
    </style>
</head>
<body>

    <h1 style="font-size:25px; font-family: 'Microsoft Sans Serif'; display: flex; justify-content: space-between; align-items: center;">
        <div style="display: flex; align-items: center;">
            <img src="logo1.png" width="50" height="50" style="vertical-align: middle;">
            <span style="vertical-align: middle;">Chutikarn</span>
        </div>
        <div class="user-section" style="display: flex; align-items: center; gap: 15px;">
            <!-- กระดิ่งแจ้งเตือนพร้อมเลขทับ -->
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
                <div class="user-username" style="font-size:16px; font-weight: bold;"><?php echo htmlspecialchars($current_username); ?></div>
                <div class="user-role" style="font-size:16px; color: #666;"><?php echo htmlspecialchars($current_user_type); ?></div>
            </div>
        </div>
    </h1>

    <!-- === ส่วนเพิ่มเติม: Modal สำหรับแจ้งเตือน (เหมือนในหน้า จัดการประเภทสินค้า) === -->
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

    <?php if (!empty($success_message)): ?>
    <div id="successAlert" class="alert show">
        <span class="alert-icon">✓</span>
        <span><?php echo htmlspecialchars($success_message); ?></span>
        <button class="alert-close" onclick="hideAlert('successAlert')">×</button>
    </div>
    <?php endif; ?>

    <?php if (!empty($error_messages)): ?>
    <div id="errorAlert" class="alert error show">
        <span class="alert-icon">⚠</span>
        <span>
            <?php 
            if (count($error_messages) == 1) {
                echo htmlspecialchars($error_messages[0]);
            } else {
                echo "พบข้อผิดพลาด " . count($error_messages) . " รายการ: " . htmlspecialchars(implode(', ', $error_messages));
            }
            ?>
        </span>
        <button class="alert-close" onclick="hideAlert('errorAlert')">×</button>
    </div>
    <?php endif; ?>

    <div class="main-container">
        <div class="sidebar">
            <a href="Dashboard.php" class="menu-item">
                <div class="icon-medium">&#127968;</div>
                <span>หน้าแรก</span>
            </a>

            <a href="Manage_products.php" class="menu-item">
                <div class="icon-medium">&#128230;</div>
                <span>จัดการสินค้า</span>
            </a>

            <a href="manage_types.php" class="menu-item">
                <div class="icon-medium">&#128193;</div>
                <span>จัดการประเภทสินค้า</span>
            </a>

            <a href="receive_stock.php" class="menu-item">
                <div class="icon-medium">&#128229;</div>
                <span>รับสินค้าเข้า</span>
            </a>

            <a href="Order.php" class="menu-item">
                <div class="icon-medium">&#128722;</div>
                <span>สั่งซื้อสินค้า</span>
            </a>

            <a href="storage.php" class="menu-item">
                <div class="icon-medium">&#128190;</div>
                <span>จัดเก็บสินค้า</span>
            </a>

            <a href="Employee_Account.php" class="menu-item">
                <div class="icon-medium">&#128101;</div>
                <span>จัดการพนักงาน</span>
            </a>

            <a href="shop.php" class="menu-item active">
                <div class="icon-medium">&#127978;</div>
                <span>จัดการร้านค้า</span>
            </a>

            <a href="reports.php" class="menu-item">
                <div class="icon-medium">&#128200;</div>
                <span>รายงาน</span>
            </a>

            <a href="settings.php" class="menu-item">
                <div class="icon-medium">⚙️</div>
                <span>ตั้งค่าระบบ</span>
            </a>
        </div>

        <div class="content">
            <h2 class="page-header" style="font-size:28px;">+เพิ่มร้านค้า</h2>
            
            <div class="shop-form-container">
                <form id="shopForm" action="" method="post" class="shop-form">
                    <div class="form-section">
                        <div class="shop-id-display"><strong>รหัสร้านค้า: <?php echo $shop_ID; ?></strong></div>
                    </div>

                    <div class="form-section">
                        <div class="section-title"><b>ข้อมูลร้านค้า</b></div>
                        <div class="form-group">
                            <label for="shop_name">ชื่อร้านค้า</label>
                            <input type="text" id="shop_name" class="form-control" name="shop_name" value="<?php echo htmlspecialchars($shop_name ?? ''); ?>" required>
                        </div>
                        <div class="form-group">
                            <label for="shop_tel">เบอร์โทรศัพท์</label>
                            <input type="tel" id="shop_tel" class="form-control" name="shop_tel" value="<?php echo htmlspecialchars($shop_tel ?? ''); ?>" required maxlength="10">
                        </div>
                        <div class="form-group">
                            <label for="shop_address">ที่อยู่</label>
                            <textarea id="shop_address" class="form-control" rows="2" name="shop_address" required><?php echo htmlspecialchars($shop_address ?? ''); ?></textarea>
                        </div>
                    </div>

                    <div class="form-actions">
                        <button type="button" class="btn btn-secondary" onclick="window.location.href='shop.php'">ยกเลิก</button>
                        <button type="submit" class="btn btn-primary">บันทึกข้อมูล</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        // ฟังก์ชันเปิด/ปิด dropdown
        function toggleDropdown() {
            document.getElementById("userDropdown").classList.toggle("show");
        }
        
        // ปิดเมนูเมื่อคลิกที่อื่นในหน้า
        window.onclick = function(event) {
            if (!event.target.matches('.user-avatar')) {
                var dropdowns = document.getElementsByClassName("dropdown-content");
                for (var i = 0; i < dropdowns.length; i++) {
                    var openDropdown = dropdowns[i];
                    if (openDropdown.classList.contains('show')) {
                        openDropdown.classList.remove('show');
                    }
                }
            }
        }

        // ฟังก์ชันซ่อนการแจ้งเตือน
        function hideAlert(alertId) {
            const alert = document.getElementById(alertId);
            if (alert) {
                alert.classList.remove('show');
                setTimeout(() => {
                    alert.style.display = 'none';
                }, 300);
            }
        }

        // === ส่วนเพิ่มเติม: JavaScript สำหรับการแจ้งเตือน (เหมือนในหน้า จัดการประเภทสินค้า) ===
        function showNotificationModal() {
            document.getElementById("notificationModal").style.display = "block";
        }
        
        function closeNotificationModal() {
            document.getElementById("notificationModal").style.display = "none";
        }
        
        window.onclick = function(event) {
            var modal = document.getElementById("notificationModal");
            if (event.target == modal) {
                modal.style.display = "none";
            }
        }

        // ซ่อนการแจ้งเตือนอัตโนมัติหลัง 5 วินาที
        <?php if (!empty($success_message)): ?>
        setTimeout(() => {
            hideAlert('successAlert');
            // redirect ไปหน้า shop_management.php หลังจากแสดงข้อความสำเร็จ
            setTimeout(() => {
                window.location.href = 'shop.php';
            }, 500);
        }, 3000);
        <?php endif; ?>

        <?php if (!empty($error_messages)): ?>
        setTimeout(() => {
            hideAlert('errorAlert');
        }, 8000);
        <?php endif; ?>

        // การจัดการฟอร์ม
        document.getElementById('shopForm').addEventListener('submit', function(e) {
            const submitBtn = this.querySelector('button[type="submit"]');
            submitBtn.disabled = true;
            submitBtn.textContent = 'กำลังบันทึก...';
            
            // ตรวจสอบข้อมูลก่อนส่ง
            const requiredFields = ['shop_name', 'shop_tel', 'shop_address'];
            let hasError = false;
            
            requiredFields.forEach(fieldName => {
                const field = document.getElementById(fieldName);
                if (!field.value.trim()) {
                    field.style.borderColor = '#e74c3c';
                    hasError = true;
                } else {
                    field.style.borderColor = '#ccc';
                }
            });
            
            // ตรวจสอบเบอร์โทรศัพท์
            const phone = document.getElementById('shop_tel');
            if (phone.value.length !== 10) {
                phone.style.borderColor = '#e74c3c';
                hasError = true;
            }
            
            if (hasError) {
                submitBtn.disabled = false;
                submitBtn.textContent = 'บันทึกข้อมูล';
                e.preventDefault();
                return false;
            }
            
            return true;
        });

        // รีเซ็ต border color เมื่อพิมพ์
        document.querySelectorAll('.form-control').forEach(field => {
            field.addEventListener('input', function() {
                this.style.borderColor = '#ccc';
            });
        });
    </script>
</body>
</html>