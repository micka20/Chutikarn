<?php
session_start();

// ตรวจสอบว่าผู้ใช้ล็อกอินอยู่หรือไม่
if(!isset($_SESSION['user_username'])) {
    // ถ้ายังไม่ได้ล็อกอิน ให้ redirect ไปหน้า login
    header("Location: login.php");
    exit();
}

// ตรวจสอบข้อความแจ้งเตือนจาก URL parameter
if (isset($_GET['success'])) {
    $_SESSION['success_message'] = $_GET['success'];
    header("Location: storage.php");
    exit();
}

if (isset($_GET['error'])) {
    $_SESSION['error_message'] = $_GET['error'];
    header("Location: storage.php");
    exit();
}

// เชื่อมต่อฐานข้อมูล
$servername = "localhost";
$username = "root";
$password = "12345678";
$dbname = "chutikarnceramic1";

// สร้างการเชื่อมต่อ
$conn = new mysqli($servername, $username, $password, $dbname);
$conn->set_charset("utf8");

// ตรวจสอบการเชื่อมต่อ
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// ดึงข้อมูลผู้ใช้ตาม username ที่ล็อกอิน
$user_username_session = $_SESSION['user_username'];
$sql = "SELECT user_username, user_type FROM user WHERE user_username = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $user_username_session);
$stmt->execute();
$result = $stmt->get_result();

$user_username = "ไม่พบข้อมูล";
$user_type = "ไม่พบข้อมูล";

if ($result->num_rows > 0) {
    $row = $result->fetch_assoc();
    $user_username = $row["user_username"];
    $user_type = $row["user_type"];
}
$stmt->close();

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

// === ส่วนการทำงานของ storage.php ===
// ดึงข้อมูลโซนจัดเก็บ
$storage_zones = [];
$zone_query = $conn->query("SELECT * FROM storage_zones ORDER BY zone_name");
if ($zone_query->num_rows > 0) {
    while($row = $zone_query->fetch_assoc()) {
        $storage_zones[] = $row;
    }
}

