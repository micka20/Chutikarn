<?php
session_start();
if(!isset($_SESSION['user_username'])) { header("Location: login.php"); exit(); }

// --- 1. การเชื่อมต่อฐานข้อมูล ---
$conn = new mysqli("localhost", "root", "12345678", "chutikarnceramic1");
$conn->set_charset("utf8");
if ($conn->connect_error) { die("Connection failed: " . $conn->connect_error); }

// --- 2. ส่วนจัดการข้อมูล (เพิ่ม, แก้ไข, ลบ) ---
$error_message = '';
$success_message = '';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // --- จัดการการเพิ่มข้อมูล ---
    if (isset($_POST['add_type'])) {
        $new_type_name = trim($_POST['typepd_name']);
        if (!empty($new_type_name)) {
            // สร้าง ID ใหม่อัตโนมัติ
            $last_id_sql = "SELECT typepd_ID FROM typepd ORDER BY typepd_ID DESC LIMIT 1";
            $last_id_result = $conn->query($last_id_sql);
            if ($last_id_result->num_rows > 0) {
                $last_row = $last_id_result->fetch_assoc();
                $last_id_num = (int)substr($last_row['typepd_ID'], 2);
                $new_id_num = $last_id_num + 1;
            } else {
                $new_id_num = 1;
            }
            $new_typepd_ID = 'T-' . sprintf('%03d', $new_id_num);

            // เพิ่มข้อมูล
            $insert_sql = "INSERT INTO typepd (typepd_ID, typepd_name) VALUES (?, ?)";
            $stmt = $conn->prepare($insert_sql);
            $stmt->bind_param("ss", $new_typepd_ID, $new_type_name);
            if ($stmt->execute()) {
                $success_message = "เพิ่มประเภทสินค้า '" . htmlspecialchars($new_type_name) . "' เรียบร้อยแล้ว!";
            } else {
                $error_message = "เกิดข้อผิดพลาดในการเพิ่มข้อมูล: " . $stmt->error;
            }
            $stmt->close();
        } else {
            $error_message = "กรุณากรอกชื่อประเภทสินค้า";
        }
    }

    // --- จัดการการแก้ไขข้อมูล ---
    if (isset($_POST['edit_type'])) {
        $typepd_id_to_edit = $_POST['typepd_id_edit'];
        $new_type_name_edit = trim($_POST['typepd_name_edit']);
        if (!empty($typepd_id_to_edit) && !empty($new_type_name_edit)) {
            $update_sql = "UPDATE typepd SET typepd_name = ? WHERE typepd_ID = ?";
            $stmt = $conn->prepare($update_sql);
            $stmt->bind_param("ss", $new_type_name_edit, $typepd_id_to_edit);
            if ($stmt->execute()) {
                $success_message = "แก้ไขข้อมูลเรียบร้อยแล้ว!";
            } else {
                $error_message = "เกิดข้อผิดพลาดในการแก้ไข: " . $stmt->error;
            }
            $stmt->close();
        } else {
            $error_message = "ข้อมูลสำหรับแก้ไขไม่ครบถ้วน";
        }
    }
}

// --- จัดการการลบข้อมูล ---
if (isset($_GET['action']) && $_GET['action'] == 'delete' && isset($_GET['id'])) {
    $typepd_id_to_delete = $_GET['id'];
    $delete_sql = "DELETE FROM typepd WHERE typepd_ID = ?";
    $stmt = $conn->prepare($delete_sql);
    $stmt->bind_param("s", $typepd_id_to_delete);
    if ($stmt->execute()) {
        $_SESSION['success_message_redirect'] = "ลบข้อมูลเรียบร้อยแล้ว!";
    } else {
        $_SESSION['error_message_redirect'] = "เกิดข้อผิดพลาดในการลบ.";
    }
    $stmt->close();
    header("Location: manage_types.php"); // Redirect เพื่อล้าง GET parameter
    exit();
}
if(isset($_SESSION['success_message_redirect'])){
    $success_message = $_SESSION['success_message_redirect'];
    unset($_SESSION['success_message_redirect']);
}
if(isset($_SESSION['error_message_redirect'])){
    $error_message = $_SESSION['error_message_redirect'];
    unset($_SESSION['error_message_redirect']);
}

