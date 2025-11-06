<?php
session_start();

// 1. ตรวจสอบการล็อกอิน
if (!isset($_SESSION['user_username'])) {
    header("Location: login.php");
    exit();
}

// 2. เชื่อมต่อฐานข้อมูล
$servername = "localhost";
$username = "root";
$password = "12345678";
$dbname = "chutikarnceramic1";

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
$conn->set_charset("utf8"); // ตั้งค่า charset

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

// 3. ดึงข้อมูลผู้ใช้ที่ล็อกอิน (สำหรับ Header)
$user_username_session = $_SESSION['user_username'];
$sql_user = "SELECT user_username, user_type FROM user WHERE user_username = ?";
$stmt_user = $conn->prepare($sql_user);
$stmt_user->bind_param("s", $user_username_session);
$stmt_user->execute();
$result_user = $stmt_user->get_result();

$user_username = "ไม่พบข้อมูล";
$user_type = "ไม่พบข้อมูล";

if ($result_user->num_rows > 0) {
    $row_user = $result_user->fetch_assoc();
    $user_username = $row_user["user_username"];
    $user_type = $row_user["user_type"];
}
$stmt_user->close();

// 4. ดึงข้อมูลร้านค้าทั้งหมดจากตาราง shop
$sql_shops = "SELECT shop_ID, shop_name, shop_address, shop_tel FROM shop ORDER BY CAST(SUBSTRING(shop_ID, 2) AS UNSIGNED) ASC";
$result_shops = $conn->query($sql_shops);

