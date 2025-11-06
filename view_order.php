<?php
session_start();

if(!isset($_SESSION['user_username'])) {
    header("Location: login.php");
    exit();
}

if(!isset($_GET['id']) || empty($_GET['id'])) {
    header("Location: Order.php");
    exit();
}

$buypd_id = $_GET['id'];

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

// === ส่วนเพิ่มเติม: ดึงข้อมูลสำหรับแจ้งเตือน ===
// ดึงข้อมูลผู้ใช้สำหรับแถบเมนู
$user_username_session = $_SESSION['user_username'];
$sql_user = "SELECT user_username, user_type FROM user WHERE user_username = ?";
$stmt_user = $conn->prepare($sql_user);
$stmt_user->bind_param("s", $user_username_session);
$stmt_user->execute();
$result_user = $stmt_user->get_result();

$user_username = "ไม่พบข้อมูล";
$user_type = "ไม่พบข้อมูล";

if ($result_user->num_rows > 0) {
    $row = $result_user->fetch_assoc();
    $user_username = $row["user_username"];
    $user_type = $row["user_type"];
}
$stmt_user->close();

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

// อัพเดทสถานะเมื่อกดพิมพ์
if(isset($_POST['mark_printed'])) {
    $update_sql = "UPDATE buypd SET buypd_status = 'printed' WHERE buypd_ID = ?";
    $stmt_update = $conn->prepare($update_sql);
    $stmt_update->bind_param("s", $buypd_id);
    $stmt_update->execute();
    $stmt_update->close();
    
    // บันทึกข้อมูลลงตาราง receive_stock (ถ้ามี)
    header("Location: receive_stock.php?order_id=" . $buypd_id);
    exit();
}

// ดึงข้อมูลหลักของใบสั่งซื้อ
$order_main = null;
$sql_main = "SELECT b.buypd_ID, b.buypd_date, b.buypd_total, b.buypd_status, s.shop_name, u.user_name 
             FROM buypd b
             JOIN shop s ON b.shop_ID = s.shop_ID
             JOIN user u ON b.user_ID = u.user_ID
             WHERE b.buypd_ID = ?";
$stmt_main = $conn->prepare($sql_main);
$stmt_main->bind_param("s", $buypd_id);
$stmt_main->execute();
$result_main = $stmt_main->get_result();
if ($result_main->num_rows > 0) {
    $order_main = $result_main->fetch_assoc();
}
$stmt_main->close();

// ดึงรายการสินค้า
$order_details = [];
$sql_details = "SELECT pd_name, OD_number, OD_amount FROM orderdetails WHERE buypd_ID = ?";
$stmt_details = $conn->prepare($sql_details);
$stmt_details->bind_param("s", $buypd_id);
$stmt_details->execute();
$result_details = $stmt_details->get_result();
if ($result_details) {
    while ($row = $result_details->fetch_assoc()) {
        $order_details[] = $row;
    }
}
$stmt_details->close();
$conn->close();

if ($order_main === null) {
    header("Location: Order.php");
    exit();
}