// --- 3. ดึงข้อมูลสำหรับแสดงผล ---
// ดึงข้อมูลผู้ใช้สำหรับ Header
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

// === ส่วนเพิ่มเติม: ดึงข้อมูลสำหรับแจ้งเตือน (เหมือนในหน้า จัดการสินค้า) ===
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

// --- ดึงข้อมูลประเภทสินค้าทั้งหมด ---
// กำหนดค่าสำหรับการแบ่งหน้า
$items_per_page = 10;
$current_page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($current_page < 1) $current_page = 1;

// คำนวณค่า offset สำหรับการแบ่งหน้า
$offset = ($current_page - 1) * $items_per_page;

// ดึงจำนวนทั้งหมดของประเภทสินค้า
$total_types_sql = "SELECT COUNT(*) as total FROM typepd";
$total_types_result = $conn->query($total_types_sql);
$total_types_row = $total_types_result->fetch_assoc();
$total_types = $total_types_row['total'];

// คำนวณจำนวนหน้าทั้งหมด
$total_pages = ceil($total_types / $items_per_page);

// ตรวจสอบว่าหน้าปัจจุบันมีค่ามากกว่าจำนวนหน้าทั้งหมดหรือไม่
if ($current_page > $total_pages && $total_pages > 0) {
    $current_page = $total_pages;
}

// ดึงข้อมูลประเภทสินค้าตามหน้า
$types = [];
$types_sql = "SELECT typepd_ID, typepd_name FROM typepd ORDER BY typepd_ID ASC LIMIT ? OFFSET ?";
$stmt = $conn->prepare($types_sql);
$stmt->bind_param("ii", $items_per_page, $offset);
$stmt->execute();
$types_result = $stmt->get_result();

