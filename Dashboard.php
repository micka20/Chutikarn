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

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// ดึงข้อมูลผู้ใช้
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

// === ดึงข้อมูลสินค้าใกล้หมด (Reorder Point) - เหมือน Manage_products.php ===
$low_stock_products = [];
$low_stock_sql = "SELECT pd_ID, pd_name, pd_number, pd_unit FROM product WHERE pd_number <= 10 AND pd_number > 0 ORDER BY pd_number ASC";
$low_stock_result = $conn->query($low_stock_sql);
if ($low_stock_result->num_rows > 0) {
    while($row = $low_stock_result->fetch_assoc()) {
        $low_stock_products[] = $row;
    }
}
$low_stock_count = count($low_stock_products);

// === ดึงข้อมูลสำหรับแจ้งเตือนสินค้าเข้าใหม่ (7 วันที่ผ่านมา) - เหมือน Manage_products.php ===
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

// --- Fetch Data for Summary Cards ---
$total_pieces_query = $conn->query("SELECT SUM(pd_number) as total_pieces FROM product");
$total_pieces_data = $total_pieces_query->fetch_assoc();
$total_pieces = $total_pieces_data['total_pieces'] ?? 0;

$total_items_query_count = $conn->query("SELECT COUNT(pd_ID) as total_items FROM product");
$total_items_data = $total_items_query_count->fetch_assoc();
$total_items = $total_items_data['total_items'] ?? 0;

$low_stock_count_card = $low_stock_count;

// รับสินค้าเข้า
$received_total = 0;
$received_pieces_total = 0;