// ฟังก์ชันแปลงวันที่เป็นภาษาไทย
function convertDateToThai($date) {
    $months = [
        'January' => 'ม.ค.',
        'February' => 'ก.พ.',
        'March' => 'มี.ค.',
        'April' => 'เม.ย.',
        'May' => 'พ.ค',
        'June' => 'มิ.ย',
        'July' => 'ก.ค.',
        'August' => 'ส.ค.',
        'September' => 'ก.ย.',
        'October' => 'ต.ค.',
        'November' => 'พ.ย.',
        'December' => 'ธ.ค.'
    ];
    
    $dateObj = new DateTime($date);
    $day = $dateObj->format('j');
    $month = $dateObj->format('F');
    $year = $dateObj->format('Y') + 543; // แปลงเป็นปีพุทธศักราช
    
    return $day . ' ' . $months[$month] . ' ' . $year;
}
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>รายละเอียดใบสั่งซื้อ <?php echo htmlspecialchars($order_main['buypd_ID']); ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Kanit:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box;}
        
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
        
        .main-container { 
            display: flex; 
            min-height: calc(100vh - 80px); 
            margin-top: 84px; /* เพิ่ม margin-top เท่ากับความสูงของ header */
        }
        
        .sidebar { 
            width: 250px; 
            background: #ffffff; 
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
            color: #A44A3F; 
        }
        
        .menu-item.active span, .menu-item.active div { 
            color: #A44A3F; 
        }
        
        .icon-medium { 
            font-size: 25px; 
            color: #000000;
            transition: color 0.3s ease;
        }
        
        /* === สไตล์กระดิ่งแจ้งเตือน === */
        .notification-container { position: relative; display: inline-block; }
        .notification-badge { position: absolute; top: -5px; right: -5px; background: #ff4444; color: white; border-radius: 50%; width: 18px; height: 18px; display: flex; align-items: center; justify-content: center; font-size: 12px; font-weight: bold; z-index: 10; }
        .bell-icon { font-size: 20px; cursor: pointer; }
        
        .content { 
            flex: 1; 
            padding: 40px; 
            background-color: #f8f9fa;
            margin-left: 250px; /* เพิ่ม margin-left เท่ากับความกว้างของ sidebar */
            width: calc(100% - 250px); /* ปรับความกว้างของ content */
        }
        
        .page-header { color: #A44A3F; font-size: 30px; font-weight: bold; margin-bottom: 20px; }
        .order-container { background-color: #fff; padding: 30px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .details-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 15px 25px; margin-bottom: 25px; }
        .detail-item strong { display: block; color: #555; margin-bottom: 4px; }
        .detail-item span { font-size: 1.1rem; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th, td { padding: 12px 15px; text-align: left; border-bottom: 1px solid #dee2e6; }
        th { background-color: #f8f9fa; font-weight: 600; }
        .text-right { text-align: right; }
        .text-center { text-align: center; }
        .summary-row { display: flex; justify-content: flex-end; margin-top: 20px; }
        .summary-box { width: 300px; font-size: 1.2rem; }
        .total { font-weight: bold; color: #c0392b; font-size: 1.4rem; }
        .action-buttons { margin-top: 30px; text-align: right; display: flex; justify-content: flex-end; gap: 10px;}
        .btn { padding: 10px 20px; border: none; border-radius: 5px; font-size: 16px; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; gap: 5px; }
        .btn-secondary { background-color: #6c757d; color: white; }
        .btn-info { background-color: #17a2b8; color: white; }
        .btn-success { background-color: #28a745; color: white; }
        .status-badge { padding: 5px 10px; border-radius: 15px; font-size: 12px; font-weight: bold; }
        .status-pending { background-color: #fff3cd; color: #856404; }
        .status-printed { background-color: #d1ecf1; color: #0c5460; }
        .status-received { background-color: #d4edda; color: #155724; }

        /* === Modal Styles สำหรับแจ้งเตือน === */
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

        /* Responsive Design */
        @media (max-width: 768px) {
            .sidebar {
                width: 100%;
                position: relative;
                height: auto;
            }

            .content {
                margin-left: 0;
                width: 100%;
                padding: 20px;
            }

            .main-container {
                flex-direction: column;
                margin-top: 84px;
            }

            .details-grid {
                grid-template-columns: 1fr;
                gap: 10px;
            }

            .action-buttons {
                flex-direction: column;
                gap: 10px;
            }

            .btn {
                width: 100%;
                justify-content: center;
            }

            .summary-box {
                width: 100%;
            }
        }

        @media print {
            body { background-color: #fff; }
            .main-container { display: block; }
            .sidebar, .action-buttons, h1, .page-header { display: none; }
            .content { padding: 0; margin-left: 0; width: 100%; }
            .order-container { box-shadow: none; border: 1px solid #ccc; }
        }

        /* === ส่วนของ dropdown user === */
        .dropdown { position: relative; display: inline-block; }
        .dropdown-content { display: none; position: absolute; right: 0; background-color: #f9f9f9; min-width: 160px; box-shadow: 0px 8px 16px 0px rgba(0,0,0,0.2); z-index: 1; border-radius: 4px; overflow: hidden; }
        .dropdown-content a { color: black; padding: 12px 16px; text-decoration: none; display: block; font-size: 14px; }
        .dropdown-content a:hover { background-color: #f1f1f1; }
        .show { display: block; }
        .logout { color: #e74c3c !important; }
    </style>
</head>
<body>
    <h1 style="font-size:25px; font-family: 'Microsoft Sans Serif'; display: flex; justify-content: space-between; align-items: center;">
        <div style="display: flex; align-items: center;">
            <img src="logo1.png" width="50" height="50" style="vertical-align: middle;">
            <span style="vertical-align: middle;">Chutikarn</span>
        </div>
        
        <div class="user-section" style="display: flex; align-items: center; gap: 15px;">
            <!-- === ระบบแจ้งเตือนกระดิ่ง === -->
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

    <!-- === Modal สำหรับแจ้งเตือน === -->
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
                        (<?php echo convertDateToThai($order['buypd_date']); ?>)
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
            <h2 class="page-header">รายละเอียดใบสั่งซื้อ: <?php echo htmlspecialchars($order_main['buypd_ID']); ?></h2>
            <div class="order-container">
                <div class="details-grid">
                    <div class="detail-item"><strong>เลขที่ใบสั่งซื้อ:</strong> <span><?php echo htmlspecialchars($order_main['buypd_ID']); ?></span></div>
                    <div class="detail-item"><strong>วันที่สั่งซื้อ:</strong> <span><?php echo convertDateToThai($order_main['buypd_date']); ?></span></div>
                    <div class="detail-item"><strong>ร้านค้า:</strong> <span><?php echo htmlspecialchars($order_main['shop_name']); ?></span></div>
                    <div class="detail-item"><strong>ผู้จัดทำ:</strong> <span><?php echo htmlspecialchars($order_main['user_name']); ?></span></div>
                    <div class="detail-item"><strong>สถานะ:</strong> 
                        <span class="status-badge status-<?php echo $order_main['buypd_status']; ?>">
                            <?php 
                            $status_text = [
                                'pending' => 'รอดำเนินการ',
                                'printed' => 'พิมพ์แล้ว',
                                'received' => 'รับสินค้าแล้ว'
                            ];
                            echo $status_text[$order_main['buypd_status']];
                            ?>
                        </span>
                    </div>
                </div>
                <hr>
                <h3>รายการสินค้า</h3>
                <table>
                    <thead>
                        <tr>
                            <th style="width: 5%;">#</th>
                            <th>ชื่อสินค้า</th>
                            <th class="text-center" style="width: 15%;">จำนวน</th>
                            <th class="text-right" style="width: 20%;">ยอดรวม (บาท)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $i = 1; foreach ($order_details as $item): ?>
                            <tr>
                                <td class="text-center"><?php echo $i++; ?></td>
                                <td><?php echo htmlspecialchars($item['pd_name']); ?></td>
                                <td class="text-center"><?php echo htmlspecialchars($item['OD_number']); ?></td>
                                <td class="text-right"><?php echo number_format($item['OD_amount'], 2); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <div class="summary-row">
                    <div class="summary-box">
                        <div style="display: flex; justify-content: space-between; padding: 10px;">
                            <strong>ยอดรวมทั้งสิ้น:</strong>
                            <span class="total"><?php echo number_format($order_main['buypd_total'], 2); ?> บาท</span>
                        </div>
                    </div>
                </div>
                <div class="action-buttons">
                    <a href="Order.php" class="btn btn-secondary">กลับไปหน้ารายการ</a>
                    
                    <?php if($order_main['buypd_status'] == 'pending'): ?>
                    <form method="POST" style="display: inline;">
                        <button type="submit" name="mark_printed" class="btn btn-success" onclick="window.print()">
                        🖨️ พิมพ์ใบสั่งซื้อ
                        </button>
                    </form>
                    <?php elseif($order_main['buypd_status'] == 'printed'): ?>
                    <a href="receive_stock.php?order_id=<?php echo $buypd_id; ?>" class="btn btn-info">
                        📥 ไปหน้าตรวจรับสินค้า
                    </a>
                    <?php endif; ?>
                    
                </div>
            </div>
        </div>
    </div>

    <script>
        // === ฟังก์ชันสำหรับแสดง/ปิด Modal แจ้งเตือน ===
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
        }

        // ฟังก์ชันสำหรับ dropdown เมนูผู้ใช้
        function toggleDropdown() { 
            document.getElementById("userDropdown").classList.toggle("show"); 
        }
    </script>
</body>
</html>