if ($types_result->num_rows > 0) {
    while($row = $types_result->fetch_assoc()) {
        $types[] = $row;
    }
}
$stmt->close();
$conn->close();
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>จัดการประเภทสินค้า</title>
    <link href="https://fonts.googleapis.com/css2?family=Kanit:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
        /* --- CSS ทั่วไปและ Layout --- */
        * { margin: 0; padding: 0; box-sizing: border-box;}
        body { 
            background-color: #f8f9fa; 
            padding-top: 84px; /* เพิ่ม padding-top เท่ากับความสูงของ header */
            padding-left: 250px; /* เพิ่ม padding-left เท่ากับความกว้างของ sidebar */
        }
        .main-container { 
            display: flex; 
            min-height: calc(100vh - 84px); 
        }
        .content { 
            flex: 1; 
            padding: 40px; 
            width: calc(100% - 250px); /* ปรับความกว้างของ content */
            margin-left: 0; /* ลบ margin-left */
        }
        .page-header { color: #A44A3F; font-size: 30px; font-weight: bold; margin-bottom: 20px; text-align: left; }
        
        /* --- Header --- */
        .top-header { 
            font-family: 'Microsoft Sans Serif'; 
            display: flex; 
            justify-content: space-between; 
            align-items: center; 
            border: 2.6px solid #ffffff; 
            padding: 10px 20px; 
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1); 
            background-color: #fff; 
            position: fixed; /* ทำให้ header คงที่ */
            top: 0;
            left: 0;
            width: 100%;
            height: 84px;
            z-index: 1000; /* ให้ header อยู่ด้านบนสุด */
        }
        .logo-section { display: flex; align-items: center; gap: 0px; }
        .user-section { display: flex; align-items: center; gap: 15px; }
        .user-info { text-align: right; }
        .user-username { font-size: 16px; font-weight: bold; }
        .user-role { font-size: 16px; color: #666; }
        .user-avatar { font-size: 25px; width: 40px; height: 40px; border-radius: 50%; background: #f0f0f0; display: flex; align-items: center; justify-content: center; cursor: pointer; }
        .dropdown { position: relative; display: inline-block; }
        .dropdown-content { display: none; position: absolute; right: 0; margin-top: 8px; background-color: #f9f9f9; min-width: 160px; box-shadow: 0px 8px 16px 0px rgba(0,0,0,0.2); z-index: 10; border-radius: 4px; overflow: hidden; }
        .dropdown-content a { color: black; padding: 12px 16px; text-decoration: none; display: block; font-size: 14px; }
        .dropdown-content a:hover { background-color: #f1f1f1; }
        .dropdown-content .logout { color: #e74c3c !important; }
        .show { display: block; }
        
        /* === ส่วนเพิ่มเติม: สไตล์สำหรับกระดิ่งแจ้งเตือน === */
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

        /* --- Sidebar --- */
        .sidebar { 
            width: 250px; 
            background: #ffffff; 
            padding: 0; 
            box-shadow: 2px 0 15px rgba(0,0,0,0.1); 
            position: fixed; /* ทำให้ sidebar คงที่ */
            top: 84px; /* วางด้านล่าง header */
            left: 0;
            height: calc(100vh - 84px); /* ความสูงเท่ากับหน้าจอลบความสูง header */
            overflow-y: auto; /* ให้เลื่อนได้เมื่อมีเนื้อหาเกิน */
            z-index: 999; /* ให้อยู่ด้านล่าง header */
        }
        .menu-item { display: flex; align-items: center; gap: 1rem; padding: 1rem 2rem; color: #000000; text-decoration: none; transition: all 0.3s ease; }
        .menu-item span { flex: 1; text-align: left; font-size: 18px; }
        .menu-item:hover { background: rgba(244, 212, 199, 0.5); }
        .menu-item.active { background: linear-gradient(90deg, #f4d4c7 0%, #edd5c7 100%); }
        .menu-item.active span, .menu-item.active div { color: #A44A3F; }
        .icon-medium { font-size: 25px; }

        /* --- Main Content Elements --- */
        .card { background-color: #fff; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); padding: 25px; margin-bottom: 25px; }
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; margin-bottom: 5px; font-weight: 500; }
        .form-control { width: 85%; padding: 10px; border: 1px solid #ddd; border-radius: 5px; font-size: 16px; }
        .btn { padding: 10px 20px; border: none; border-radius: 5px; font-size: 16px; cursor: pointer; text-decoration: none; display: inline-block; }
        .btn-success { background-color: #c0392b; color: white; }
        .btn-warning { background-color: #ffc107; color: #212529; }
        .btn-danger { background-color: #dc3545; color: white; }
        .btn-sm { padding: 5px 10px; font-size: 14px; }
        .action-buttons { display: flex; gap: 10px; justify-content: center; }

        .product-controls {
            display: flex;
            gap: 15px;
            margin-bottom: 20px;
            align-items: center;
        }

        .search-container {
            flex: 1;
            position: relative;
        }

        .search-input {
            width: 100%;
            padding: 12px 120px 12px 15px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 16px;
            background-color: white;
        }

        .search-btn {
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
            background: #c0392b;
            color: white;
            border: none;
            padding: 8px 15px;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
            transition: all 0.3s ease;
            box-shadow: 0 2px 4px rgba(0,0,0,0.2);
        }

        .search-btn:hover {
            background: #a93226;
        }

        /* --- Table --- */
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 15px; text-align: left; border-bottom: 1px solid #dee2e6; }
        th { background-color: #f8f9fa; }
        td.text-center, th.text-center { text-align: center; }

        /* --- Modal --- */
        .modal { display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; overflow: auto; background-color: rgba(0,0,0,0.5); justify-content: center; align-items: center; }
        .modal.show { display: flex; }
        .modal-content { background-color: #fff; padding: 30px; border-radius: 10px; width: 450px; max-width: 90%; box-shadow: 0 5px 15px rgba(0,0,0,0.3); }
        .modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        .modal-title { font-size: 22px; font-weight: 600; }
        .close-btn { font-size: 28px; font-weight: bold; cursor: pointer; color: #aaa; }
        .close-btn:hover { color: #000; }
        .modal-footer { margin-top: 20px; text-align: right; }

        /* --- Alerts --- */
        .alert { padding: 15px; margin-bottom: 20px; border: 1px solid transparent; border-radius: 4px; }
        .alert-success { color: #155724; background-color: #d4edda; border-color: #c3e6cb; }
        .alert-danger { color: #721c24; background-color: #f8d7da; border-color: #f5c6cb; }

        /* === ส่วนเพิ่มเติม: Modal สำหรับแจ้งเตือน (เหมือนในหน้า จัดการสินค้า) === */
        .notification-modal { display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5); }
        .notification-modal-content { background-color: #fefefe; margin: 5% auto; padding: 20px; border-radius: 8px; width: 80%; max-width: 600px; box-shadow: 0 4px 8px rgba(0,0,0,0.2); }
        .close { color: #aaa; float: right; font-size: 28px; font-weight: bold; cursor: pointer; }
        .close:hover { color: black; }
        .notification-section { margin-bottom: 20px; }
        .notification-section h3 { color: #A44A3F; margin-bottom: 10px; border-bottom: 1px solid #eee; padding-bottom: 5px; }
        .notification-item { padding: 8px 0; border-bottom: 1px solid #f0f0f0; }
        .notification-item:last-child { border-bottom: none; }
        .no-notifications { color: #777; font-style: italic; }

        /* --- Pagination --- */
        .pagination { display: flex; justify-content: center; margin-top: 20px; }
        .pagination a, .pagination span { color: #333; padding: 8px 16px; text-decoration: none; border: 1px solid #ddd; margin: 0 4px; border-radius: 4px; }
        .pagination a:hover { background-color: #f0f0f0; }
        .pagination .active { background-color: #c0392b; color: white; border-color: #c0392b; }
        .pagination .disabled { color: #ccc; pointer-events: none; }

        /* --- Delete Confirmation Modal --- */
        .delete-modal { display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5); }
        .delete-modal-content { background-color: #fff; margin: 15% auto; padding: 20px; border-radius: 8px; width: 400px; max-width: 90%; box-shadow: 0 4px 8px rgba(0,0,0,0.2); }
        .delete-modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; }
        .delete-modal-title { font-size: 20px; font-weight: bold; color: #A44A3F; }
        .delete-modal-body { margin-bottom: 20px; }
        .delete-modal-footer { 
            display: flex; 
            justify-content: center;  /* เปลี่ยนจาก flex-end เป็น center */
            gap: 15px; 
        }
        .btn-cancel { 
            background-color: #6c757d; 
            color: white; 
            padding: 10px 20px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            font-size: 16px;
            min-width: 100px;
        }
        .btn-confirm-delete { 
            background-color: #dc3545; 
            color: white; 
            padding: 10px 20px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            font-size: 16px;
            min-width: 100px;
        }
        .btn-cancel:hover {
            background-color: #5a6268;
        }
        .btn-confirm-delete:hover {
            background-color: #c82333;
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
            <!-- === ส่วนเพิ่มเติม: ระบบแจ้งเตือนกระดิ่ง === -->
            <div class="notification-container">
                <div class="bell-icon" onclick="showNotificationModal()">&#128276;</div>
                <?php if ($low_stock_count > 0 || $recent_received_count > 0): ?>
                    <div class="notification-badge"><?php echo $low_stock_count + $recent_received_count; ?></div>
                <?php endif; ?>
            </div>
            
            <div class="dropdown">
                <div class="user-avatar" onclick="toggleDropdown()">&#128100;</div>
                <div id="userDropdown" class="dropdown-content">
                    <a href="settings.php">ข้อมูลส่วนตัว</a>
                    <a href="login.php" class="logout">Logout</a>
                </div>
            </div>
            <div class="user-info">
                <div class="user-username"><?php echo htmlspecialchars($user_username); ?></div>
                <div class="user-role"><?php echo htmlspecialchars($user_type); ?></div>
            </div>
        </div>
    </h1>

    <div class="main-container">
        <div class="sidebar">
            <a href="Dashboard.php" class="menu-item"><div class="icon-medium">&#127968;</div><span>หน้าแรก</span></a>
            <a href="Manage_products.php" class="menu-item"><div class="icon-medium">&#128230;</div><span>จัดการสินค้า</span></a>
            <a href="manage_types.php" class="menu-item active"><div class="icon-medium">&#128193;</div><span>จัดการประเภทสินค้า</span></a>
            <a href="receive_stock.php" class="menu-item"><div class="icon-medium">&#128229;</div><span>รับสินค้าเข้า</span></a>
            <a href="Order.php" class="menu-item"><div class="icon-medium">&#128722;</div><span>สั่งซื้อสินค้า</span></a>
            <a href="storage.php" class="menu-item"><div class="icon-medium">&#128190;</div><span>จัดเก็บสินค้า</span></a>
            <a href="Employee_Account.php" class="menu-item"><div class="icon-medium">&#128101;</div><span>จัดการพนักงาน</span></a>
            <a href="shop.php" class="menu-item"><div class="icon-medium">&#127978;</div><span>จัดการร้านค้า</span></a>
            <a href="reports.php" class="menu-item"><div class="icon-medium">&#128200;</div><span>รายงาน</span></a>
            <a href="settings.php" class="menu-item"><div class="icon-medium">⚙️</div><span>ตั้งค่าระบบ</span></a>
        </div>

        <div class="content">
            <h2 class="page-header">จัดการประเภทสินค้า</h2>

            <?php if ($success_message): ?>
                <div class="alert alert-success"><?php echo $success_message; ?></div>
            <?php endif; ?>
            <?php if ($error_message): ?>
                <div class="alert alert-danger"><?php echo $error_message; ?></div>
            <?php endif; ?>

            <div class="card">
                <form method="POST" action="manage_types.php" class="form-inline">
                    <label for="typepd_name" class="mr-2">ประเภทสินค้าใหม่</label>
                    <div>
                        <input type="text" id="typepd_name" name="typepd_name" class="form-control mr-2" placeholder="เช่น เครื่องใช้ในครัวเรือน" required>
                        <button type="submit" name="add_type" class="btn btn-success">เพิ่มประเภทสินค้า</button>
                    </div>
                </form>
            </div>

            <div class="card">
                <div class="search-container">
                    <input type="text" id="searchInput" class="search-input" placeholder="ค้นหาประเภทสินค้า..." onkeyup="searchTable()">
                    <button class="search-btn" onclick="searchTable()">ค้นหา</button>
                </div>
                <br>
                <table id="typeTable">
                    <thead>
                        <tr>
                            <th style="width: 20%;">รหัสประเภท</th>
                            <th>ชื่อประเภท</th>
                            <th style="width: 20%;" class="text-center">การกระทำ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($types)): ?>
                            <?php foreach ($types as $type): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($type['typepd_ID']); ?></td>
                                    <td><?php echo htmlspecialchars($type['typepd_name']); ?></td>
                                    <td class="text-center action-buttons">
                                        <button class="btn btn-warning btn-sm" onclick="openEditModal('<?php echo htmlspecialchars($type['typepd_ID']); ?>', '<?php echo htmlspecialchars(addslashes($type['typepd_name'])); ?>')">แก้ไข</button>
                                        <button class="btn btn-danger btn-sm" onclick="openDeleteModal('<?php echo htmlspecialchars($type['typepd_ID']); ?>', '<?php echo htmlspecialchars(addslashes($type['typepd_name'])); ?>')">ลบ</button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="3" class="text-center">ไม่พบข้อมูลประเภทสินค้า</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
                
                <!-- Pagination -->
                <?php if ($total_pages > 1): ?>
                <div class="pagination">
                    <?php if ($current_page > 1): ?>
                        <a href="manage_types.php?page=1">&laquo; หน้าแรก</a>
                        <a href="manage_types.php?page=<?php echo $current_page - 1; ?>">&lsaquo; ก่อนหน้า</a>
                    <?php else: ?>
                        <span class="disabled">&laquo; หน้าแรก</span>
                        <span class="disabled">&lsaquo; ก่อนหน้า</span>
                    <?php endif; ?>
                    
                    <?php
                    // แสดงปุ่มหมายเลขหน้า
                    $start_page = max(1, $current_page - 2);
                    $end_page = min($total_pages, $current_page + 2);
                    
                    for ($i = $start_page; $i <= $end_page; $i++):
                        if ($i == $current_page): ?>
                            <span class="active"><?php echo $i; ?></span>
                        <?php else: ?>
                            <a href="manage_types.php?page=<?php echo $i; ?>"><?php echo $i; ?></a>
                        <?php endif; ?>
                    <?php endfor; ?>
                    
                    <?php if ($current_page < $total_pages): ?>
                        <a href="manage_types.php?page=<?php echo $current_page + 1; ?>">ถัดไป &rsaquo;</a>
                        <a href="manage_types.php?page=<?php echo $total_pages; ?>">หน้าสุดท้าย &raquo;</a>
                    <?php else: ?>
                        <span class="disabled">ถัดไป &rsaquo;</span>
                        <span class="disabled">หน้าสุดท้าย &raquo;</span>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- === ส่วนเพิ่มเติม: Modal สำหรับแจ้งเตือน (เหมือนในหน้า จัดการสินค้า) === -->
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

    <!-- Modal สำหรับแก้ไขข้อมูล -->
    <div id="editModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">แก้ไขประเภทสินค้า</h3>
                <span class="close-btn" onclick="closeEditModal()">&times;</span>
            </div>
            <form method="POST" action="manage_types.php">
                <input type="hidden" id="typepd_id_edit" name="typepd_id_edit">
                <div class="form-group">
                    <label for="typepd_name_edit">ชื่อประเภทสินค้า</label>
                    <input type="text" id="typepd_name_edit" name="typepd_name_edit" class="form-control" required>
                </div>
                <div class="modal-footer">
                    <button type="submit" name="edit_type" class="btn btn-success">บันทึกการเปลี่ยนแปลง</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal สำหรับยืนยันการลบ -->
    <div id="deleteModal" class="delete-modal">
        <div class="delete-modal-content">
            <div class="delete-modal-header">
                <h3 class="delete-modal-title">ยืนยันการลบ</h3>
                <span class="close-btn" onclick="closeDeleteModal()">&times;</span>
            </div>
            <div class="delete-modal-body">
                <p>คุณแน่ใจหรือไม่ว่าต้องการลบประเภทสินค้า "<span id="deleteTypeName"></span>"?</p>
                <p class="text-danger">การดำเนินการนี้ไม่สามารถย้อนกลับได้!</p>
            </div>
            <div class="delete-modal-footer">
                <a href="#" id="confirmDeleteBtn" class="btn btn-confirm-delete">ยืนยันการลบ</a>
                <button type="button" class="btn btn-cancel" onclick="closeDeleteModal()">ยกเลิก</button>
            </div>
        </div>
    </div>

<script>
    // --- JavaScript สำหรับ Dropdown ---
    function toggleDropdown() {
        document.getElementById("userDropdown").classList.toggle("show");
    }
    window.onclick = function(event) {
        if (!event.target.closest('.user-avatar')) {
            var dropdowns = document.getElementsByClassName("dropdown-content");
            for (var i = 0; i < dropdowns.length; i++) {
                var openDropdown = dropdowns[i];
                if (openDropdown.classList.contains('show')) {
                    openDropdown.classList.remove('show');
                }
            }
        }
    }

    // --- JavaScript สำหรับ Modal ---
    const editModal = document.getElementById('editModal');
    const deleteModal = document.getElementById('deleteModal');
    
    function openEditModal(id, name) {
        document.getElementById('typepd_id_edit').value = id;
        document.getElementById('typepd_name_edit').value = name;
        editModal.classList.add('show');
    }
    
    function closeEditModal() {
        editModal.classList.remove('show');
    }
    
    function openDeleteModal(id, name) {
        document.getElementById('deleteTypeName').textContent = name;
        document.getElementById('confirmDeleteBtn').href = 'manage_types.php?action=delete&id=' + id;
        deleteModal.style.display = 'block';
    }
    
    function closeDeleteModal() {
        deleteModal.style.display = 'none';
    }
    
    window.addEventListener('click', function(event) {
        if (event.target == editModal) {
            closeEditModal();
        }
        if (event.target == deleteModal) {
            closeDeleteModal();
        }
    });

    // === ส่วนเพิ่มเติม: JavaScript สำหรับการแจ้งเตือน (เหมือนในหน้า จัดการสินค้า) ===
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

    // --- JavaScript สำหรับค้นหาในตาราง ---
    function searchTable() {
        const input = document.getElementById("searchInput");
        const filter = input.value.toUpperCase();
        const table = document.getElementById("typeTable");
        const tr = table.getElementsByTagName("tr");

        for (let i = 1; i < tr.length; i++) { // เริ่มจาก 1 เพื่อข้าม aheader
            let td1 = tr[i].getElementsByTagName("td")[0];
            let td2 = tr[i].getElementsByTagName("td")[1];
            if (td1 || td2) {
                let txtValue1 = td1.textContent || td1.innerText;
                let txtValue2 = td2.textContent || td2.innerText;
                if (txtValue1.toUpperCase().indexOf(filter) > -1 || txtValue2.toUpperCase().indexOf(filter) > -1) {
                    tr[i].style.display = "";
                } else {
                    tr[i].style.display = "none";
                }
            }
        }
    }
</script>

</body>
</html>