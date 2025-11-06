<?php
session_start();
if (!isset($_SESSION['user_username'])) {
    header("Location: login.php");
    exit();
}

$conn = new mysqli("localhost", "root", "12345678", "chutikarnceramic1");
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

// === ดึงข้อมูลสินค้าใกล้หมด (Reorder Point) ===
$low_stock_products = [];
$low_stock_sql = "SELECT pd_ID, pd_name, pd_number, pd_unit FROM product WHERE pd_number <= 10 AND pd_number > 0 ORDER BY pd_number ASC";
$low_stock_result = $conn->query($low_stock_sql);
if ($low_stock_result->num_rows > 0) {
    while ($row = $low_stock_result->fetch_assoc()) {
        $low_stock_products[] = $row;
    }
}
$low_stock_count = count($low_stock_products);

// === ส่วนเพิ่มเติม: ดึงข้อมูลสำหรับแจ้งเตือนสินค้าเข้าใหม่ (7 วันที่ผ่านมา) ===
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

// --- ดึงข้อมูลใบสั่งซื้อเฉพาะที่ยังไม่พิมพ์ (สถานะ pending) ---
$orders = [];
$order_sql = "SELECT b.buypd_ID, b.buypd_date, b.buypd_total, s.shop_name, u.user_name,
                     b.buypd_status, b.shop_ID, b.user_ID
               FROM buypd b
               LEFT JOIN shop s ON b.shop_ID = s.shop_ID
               LEFT JOIN user u ON b.user_ID = u.user_ID
               WHERE b.buypd_status = 'pending' OR b.buypd_status IS NULL OR b.buypd_status = ''
               ORDER BY
                 CAST(SUBSTRING(b.buypd_ID, 4) AS UNSIGNED) ASC,
                 b.buypd_date DESC";
$order_result = $conn->query($order_sql);
if ($order_result->num_rows > 0) {
    while ($row = $order_result->fetch_assoc()) {
        $orders[] = $row;
    }
}

// --- ดึงข้อมูลใบสั่งซื้อทั้งหมดสำหรับสรุปสถิติ ---
$all_orders_sql = "SELECT buypd_status, buypd_total FROM buypd";
$all_orders_result = $conn->query($all_orders_sql);
$all_orders = [];
if ($all_orders_result->num_rows > 0) {
    while ($row = $all_orders_result->fetch_assoc()) {
        $all_orders[] = $row;
    }
}

// +++ ดึงข้อมูลใบสั่งซื้อทั้งหมดสำหรับแท็บ "ประวัติทั้งหมด" และเรียงจากน้อยไปมาก +++
$all_history_orders = [];
$all_history_sql = "SELECT b.buypd_ID, b.buypd_date, b.buypd_total, s.shop_name, u.user_name,
                           b.buypd_status, b.shop_ID, b.user_ID
                      FROM buypd b
                      LEFT JOIN shop s ON b.shop_ID = s.shop_ID
                      LEFT JOIN user u ON b.user_ID = u.user_ID
                      ORDER BY
                        CAST(SUBSTRING(b.buypd_ID, 4) AS UNSIGNED) ASC,
                        b.buypd_date ASC";
