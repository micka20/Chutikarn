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
// ดึงข้อมูลสินค้าใกล้หมด
$low_stock_products = [];
$low_stock_sql = "SELECT pd_ID, pd_name, pd_number, pd_unit FROM product WHERE pd_number <= 10 AND pd_number > 0 ORDER BY pd_number ASC";
$low_stock_result = $conn->query($low_stock_sql);
if ($low_stock_result->num_rows > 0) {
    while($row = $low_stock_result->fetch_assoc()) {
        $low_stock_products[] = $row;
    }
}
$low_stock_count = count($low_stock_products);

// ดึงข้อมูลสินค้าเข้าใหม่ (7 วันที่ผ่านมา)
$recent_received_orders_notification = [];
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
        $recent_received_orders_notification[] = $row;
        $recent_received_count++;
    }
}

// --- ดึงข้อมูลประวัติใบสั่งซื้อที่รับเข้าแล้ว (สถานะ received) ---
$received_orders = [];
$sql = "SELECT b.buypd_ID, b.buypd_date, b.buypd_total, s.shop_name, u.user_name
        FROM buypd b
        JOIN shop s ON b.shop_ID = s.shop_ID
        JOIN user u ON b.user_ID = u.user_ID
        WHERE b.buypd_status = 'received'
        ORDER BY b.buypd_date DESC, CAST(SUBSTRING(b.buypd_ID, 4) AS UNSIGNED) DESC"; // เรียงจากวันที่ล่าสุด และใบสั่งซื้อล่าสุดก่อน

