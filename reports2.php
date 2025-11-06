<?php
session_start();

// ตรวจสอบการล็อกอิน
if(!isset($_SESSION['user_username'])) {
    header("Location: login.php");
    exit();
}

// เชื่อมต่อฐานข้อมูล
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
$user_data = $stmt_user->get_result()->fetch_assoc();
$user_username = $user_data['user_username'] ?? 'ผู้ใช้';
$user_type = $user_data['user_type'] ?? ' ';
$stmt_user->close();

// === ดึงข้อมูลสำหรับแจ้งเตือน ===

// 1. สินค้าใกล้หมด (ต่ำกว่าจุดสั่งซื้อใหม่)
$low_stock_products = [];
$low_stock_sql = "SELECT pd_ID, pd_name, pd_number, pd_unit FROM product WHERE pd_number <= 10 AND pd_number > 0 ORDER BY pd_number ASC";
$low_stock_result = $conn->query($low_stock_sql);
if ($low_stock_result->num_rows > 0) {
    while($row = $low_stock_result->fetch_assoc()) {
        $low_stock_products[] = $row;
    }
}
$low_stock_count = count($low_stock_products);

// 2. สินค้าเข้าใหม่ (ใบสั่งซื้อที่ได้รับใน 7 วันที่ผ่านมา)
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

// === ดึงข้อมูลสำหรับแต่ละรายงาน ===