?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>จัดการร้านค้า</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* ========== General Styles ========== */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            background-color: #f4f4f4;
            padding-top: 84px; /* เพิ่ม padding-top เท่ากับความสูงของ header */
            padding-left: 250px; /* เพิ่ม padding-left เท่ากับความกว้างของ sidebar */
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
            position: fixed; /* ทำให้ header คงที่ */
            top: 0;
            left: 0;
            width: 100%;
            height: 84px;
            z-index: 1000; /* ให้ header อยู่ด้านบนสุด */
        }

        /* ========== Layout ========== */
        .main-container {
            display: flex;
            min-height: calc(100vh - 84px); /* Adjusted for header height */
        }

        .sidebar {
            width: 250px;
            background: #ffffff;
            box-shadow: 2px 0 15px rgba(0,0,0,0.1);
            position: fixed; /* ทำให้ sidebar คงที่ */
            top: 84px; /* วางด้านล่าง header */
            left: 0;
            height: calc(100vh - 84px); /* ความสูงเท่ากับหน้าจอลบความสูง header */
            overflow-y: auto; /* ให้เลื่อนได้เมื่อมีเนื้อหาเกิน */
            z-index: 999; /* ให้อยู่ด้านล่าง header */
        }

        .content {
            flex: 1;
            padding: 2.5rem;
            position: relative;
            width: calc(100% - 250px); /* ปรับความกว้างของ content */
            margin-left: 0; /* ลบ margin-left */
        }
        
        .page-header {
            color: #A44A3F;
            font-size: 28px;
            margin-bottom: 2rem;
        }

        /* ========== Header & User Section ========== */
        .dropdown { position: relative; display: inline-block; }
        .dropdown-content {
            display: none; position: absolute; right: 0;
            background-color: #f9f9f9; min-width: 160px;
            box-shadow: 0px 8px 16px 0px rgba(0,0,0,0.2);
            z-index: 101; border-radius: 4px; overflow: hidden;
        }
        .dropdown-content a { color: black; padding: 12px 16px; text-decoration: none; display: block; font-size: 14px; font-weight: bold;}
        .dropdown-content a:hover { background-color: #f1f1f1; }
        .show { display: block; }
        .logout { color: #e74c3c !important; }

        .notification-container { position: relative; display: inline-block; }
        .bell-icon { font-size: 20px; cursor: pointer; }
        .notification-badge {
            position: absolute; top: -5px; right: -5px;
            background: #ff4444; color: white; border-radius: 50%;
            width: 18px; height: 18px; display: flex;
            align-items: center; justify-content: center;
            font-size: 12px; z-index: 10;
        }

        /* ========== Sidebar Menu ========== */
        .menu-item {
            display: flex; align-items: center; gap: 1rem;
            padding: 1rem 2rem; text-decoration: none;
            transition: all 0.3s ease; border-left: 4px solid transparent;
        }
        .menu-item span { flex: 1; text-align: left; font-size: 18px; color: #000; }
        .menu-item:hover { background: rgba(236, 236, 236, 0.4); transform: translateX(1.5px); }
        .menu-item.active { background: linear-gradient(90deg, #f4d4c7 0%, #edd5c7 100%); }
        .menu-item.active span, .menu-item.active .icon-medium { color: #A44A3F; }
        .icon-medium { font-size: 25px; color: #000; }

        /* ส่วนค้นหาและปุ่มเพิ่มร้านค้า */
        .shop-controls {
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
            padding: 12px 45px 12px 15px;
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
            transform: translateY(-50%) translateY(-1px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.3);
        }

        .search-btn:active {
            transform: translateY(-50%) translateY(1px);
            box-shadow: 0 1px 2px rgba(0,0,0,0.2);
        }

        .add-shop-btn {
            background: #c0392b;
            color: white;
            border: none;
            padding: 12px 20px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 16px;
            white-space: nowrap;
            transition: all 0.3s ease;
            box-shadow: 0 2px 4px rgba(0,0,0,0.2);
            position: relative;
            overflow: hidden;
        }

        .add-shop-btn:hover {
            background: #a93226;
            transform: translateY(-2px);
            box-shadow: 0 6px 12px rgba(0,0,0,0.3);
        }

        .add-shop-btn:active {
            transform: translateY(0);
            box-shadow: 0 2px 4px rgba(0,0,0,0.2);
        }

        .add-shop-btn::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 0;
            height: 0;
            background: rgba(255,255,255,0.3);
            border-radius: 50%;
            transform: translate(-50%, -50%);
            transition: width 0.6s, height 0.6s;
        }

        .add-shop-btn:active::before {
            width: 300px;
            height: 300px;
        }

        /* ========== Table Styles ========== */
        .shop-table-container {
            background: white;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
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
        
        /* ========== Action Buttons ========== */
        .action-buttons {
            display: flex;
            gap: 8px;
            justify-content: center;
        }
        .action-buttons a, .action-buttons button {
            text-decoration: none;
            padding: 8px 12px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
            transition: all 0.3s ease;
        }
        .edit-btn {
            background-color: #ffc107;
            color: #333;
        }
        .edit-btn:hover {
            background-color: #e0a800;
            color: #000;
        }
        .delete-btn {
            background-color: #dc3545;
            color: white;
        }
        .delete-btn:hover {
            background-color: #c82333;
        }

        /* ========== Empty State (เมื่อไม่มีข้อมูล) ========== */
        .empty-state { text-align: center; margin: 4rem auto; max-width: 500px; }
        .empty-state h3 { font-size: 24px; color: #333; margin-top: 1rem; }
        .empty-state p { color: #666; margin-top: 0.5rem; }
        .empty-state .icon-medium2 { font-size: 65px; color: #A44A3F; }
        .add-btn {
            display: block; width: 300px; padding: 14px; margin: 20px auto;
            background: linear-gradient(135deg, #e74c3c, #c0392b); color: white;
            border: none; border-radius: 8px; font-size: 18px; font-weight: 600;
            cursor: pointer; transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(50, 26, 60, 0.3);
        }
        .add-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(50, 26, 60, 0.4);
            background: linear-gradient(135deg, #c0392b, #a93226);
        }

        /* ========== Modal Styles ========== */
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

        .modal-content {
            background-color: #fff;
            padding: 30px;
            border-radius: 12px;
            width: 450px;
            max-width: 90%;
            text-align: center;
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
            color: #dc3545;
        }

        .modal-body {
            font-size: 16px;
            line-height: 1.5;
            margin-bottom: 25px;
            color: #333;
        }

        .modal-footer {
            margin-top: 20px;
            display: flex;
            justify-content: center;
            gap: 15px;
        }

        .modal-btn {
            padding: 12px 25px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 16px;
            font-weight: 500;
            transition: all 0.3s ease;
        }

        .modal-btn.submit {
            background-color: #dc3545;
            color: white;
        }

        .modal-btn.submit:hover {
            background-color: #c82333;
            transform: translateY(-1px);
        }

        .modal-btn.cancel {
            background-color: #6c757d;
            color: white;
        }

        .modal-btn.cancel:hover {
            background-color: #5a6268;
            transform: translateY(-1px);
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

        /* Responsive adjustments for smaller screens */
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
            .shop-controls {
                flex-direction: column;
                align-items: stretch;
            }
            .search-container {
                width: 100%;
            }
            .add-shop-btn {
                width: 100%;
            }
            table {
                min-width: 100%; /* Allow table to shrink more on small screens */
            }
            .modal-content {
                width: 95%;
                padding: 20px;
            }
        }

    </style>
</head>
<body>

    <div class="top-header">
        <div style="display: flex; align-items: center;">
            <img src="logo1.png" width="50" height="50" style="vertical-align: middle;">
            <span style="vertical-align: middle; font-size: 25px; font-weight: bold;">Chutikarn</span>
        </div>
        <div class="user-section" style="display: flex; align-items: center; gap: 20px;">
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
                <div class="user-role" style="font-size:16px; color: #666; font-weight: bold;"><?php echo htmlspecialchars($user_type); ?></div>
            </div>
        </div>
    </div>

    <div class="main-container">
        <div class="sidebar">
            <a href="Dashboard.php" class="menu-item"><div class="icon-medium">&#127968;</div><span>หน้าแรก</span></a>
            <a href="Manage_products.php" class="menu-item"><div class="icon-medium">&#128230;</div><span>จัดการสินค้า</span></a>
            <a href="manage_types.php" class="menu-item"><div class="icon-medium">&#128193;</div><span>จัดการประเภทสินค้า</span></a>
            <a href="receive_stock.php" class="menu-item"><div class="icon-medium">&#128229;</div><span>รับสินค้าเข้า</span></a>
            <a href="Order.php" class="menu-item"><div class="icon-medium">&#128722;</div><span>สั่งซื้อสินค้า</span></a>
            <a href="storage.php" class="menu-item"><div class="icon-medium">&#128190;</div><span>จัดเก็บสินค้า</span></a>
            <a href="Employee_Account.php" class="menu-item"><div class="icon-medium">&#128101;</div><span>จัดการพนักงาน</span></a>
            <a href="shop.php" class="menu-item active"><div class="icon-medium">&#127978;</div><span>จัดการร้านค้า</span></a>
            <a href="reports.php" class="menu-item"><div class="icon-medium">&#128200;</div><span>รายงาน</span></a>
            <a href="settings.php" class="menu-item"><div class="icon-medium">⚙️</div><span>ตั้งค่าระบบ</span></a>
        </div>

        <div class="content">
            <h2 class="page-header">จัดการร้านค้า</h2>
            <?php if ($result_shops && $result_shops->num_rows > 0): ?>
                <div class="shop-controls">
                    <div class="search-container">
                        <input type="text" id="searchInput" class="search-input" placeholder="ค้นหาร้านค้า..." onkeyup="searchShop()">
                        <button class="search-btn" onclick="searchShop()">ค้นหา</button>
                    </div>
                    <button class="add-shop-btn" onclick="window.location.href='Addshop.php'"><i class="fa fa-plus"></i> เพิ่มร้านค้าใหม่</button>
                </div>
                <div class="shop-table-container">
                    <table class="shop-table" id="shopTable">
                        <thead>
                            <tr>
                                <th>รหัสร้านค้า</th>
                                <th>ชื่อร้านค้า</th>
                                <th>ที่อยู่</th>
                                <th>เบอร์โทรศัพท์</th>
                                <th>การจัดการ</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while ($row = $result_shops->fetch_assoc()): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($row['shop_ID']); ?></td>
                                <td><?php echo htmlspecialchars($row['shop_name']); ?></td>
                                <td><?php echo htmlspecialchars($row['shop_address']); ?></td>
                                <td><?php echo htmlspecialchars($row['shop_tel']); ?></td>
                                <td>
                                    <div class="action-buttons">
                                        <button class="edit-btn" onclick="editShop('<?php echo htmlspecialchars($row['shop_ID']); ?>')">แก้ไข</button>
                                        <button class="delete-btn" onclick="deleteShop('<?php echo $row['shop_ID']; ?>', this)">ลบ</button>
                                    </div>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <div class="icon-medium2">&#127978;</div>
                    <h3>ยังไม่มีข้อมูลร้านค้าในระบบ</h3>
                    <p>คุณสามารถเริ่มต้นด้วยการเพิ่มร้านค้าใหม่เข้าระบบ</p>
                    <button class="add-btn" onclick="window.location.href='Addshop.php'"><i class="fa fa-plus"></i> เพิ่มร้านค้าใหม่</button>
                </div>
            <?php endif; ?>
            <?php $conn->close(); ?>
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

    <script>
        function toggleDropdown() {
            document.getElementById("userDropdown").classList.toggle("show");
        }

        // --- JavaScript ส่วนที่เหลือเหมือนเดิมทั้งหมด ---
        function showNotificationModal(){document.getElementById("notificationModal").style.display="block"}function closeNotificationModal(){document.getElementById("notificationModal").style.display="none"}window.onclick=function(e){var o;if(o=document.getElementById("notificationModal"),e.target==o)o.style.display="none";if(!e.target.matches(".user-avatar")){var t=document.getElementsByClassName("dropdown-content");for(o=0;o<t.length;o++){var n=t[o];n.classList.contains("show")&&n.classList.remove("show")}}};function searchShop(){const e=document.getElementById("searchInput").value.toLowerCase(),o=document.getElementById("shopTable").getElementsByTagName("tbody")[0].getElementsByTagName("tr");for(let t=0;t<o.length;t++){const n=o[t].getElementsByTagName("td");let d=!1;for(let e=0;e<n.length-1;e++)if(n[e].textContent.toLowerCase().includes(searchTerm)){d=!0;break}o[t].style.display=d?"":"none"}}function editShop(e){window.location.href="editshop.php?id="+e}const deleteModal=document.getElementById("deleteConfirmModal");let shopToDeleteId=null;function showDeleteModal(e,o){shopToDeleteId=e;const t=document.getElementById("deleteConfirmText");t.innerHTML=`คุณแน่ใจว่าต้องการลบร้านค้า <br><b>"${o}" (รหัส: ${e})</b> หรือไม่? <br><br><span style="color: #dc3545;">การกระทำนี้ไม่สามารถกู้คืนได้</span>`;const n=document.getElementById("deleteConfirmModal");n.style.display="flex",setTimeout(()=>{n.classList.add("show")},10)}function hideDeleteModal(){const e=document.getElementById("deleteConfirmModal");e.classList.remove("show"),setTimeout(()=>{e.style.display="none"},300),shopToDeleteId=null}function confirmDelete(){shopToDeleteId&&(fetch("deleteshop.php",{method:"POST",headers:{"Content-Type":"application/x-www-form-urlencoded"},body:"shop_ID="+encodeURIComponent(shopToDeleteId)}).then(e=>e.json()).then(e=>{e.success?(alert("ลบร้านค้าสำเร็จ!"),document.querySelectorAll("#shopTable tbody tr").forEach(e=>{e.cells[0]&&e.cells[0].textContent.trim()===shopToDeleteId&&e.remove()})):alert("เกิดข้อผิดพลาด: "+e.message)}).catch(e=>{console.error("Fetch Error:",e),alert("เกิดข้อผิดพลาดในการเชื่อมต่อกับเซิร์ฟเวอร์")}).finally(()=>{hideDeleteModal()}))}window.addEventListener("click",function(e){e.target==deleteModal&&hideDeleteModal()});function deleteShop(e,o){const t=o.closest("tr").cells[1].textContent;showDeleteModal(e,t)}
    </script>

</body>
</html>