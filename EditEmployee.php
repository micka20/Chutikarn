<?php
session_start();

// ตรวจสอบว่าผู้ใช้ล็อกอินอยู่หรือไม่
if(!isset($_SESSION['user_username'])) {
    header("Location: login.php");
    exit();
}

// เชื่อมต่อฐานข้อมูล
$servername = "localhost";
$username = "root";
$password = "12345678";
$dbname = "chutikarnceramic1";

$conn = new mysqli($servername, $username, $password, $dbname);
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

// รับค่า user_ID จาก URL
$user_ID = $_GET['user_ID'] ?? '';

// ถ้าไม่มี user_ID ให้ redirect กลับ
if (empty($user_ID)) {
    header("Location: Employee_Account.php");
    exit;
}

// 🔍 ดึงข้อมูลพนักงานที่ต้องการแก้ไข
$sql = "SELECT * FROM user WHERE user_ID = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $user_ID);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows == 0) {
    die("ไม่พบข้อมูลพนักงาน");
}

$employee = $result->fetch_assoc();

// ตั้งค่าตัวแปรสำหรับแสดงข้อมูลผู้ใช้ปัจจุบัน
$user_username_session = $_SESSION['user_username'];
$user_sql = "SELECT user_username, user_type FROM user WHERE user_username = ?";
$stmt_user = $conn->prepare($user_sql);
$stmt_user->bind_param("s", $user_username_session);
$stmt_user->execute();
$user_result = $stmt_user->get_result();
$user_data = $user_result->fetch_assoc();
$current_username = $user_data['user_username'] ?? 'ผู้ใช้งาน';
$current_user_type = $user_data['user_type'] ?? 'ผู้ดูแลระบบ';
$stmt_user->close();

// ตัวแปรสำหรับแสดงข้อความ
$success_message = "";
$error_messages = [];