$received_total_query = $conn->query("
    SELECT COUNT(DISTINCT buypd_ID) as received_orders 
    FROM buypd 
    WHERE buypd_status = 'received'
");
if ($received_total_query && $received_total_query->num_rows > 0) {
    $received_total_data = $received_total_query->fetch_assoc();
    $received_total = $received_total_data['received_orders'] ?? 0;
}

$received_pieces_total_query = $conn->query("
    SELECT SUM(od.OD_number) as received_pieces
    FROM orderdetails od
    JOIN buypd bp ON od.buypd_ID = bp.buypd_ID
    WHERE bp.buypd_status = 'received'
");
if ($received_pieces_total_query && $received_pieces_total_query->num_rows > 0) {
    $received_pieces_total_data = $received_pieces_total_query->fetch_assoc();
    $received_pieces_total = $received_pieces_total_data['received_pieces'] ?? 0;
}

// === จ่ายสินค้าออก (วันนี้) - แก้ไขเฉพาะส่วนนี้ ===
$dispensed_today = 0;
$dispensed_pieces_today = 0;

// ดึงข้อมูลจาก stock_history สำหรับวันนี้
$today_dispensed_sql = "
    SELECT 
        COUNT(DISTINCT order_id) as total_orders,
        SUM(quantity_change) as total_pieces
    FROM stock_history 
    WHERE stock_type = 'out' 
    AND DATE(created_at) = CURDATE()
    AND order_id IS NOT NULL
";
$today_dispensed_result = $conn->query($today_dispensed_sql);
if ($today_dispensed_result && $today_dispensed_result->num_rows > 0) {
    $today_dispensed_data = $today_dispensed_result->fetch_assoc();
    $dispensed_today = $today_dispensed_data['total_orders'] ?? 0;
    $dispensed_pieces_today = $today_dispensed_data['total_pieces'] ?? 0;
}

// จ่ายสินค้าออก (ทั้งหมด) - ส่วนเดิม
$dispensed_total = 0;
$dispensed_pieces_total = 0;

$dispensed_total_query = $conn->query("
    SELECT COUNT(DISTINCT buypd_ID) as dispensed_orders 
    FROM buypd 
    WHERE buypd_status = 'printed'
");
if ($dispensed_total_query && $dispensed_total_query->num_rows > 0) {
    $dispensed_total_data = $dispensed_total_query->fetch_assoc();
    $dispensed_total = $dispensed_total_data['dispensed_orders'] ?? 0;
}

$dispensed_pieces_total_query = $conn->query("
    SELECT SUM(od.OD_number) as dispensed_pieces
    FROM orderdetails od
    JOIN buypd bp ON od.buypd_ID = bp.buypd_ID
    WHERE bp.buypd_status = 'printed'
");
if ($dispensed_pieces_total_query && $dispensed_pieces_total_query->num_rows > 0) {
    $dispensed_pieces_total_data = $dispensed_pieces_total_query->fetch_assoc();
    $dispensed_pieces_total = $dispensed_pieces_total_data['dispensed_pieces'] ?? 0;
}

// ข้อมูลสำหรับตาราง
$type_map = [];
$type_query = $conn->query("SELECT typepd_ID, typepd_name FROM typepd");
if ($type_query->num_rows > 0) {
    while($row = $type_query->fetch_assoc()) {
        $type_map[$row['typepd_ID']] = $row['typepd_name'];
    }
}

$zone_map = [];
$zone_query = $conn->query("SELECT zone_id, zone_name FROM storage_zones");
if ($zone_query->num_rows > 0) {
    while($row = $zone_query->fetch_assoc()) {
        $zone_map[$row['zone_id']] = $row['zone_name'];
    }
}

// Pagination
$items_per_page = 6;
$current_page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;

$total_products_query = $conn->query("SELECT COUNT(pd_ID) as total FROM product");
$total_products = $total_products_query->fetch_assoc()['total'];
$total_pages = ceil($total_products / $items_per_page);

$offset = ($current_page - 1) * $items_per_page;

?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>แดชบอร์ด</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Kanit:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        /* แก้ไขส่วนหัวให้เป็น fixed */
        h1 { 
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            border: 2.6px solid #ffffff; 
            padding: 20px; 
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1); 
            background-color: #fff; 
            z-index: 1000;
            height: 80px;
        }
        
        .dropdown { position: relative; display: inline-block; }
        .dropdown-content { display: none; position: absolute; right: 0; background-color: #f9f9f9; min-width: 160px; 
            box-shadow: 0px 8px 16px 0px rgba(0,0,0,0.2); z-index: 1; border-radius: 4px; overflow: hidden; }
        .dropdown-content a { color: black; padding: 12px 16px; text-decoration: none; display: block; font-size: 14px; }
        .dropdown-content a:hover { background-color: #f1f1f1; }
        .show { display: block; }
        .logout { color: #e74c3c !important; }
        
        /* === สไตล์กระดิ่งแจ้งเตือนจาก Manage_products.php === */
        .notification-container { position: relative; display: inline-block; }
        .notification-badge { position: absolute; top: -5px; right: -5px; background: #ff4444; color: white; border-radius: 50%; width: 18px; height: 18px; 
            display: flex; align-items: center; justify-content: center; font-size: 12px; font-weight: bold; z-index: 10; }
        .bell-icon { font-size: 20px; cursor: pointer; }
        
        /* แก้ไขส่วนหลักให้รองรับเมนู fixed */
        .main-container { 
            display: flex; 
            min-height: 100vh;
            margin-top: 80px; /* เพิ่ม margin-top เท่ากับความสูงของ header */
        }
        
        /* แก้ไข sidebar ให้เป็น fixed และไม่เลื่อน */
        .sidebar { 
            position: fixed;
            top: 80px; /* อยู่ใต้ header */
            left: 0;
            width: 250px; 
            height: calc(100vh - 80px); /* ความสูงเต็มหน้าจอลบด้วยความสูงของ header */
            background: linear-gradient(180deg, #ffffff 0%, #ffffff 100%); 
            padding: 0; 
            box-shadow: 2px 0 15px rgba(0,0,0,0.1); 
            overflow: hidden; /* ปิดการเลื่อน */
            z-index: 999;
            display: flex;
            flex-direction: column;
        }
        
        .menu-items-container {
            flex: 1;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }
        
        .menu-items-top {
            flex: 1;
        }
        
        .menu-items-bottom {
            margin-top: auto;
            border-top: 1px solid #f0f0f0;
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
        .menu-item span { flex: 1; text-align: left; font-size: 18px; }
        .menu-item:hover { background: rgba(244, 212, 199, 0.5); border-left-color: transparent; transform: translateX(1.5px); }
        .menu-item.active { background: linear-gradient(90deg, #f4d4c7 0%, #edd5c7 100%); border-left-color: transparent; color: #A44A3F; }
        .menu-item.active span, .menu-item.active div { color: #A44A3F; }
        .icon-medium { font-size: 25px; }
        
        /* แก้ไขส่วนเนื้อหาให้มี margin-left เท่ากับความกว้างของ sidebar */
        .content { 
            flex: 1; 
            padding: 40px; 
            background-color: #f8f9fa; 
            margin-left: 250px; /* เพิ่ม margin-left เท่ากับความกว้างของ sidebar */
        }
        
        .page-header { color: #A44A3F; font-size: 30px; font-weight: bold; margin-bottom: 30px; text-align: left; }
        .search-container { position: relative; margin-bottom: 20px; }
        .search-input { width: 100%; padding: 12px 120px 12px 15px; border: 1px solid #ddd; border-radius: 8px; font-size: 16px; background-color: white; }
        .search-btn { position: absolute; right: 10px; top: 50%; transform: translateY(-50%); background: #c0392b; color: white; border: none; padding: 8px 15px; border-radius: 5px; cursor: pointer; 
            font-size: 14px; transition: all 0.3s ease; box-shadow: 0 2px 4px rgba(0,0,0,0.2); }
        .search-btn:hover { background: #a93226; transform: translateY(-50%) translateY(-1px); box-shadow: 0 4px 8px rgba(0,0,0,0.3); }
        table { width: 100%; border-collapse: collapse; }
        th { background-color: #f8f9fa; padding: 15px; text-align: center; font-weight: 600; color: #333; border-bottom: 2px solid #dee2e6; }
        td { padding: 15px; text-align: center; border-bottom: 1px solid #dee2e6; }
        tr:hover { background-color: #f8f9fa; }
        .action-buttons { display: flex; gap: 8px; justify-content: center; }
        .action-btn { padding: 6px 12px; border-radius: 6px; color: white; border: none; cursor: pointer; transition: background-color 0.2s; }
        .edit-btn { background-color: #ffc107; color: #333; }
        .edit-btn:hover { background-color: #e0a800; }
        .delete-btn { background-color: #dc3545; }
        .delete-btn:hover { background-color: #c82333; }
        .modal { display: none; position: fixed; z-index: 999; left: 0; top: 0; width: 100%; height: 100%; overflow: auto; background-color: rgba(0,0,0,0.6); justify-content: center; align-items: center; }
        .modal.show { display: flex; }
        .modal-content { background-color: #fff; padding: 30px; border-radius: 12px; width: 450px; max-width: 90%; text-align: center; box-shadow: 0 10px 30px rgba(0,0,0,0.3); transform: scale(0.7); transition: transform 0.3s ease; }
        .modal.show .modal-content { transform: scale(1); }
        .modal-header { font-size: 22px; font-weight: bold; margin-bottom: 15px; color: #dc3545; }
        .modal-body { font-size: 16px; line-height: 1.5; margin-bottom: 25px; color: #333; }
        .modal-footer { margin-top: 20px; display: flex; justify-content: center; gap: 15px; }
        .modal-btn { padding: 12px 25px; border: none; border-radius: 8px; cursor: pointer; font-size: 16px; font-weight: 500; transition: all 0.3s ease; }
        .modal-btn.submit { background-color: #dc3545; color: white; }
        .modal-btn.submit:hover { background-color: #c82333; transform: translateY(-1px); }
        .modal-btn.cancel { background-color: #6c757d; color: white; }
        .modal-btn.cancel:hover { background-color: #5a6268; transform: translateY(-1px); }
        .status-badge { padding: 4px 12px; border-radius: 9999px; font-size: 12px; color: white; font-weight: 500; text-align: center; min-width: 80px; display: inline-block; }
        .status-in-stock { background-color: #F3FFF4; color: #055D19; }
        .status-low-stock { background-color: #ffc107; color: #333; }
        .status-out-of-stock { background-color: #dc3545; }
        .pagination-container { text-align: center; margin-top: 30px; }
        .pagination { display: inline-flex; list-style-type: none; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.1); }
        .pagination a { color: #c0392b; padding: 12px 18px; text-decoration: none; transition: background-color .3s; border-right: 1px solid #ddd; background-color: #fff; font-weight: 500; }
        .pagination li:last-child a { border-right: none; }
        .pagination a.active { background-color: #c0392b; color: white; }
        .pagination a:hover:not(.active) { background-color: #f5b7b1; }
        .pagination .disabled a { color: #ccc; pointer-events: none; cursor: default; }
        
        /* === Modal Styles สำหรับแจ้งเตือนจาก Manage_products.php === */
        .notification-modal { display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5); }
        .notification-modal-content { background-color: #fefefe; margin: 5% auto; padding: 20px; border-radius: 8px; width: 80%; max-width: 600px; box-shadow: 0 4px 8px rgba(0,0,0,0.2); }
        .close { color: #aaa; float: right; font-size: 28px; font-weight: bold; cursor: pointer; }
        .close:hover { color: black; }
        .notification-section { margin-bottom: 20px; }
        .notification-section h3 { color: #A44A3F; margin-bottom: 10px; border-bottom: 1px solid #eee; padding-bottom: 5px; }
        .notification-item { padding: 8px 0; border-bottom: 1px solid #f0f0f0; }
        .notification-item:last-child { border-bottom: none; }
        .no-notifications { color: #777; font-style: italic; }
        
        /* === สไตล์สำหรับแสดงประเภทสินค้าให้อยู่ในบรรทัดเดียว === */
        .product-type-cell {
            max-width: 200px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            text-align: left;
        }
        
        .product-name-cell {
            max-width: 250px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            text-align: left;
        }
    </style>
</head>
<body class="bg-gray-50 font-kanit">

<h1 style="font-size:25px; font-family: 'Microsoft Sans Serif'; font-weight: bold; display: flex; justify-content: space-between; align-items: center;">
    <div style="display: flex; align-items: center;">
        <img src="logo1.png" width="50" height="50" style="vertical-align: middle;">
        <span style="vertical-align: middle;">Chutikarn</span>
    </div>
    
    <div class="user-section" style="display: flex; align-items: center; gap: 15px;">
        <!-- === ระบบแจ้งเตือนกระดิ่งจาก Manage_products.php === -->
        <div class="notification-container">
            <div class="bell-icon" onclick="showNotificationModal()">&#128276;</div>
            <?php if ($low_stock_count > 0 || $recent_received_count > 0): ?>
                <div class="notification-badge"><?php echo $low_stock_count + $recent_received_count; ?></div>
            <?php endif; ?>
        </div>
        
        <div class="dropdown">
            <div class="user-avatar" onclick="toggleDropdown()" style="font-size:25px; width: 40px; height: 40px; border-radius: 50%; 
            background: #f0f0f0; display: flex; align-items: center; justify-content: center; cursor: pointer;">&#128100;</div>
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
        <div class="menu-items-container">
            <div class="menu-items-top">
                <a href="Dashboard.php" class="menu-item active"><div class="icon-medium">&#127968;</div><span>หน้าแรก</span></a>
                <a href="Manage_products.php" class="menu-item"><div class="icon-medium">&#128230;</div><span>จัดการสินค้า</span></a>
                <a href="manage_types.php" class="menu-item"><div class="icon-medium">&#128193;</div><span>จัดการประเภทสินค้า</span></a>
                <a href="receive_stock.php" class="menu-item"><div class="icon-medium">&#128229;</div><span>รับสินค้าเข้า</span></a>
                <a href="Order.php" class="menu-item"><div class="icon-medium">&#128722;</div><span>สั่งซื้อสินค้า</span></a>
                <a href="storage.php" class="menu-item"><div class="icon-medium">&#128190;</div><span>จัดเก็บสินค้า</span></a>
                <a href="Employee_Account.php" class="menu-item"><div class="icon-medium">&#128101;</div><span>จัดการพนักงาน</span></a>
                <a href="shop.php" class="menu-item"><div class="icon-medium">&#127978;</div><span>จัดการร้านค้า</span></a>
                <a href="reports.php" class="menu-item"><div class="icon-medium">&#128200;</div><span>รายงาน</span></a>
                <a href="settings.php" class="menu-item"><div class="icon-medium">⚙️</div><span>ตั้งค่าระบบ</span></a>
            </div>
        </div>
    </div>

    <div class="content">
        <h2 class="page-header">ระบบจัดการคลังสินค้า</h2>

        <div class="search-container">
            <input type="text" id="searchInput" class="search-input" placeholder="ค้นหาสินค้า..." onkeyup="searchProducts()">
            <button class="search-btn" onclick="searchProducts()">ค้นหา</button>
        </div>

        <div class="container mx-auto">
            <!-- Summary Cards -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                 <a href="Manage_products.php" class="bg-white p-6 rounded-lg shadow-md flex items-center transition-transform transform hover:-translate-y-1 hover:shadow-lg cursor-pointer">
                    <div class="bg-blue-100 text-blue-500 rounded-full w-16 h-16 flex items-center justify-center"><i class="fas fa-boxes-stacked text-3xl"></i></div>
                    <div class="ml-4">
                        <p class="text-2xl font-bold text-gray-800"><?php echo number_format($total_pieces); ?> ชิ้น</p>
                        <p class="text-gray-600">สินค้าทั้งหมด</p>
                        <p class="text-sm text-gray-400">จาก <?php echo $total_items; ?> รายการ</p>
                    </div>
                </a>
                 <a href="Manage_products.php?filter=low_stock" class="bg-white p-6 rounded-lg shadow-md flex items-center transition-transform transform hover:-translate-y-1 hover:shadow-lg cursor-pointer">
                    <div class="bg-yellow-100 text-yellow-500 rounded-full w-16 h-16 flex items-center justify-center"><i class="fas fa-exclamation-triangle text-3xl"></i></div>
                    <div class="ml-4">
                        <p class="text-2xl font-bold text-gray-800"><?php echo $low_stock_count; ?> รายการ</p>
                        <p class="text-gray-600">สินค้าใกล้หมด</p>
                    </div>
                </a>
                 <a href="#" class="bg-white p-6 rounded-lg shadow-md flex items-center transition-transform transform hover:-translate-y-1 hover:shadow-lg cursor-pointer">
                    <div class="bg-green-100 text-green-500 rounded-full w-16 h-16 flex items-center justify-center"><i class="fas fa-arrow-alt-circle-down text-3xl"></i></div>
                    <div class="ml-4">
                        <p class="text-2xl font-bold text-gray-800"><?php echo number_format($received_pieces_total); ?> ชิ้น</p>
                        <p class="text-gray-600">รับสินค้าเข้า</p>
                        <p class="text-sm text-gray-400"><?php echo $received_total; ?> รายการ</p>
                    </div>
                </a>
                <!-- แก้ไขเฉพาะส่วนนี้ให้แสดงข้อมูลจ่ายออกวันนี้ -->
                <a href="stock_history.php?type=out&date=<?php echo date('Y-m-d'); ?>" class="bg-white p-6 rounded-lg shadow-md flex items-center transition-transform transform hover:-translate-y-1 hover:shadow-lg cursor-pointer">
                    <div class="bg-red-100 text-red-500 rounded-full w-16 h-16 flex items-center justify-center"><i class="fas fa-arrow-alt-circle-up text-3xl"></i></div>
                    <div class="ml-4">
                        <p class="text-2xl font-bold text-gray-800"><?php echo number_format($dispensed_pieces_today); ?> ชิ้น</p>
                        <p class="text-gray-600">จ่ายสินค้าออก (วันนี้)</p>
                        <p class="text-sm text-gray-400"><?php echo $dispensed_today; ?> รายการ</p>
                    </div>
                </a>
            </div>

            <!-- Product Table -->
            <div class="bg-white rounded-lg shadow-md overflow-hidden">
                <table class="w-full text-left" id="productTable">
                    <thead>
                        <tr>
                            <th class="p-4">#</th>
                            <th class="p-4">รหัสสินค้า</th>
                            <th class="p-4">ชื่อสินค้า</th>
                            <th class="p-4">ประเภทสินค้า</th>
                            <th class="p-4">ตำแหน่ง</th>
                            <th class="p-4 text-center">จำนวน</th>
                            <th class="p-4">หน่วยนับ</th>
                            <th class="p-4 text-center">สถานะ</th>
                            <th class="p-4 text-center">การกระทำ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $sql_table = "SELECT pd_ID, pd_name, pd_number, pd_unit, typepd_ID, zone_id FROM product ORDER BY pd_ID ASC LIMIT ? OFFSET ?";
                        $stmt_table = $conn->prepare($sql_table);
                        $stmt_table->bind_param("ii", $items_per_page, $offset);
                        $stmt_table->execute();
                        $result_table = $stmt_table->get_result();
                        
                        $i = $offset + 1;

                        if ($result_table->num_rows > 0) {
                            while($row = $result_table->fetch_assoc()) {
                                $typeName = $type_map[$row['typepd_ID']] ?? 'ไม่ระบุ';
                                $location = !empty($row['zone_id']) ? ($zone_map[$row['zone_id']] ?? $row['zone_id']) : 'ไม่ระบุ';
                                
                                if ($row["pd_number"] == 0) {
                                    $status = 'สินค้าหมด';
                                    $status_class = 'status-out-of-stock';
                                } elseif ($row["pd_number"] <= 10) {
                                    $status = 'ใกล้หมด';
                                    $status_class = 'status-low-stock';
                                } else {
                                    $status = 'มีสต๊อก';
                                    $status_class = 'status-in-stock';
                                }
                        ?>
                        <tr class="hover:bg-gray-50">
                            <td class="p-4"><?php echo $i++; ?></td>
                            <td class="p-4 font-mono"><?php echo htmlspecialchars($row["pd_ID"]); ?></td>
                            <td class="p-4 product-name-cell" title="<?php echo htmlspecialchars($row["pd_name"]); ?>">
                                <?php echo htmlspecialchars($row["pd_name"]); ?>
                            </td>
                            <td class="p-4 product-type-cell" title="<?php echo htmlspecialchars($typeName); ?>">
                                <?php echo htmlspecialchars($typeName); ?>
                            </td>
                            <td class="p-4"><?php echo htmlspecialchars($location); ?></td>
                            <td class="p-4 text-center font-bold"><?php echo htmlspecialchars($row["pd_number"]); ?></td>
                            <td class="p-4"><?php echo htmlspecialchars($row["pd_unit"]); ?></td>
                            <td class="p-4 text-center"><span class="status-badge <?php echo $status_class; ?>"><?php echo $status; ?></span></td>
                            <td class="p-4 text-center">
                                <div class="action-buttons justify-center">
                                    <button onclick="editProduct('<?php echo htmlspecialchars($row['pd_ID']); ?>')" class="action-btn edit-btn">แก้ไข</button>
                                    <button onclick="showDeleteModal('<?php echo htmlspecialchars($row['pd_ID']); ?>', '<?php echo htmlspecialchars(addslashes($row['pd_name'])); ?>')" class="action-btn delete-btn">ลบ</button>
                                </div>
                            </td>
                        </tr>
                        <?php
                            }
                        } else {
                            echo "<tr><td colspan='9' class='text-center p-8'>ไม่พบข้อมูลสินค้าในระบบ</td></tr>";
                        }
                        ?>
                        <tr id="no-results-row" style="display: none;">
                            <td colspan="9" class="text-center p-8">ไม่พบข้อมูลที่ตรงกับการค้นหา</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <?php if ($total_pages > 1): ?>
            <div class="pagination-container">
                <ul class="pagination">
                    <li class="<?php if($current_page <= 1){ echo 'disabled'; } ?>"><a href="<?php if($current_page > 1){ echo '?page=' . ($current_page - 1); } else { echo '#'; } ?>">ก่อนหน้า</a></li>
                    <?php for($page = 1; $page <= $total_pages; $page++): ?>
                    <li><a href="?page=<?php echo $page; ?>" class="<?php if($page == $current_page) {echo 'active';} ?>"><?php echo $page; ?></a></li>
                    <?php endfor; ?>
                    <li class="<?php if($current_page >= $total_pages){ echo 'disabled'; } ?>"><a href="<?php if($current_page < $total_pages) { echo '?page=' . ($current_page + 1); } else { echo '#'; } ?>">ถัดไป</a></li>
                </ul>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Modal ยืนยันการลบ -->
<div id="deleteConfirmModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">ยืนยันการลบ</div>
        <div class="modal-body" id="deleteConfirmText"></div>
        <div class="modal-footer">
            <button class="modal-btn submit" onclick="confirmDelete()">ยืนยันการลบ</button>
            <button class="modal-btn cancel" onclick="hideDeleteModal()">ยกเลิก</button>
        </div>
    </div>
</div>

<!-- === Modal สำหรับแจ้งเตือนจาก Manage_products.php === -->
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

<script>
    // === ฟังก์ชันสำหรับแสดง/ปิด Modal แจ้งเตือนจาก Manage_products.php ===
    function showNotificationModal() {
        document.getElementById("notificationModal").style.display = "block";
    }
    
    function closeNotificationModal() {
        document.getElementById("notificationModal").style.display = "none";
    }
    
    // ปิด Modal เมื่อคลิกนอกพื้นที่
    window.onclick = function(event) {
        var notificationModal = document.getElementById("notificationModal");
        if (event.target == notificationModal) {
            notificationModal.style.display = "none";
        }
        
        // สำหรับ dropdown เมนูผู้ใช้
        if (!event.target.closest('.user-avatar')) { 
            var dropdowns = document.getElementsByClassName("dropdown-content"); 
            for (var i = 0; i < dropdowns.length; i++) { 
                var openDropdown = dropdowns[i]; 
                if (openDropdown.classList.contains('show')) { 
                    openDropdown.classList.remove('show'); 
                } 
            } 
        }
        
        // สำหรับ Modal ยืนยันการลบ
        var deleteModal = document.getElementById("deleteConfirmModal");
        if (event.target == deleteModal) {
            hideDeleteModal();
        }
    }

    // ฟังก์ชันอื่นๆ ที่มีอยู่เดิม
    function toggleDropdown() { 
        document.getElementById("userDropdown").classList.toggle("show"); 
    }
    
    function editProduct(productId) { 
        window.location.href = 'edit_product.php?id=' + productId + '&from=dashboard'; 
    }
    
    const deleteModal = document.getElementById('deleteConfirmModal');
    let productToDeleteId = null;
    
    function showDeleteModal(pdId, pdName) { 
        productToDeleteId = pdId; 
        const confirmText = document.getElementById('deleteConfirmText'); 
        confirmText.innerHTML = `คุณแน่ใจว่าต้องการลบสินค้า <br><b>"${pdName}" (รหัส: ${pdId})</b> หรือไม่? <br><br><span style="color: #dc3545;">การกระทำนี้ไม่สามารถกู้คืนได้</span>`; 
        deleteModal.style.display = 'flex'; 
        setTimeout(() => { 
            deleteModal.classList.add('show'); 
        }, 10); 
    }
    
    function hideDeleteModal() { 
        deleteModal.classList.remove('show'); 
        setTimeout(() => { 
            deleteModal.style.display = 'none'; 
        }, 300); 
        productToDeleteId = null; 
    }
    
    function confirmDelete() { 
        if (!productToDeleteId) return; 
        fetch("delete_product.php", { 
            method: "POST", 
            headers: { "Content-Type": "application/x-www-form-urlencoded" }, 
            body: "pd_ID=" + encodeURIComponent(productToDeleteId) 
        }).then(res => res.json()).then(data => { 
            if (data.success) { 
                alert("ลบสินค้าสำเร็จ!"); 
                window.location.reload(); 
            } else { 
                alert("เกิดข้อผิดพลาด: " . data.message); 
            } 
        }).catch(err => { 
            console.error('Fetch Error:', err); 
            alert("เกิดข้อผิดพลาดในการเชื่อมต่อกับเซิร์ฟเวอร์"); 
        }).finally(() => { 
            hideDeleteModal(); 
        }); 
    }
    
    function searchProducts() { 
        const input = document.getElementById("searchInput"); 
        const filter = input.value.toUpperCase(); 
        const table = document.getElementById("productTable"); 
        const tbody = table.getElementsByTagName("tbody")[0]; 
        const tr = tbody.getElementsByTagName("tr"); 
        const noResultsRow = document.getElementById("no-results-row"); 
        let visibleRowCount = 0; 
        for (let i = 0; i < tr.length; i++) { 
            if (tr[i].id === 'no-results-row') continue; 
            let isVisible = false; 
            const tds = tr[i].getElementsByTagName("td"); 
            if (tds.length > 3) { 
                const productId = tds[1].textContent || tds[1].innerText; 
                const productName = tds[2].textContent || tds[2].innerText; 
                const productType = tds[3].textContent || tds[3].innerText; 
                if (productId.toUpperCase().indexOf(filter) > -1 || productName.toUpperCase().indexOf(filter) > -1 || productType.toUpperCase().indexOf(filter) > -1) { 
                    isVisible = true; 
                } 
            } 
            if (isVisible) { 
                tr[i].style.display = ""; 
                visibleRowCount++; 
            } else { 
                tr[i].style.display = "none"; 
            } 
        } 
        noResultsRow.style.display = (visibleRowCount > 0 || tr.length <= 1) ? "none" : "table-row"; 
    }
</script>

</body>
</html>