// ดึงข้อมูลสินค้าพร้อมตำแหน่งจัดเก็บ
$products_with_location = [];
$products_query = $conn->query("
    SELECT p.pd_ID, p.pd_name, p.pd_number, p.pd_unit, p.zone_id, z.zone_name
    FROM product p
    LEFT JOIN storage_zones z ON p.zone_id = z.zone_id
    ORDER BY z.zone_name, p.pd_name
");
if ($products_query->num_rows > 0) {
    while($row = $products_query->fetch_assoc()) {
        $products_with_location[] = $row;
    }
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>จัดเก็บสินค้า</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        /* --- จุดที่แก้ไข 1: ทำให้ Header ไม่เลื่อนตาม --- */
        h1 {
            border: 2.6px solid #ffffff;
            padding: 15px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            background-color: #fff;
            position: sticky; /* ทำให้ Header ติดด้านบน */
            top: 0;
            z-index: 1000; /* ทำให้ Header อยู่เหนือส่วนอื่น */
        }

        /* สไตล์เมนู dropdown */
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

        /* แถบเมนู */
        .main-container {
            display: flex;
            min-height: 100vh;
        }
        
        /* --- จุดที่แก้ไข 2: ทำให้ Sidebar ไม่เลื่อนตาม --- */
        .sidebar {
            width: 250px;
            background: #ffffff;
            padding: 0;
            box-shadow: 2px 0 15px rgba(0,0,0,0.1);
            
            /* ทำให้ Sidebar ติดอยู่กับที่เมื่อเลื่อน */
            position: sticky;
            top: 80px;  /* ความสูงของ Header โดยประมาณ */
            height: calc(100vh - 80px); /* ความสูงเต็มพื้นที่ที่เหลือ */
            overflow-y: auto; /* เพิ่ม scrollbar ถ้าเมนูยาวเกิน */
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
        }

        .menu-item:hover {
            background: rgba(244, 212, 199, 0.5);
        }

        .menu-item.active {
            background: linear-gradient(90deg, #f4d4c7 0%, #edd5c7 100%);
        }

        .icon-medium { font-size: 25px; }

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
            font-weight: bold;
            z-index: 10;
        }

        .bell-icon {
            font-size: 20px;
            cursor: pointer;
        }

        /* จัดตำแหน่งของข้อมูลที่อยู่ตรงกลาง */
        .content {
            flex: 1;
            padding: 40px;
            background-color: #f8f9fa;
        }

        /* ส่วนหัวเรื่องที่อยู่ด้านซ้าย */
        .page-header {
            color: #A44A3F;
            font-size: 30px;
            font-weight: bold;
            margin-bottom: 30px;
            text-align: left;
        }

        /* สไตล์สำหรับส่วนจัดการโซนจัดเก็บ */
        .storage-section {
            background: white;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }

        .section-title {
            color: #A44A3F;
            font-size: 20px;
            font-weight: bold;
            border-bottom: 2px solid #f4d4c7;
            padding-bottom: 10px;
        }

        .zone-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }

        .zone-card {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 15px;
            transition: all 0.3s ease;
            cursor: pointer;
        }

        .zone-card:hover {
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
            transform: translateY(-2px);
        }

        .zone-card.selected {
            border: 2px solid #A44A3F;
            background-color: #f9f0ed;
        }

        .zone-name {
            font-weight: bold;
            color: #A44A3F;
            margin-bottom: 10px;
        }

        .zone-description {
            color: #666;
            font-size: 14px;
            margin-bottom: 10px;
        }

        .zone-capacity {
            color: #333;
            font-size: 14px;
        }

        /* ตารางสินค้า */
        .product-table {
            background: white;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-top: 20px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th {
            background-color: #f8f9fa;
            padding: 15px;
            text-align: center;
            font-weight: 600;
            color: #333;
            border-bottom: 2px solid #dee2e6;
        }

        td {
            padding: 15px;
            text-align: center;
            border-bottom: 1px solid #dee2e6;
        }

        tr:hover {
            background-color: #f8f9fa;
        }

        /* ปุ่มจัดการ */
        .action-buttons {
            display: flex;
            gap: 8px;
            justify-content: center;
        }

        .btn {
            padding: 8px 15px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
            transition: background-color 0.2s;
        }

        .btn-primary {
            background-color: #c0392b;
            color: white;
        }

        .btn-primary:hover {
            background-color: #a93226;
        }

        .btn-warning {
            background-color: #ffc107;
            color: #333;
        }

        .btn-warning:hover {
            background-color: #e0a800;
        }

        .btn-danger {
            background-color: #dc3545;
            color: white;
        }

        .btn-danger:hover {
            background-color: #c82333;
        }

        .btn-success {
            background-color: #28a745;
            color: white;
        }

        .btn-success:hover {
            background-color: #218838;
        }

        /* Modal Styles สำหรับแจ้งเตือน */
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

        /* Modal สำหรับฟอร์ม */
        .modal { 
            display: none; 
            position: fixed; 
            z-index: 999; 
            left: 0; 
            top: 0; 
            width: 100%; 
            height: 100%; 
            overflow: auto; 
            background-color: rgba(0,0,0,0.6); 
            justify-content: center; 
            align-items: center; 
        }
        .modal.show { 
            display: flex; 
        }
        .modal-content { 
            background-color: #fff; 
            padding: 30px; 
            border-radius: 12px; 
            width: 500px; 
            max-width: 90%; 
            box-shadow: 0 10px 30px rgba(0,0,0,0.3); 
            transform: scale(0.7); 
            transition: transform 0.3s ease; 
        }
        .modal.show .modal-content { 
            transform: scale(1); 
        }
        .modal-header { 
            font-size: 22px; 
            font-weight: bold; 
            margin-bottom: 15px; 
            color: #A44A3F; 
        }
        .modal-body { 
            margin-bottom: 25px; 
        }
        .form-group {
            margin-bottom: 15px;
        }
        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
            color: #333;
        }
        .form-control {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 14px;
        }
        .modal-footer { 
            margin-top: 20px; 
            display: flex; 
            justify-content: flex-end; 
            gap: 15px; 
        }
        .modal-btn { 
            padding: 10px 20px; 
            border: none; 
            border-radius: 8px; 
            cursor: pointer; 
            font-size: 14px; 
            font-weight: 500; 
            transition: all 0.3s ease; 
        }
        .modal-btn.primary { 
            background-color: #c0392b; 
            color: white; 
        }
        .modal-btn.primary:hover { 
            background-color: #a93226; 
        }
        .modal-btn.secondary { 
            background-color: #6c757d; 
            color: white; 
        }
        .modal-btn.secondary:hover { 
            background-color: #5a6268; 
        }

        /* สไตล์สำหรับส่วนแสดงรายละเอียดโซน */
        .zone-details {
            background: white;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            display: none;
        }

        .zone-details.show {
            display: block;
        }

        .zone-details-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
            border-bottom: 2px solid #f4d4c7;
            padding-bottom: 10px;
        }

        .zone-details-title {
            color: #A44A3F;
            font-size: 20px;
            font-weight: bold;
        }

        .zone-details-content {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
        }

        .zone-detail-item {
            margin-bottom: 10px;
        }

        .zone-detail-label {
            font-weight: bold;
            color: #333;
            margin-bottom: 5px;
        }

        .zone-detail-value {
            color: #666;
        }

        .zone-products {
            margin-top: 20px;
        }

        .zone-products-title {
            font-size: 18px;
            font-weight: bold;
            margin-bottom: 10px;
            color: #A44A3F;
        }

        /* Modal ยืนยันการลบ */
        .confirm-modal {
            display: none;
            position: fixed;
            z-index: 1001;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
            justify-content: center;
            align-items: center;
        }

        .confirm-modal.show {
            display: flex;
        }

        .confirm-modal-content {
            background-color: #fff;
            padding: 30px;
            border-radius: 12px;
            width: 400px;
            max-width: 90%;
            box-shadow: 0 10px 30px rgba(0,0,0,0.3);
            text-align: center;
        }

        .confirm-icon {
            font-size: 48px;
            color: #dc3545;
            margin-bottom: 15px;
        }

        .confirm-title {
            font-size: 20px;
            font-weight: bold;
            margin-bottom: 10px;
            color: #333;
        }

        .confirm-message {
            color: #666;
            margin-bottom: 25px;
            line-height: 1.5;
        }

        .confirm-buttons {
            display: flex;
            justify-content: center;
            gap: 15px;
        }

        .confirm-btn {
            padding: 10px 25px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
            transition: all 0.3s ease;
            min-width: 80px;
        }

        .confirm-btn.cancel {
            background-color: #6c757d;
            color: white;
        }

        .confirm-btn.cancel:hover {
            background-color: #5a6268;
        }

        .confirm-btn.delete {
            background-color: #dc3545;
            color: white;
        }

        .confirm-btn.delete:hover {
            background-color: #c82333;
        }

        /* ข้อความแจ้งเตือน */
        .alert {
            padding: 12px 15px;
            border-radius: 5px;
            margin-bottom: 15px;
            font-weight: 500;
            border: 1px solid transparent;
        }

        .alert-success {
            background-color: #d4edda;
            color: #155724;
            border-color: #c3e6cb;
        }

        .alert-error {
            background-color: #f8d7da;
            color: #721c24;
            border-color: #f5c6cb;
        }

        .alert-info {
            background-color: #d1ecf1;
            color: #0c5460;
            border-color: #bee5eb;
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
            <a href="Dashboard.php" class="menu-item">
                <div class="icon-medium">&#127968;</div>
                <span style="font-size:18px; color: #000000;">หน้าแรก</span>
            </a>
            <a href="Manage_products.php" class="menu-item">
                <div class="icon-medium">&#128230;</div>
                <span style="font-size:18px; color: #000000;">จัดการสินค้า</span>
            </a>
            <a href="manage_types.php" class="menu-item">
                <div class="icon-medium">&#128193;</div>
                <span style="font-size:18px; color: #000000;">จัดการประเภทสินค้า</span>
            </a>
            <a href="receive_stock.php" class="menu-item">
                <div class="icon-medium">&#128229;</div>
                <span style="font-size:18px; color: #000000;">รับสินค้าเข้า</span>
            </a>
            <a href="Order.php" class="menu-item">
                <div class="icon-medium">&#128722;</div>
                <span style="font-size:18px; color: #000000;">สั่งซื้อสินค้า</span>
            </a>
            <a href="storage.php" class="menu-item active">
                <div class="icon-medium">&#128190;</div>
                <span style="font-size:18px; color: #A44A3F;">จัดเก็บสินค้า</span>
            </a>
            <a href="Employee_Account.php" class="menu-item">
                <div class="icon-medium">&#128101;</div>
                <span style="font-size:18px; color: #000000;">จัดการพนักงาน</span>
            </a>
            <a href="shop.php" class="menu-item">
                <div class="icon-medium">&#127978;</div>
                <span style="font-size:18px; color: #000000;">จัดการร้านค้า</span>
            </a>
            <a href="reports.php" class="menu-item">
                <div class="icon-medium">&#128200;</div>
                <span style="font-size:18px; color: #000000;">รายงาน</span>
            </a>
            <a href="settings.php" class="menu-item">
                <div class="icon-medium">⚙️</div>
                <span style="font-size:18px; color: #000000;">ตั้งค่าระบบ</span>
            </a>
        </div>

        <div class="content">
            <h2 class="page-header">จัดการโซนจัดเก็บสินค้า</h2>
            <?php if (isset($_SESSION['success_message'])): ?>
            <div class="alert alert-success" id="successMessage">✅ <?php echo $_SESSION['success_message']; unset($_SESSION['success_message']); ?></div>
            <?php endif; ?>
            <?php if (isset($_SESSION['error_message'])): ?>
            <div class="alert alert-error" id="errorMessage">❌ <?php echo $_SESSION['error_message']; unset($_SESSION['error_message']); ?></div>
            <?php endif; ?>
            <?php if (isset($_SESSION['info_message'])): ?>
            <div class="alert alert-info" id="infoMessage">ℹ️ <?php echo $_SESSION['info_message']; unset($_SESSION['info_message']); ?></div>
            <?php endif; ?>
            <div class="storage-section">
                <div class="section-header">
                    <div class="section-title">โซนจัดเก็บทั้งหมด</div>
                    <button class="btn btn-primary" onclick="showAddZoneModal()">+ เพิ่มโซนจัดเก็บ</button>
                </div>
                <div class="zone-grid">
                    <?php if (count($storage_zones) > 0): ?>
                    <?php foreach ($storage_zones as $zone): ?>
                    <div class="zone-card" onclick="selectZone('<?php echo $zone['zone_id']; ?>')">
                        <div class="zone-name"><?php echo htmlspecialchars($zone['zone_name']); ?></div>
                        <div class="zone-description"><?php echo htmlspecialchars($zone['zone_description']); ?></div>
                        <div class="action-buttons" style="margin-top: 10px;">
                            <button class="btn btn-warning" onclick="event.stopPropagation(); editZone('<?php echo $zone['zone_id']; ?>', '<?php echo htmlspecialchars($zone['zone_name']); ?>', '<?php echo htmlspecialchars($zone['zone_description']); ?>')">แก้ไข</button>
                            <button class="btn btn-danger" onclick="event.stopPropagation(); showDeleteConfirm('<?php echo $zone['zone_id']; ?>', '<?php echo htmlspecialchars($zone['zone_name']); ?>')">ลบ</button>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <?php else: ?>
                    <div style="grid-column: 1 / -1; text-align: center; padding: 20px; color: #666;">ไม่พบโซนจัดเก็บ</div>
                    <?php endif; ?>
                </div>
            </div>
            <div id="zoneDetails" class="zone-details"></div>
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
                <div class="notification-item"><strong><?php echo htmlspecialchars($item['pd_name']); ?></strong> (เหลือ <?php echo $item['pd_number']; ?> <?php echo htmlspecialchars($item['pd_unit']); ?>)</div>
                <?php endforeach; ?>
                <?php else: ?>
                <div class="no-notifications">ไม่มีสินค้าใกล้หมด</div>
                <?php endif; ?>
            </div>
            <div class="notification-section">
                <h3>📥 สินค้าเข้าใหม่ (<?php echo $recent_received_count; ?> รายการ)</h3>
                <?php if ($recent_received_count > 0): ?>
                <?php foreach ($recent_received_orders as $order): ?>
                <div class="notification-item"><strong>ใบสั่งซื้อ: <?php echo htmlspecialchars($order['buypd_ID']); ?></strong> จาก <?php echo htmlspecialchars($order['shop_name']); ?> (<?php echo date("d/m/Y", strtotime($order['buypd_date'])); ?>) - ฿<?php echo number_format($order['buypd_total'], 2); ?></div>
                <?php endforeach; ?>
                <?php else: ?>
                <div class="no-notifications">ไม่มีสินค้าเข้าใหม่ใน 7 วันที่ผ่านมา</div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div id="zoneModal" class="modal">
        <div class="modal-content">
            <div class="modal-header" id="zoneModalTitle">เพิ่มโซนจัดเก็บ</div>
            <div class="modal-body">
                <form id="zoneForm">
                    <input type="hidden" id="zone_id" name="zone_id">
                    <div class="form-group"><label for="zone_name">ชื่อโซนจัดเก็บ</label><input type="text" id="zone_name" name="zone_name" class="form-control" required></div>
                    <div class="form-group"><label for="zone_description">คำอธิบาย</label><textarea id="zone_description" name="zone_description" class="form-control" rows="3" placeholder="คำอธิบายเกี่ยวกับโซนจัดเก็บนี้..."></textarea></div>
                </form>
            </div>
            <div class="modal-footer"><button class="modal-btn primary" onclick="saveZone()">บันทึก</button><button class="modal-btn secondary" onclick="hideZoneModal()">ยกเลิก</button></div>
        </div>
    </div>
    <div id="deleteConfirmModal" class="confirm-modal">
        <div class="confirm-modal-content">
            <div class="confirm-icon">⚠️</div>
            <div class="confirm-title">ยืนยันการลบ</div>
            <div class="confirm-message" id="deleteConfirmMessage">คุณแน่ใจว่าต้องการลบโซนจัดเก็บ "<span id="deleteZoneName"></span>" ใช่หรือไม่?<br><br><strong>หมายเหตุ:</strong> การลบโซนจัดเก็บจะไม่ลบสินค้าที่อยู่ในโซน แต่จะเปลี่ยนสถานะสินค้าเหล่านั้นเป็น "ยังไม่ได้จัดเก็บ"</div>
            <div class="confirm-buttons"><button class="confirm-btn delete" onclick="confirmDelete()">ลบ</button><button class="confirm-btn cancel" onclick="hideDeleteConfirm()">ยกเลิก</button></div>
        </div>
    </div>

<script>
    // โค้ด JavaScript ทั้งหมดเหมือนเดิม ไม่ต้องแก้ไข
    let zoneToDelete = null;
    function toggleDropdown(){document.getElementById("userDropdown").classList.toggle("show")}window.onclick=function(e){if(!e.target.matches(".user-avatar")){for(var o=document.getElementsByClassName("dropdown-content"),t=0;t<o.length;t++){var n=o[t];n.classList.contains("show")&&n.classList.remove("show")}}(modal=document.getElementById("notificationModal"))==e.target&&(modal.style.display="none"),(zoneModal=document.getElementById("zoneModal"))==e.target&&hideZoneModal(),(deleteModal=document.getElementById("deleteConfirmModal"))==e.target&&hideDeleteConfirm()};function showNotificationModal(){document.getElementById("notificationModal").style.display="block"}function closeNotificationModal(){document.getElementById("notificationModal").style.display="none"}function showAddZoneModal(){document.getElementById("zoneModalTitle").textContent="เพิ่มโซนจัดเก็บ",document.getElementById("zoneForm").reset(),document.getElementById("zone_id").value="",showZoneModal()}function editZone(e,o,t){document.getElementById("zoneModalTitle").textContent="แก้ไขโซนจัดเก็บ",document.getElementById("zone_id").value=e,document.getElementById("zone_name").value=o,document.getElementById("zone_description").value=t||"",showZoneModal()}function showZoneModal(){const e=document.getElementById("zoneModal");e.style.display="flex",setTimeout(()=>{e.classList.add("show")},10)}function hideZoneModal(){const e=document.getElementById("zoneModal");e.classList.remove("show"),setTimeout(()=>{e.style.display="none"},300)}function saveZone(){const e=document.getElementById("zone_id").value,o=document.getElementById("zone_name").value.trim(),t=document.getElementById("zone_description").value.trim();if(!o)return void alert("กรุณากรอกชื่อโซนจัดเก็บ");const n=new FormData;n.append("zone_id",e),n.append("zone_name",o),n.append("zone_description",t);const d=document.querySelector(".modal-btn.primary"),c=d.textContent;d.textContent="กำลังบันทึก...",d.disabled=!0,fetch("save_zone.php",{method:"POST",body:n}).then(e=>e.json()).then(e=>{e.success?window.location.href="storage.php?success="+encodeURIComponent(e.message):(alert("เกิดข้อผิดพลาด: "+e.message),d.textContent=c,d.disabled=!1)}).catch(e=>{console.error("Error:",e),alert("เกิดข้อผิดพลาดในการเชื่อมต่อกับเซิร์ฟเวอร์"),d.textContent=c,d.disabled=!1})}function showDeleteConfirm(e,o){zoneToDelete=e,document.getElementById("deleteZoneName").textContent=o;const t=document.getElementById("deleteConfirmModal");t.style.display="flex",setTimeout(()=>{t.classList.add("show")},10)}function hideDeleteConfirm(){zoneToDelete=null;const e=document.getElementById("deleteConfirmModal");e.classList.remove("show"),setTimeout(()=>{e.style.display="none"},300)}function confirmDelete(){if(!zoneToDelete)return;const e=document.querySelector(".confirm-btn.delete"),o=e.textContent;e.textContent="กำลังลบ...",e.disabled=!0,fetch("delete_zone.php",{method:"POST",headers:{"Content-Type":"application/x-www-form-urlencoded"},body:"zone_id="+zoneToDelete}).then(e=>e.json()).then(e=>{e.success?window.location.href="storage.php?success="+encodeURIComponent(e.message):alert("เกิดข้อผิดพลาด: "+e.message)}).catch(e=>{console.error("Error:",e),alert("เกิดข้อผิดพลาดในการเชื่อมต่อกับเซิร์ฟเวอร์")}).finally(()=>{hideDeleteConfirm(),e.textContent=o,e.disabled=!1})}function selectZone(e){document.querySelectorAll(".zone-card").forEach(e=>{e.classList.remove("selected")}),event.currentTarget.classList.add("selected"),fetch(`get_zone_details.php?zone_id=${e}`).then(e=>e.json()).then(o=>{o.success?displayZoneDetails(o.zone,o.products):alert("เกิดข้อผิดพลาดในการดึงข้อมูลโซน")}).catch(e=>{console.error("Error:",e),alert("เกิดข้อผิดพลาดในการเชื่อมต่อกับเซิร์ฟเวอร์")})}function displayZoneDetails(e,o){const t=document.getElementById("zoneDetails");let n="";n=o.length>0?`\n                <div class="product-table">\n                    <table>\n                        <thead>\n                            <tr>\n                                <th>รหัสสินค้า</th>\n                                <th>ชื่อสินค้า</th>\n                                <th>จำนวนคงเหลือ</th>\n                                <th>หน่วยนับ</th>\n                                <th>ประเภท</th>\n                            </tr>\n                        </thead>\n                        <tbody>\n                            ${o.map(e=>`\n                                <tr>\n                                    <td>${e.pd_ID}</td>\n                                    <td>${e.pd_name}</td>\n                                    <td>${e.pd_number}</td>\n                                    <td>${e.pd_unit}</td>\n                                    <td>${e.typepd_name||"-"}</td>\n                                </tr>\n                            `).join("")}\n                        </tbody>\n                    </table>\n                </div>\n            `:'<p style="text-align: center; color: #666; padding: 20px;">ไม่มีสินค้าในโซนนี้</p>',t.innerHTML=`\n            <div class="zone-details-header">\n                <div class="zone-details-title">รายละเอียดโซนจัดเก็บ: ${e.zone_name}</div>\n            </div>\n            <div class="zone-details-content">\n                <div class="zone-detail-item">\n                    <div class="zone-detail-label">รหัสโซน</div>\n                    <div class="zone-detail-value">${e.zone_id}</div>\n                </div>\n                <div class="zone-detail-item">\n                    <div class="zone-detail-label">ชื่อโซน</div>\n                    <div class="zone-detail-value">${e.zone_name}</div>\n                </div>\n                <div class="zone-detail-item">\n                    <div class="zone-detail-label">คำอธิบาย</div>\n                    <div class="zone-detail-value">${e.zone_description||"-"}</div>\n                </div>\n                <div class="zone-detail-item">\n                    <div class="zone-detail-label">จำนวนสินค้าในโซน</div>\n                    <div class="zone-detail-value">${o.length} รายการ</div>\n                </div>\n            </div>\n            <div class="zone-products">\n                <div class="zone-products-title">สินค้าในโซนนี้</div>\n                ${n}\n            </div>\n        `,t.classList.add("show")}setTimeout(()=>{const e=document.getElementById("successMessage"),o=document.getElementById("errorMessage"),t=document.getElementById("infoMessage");e&&(e.style.opacity="0",setTimeout(()=>e.style.display="none",500)),o&&(o.style.opacity="0",setTimeout(()=>o.style.display="none",500)),t&&(t.style.opacity="0",setTimeout(()=>t.style.display="none",500))},5e3),document.addEventListener("DOMContentLoaded",function(){document.querySelectorAll(".alert").forEach(e=>{e.style.transition="opacity 0.5s ease"})});
</script>

</body>
</html>