// 📝 อัปเดตข้อมูลเมื่อกดบันทึก
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // รวมชื่อ-นามสกุล
    $user_name = $_POST['emp_firstname'] . ' ' . $_POST['emp_lastname'];
    $user_tel = $_POST['emp_phone'];
    $uesr_address = $_POST['emp_address'];
    $user_position = $_POST['emp_position'];
    $user_type = $_POST['emp_type'];
    $user_salary = $_POST['emp_salary'];
    $user_username = $_POST['emp_username'];
    $user_password = $_POST['emp_password'];

    $update_sql = "UPDATE user 
                   SET user_name=?, user_tel=?, user_position=?, user_type=?, 
                       user_salary=?, uesr_address=?, user_username=?, user_password=? 
                   WHERE user_ID=?";
    $update_stmt = $conn->prepare($update_sql);
    $update_stmt->bind_param("sssssssss", 
        $user_name, $user_tel, $user_position, $user_type, 
        $user_salary, $uesr_address, $user_username, $user_password, $user_ID
    );

    if ($update_stmt->execute()) {
        $success_message = "แก้ไขข้อมูลพนักงานเรียบร้อย!";
        // อัปเดตข้อมูลในตัวแปร employee เพื่อแสดงข้อมูลล่าสุด
        $employee['user_name'] = $user_name;
        $employee['user_tel'] = $user_tel;
        $employee['user_position'] = $user_position;
        $employee['user_type'] = $user_type;
        $employee['user_salary'] = $user_salary;
        $employee['uesr_address'] = $uesr_address;
        $employee['user_username'] = $user_username;
        $employee['user_password'] = $user_password;
    } else {
        $error_messages[] = "เกิดข้อผิดพลาด: " . $conn->error;
    }
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>หน้าแก้ไขพนักงาน</title>
    <style>
        /* CSS เดิม */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        h1 {
            border: 2.6px solid #ffffff;
            padding: 20px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            background-color: #fff;
            position: sticky;
            top: 0;
            z-index: 100;
        }

        .main-container {
            display: flex;
            min-height: calc(100vh - 80px);
        }

        .sidebar {
            width: 250px;
            background: linear-gradient(180deg, #ffffff 0%, #ffffff 100%);
            padding: 0;
            box-shadow: 2px 0 15px rgba(0,0,0,0.1);
            position: sticky;
            top: 94px;
            height: calc(100vh - 94px);
            overflow-y: auto;
        }

        .menu-item {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 1rem 2rem;
            color: #ecf0f1;
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
            background: rgba(244, 212, 199, 0.5);
            border-left-color: transparent;
            transform: translateX(1.5px);
        }

        .menu-item:hover span {
            color: #A44A3F;
        }

        .menu-item.active {
            background: linear-gradient(90deg, #f4d4c7 0%, #edd5c7 100%);
            border-left-color: transparent;
            color: #2c3e50;
            justify-content: flex-start;
        }

        .menu-item.active span {
            color: #A44A3F;
            /* ไม่ใช้ font-weight: bold */
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

        .product-form-container {
            width: 100%;
            max-width: 1000px;
            margin-top: 80px;
        }

        .product-form {
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
            background: linear-gradient(135deg, #c0392b);
            color: white;
            box-shadow: 0 4px 15px rgba(50, 26, 60, 0.3);
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(50, 26, 60, 0.4);
            background: linear-gradient(135deg, #a93225);
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

        .product-id-display {
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
            
            .product-form {
                padding: 20px;
            }
            
            .content {
                padding: 1rem;
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
            <a href="Employee_Account.php" class="menu-item active">
                <div class="icon-medium">&#128101;</div>
                <span>จัดการพนักงาน</span>
            </a>
            <a href="shop.php" class="menu-item">
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
            <h2 class="page-header" style="font-size:28px;">แก้ไขข้อมูลพนักงาน</h2>
            
            <div class="product-form-container">
            <form id="employeeForm" action="" method="post" class="product-form">
    <div class="form-section">
        <div class="product-id-display"><strong>รหัสผู้ใช้ <?php echo $user_ID; ?></strong></div>
    </div>

    <div class="form-section">
        <div class="section-title"><b>ข้อมูลส่วนตัว</b></div>
        <div class="form-group">
            <label for="emp_firstname">ชื่อ</label>
            <?php 
                $name_parts = explode(' ', $employee['user_name']);
                $first_name = $name_parts[0];
                $last_name = isset($name_parts[1]) ? $name_parts[1] : '';
            ?>
            <input type="text" id="emp_firstname" name="emp_firstname" class="form-control"
                   value="<?php echo htmlspecialchars($first_name); ?>" required>
        </div>
        <div class="form-group">
            <label for="emp_lastname">นามสกุล</label>
            <input type="text" id="emp_lastname" name="emp_lastname" class="form-control"
                   value="<?php echo htmlspecialchars($last_name); ?>" required>
        </div>
        <div class="form-group">
            <label for="emp_phone">เบอร์โทรศัพท์</label>
            <input type="text" id="emp_phone" name="emp_phone" class="form-control"
                   value="<?php echo htmlspecialchars($employee['user_tel']); ?>" required maxlength="10">
        </div>
    </div>

    <div class="form-section">
        <div class="section-title"><b>ข้อมูลที่อยู่</b></div>
        <div class="form-group">
            <label for="emp_address">ที่อยู่</label>
            <textarea id="emp_address" name="emp_address" class="form-control"><?php echo htmlspecialchars($employee['uesr_address']); ?></textarea>
        </div>
    </div>

    <div class="form-section">
        <div class="section-title"><b>ข้อมูลการทำงาน</b></div>
        <div class="form-group">
            <label for="emp_position">ตำแหน่ง</label>
            <select id="emp_position" name="emp_position" class="form-control" required>
                <option value="ผู้ดูแลระบบ" <?php echo ($employee['user_position'] == 'ผู้ดูแลระบบ') ? 'selected' : ''; ?>>ผู้ดูแลระบบ</option>
                <option value="พนักงานทั่วไป" <?php echo ($employee['user_position'] == 'พนักงานทั่วไป') ? 'selected' : ''; ?>>พนักงานทั่วไป</option>
            </select>
        </div>
        <div class="form-group">
            <label for="emp_type">ประเภทผู้ใช้งาน</label>
            <select id="emp_type" name="emp_type" class="form-control" required>
                <option value="ผู้ดูแลระบบ" <?php echo ($employee['user_type'] == 'ผู้ดูแลระบบ') ? 'selected' : ''; ?>>ผู้ดูแลระบบ</option>
                <option value="พนักงาน" <?php echo ($employee['user_type'] == 'พนักงาน') ? 'selected' : ''; ?>>พนักงาน</option>
            </select>
        </div>
        <div class="form-group">
            <label for="emp_salary">เงินเดือน</label>
            <input type="number" id="emp_salary" name="emp_salary" class="form-control"
                   value="<?php echo htmlspecialchars($employee['user_salary']); ?>" required>
        </div>
    </div>

    <div class="form-section">
        <div class="section-title"><b>ข้อมูลบัญชีผู้ใช้ระบบ</b></div>
        <div class="form-group">
            <label for="emp_username">ชื่อผู้ใช้</label>
            <input type="text" id="emp_username" name="emp_username" class="form-control"
                   value="<?php echo htmlspecialchars($employee['user_username']); ?>" required>
        </div>
        <div class="form-group">
            <label for="emp_password">รหัสผ่าน</label>
            <input type="password" id="emp_password" name="emp_password" class="form-control"
                   value="<?php echo htmlspecialchars($employee['user_password']); ?>" required>
        </div>
    </div>

    <div class="form-actions">
        <button type="button" class="btn btn-secondary" onclick="window.location.href='Employee_Account.php'">ยกเลิก</button>
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
            // redirect ไปหน้า Employee_Account.php หลังจากแสดงข้อความสำเร็จ
            setTimeout(() => {
                window.location.href = 'Employee_Account.php';
            }, 500);
        }, 3000);
        <?php endif; ?>

        <?php if (!empty($error_messages)): ?>
        setTimeout(() => {
            hideAlert('errorAlert');
        }, 8000);
        <?php endif; ?>

        // การจัดการฟอร์ม
        document.getElementById('employeeForm').addEventListener('submit', function(e) {
            const submitBtn = this.querySelector('button[type="submit"]');
            submitBtn.disabled = true;
            submitBtn.textContent = 'กำลังบันทึก...';
            
            // ตรวจสอบข้อมูลก่อนส่ง
            const requiredFields = ['emp_firstname', 'emp_lastname', 'emp_phone', 'emp_position', 'emp_type', 'emp_address', 'emp_username', 'emp_password'];
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
            const phone = document.getElementById('emp_phone');
            if (phone.value.length !== 10 || isNaN(phone.value)) {
                phone.style.borderColor = '#e74c3c';
                hasError = true;
                alert('กรุณากรอกเบอร์โทรศัพท์เป็นตัวเลข 10 หลัก');
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