// 1. รายงานสต็อกสินค้าทั้งหมด
$stock_report_data = [];
$stock_query = $conn->query("SELECT p.pd_ID, p.pd_name, t.typepd_name, p.pd_number, p.pd_unit, 
    p.pd_costprice, (p.pd_number * p.pd_costprice) as item_value
    FROM product p
    LEFT JOIN typepd t ON p.typepd_ID = t.typepd_ID
    ORDER BY p.pd_ID ASC");
while($row = $stock_query->fetch_assoc()){
    $stock_report_data[] = $row;
}

// 2. รายงานการรับสินค้าเข้าตามช่วงเวลา
$start_date = isset($_GET['start_date']) && !empty($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-01');
$end_date = isset($_GET['end_date']) && !empty($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-t');
$received_report_data = [];
$received_stmt = $conn->prepare("
    SELECT b.buypd_ID, b.buypd_date, s.shop_name, u.user_name, b.buypd_total
    FROM buypd b
    JOIN shop s ON b.shop_ID = s.shop_ID
    JOIN user u ON b.user_ID = u.user_ID
    WHERE b.buypd_status = 'received' AND b.buypd_date BETWEEN ? AND ?
    ORDER BY b.buypd_date DESC
");
$received_stmt->bind_param("ss", $start_date, $end_date);
$received_stmt->execute();
$received_result = $received_stmt->get_result();
while($row = $received_result->fetch_assoc()){
    $received_report_data[] = $row;
}
$received_stmt->close();

// 3. รายงานผู้ใช้
$user_report_data = [];
$user_query = $conn->query("
    SELECT u.user_ID, u.user_name, u.user_position, u.user_type, COUNT(b.buypd_ID) as po_count
    FROM user u
    LEFT JOIN buypd b ON u.user_ID = b.user_ID
    GROUP BY u.user_ID, u.user_name, u.user_position, u.user_type
    ORDER BY u.user_ID ASC
");
while($row = $user_query->fetch_assoc()){
    $user_report_data[] = $row;
}

// 4. รายงานประเภทสินค้า
$type_report_data = [];
$type_query = $conn->query("
    SELECT t.typepd_ID, t.typepd_name, COUNT(p.pd_ID) as product_sku_count, SUM(p.pd_number) as total_stock
    FROM typepd t
    LEFT JOIN product p ON t.typepd_ID = p.typepd_ID
    GROUP BY t.typepd_ID, t.typepd_name
    ORDER BY t.typepd_ID ASC
");
while($row = $type_query->fetch_assoc()){
    $type_report_data[] = $row;
}

// 5. รายงานสินค้าที่จ่ายออก (ขายไป)
$outgoing_start_date = isset($_GET['outgoing_start_date']) && !empty($_GET['outgoing_start_date']) ? $_GET['outgoing_start_date'] : date('Y-m-01');
$outgoing_end_date = isset($_GET['outgoing_end_date']) && !empty($_GET['outgoing_end_date']) ? $_GET['outgoing_end_date'] : date('Y-m-t');
$outgoing_report_data = [];
$outgoing_stmt = $conn->prepare("
    SELECT 
        o.order_id,
        o.order_date,
        o.customer_name,
        o.total_amount,
        o.order_status,
        od.product_id,
        p.pd_name,
        od.quantity,
        od.price_per_unit,
        (od.quantity * od.price_per_unit) as line_total
    FROM orders o
    JOIN order_details od ON o.order_id = od.order_id
    JOIN product p ON od.product_id = p.pd_ID
    WHERE o.order_date BETWEEN ? AND ?
    ORDER BY o.order_date DESC, o.order_id DESC
");
$outgoing_stmt->bind_param("ss", $outgoing_start_date, $outgoing_end_date);
$outgoing_stmt->execute();
$outgoing_result = $outgoing_stmt->get_result();
while($row = $outgoing_result->fetch_assoc()){
    $outgoing_report_data[] = $row;
}
$outgoing_stmt->close();

// สรุปยอดขายรวม
$sales_summary = [];
$summary_stmt = $conn->prepare("
    SELECT 
        SUM(o.total_amount) as total_sales,
        COUNT(DISTINCT o.order_id) as total_orders,
        SUM(od.quantity) as total_items_sold
    FROM orders o
    JOIN order_details od ON o.order_id = od.order_id
    WHERE o.order_date BETWEEN ? AND ?
");
$summary_stmt->bind_param("ss", $outgoing_start_date, $outgoing_end_date);
$summary_stmt->execute();
$summary_result = $summary_stmt->get_result();
$sales_summary = $summary_result->fetch_assoc();
$summary_stmt->close();

$conn->close();
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>รายงาน</title>
    <link href="https://fonts.googleapis.com/css2?family=Kanit:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
        * { 
            margin: 0; 
            padding: 0; 
            box-sizing: border-box; 
        }
        
        body {
            background-color: #f8f9fa;
            padding-top: 84px;
            padding-left: 250px;
        }

        /* ========== Header Styles ========== */
        .top-header { 
            font-family: 'Microsoft Sans Serif'; 
            display: flex; 
            justify-content: space-between; 
            align-items: center; 
            border: 2.6px solid #ffffff; 
            padding: 10px 20px; 
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1); 
            background-color: #fff; 
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 84px;
            z-index: 1000;
        }

        .logo-section {
            display: flex;
            align-items: center;
            gap: 0px;
        }

        .brand-name {
            font-weight: bold;
            font-size: 25px;
        }

        .user-section {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .user-info {
            text-align: right;
        }

        .user-username {
            font-size: 16px;
            font-weight: bold;
        }

        .user-role {
            font-size: 16px;
            color: #666;
            font-weight: bold;
        }

        .user-avatar {
            font-size: 25px;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: #f0f0f0;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
        }

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

        .dropdown { 
            position: relative; 
            display: inline-block; 
        }

        .dropdown-content { 
            display: none; 
            position: absolute; 
            right: 0;
            margin-top: 8px;
            background-color: #f9f9f9; 
            min-width: 160px; 
            box-shadow: 0px 8px 16px 0px rgba(0,0,0,0.2); 
            z-index: 1001; 
            border-radius: 4px; 
            overflow: hidden; 
        }

        .dropdown-content a { 
            color: black; 
            padding: 12px 16px; 
            text-decoration: none; 
            display: block; 
            font-size: 14px; 
            font-weight: bold;
        }

        .dropdown-content a:hover { 
            background-color: #f1f1f1; 
        }

        .dropdown-content .logout { 
            color: #e74c3c !important; 
        }

        .show { 
            display: block; 
        }

        .main-container { 
            display: flex; 
            min-height: calc(100vh - 84px); 
        }

        /* ========== Sidebar Styles ========== */
        .sidebar { 
            width: 250px; 
            background: #ffffff; 
            padding: 0; 
            box-shadow: 2px 0 15px rgba(0,0,0,0.1); 
            position: fixed;
            top: 84px;
            left: 0;
            height: calc(100vh - 84px);
            overflow-y: auto;
            z-index: 999;
        }

        .menu-item { 
            display: flex; 
            align-items: center; 
            gap: 1rem; 
            padding: 1rem 2rem; 
            color: #000000; 
            text-decoration: none; 
            transition: all 0.3s ease; 
        }

        .menu-item span { 
            flex: 1; 
            text-align: left; 
            font-size: 18px; 
        }

        .menu-item:hover { 
            background: rgba(244, 212, 199, 0.5);
        }

        .menu-item.active { 
            background: linear-gradient(90deg, #f4d4c7 0%, #edd5c7 100%);
        }

        .menu-item.active span, .menu-item.active div { 
            color: #A44A3F; 
        }

        .icon-medium { 
            font-size: 25px; 
        }

        /* ========== Content Styles ========== */
        .content { 
            flex: 1; 
            padding: 40px; 
            background-color: #f8f9fa; 
            width: calc(100% - 250px);
            margin-left: 0;
        }

        .page-header { 
            color: #A44A3F; 
            font-size: 30px; 
            font-weight: bold; 
            margin-bottom: 30px; 
            text-align: left; 
        }

        /* ========== Tab Styles ========== */
        .tab-nav { 
            display: flex; 
            border-bottom: 2px solid #dee2e6; 
            margin-bottom: 20px; 
        }

        .tab-link { 
            padding: 10px 20px; 
            cursor: pointer; 
            border: none; 
            background: none; 
            font-size: 16px; 
            transition: all 0.2s; 
            border-bottom: 3px solid transparent; 
        }

        .tab-link.active { 
            color: #A44A3F; 
            border-bottom-color: #A44A3F; 
            font-weight: 600; 
        }

        .tab-content { 
            display: none; 
        }

        .tab-content.active { 
            display: block; 
        }

        /* ========== Report Styles ========== */
        .report-section { 
            background: #fff; 
            padding: 25px; 
            border-radius: 8px; 
            box-shadow: 0 4px 15px rgba(0,0,0,0.07); 
        }

        .report-header { 
            display: flex; 
            justify-content: space-between; 
            align-items: center; 
            margin-bottom: 20px; 
        }

        .report-header h3 { 
            font-size: 20px; 
            color: #A44A3F; 
        }

        .report-actions { 
            display: flex; 
            gap: 10px; 
        }

        .btn-print { 
            background-color: #3498db; 
            color: white; 
            padding: 8px 15px; 
            border-radius: 5px; 
            cursor: pointer; 
            border:none; 
        }

        table { 
            width: 100%; 
            border-collapse: collapse; 
        }

        th, td { 
            padding: 12px; 
            text-align: left; 
            border-bottom: 1px solid #dee2e6; 
        }

        th { 
            background-color: #f8f9fa; 
            font-weight: 500; 
        }

        .text-right { 
            text-align: right; 
        }

        .text-center { 
            text-align: center; 
        }

        .filter-container { 
            background: #fff; 
            padding: 20px; 
            border-radius: 8px; 
            box-shadow: 0 2px 10px rgba(0,0,0,0.05); 
            margin-bottom: 20px; 
            display: flex; 
            align-items: center; 
            gap: 15px; 
            font-family: 'Kanit', sans-serif;
        }

        .btn-primary { 
            background-color: #A44A3F; 
            color: white; 
            padding: 8px 15px; 
            border: none; 
            border-radius: 5px; 
            cursor: pointer; 
        }

        .summary-box {
            background: #f8f9fa;
            border-left: 4px solid #A44A3F;
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 4px;
        }

        .summary-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }

        .summary-item {
            background: white;
            padding: 15px;
            border-radius: 8px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
            text-align: center;
        }

        .summary-value {
            font-size: 24px;
            font-weight: bold;
            color: #A44A3F;
        }

        .summary-label {
            font-size: 14px;
            color: #666;
            margin-top: 5px;
        }
        
        /* ========== Modal Styles ========== */
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
        
        /* ========== Print Styles ========== */
        @media print {
            .no-print { 
                display: none !important; 
            }
            body, .main-container { 
                display: block; 
            }
            .content { 
                padding: 0; 
            }
            .tab-content { 
                display: none !important; 
            }
            .tab-content.active { 
                display: block !important; 
            }
            .report-section { 
                box-shadow: none; 
                border: 1px solid #ddd; 
            }
            body * { 
                visibility: hidden; 
            }
            .print-area, .print-area * { 
                visibility: visible; 
            }
            .print-area { 
                position: absolute; 
                left: 0; 
                top: 0; 
                width: 100%; 
            }
        }

        /* ========== Responsive Styles ========== */
        @media (max-width: 768px) {
            body {
                padding-top: 84px;
                padding-left: 0;
            }
            
            .main-container {
                flex-direction: column;
            }
            .sidebar {
                width: 100%;
                height: auto;
                box-shadow: 0 2px 5px rgba(0,0,0,0.1);
                position: relative;
                top: 0;
            }
            .content {
                padding: 20px;
                width: 100%;
            }
            .tab-nav {
                flex-direction: column;
            }
            .tab-link {
                text-align: left;
            }
            .filter-container {
                flex-direction: column;
                align-items: stretch;
            }
            .report-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 15px;
            }
            table {
                font-size: 14px;
            }
            .notification-modal-content {
                width: 95%;
                padding: 15px;
            }
            .summary-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="top-header">
        <div class="logo-section">
            <img src="logo1.png" width="50" height="50">
            <span class="brand-name">Chutikarn</span>
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
                    <a href="settings2.php">ข้อมูลส่วนตัว</a>
                    <a href="Login.php" class="logout">Logout</a>
                </div>
            </div>
            
            <div class="user-info">
                <div class="user-username"><?php echo htmlspecialchars($user_username); ?></div>
                <div class="user-role"><?php echo htmlspecialchars($user_type); ?></div>
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

    <div class="main-container">
        <div class="sidebar">
            <a href="Dashboard2.php" class="menu-item"><div class="icon-medium">&#127968;</div><span>หน้าแรก</span></a>
            <a href="receive_stock2.php" class="menu-item"><div class="icon-medium">&#128229;</div><span>รับสินค้าเข้า</span></a>
            <a href="reports2.php" class="menu-item active"><div class="icon-medium">&#128200;</div><span>รายงาน</span></a>
            <a href="settings2.php" class="menu-item"><div class="icon-medium">⚙️</div><span>ตั้งค่าระบบ</span></a>
        </div>

        <main class="content">
            <h2 class="page-header">รายงาน</h2>

            <div class="tab-nav no-print">
                <button class="tab-link active" onclick="openTab(event, 'stock')">รายงานสต็อกสินค้าทั้งหมด</button>
                <button class="tab-link" onclick="openTab(event, 'received')">รายงานการรับสินค้าเข้า</button>
                <button class="tab-link" onclick="openTab(event, 'outgoing')">รายงานสินค้าที่จ่ายออก</button>
                <button class="tab-link" onclick="openTab(event, 'user')">รายงานผู้ใช้</button>
                <button class="tab-link" onclick="openTab(event, 'type')">รายงานประเภทสินค้า</button>
            </div>

            <div id="stock" class="tab-content active">
                <section class="report-section print-area">
                    <div class="report-header">
                        <h3>รายงานสต็อกสินค้าทั้งหมด</h3>
                        <div class="report-actions no-print">
                            <button class="btn-print" onclick="window.print()">พิมพ์</button>
                        </div>
                    </div>
                    <table>
                        <thead>
                            <tr>
                                <th>รหัสสินค้า</th>
                                <th>ชื่อสินค้า</th>
                                <th>ประเภท</th>
                                <th class="text-right">จำนวน</th>
                                <th>หน่วย</th>
                                <th class="text-right">ต้นทุน/หน่วย</th>
                                <th class="text-right">มูลค่ารวม</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($stock_report_data as $item): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($item['pd_ID']); ?></td>
                                <td><?php echo htmlspecialchars($item['pd_name']); ?></td>
                                <td><?php echo htmlspecialchars($item['typepd_name'] ?? 'N/A'); ?></td>
                                <td class="text-right"><?php echo number_format($item['pd_number']); ?></td>
                                <td><?php echo htmlspecialchars($item['pd_unit']); ?></td>
                                <td class="text-right">฿<?php echo number_format($item['pd_costprice'], 2); ?></td>
                                <td class="text-right">฿<?php echo number_format($item['item_value'], 2); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </section>
            </div>

            <div id="received" class="tab-content">
                <form method="GET" class="filter-container no-print">
                    <label>ตั้งแต่วันที่:</label>
                    <input type="date" name="start_date" value="<?php echo htmlspecialchars($start_date); ?>">
                    <label>ถึงวันที่:</label>
                    <input type="date" name="end_date" value="<?php echo htmlspecialchars($end_date); ?>">
                    <button type="submit" class="btn-primary">กรองข้อมูล</button>
                </form>
                <section class="report-section print-area">
                    <div class="report-header">
                        <h3>รายงานการรับสินค้าเข้า (<?php echo date('d/m/Y', strtotime($start_date)) . ' - ' . date('d/m/Y', strtotime($end_date)); ?>)</h3>
                        <div class="report-actions no-print">
                            <button class="btn-print" onclick="window.print()">พิมพ์</button>
                        </div>
                    </div>
                    <table>
                        <thead>
                            <tr>
                                <th>เลขที่ใบสั่งซื้อ</th>
                                <th>วันที่รับ</th>
                                <th>ร้านค้า</th>
                                <th>ผู้รับ</th>
                                <th class="text-right">ยอดรวม (บาท)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($received_report_data)): ?>
                                <?php foreach ($received_report_data as $item): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($item['buypd_ID']); ?></td>
                                    <td><?php echo date("d/m/Y", strtotime($item['buypd_date'])); ?></td>
                                    <td><?php echo htmlspecialchars($item['shop_name']); ?></td>
                                    <td><?php echo htmlspecialchars($item['user_name']); ?></td>
                                    <td class="text-right">฿<?php echo number_format($item['buypd_total'], 2); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="5" style="text-align:center;">ไม่พบข้อมูลการรับสินค้าในช่วงวันที่นี้</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </section>
            </div>

            <div id="outgoing" class="tab-content">
                <form method="GET" class="filter-container no-print">
                    <label>ตั้งแต่วันที่:</label>
                    <input type="date" name="outgoing_start_date" value="<?php echo htmlspecialchars($outgoing_start_date); ?>">
                    <label>ถึงวันที่:</label>
                    <input type="date" name="outgoing_end_date" value="<?php echo htmlspecialchars($outgoing_end_date); ?>">
                    <button type="submit" class="btn-primary">กรองข้อมูล</button>
                </form>
                <section class="report-section print-area">
                    <div class="report-header">
                        <h3>รายงานสินค้าที่จ่ายออก (<?php echo date('d/m/Y', strtotime($outgoing_start_date)) . ' - ' . date('d/m/Y', strtotime($outgoing_end_date)); ?>)</h3>
                        <div class="report-actions no-print">
                            <button class="btn-print" onclick="window.print()">พิมพ์</button>
                        </div>
                    </div>
                    
                    <div class="summary-grid no-print">
                        <div class="summary-item">
                            <div class="summary-value">฿<?php echo number_format($sales_summary['total_sales'] ?? 0, 2); ?></div>
                            <div class="summary-label">ยอดขายรวม</div>
                        </div>
                        <div class="summary-item">
                            <div class="summary-value"><?php echo number_format($sales_summary['total_orders'] ?? 0); ?></div>
                            <div class="summary-label">จำนวนคำสั่งซื้อ</div>
                        </div>
                        <div class="summary-item">
                            <div class="summary-value"><?php echo number_format($sales_summary['total_items_sold'] ?? 0); ?></div>
                            <div class="summary-label">จำนวนสินค้าที่ขาย</div>
                        </div>
                    </div>
                    
                    <table>
                        <thead>
                            <tr>
                                <th>เลขที่คำสั่งซื้อ</th>
                                <th>วันที่ขาย</th>
                                <th>ลูกค้า</th>
                                <th>รหัสสินค้า</th>
                                <th>ชื่อสินค้า</th>
                                <th class="text-right">จำนวน</th>
                                <th class="text-right">ราคาต่อหน่วย</th>
                                <th class="text-right">ยอดรวม</th>
                                <th class="text-center">สถานะ</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($outgoing_report_data)): ?>
                                <?php 
                                $current_order = null;
                                foreach ($outgoing_report_data as $item): 
                                    if ($current_order !== $item['order_id']):
                                        $current_order = $item['order_id'];
                                ?>
                                <tr style="background-color: #f8f9fa;">
                                    <td colspan="9" style="font-weight: bold; color: #A44A3F;">
                                        คำสั่งซื้อ #<?php echo $item['order_id']; ?> - ยอดรวม: ฿<?php echo number_format($item['total_amount'], 2); ?>
                                    </td>
                                </tr>
                                <?php endif; ?>
                                <tr>
                                    <td></td>
                                    <td><?php echo date("d/m/Y H:i", strtotime($item['order_date'])); ?></td>
                                    <td><?php echo htmlspecialchars($item['customer_name']); ?></td>
                                    <td><?php echo htmlspecialchars($item['product_id']); ?></td>
                                    <td><?php echo htmlspecialchars($item['pd_name']); ?></td>
                                    <td class="text-right"><?php echo number_format($item['quantity']); ?></td>
                                    <td class="text-right">฿<?php echo number_format($item['price_per_unit'], 2); ?></td>
                                    <td class="text-right">฿<?php echo number_format($item['line_total'], 2); ?></td>
                                    <td class="text-center">
                                        <span style="padding: 4px 8px; border-radius: 4px; font-size: 12px; 
                                            background-color: <?php 
                                                switch($item['order_status']) {
                                                    case 'completed': echo '#d4edda'; break;
                                                    case 'pending': echo '#fff3cd'; break;
                                                    case 'cancelled': echo '#f8d7da'; break;
                                                    default: echo '#e2e3e5';
                                                }
                                            ?>; 
                                            color: <?php 
                                                switch($item['order_status']) {
                                                    case 'completed': echo '#155724'; break;
                                                    case 'pending': echo '#856404'; break;
                                                    case 'cancelled': echo '#721c24'; break;
                                                    default: echo '#383d41';
                                                }
                                            ?>;">
                                            <?php 
                                                switch($item['order_status']) {
                                                    case 'completed': echo 'สำเร็จ'; break;
                                                    case 'pending': echo 'รอดำเนินการ'; break;
                                                    case 'cancelled': echo 'ยกเลิก'; break;
                                                    default: echo $item['order_status'];
                                                }
                                            ?>
                                        </span>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="9" style="text-align:center;">ไม่พบข้อมูลการขายในช่วงวันที่นี้</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </section>
            </div>

            <div id="user" class="tab-content">
                 <section class="report-section print-area">
                    <div class="report-header">
                        <h3>รายงานผู้ใช้</h3>
                        <div class="report-actions no-print">
                            <button class="btn-print" onclick="window.print()">พิมพ์</button>
                        </div>
                    </div>
                    <table>
                        <thead>
                            <tr>
                                <th>รหัสผู้ใช้</th>
                                <th>ชื่อ-สกุล</th>
                                <th>ตำแหน่ง</th>
                                <th>ประเภท</th>
                                <th class="text-right">จำนวน PO ที่สร้าง</th>
                            </tr>
                        </thead>
                        <tbody>
                             <?php foreach ($user_report_data as $item): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($item['user_ID']); ?></td>
                                <td><?php echo htmlspecialchars($item['user_name']); ?></td>
                                <td><?php echo htmlspecialchars($item['user_position']); ?></td>
                                <td><?php echo htmlspecialchars($item['user_type']); ?></td>
                                <td class="text-right"><?php echo number_format($item['po_count']); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </section>
            </div>

            <div id="type" class="tab-content">
                 <section class="report-section print-area">
                    <div class="report-header">
                        <h3>รายงานประเภทสินค้า</h3>
                        <div class="report-actions no-print">
                            <button class="btn-print" onclick="window.print()">พิมพ์</button>
                        </div>
                    </div>
                    <table>
                        <thead>
                            <tr>
                                <th>รหัสประเภท</th>
                                <th>ชื่อประเภท</th>
                                <th class="text-right">จำนวนรายการ (SKU)</th>
                                <th class="text-right">จำนวนสินค้าทั้งหมด (ชิ้น)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($type_report_data as $item): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($item['typepd_ID']); ?></td>
                                <td><?php echo htmlspecialchars($item['typepd_name']); ?></td>
                                <td class="text-right"><?php echo number_format($item['product_sku_count']); ?></td>
                                <td class="text-right"><?php echo number_format($item['total_stock'] ?? 0); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </section>
            </div>
        </main>
    </div>

    <script>
        // ฟังก์ชันสำหรับแสดง Modal แจ้งเตือน
        function showNotificationModal() {
            document.getElementById("notificationModal").style.display = "block";
        }
        
        // ฟังก์ชันสำหรับปิด Modal แจ้งเตือน
        function closeNotificationModal() {
            document.getElementById("notificationModal").style.display = "none";
        }
        
        // ฟังก์ชันสำหรับ dropdown เมนูผู้ใช้
        function toggleDropdown() { 
            document.getElementById("userDropdown").classList.toggle("show"); 
        }
        
        // ปิด Modal และ Dropdown เมื่อคลิกนอกพื้นที่
        window.onclick = function(event) {
            var modal = document.getElementById("notificationModal");
            if (event.target == modal) {
                modal.style.display = "none";
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
        }
        
        // ฟังก์ชันสำหรับเปลี่ยนแท็บ
        function openTab(evt, tabName) {
            var i, tabcontent, tablinks;
            tabcontent = document.getElementsByClassName("tab-content");
            for (i = 0; i < tabcontent.length; i++) {
                tabcontent[i].classList.remove("active");
                tabcontent[i].style.display = "none"; // Hide all tabs
            }
            tablinks = document.getElementsByClassName("tab-link");
            for (i = 0; i < tablinks.length; i++) {
                tablinks[i].classList.remove("active");
            }
            
            // Show the current tab and add an "active" class
            const currentTab = document.getElementById(tabName);
            currentTab.style.display = "block";
            currentTab.classList.add("active");
            evt.currentTarget.classList.add("active");
        }

        // ทำให้แท็บแรกแสดงผลเมื่อโหลดหน้าเว็บ
        document.addEventListener("DOMContentLoaded", function() {
            document.querySelector('.tab-link').click();
        });
    </script>

</body>
</html>