$result = $conn->query($sql);
if ($result && $result->num_rows > 0) {
    while($row = $result->fetch_assoc()) {
        $received_orders[] = $row;
    }
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>ประวัติรับสินค้าเข้า</title>
    <link href="https://fonts.googleapis.com/css2?family=Kanit:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
        html { scroll-behavior: smooth; }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { background-color: #f8f9fa;}
        
        /* --- General Styles from your file --- */
        .top-header { font-family: 'Microsoft Sans Serif'; display: flex; justify-content: space-between; align-items: center; border: 2.6px solid #ffffff; padding: 10px 20px; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1); background-color: #fff; position: sticky; top: 0; z-index: 1000; }
        .logo-section { display: flex; align-items: center; gap: 10px; }
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
        
        /* Notification Styles */
        .notification-container { position: relative; display: inline-block; }
        .notification-badge { position: absolute; top: -5px; right: -5px; background: #ff4444; color: white; border-radius: 50%; width: 18px; height: 18px; display: flex; align-items: center; justify-content: center; font-size: 12px; font-weight: bold; z-index: 10; }
        .bell-icon { font-size: 22px; cursor: pointer; }
        
        .main-container { display: flex; min-height: calc(100vh - 84px); }
        .sidebar { width: 250px; background: #ffffff; padding: 0; box-shadow: 2px 0 15px rgba(0,0,0,0.1); position: sticky; top: 84px; height: calc(100vh - 84px); overflow-y: auto; }
        .menu-item { display: flex; align-items: center; gap: 1rem; padding: 1rem 2rem; color: #000000; text-decoration: none; transition: background 0.3s; }
        .menu-item:hover { background: rgba(244, 212, 199, 0.5); }
        .menu-item span { flex: 1; text-align: left; font-size: 18px; }
        .menu-item.active { background: linear-gradient(90deg, #f4d4c7 0%, #edd5c7 100%); }
        .menu-item.active span, .menu-item.active div { color: #A44A3F; }
        .icon-medium { font-size: 25px; }
        
        .content { flex: 1; padding: 40px; }
        .page-header { color: #A44A3F; font-size: 30px; font-weight: bold; margin-bottom: 30px; }
        .orders-container { background-color: #fff; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); overflow: hidden; }
        
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 15px; text-align: left; border-bottom: 1px solid #dee2e6; }
        th { background-color: #f8f9fa; font-weight: 600; color: #495057; }
        tr:hover { background-color: #f8f9fa; }
        
        .btn { padding: 8px 16px; border: none; border-radius: 4px; cursor: pointer; text-decoration: none; font-size: 14px; display: inline-block; transition: all 0.3s; }
        .btn-info { background-color: #17a2b8; color: white; }
        .btn-info:hover { background-color: #117a8b; }

        .text-center { text-align: center; }
        .text-right { text-align: right; }
        .empty-state { text-align: center; padding: 50px; color: #6c757d; }
        .empty-state .icon { font-size: 64px; margin-bottom: 20px; opacity: 0.3; }
        
        /* Modal Styles */
        .modal { display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5); }
        .modal-content { background-color: #fff; margin: 5% auto; padding: 30px; border-radius: 8px; width: 80%; max-width: 800px; max-height: 80vh; overflow-y: auto; }
        .modal-header { margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; }
        .modal-header h3 { color: #A44A3F; margin: 0; }
        .close { color: #aaa; font-size: 28px; font-weight: bold; cursor: pointer; }
        .close:hover { color: #000; }
        
        .order-number { font-weight: bold; color: #000000; }
        
        .status-badge { padding: 5px 10px; border-radius: 15px; font-size: 12px; font-weight: bold; color: white; background-color: #28a745; display: inline-block; }
        
        /* Notification Modal Styles */
        .notification-modal { display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5); }
        .notification-modal-content { background-color: #fefefe; margin: 5% auto; padding: 20px; border-radius: 8px; width: 80%; max-width: 600px; box-shadow: 0 4px 8px rgba(0,0,0,0.2); }
        .notification-section { margin-bottom: 20px; }
        .notification-section h3 { color: #A44A3F; margin-bottom: 10px; border-bottom: 1px solid #eee; padding-bottom: 5px; }
        .notification-item { padding: 8px 0; border-bottom: 1px solid #f0f0f0; }
        .notification-item:last-child { border-bottom: none; }
        .no-notifications { color: #777; font-style: italic; }
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
                    <a href="Login.php" class="logout">Logout</a>
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
            <a href="Dashboard.php" class="menu-item">
                <div class="icon-medium">&#127968;</div><span>หน้าแรก</span>
            </a>
            <a href="Manage_products.php" class="menu-item">
                <div class="icon-medium">&#128230;</div><span>จัดการสินค้า</span>
            </a>
            <a href="manage_types.php" class="menu-item">
                <div class="icon-medium">&#128193;</div><span>จัดการประเภทสินค้า</span>
            </a>
            <a href="receive_stock.php" class="menu-item active">
                <div class="icon-medium">&#128229;</div><span>รับสินค้าเข้า</span>
            </a>
            <a href="Order.php" class="menu-item">
                <div class="icon-medium">&#128722;</div><span>สั่งซื้อสินค้า</span>
            </a>
            <a href="storage.php" class="menu-item">
                <div class="icon-medium">&#128190;</div><span>จัดเก็บสินค้า</span>
            </a>
            <a href="Employee_Account.php" class="menu-item">
                <div class="icon-medium">&#128101;</div><span>จัดการพนักงาน</span>
            </a>
            <a href="shop.php" class="menu-item">
                <div class="icon-medium">&#127978;</div><span>จัดการร้านค้า</span>
            </a>
            <a href="reports.php" class="menu-item">
                <div class="icon-medium">&#128200;</div><span>รายงาน</span>
            </a>
            <a href="settings.php" class="menu-item">
                <div class="icon-medium">⚙️</div><span>ตั้งค่าระบบ</span>
            </a>
        </div>
        
        <div class="content">
            <h2 class="page-header">ประวัติรับสินค้าเข้า</h2>
            
            <div class="orders-container">
                <?php if(empty($received_orders)): ?>
                    <div class="empty-state">
                        <div class="icon">📜</div>
                        <h3>ยังไม่มีประวัติการรับสินค้า</h3>
                        <p>เมื่อมีการรับสินค้าเข้าระบบ ประวัติจะแสดงที่นี่</p>
                    </div>
                <?php else: ?>
                    <table>
                        <thead>
                            <tr>
                                <th style="width: 15%;">เลขที่ใบสั่งซื้อ</th>
                                <th style="width: 15%;">วันที่สั่งซื้อ</th>
                                <th style="width: 25%;">ร้านค้า</th>
                                <th style="width: 15%;">ผู้จัดทำ</th>
                                <th style="width: 15%;" class="text-right">ยอดรวม (บาท)</th>
                                <th style="width: 15%;" class="text-center">สถานะ</th>
                                <th style="width: 10%;" class="text-center">จัดการ</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($received_orders as $order): ?>
                                <tr>
                                    <td><strong class="order-number"><?php echo htmlspecialchars($order['buypd_ID']); ?></strong></td>
                                    <td><?php echo date("d/m/Y", strtotime($order['buypd_date'])); ?></td>
                                    <td><?php echo htmlspecialchars($order['shop_name']); ?></td>
                                    <td><?php echo htmlspecialchars($order['user_name']); ?></td>
                                    <td class="text-right" style="font-weight: bold;">฿<?php echo number_format($order['buypd_total'], 2); ?></td>
                                    <td class="text-center">
                                        <span class="status-badge">รับเข้าแล้ว</span>
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
                    <?php foreach ($recent_received_orders_notification as $order): ?>
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
        // ใช้ JavaScript เดิมได้เลย
        function viewOrderDetails(buypd_id) {
            document.getElementById('orderDetailsModal').style.display = 'block';
            document.getElementById('orderDetailsContent').innerHTML = '<p style="text-align: center; padding: 20px;">กำลังโหลด...</p>';
            
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
        
        window.onclick = function(event) {
            var modal = document.getElementById('orderDetailsModal');
            var notificationModal = document.getElementById('notificationModal');
            
            if (event.target == modal) {
                modal.style.display = 'none';
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