$all_history_result = $conn->query($all_history_sql);
if ($all_history_result->num_rows > 0) {
    while ($row = $all_history_result->fetch_assoc()) {
        $all_history_orders[] = $row;
    }
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>รายการใบสั่งซื้อ</title>
    <link href="https://fonts.googleapis.com/css2?family=Kanit:wght@400;500;600&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <style>
        html { scroll-behavior: smooth; }
        * { margin: 0; padding: 0; box-sizing: border-box;}
        body { 
            background-color: #f8f9fa;
        }
        
        /* --- CSS อื่นๆ (เหมือนเดิม) --- */
        .top-header { font-family: 'Microsoft Sans Serif'; display: flex; justify-content: space-between; align-items: center; border: 2.6px solid #ffffff; padding: 15px 20px; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1); background-color: #fff; position: sticky; top: 0; z-index: 1000; }
        .logo-section { display: flex; align-items: center; gap: 0px; }
        .user-section { display: flex; align-items: center; gap: 20px; }
        .user-info { text-align: right; }
        .user-username { font-size: 16px; font-weight: bold; }
        .user-role { font-size: 16px; color: #666; }
        .user-avatar { font-size: 25px; width: 40px; height: 40px; border-radius: 50%; background: #f0f0f0; display: flex; align-items: center; justify-content: center; cursor: pointer; }
        .dropdown { position: relative; display: inline-block; }
        .dropdown-content { display: none; position: absolute; right: 0; margin-top: 8px; background-color: #fff; min-width: 160px; box-shadow: 0px 8px 16px 0px rgba(0,0,0,0.2); z-index: 10; border-radius: 4px; overflow: hidden; }
        .dropdown-content a { color: black; padding: 12px 16px; text-decoration: none; display: block; font-size: 14px; }
        .dropdown-content a:hover { background-color: #f1f1f1; }
        .dropdown-content .logout { color: #e74c3c !important; }
        .show { display: block; }
        .notification-container { position: relative; display: inline-block; cursor: pointer; }
        .notification-badge { position: absolute; top: -5px; right: -5px; background: #ff4444; color: white; border-radius: 50%; width: 18px; height: 18px; display: flex; align-items: center; justify-content: center; font-size: 12px; font-weight: bold; z-index: 10; }
        .bell-icon { font-size: 22px; cursor: pointer; }
        .main-container { display: flex; min-height: calc(100vh - 84px); }
        .sidebar { width: 250px; background: #ffffff; padding: 0; box-shadow: 2px 0 15px rgba(0,0,0,0.1); position: sticky; top: 84px; height: calc(100vh - 84px); overflow-y: auto; }
        .menu-item { display: flex; align-items: center; gap: 1rem; padding: 1rem 2rem; color: #000000; text-decoration: none; transition: all 0.3s ease; }
        .menu-item span { flex: 1; text-align: left; font-size: 18px; }
        .menu-item:hover { background: rgba(244, 212, 199, 0.5); }
        .menu-item.active { background: linear-gradient(90deg, #f4d4c7 0%, #edd5c7 100%); }
        .menu-item.active span, .menu-item.active div { color: #A44A3F; }
        .icon-medium { font-size: 25px; }
        .content { flex: 1; padding: 40px; }
        .page-header { color: #A44A3F; font-size: 30px; font-weight: bold; margin-bottom: 20px; text-align: left; }
        .controls { display: flex; justify-content: flex-end; margin-bottom: 20px; gap: 10px; }
        .btn { padding: 10px 20px; border: none; border-radius: 5px; font-size: 16px; cursor: pointer; transition: background-color 0.3s; text-decoration: none; display: inline-block; text-align: center; }
        .btn-success { background-color: #c0392b; color: white; }
        .btn-success:hover { background-color: #a93226; }
        .btn-info { background-color: #17a2b8; color: white; }
        .btn-warning { background-color: #ffc107; color: #212529; }
        .btn-danger { background-color: #dc3545; color: white; }
        .btn-secondary { background-color: #6c757d; color: white; }
        .btn-excel { background-color: #217346; color: white; }
        .btn-excel:hover { background-color: #1a5c38; }
        table { width: 100%; border-collapse: collapse; background: white; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        th, td { padding: 15px; text-align: left; border-bottom: 1px solid #dee2e6; }
        th { background-color: #f8f9fa; font-weight: 600; }
        .action-buttons { display: flex; gap: 10px; flex-wrap: wrap; }
        .suggestion-box { background-color: #fff3cd; border: 1px solid #ffeeba; border-radius: 8px; padding: 20px; margin-bottom: 30px; }
        .suggestion-box h3 { color: #856404; margin-bottom: 15px; font-size: 1.2em; }
        .suggestion-box .btn-warning { margin-top: 15px; font-weight: bold; }
        .status-badge { padding: 5px 10px; border-radius: 15px; font-size: 12px; font-weight: bold; }
        .status-pending { background-color: #fff3cd; color: #856404; }
        .status-printed { background-color: #d1ecf1; color: #0c5460; }
        .status-received { background-color: #d4edda; color: #155724; }
        .status-cancelled { background-color: #f8d7da; color: #721c24; }
        .order-summary { background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); margin-bottom: 20px; }
        .order-summary h3 { color: #A44A3F; margin-bottom: 15px; }
        .summary-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; }
        .stat-card { background: #f8f9fa; padding: 15px; border-radius: 8px; text-align: center; }
        .stat-number { font-size: 24px; font-weight: bold;}
        .stat-label { font-size: 14px; color: #666; }
        .order-id { font-weight: bold;}
        .loading { opacity: 0.6; pointer-events: none; }
        .nav-tabs { display: flex; margin-bottom: 20px; border-bottom: 1px solid #dee2e6; }
        .nav-tab { padding: 10px 20px; cursor: pointer; border: 1px solid transparent; border-bottom: none; margin-right: 5px; border-radius: 5px 5px 0 0; }
        .nav-tab.active { background: white; border-color: #dee2e6 #dee2e6 white; font-weight: bold; color: #A44A3F; }
        .tab-content { display: none; }
        .tab-content.active { display: block; }
        .notification-modal { display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5); }
        .notification-modal-content { background-color: #fefefe; margin: 5% auto; padding: 20px; border-radius: 8px; width: 80%; max-width: 600px; box-shadow: 0 4px 8px rgba(0,0,0,0.2); }
        .close { color: #aaa; float: right; font-size: 28px; font-weight: bold; cursor: pointer; }
        .close:hover { color: black; }
        .notification-section { margin-bottom: 20px; }
        .notification-section h3 { color: #A44A3F; margin-bottom: 10px; border-bottom: 1px solid #eee; padding-bottom: 5px; }
        .notification-item { padding: 8px 0; border-bottom: 1px solid #f0f0f0; }
        .notification-item:last-child { border-bottom: none; }
        .no-notifications { color: #777; font-style: italic; }

        /* *** CSS สำหรับปรับแต่ง SweetAlert2 ให้เหมือนตัวอย่าง *** */
        .swal2-popup {
            font-family: 'Kanit', sans-serif !important;
            border-radius: 12px !important;
            padding: 2em !important;
        }
        .swal2-title {
            font-size: 1.8em !important;
            font-weight: 600 !important;
            color: #333;
            margin-bottom: 1em !important;
        }
        .swal2-html-container {
            font-size: 1.1em !important;
            color: #555;
            line-height: 1.6;
            margin: 1em 0 !important;
        }
        .swal2-confirm,
        .swal2-cancel {
            font-size: 1em !important;
            font-weight: 500 !important;
            padding: 0.7em 2em !important;
            border-radius: 8px !important;
            margin: 0 0.5em !important;
        }
        .swal2-confirm {
            background-color: #dc3545 !important;
            border: none !important;
        }
        .swal2-confirm:hover {
            background-color: #c82333 !important;
        }
        .swal2-cancel {
            background-color: #6c757d !important;
            border: none !important;
        }
        .swal2-cancel:hover {
            background-color: #5a6268 !important;
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
            <a href="manage_types.php" class="menu-item"><div class="icon-medium">&#128193;</div><span>จัดการประเภทสินค้า</span></a>
            <a href="receive_stock.php" class="menu-item"><div class="icon-medium">&#128229;</div><span>รับสินค้าเข้า</span></a>
            <a href="Order.php" class="menu-item active"><div class="icon-medium">&#128722;</div><span>สั่งซื้อสินค้า</span></a>
            <a href="storage.php" class="menu-item"><div class="icon-medium">&#128190;</div><span>จัดเก็บสินค้า</span></a>
            <a href="Employee_Account.php" class="menu-item"><div class="icon-medium">&#128101;</div><span>จัดการพนักงาน</span></a>
            <a href="shop.php" class="menu-item"><div class="icon-medium">&#127978;</div><span>จัดการร้านค้า</span></a>
            <a href="reports.php" class="menu-item"><div class="icon-medium">&#128200;</div><span>รายงาน</span></a>
            <a href="settings.php" class="menu-item"><div class="icon-medium">⚙️</div><span>ตั้งค่าระบบ</span></a>
        </div>
        <div class="content">
            <div class="order-summary">
                <h3>📊 สรุปใบสั่งซื้อ</h3>
                <div class="summary-stats">
                    <div class="stat-card"><div class="stat-number"><?php echo count($all_orders); ?></div><div class="stat-label">ทั้งหมด</div></div>
                    <div class="stat-card"><div class="stat-number"><?php echo count($orders); ?></div><div class="stat-label">รอดำเนินการ</div></div>
                    <div class="stat-card"><div class="stat-number"><?php echo count(array_filter($all_orders, function($order) { return $order['buypd_status'] === 'printed'; })); ?></div><div class="stat-label">พิมพ์แล้ว</div></div>
                    <div class="stat-card"><div class="stat-number"><?php echo count(array_filter($all_orders, function($order) { return $order['buypd_status'] === 'received'; })); ?></div><div class="stat-label">รับสินค้าแล้ว</div></div>
                </div>
            </div>
            
            <?php if (!empty($low_stock_products)): ?>
            <div class="suggestion-box" id="low-stock-suggestion">
                <h3>⚠️ รายการแนะนำให้สั่งซื้อ (สินค้าใกล้หมด)</h3>
                <table>
                    <thead><tr><th>รหัสสินค้า</th><th>ชื่อสินค้า</th><th style="text-align: center;">จำนวนคงเหลือ</th><th>หน่วย</th></tr></thead>
                    <tbody><?php foreach($low_stock_products as $item): ?><tr><td><?php echo htmlspecialchars($item['pd_ID']); ?></td><td><?php echo htmlspecialchars($item['pd_name']); ?></td><td style="text-align: center; color: red; font-weight: bold;"><?php echo htmlspecialchars($item['pd_number']); ?></td><td style="text-align: center;"><?php echo htmlspecialchars($item['pd_unit']); ?></td></tr><?php endforeach; ?></tbody>
                </table>
                <button onclick="createPrefilledOrder()" class="btn btn-warning" id="prefillBtn">สร้างใบสั่งซื้อจากรายการนี้</button>
            </div>
            <?php endif; ?>

            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <h2 class="page-header">รายการใบสั่งซื้อ</h2>
                <div class="controls"><a href="create_order.php" class="btn btn-success">+ สร้างใบสั่งซื้อใหม่</a><button onclick="exportToExcel()" class="btn btn-excel" id="excelBtn">📊 Export to Excel</button></div>
            </div>

            <div class="nav-tabs">
                <div class="nav-tab active" onclick="switchTab('pending')">รอดำเนินการ (<?php echo count($orders); ?>)</div>
                <div class="nav-tab" onclick="switchTab('all')">ประวัติทั้งหมด (<?php echo count($all_history_orders); ?>)</div>
            </div>

            <div id="pending-tab" class="tab-content active">
                <?php if (!empty($orders)): ?>
                <table>
                    <thead><tr><th>เลขที่ใบสั่งซื้อ</th><th>วันที่</th><th>สั่งซื้อจากร้านค้า</th><th>ผู้จัดทำ</th><th style="text-align: right;">ยอดรวม (บาท)</th><th>สถานะ</th><th>การกระทำ</th></tr></thead>
                    <tbody>
                        <?php foreach ($orders as $order): ?>
                        <tr>
                            <td><strong class="order-id"><?php echo htmlspecialchars($order['buypd_ID']); ?></strong></td>
                            <td><?php echo date("d/m/Y", strtotime($order['buypd_date'])); ?></td>
                            <td><?php echo htmlspecialchars($order['shop_name'] ?? 'ไม่ระบุ'); ?></td>
                            <td><?php echo htmlspecialchars($order['user_name'] ?? 'ไม่ระบุ'); ?></td>
                            <td style="text-align: right; font-weight: bold;">฿<?php echo number_format($order['buypd_total'], 2); ?></td>
                            <td>
                                <?php
                                $status_class = 'status-pending'; $status_text = 'รอดำเนินการ';
                                if (!empty($order['buypd_status'])) {
                                    switch($order['buypd_status']) {
                                        case 'printed': $status_class = 'status-printed'; $status_text = 'พิมพ์แล้ว'; break;
                                        case 'received': $status_class = 'status-received'; $status_text = 'รับสินค้าแล้ว'; break;
                                        case 'cancelled': $status_class = 'status-cancelled'; $status_text = 'ยกเลิก'; break;
                                    }
                                }
                                ?>
                                <span class="status-badge <?php echo $status_class; ?>"><?php echo $status_text; ?></span>
                            </td>
                            <td class="action-buttons">
                                <a href="view_order.php?id=<?php echo $order['buypd_ID']; ?>" class="btn btn-info">รายละเอียด</a>
                                <a href="javascript:void(0);" class="btn btn-danger" onclick="confirmDelete('<?php echo htmlspecialchars($order['buypd_ID']); ?>')">ลบ</a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php else: ?>
                <div style="text-align: center; padding: 40px; background: white; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1);"><h3 style="color: #666; margin-bottom: 20px;">ไม่มีใบสั่งซื้อรอดำเนินการ</h3><p style="color: #888; margin-bottom: 20px;">เริ่มต้นสร้างใบสั่งซื้อใหม่ของคุณ</p><a href="create_order.php" class="btn btn-success" style="font-size: 18px; padding: 12px 30px;">+ สร้างใบสั่งซื้อแรก</a></div>
                <?php endif; ?>
            </div>

            <div id="all-tab" class="tab-content">
                <?php if (!empty($all_history_orders)): ?>
                <table>
                    <thead><tr><th>เลขที่ใบสั่งซื้อ</th><th>วันที่</th><th>สั่งซื้อจากร้านค้า</th><th>ผู้จัดทำ</th><th style="text-align: right;">ยอดรวม (บาท)</th><th>สถานะ</th><th>การกระทำ</th></tr></thead>
                    <tbody>
                        <?php foreach ($all_history_orders as $order): ?>
                        <tr>
                            <td><strong class="order-id"><?php echo htmlspecialchars($order['buypd_ID']); ?></strong></td>
                            <td><?php echo date("d/m/Y", strtotime($order['buypd_date'])); ?></td>
                            <td><?php echo htmlspecialchars($order['shop_name'] ?? 'ไม่ระบุ'); ?></td>
                            <td><?php echo htmlspecialchars($order['user_name'] ?? 'ไม่ระบุ'); ?></td>
                            <td style="text-align: right; font-weight: bold;">฿<?php echo number_format($order['buypd_total'], 2); ?></td>
                            <td>
                                <?php
                                $status_class = 'status-pending'; $status_text = 'รอดำเนินการ';
                                if (!empty($order['buypd_status'])) {
                                    switch($order['buypd_status']) {
                                        case 'printed': $status_class = 'status-printed'; $status_text = 'พิมพ์แล้ว'; break;
                                        case 'received': $status_class = 'status-received'; $status_text = 'รับสินค้าแล้ว'; break;
                                        case 'cancelled': $status_class = 'status-cancelled'; $status_text = 'ยกเลิก'; break;
                                    }
                                }
                                ?>
                                <span class="status-badge <?php echo $status_class; ?>"><?php echo $status_text; ?></span>
                            </td>
                            <td class="action-buttons">
                                <a href="view_order.php?id=<?php echo $order['buypd_ID']; ?>" class="btn btn-info">รายละเอียด</a>
                                <a href="javascript:void(0);" class="btn btn-danger" onclick="confirmDelete('<?php echo htmlspecialchars($order['buypd_ID']); ?>')">ลบ</a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php else: ?>
                <div style="text-align: center; padding: 40px; background: white; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1);"><h3 style="color: #666;">ยังไม่มีประวัติใบสั่งซื้อ</h3></div>
                <?php endif; ?>
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
                    <?php foreach ($low_stock_products as $item): ?><div class="notification-item"><strong><?php echo htmlspecialchars($item['pd_name']); ?></strong> (เหลือ <?php echo $item['pd_number']; ?> <?php echo htmlspecialchars($item['pd_unit']); ?>)</div><?php endforeach; ?>
                <?php else: ?><div class="no-notifications">ไม่มีสินค้าใกล้หมด</div><?php endif; ?>
            </div>
            <div class="notification-section">
                <h3>📥 สินค้าเข้าใหม่ (<?php echo $recent_received_count; ?> รายการ)</h3>
                <?php if ($recent_received_count > 0): ?>
                    <?php foreach ($recent_received_orders as $order): ?><div class="notification-item"><strong>ใบสั่งซื้อ: <?php echo htmlspecialchars($order['buypd_ID']); ?></strong> จาก <?php echo htmlspecialchars($order['shop_name']); ?> (<?php echo date("d/m/Y", strtotime($order['buypd_date'])); ?>) - ฿<?php echo number_format($order['buypd_total'], 2); ?></div><?php endforeach; ?>
                <?php else: ?><div class="no-notifications">ไม่มีสินค้าเข้าใหม่ใน 7 วันที่ผ่านมา</div><?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        function confirmDelete(buypdId) {
            Swal.fire({
                title: 'ยืนยันการลบ',
                html: `คุณแน่ใจว่าต้องการลบใบสั่งซื้อ<br>"<strong>${buypdId}</strong>" หรือไม่?`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#dc3545',
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'ยืนยัน',
                cancelButtonText: 'ยกเลิก',
                customClass: {
                    popup: 'swal2-popup',
                    title: 'swal2-title',
                    htmlContainer: 'swal2-html-container',
                    confirmButton: 'swal2-confirm',
                    cancelButton: 'swal2-cancel'
                }
            }).then((result) => {
                if (result.isConfirmed) {
                    // แสดง loading ก่อนลบ
                    Swal.fire({
                        title: 'กำลังลบ...',
                        text: 'กรุณารอสักครู่',
                        allowOutsideClick: false,
                        didOpen: () => {
                            Swal.showLoading();
                        }
                    });

                    // ส่งคำขอลบไปยังเซิร์ฟเวอร์
                    fetch(`delete_order.php?id=${buypdId}`, {
                        method: 'GET',
                        headers: {
                            'Content-Type': 'application/json',
                        }
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            Swal.fire({
                                title: 'ลบสำเร็จ!',
                                text: data.message || 'ลบใบสั่งซื้อเรียบร้อยแล้ว',
                                icon: 'success',
                                confirmButtonColor: '#28a745',
                                confirmButtonText: 'ตกลง'
                            }).then(() => {
                                // รีเฟรชหน้าเว็บหลังจากลบสำเร็จ
                                window.location.reload();
                            });
                        } else {
                            Swal.fire({
                                title: 'ลบไม่สำเร็จ!',
                                text: data.message || 'เกิดข้อผิดพลาดในการลบ',
                                icon: 'error',
                                confirmButtonColor: '#dc3545',
                                confirmButtonText: 'ตกลง'
                            });
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        Swal.fire({
                            title: 'ข้อผิดพลาด!',
                            text: 'เกิดข้อผิดพลาดในการเชื่อมต่อกับเซิร์ฟเวอร์',
                            icon: 'error',
                            confirmButtonColor: '#dc3545',
                            confirmButtonText: 'ตกลง'
                        });
                    });
                }
            });
        }

        function createPrefilledOrder() {
            const prefillBtn = document.getElementById('prefillBtn');
            const originalText = prefillBtn.innerHTML;
            
            Swal.fire({
                title: 'สร้างใบสั่งซื้อ?',
                text: "คุณต้องการสร้างใบสั่งซื้อจากรายการสินค้าใกล้หมดทั้งหมดใช่หรือไม่?",
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#c0392b',
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'ใช่, สร้างเลย!',
                cancelButtonText: 'ยกเลิก'
            }).then((result) => {
                if (result.isConfirmed) {
                    prefillBtn.innerHTML = '⏳ กำลังเตรียมข้อมูล...';
                    prefillBtn.classList.add('loading');
                    
                    fetch('save_low_stock_to_session.php')
                        .then(response => response.json())
                        .then(data => {
                            if (data.status === 'success') {
                                window.location.href = 'create_order.php?prefilled=true&count=' + data.count;
                            } else {
                                Swal.fire('เกิดข้อผิดพลาด!', data.message || 'ไม่สามารถสร้างใบสั่งซื้อได้', 'error');
                                prefillBtn.innerHTML = originalText;
                                prefillBtn.classList.remove('loading');
                            }
                        })
                        .catch(error => {
                            console.error('Error:', error);
                            Swal.fire('ผิดพลาด!', 'เกิดข้อผิดพลาดในการเชื่อมต่อกับเซิร์ฟเวอร์', 'error');
                            prefillBtn.innerHTML = originalText;
                            prefillBtn.classList.remove('loading');
                        });
                }
            });
        }

        // --- JavaScript ส่วนที่เหลือ (เหมือนเดิม) ---
        function exportToExcel() {
            const excelBtn = document.getElementById('excelBtn');
            const originalText = excelBtn.innerHTML;
            excelBtn.innerHTML = '⏳ กำลังสร้างไฟล์...';
            excelBtn.classList.add('loading');
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = 'export_orders.php';
            document.body.appendChild(form);
            form.submit();
            document.body.removeChild(form);
            setTimeout(() => {
                excelBtn.innerHTML = originalText;
                excelBtn.classList.remove('loading');
            }, 3000);
        }
        function scrollToSuggestions() {
            const suggestionBox = document.getElementById('low-stock-suggestion');
            if (suggestionBox) {
                suggestionBox.scrollIntoView({ behavior: 'smooth', block: 'start' });
            } else {
                Swal.fire('ไม่มีสินค้า!', 'ไม่มีสินค้าใกล้หมดในขณะนี้', 'info');
            }
        }
        function toggleDropdown() {
            document.getElementById("userDropdown").classList.toggle("show");
        }
        function switchTab(tabName) {
            document.querySelectorAll('.tab-content').forEach(tab => tab.classList.remove('active'));
            document.querySelectorAll('.nav-tab').forEach(tab => tab.classList.remove('active'));
            event.currentTarget.classList.add('active');
            document.getElementById(tabName + '-tab').classList.add('active');
        }
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