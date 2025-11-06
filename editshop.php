<?php
session_start();

// ตรวจสอบการล็อกอิน
if (!isset($_SESSION['user_username'])) {
    header("Location: login.php");
    exit();
}

// เชื่อมต่อฐานข้อมูล
$servername = "localhost";
$username = "root";
$password = "12345678";
$dbname = "chutikarnceramic1";

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
$conn->set_charset("utf8");

// === ดึงข้อมูลผู้ใช้สำหรับแถบเมนู ===
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

// === ดึงข้อมูลสินค้าใกล้หมด (Reorder Point) ===
$low_stock_products = [];
$low_stock_sql = "SELECT pd_ID, pd_name, pd_number, pd_unit FROM product WHERE pd_number <= 10 AND pd_number > 0 ORDER BY pd_number ASC";
$low_stock_result = $conn->query($low_stock_sql);
if ($low_stock_result->num_rows > 0) {
    while($row = $low_stock_result->fetch_assoc()) {
        $low_stock_products[] = $row;
    }
}
$low_stock_count = count($low_stock_products);

// === ดึงข้อมูลสำหรับแจ้งเตือนสินค้าเข้าใหม่ (7 วันที่ผ่านมา) ===
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

// ตรวจสอบว่ามีการส่ง ID ร้านค้ามาหรือไม่
if (!isset($_GET['id'])) {
    header("Location: shop.php");
    exit();
}

$shop_id = $_GET['id'];

// ดึงข้อมูลร้านค้าจากฐานข้อมูล
$sql = "SELECT * FROM shop WHERE shop_ID = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $shop_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows == 0) {
    // ไม่พบร้านค้า
    header("Location: shop.php");
    exit();
}

$shop = $result->fetch_assoc();
$stmt->close();

// ตรวจสอบการส่งฟอร์มแก้ไข
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $shop_name = $_POST['shop_name'];
    $shop_address = $_POST['shop_address'];
    $shop_tel = $_POST['shop_tel'];

    // อัปเดตข้อมูลในฐานข้อมูล
    $sql_update = "UPDATE shop SET shop_name = ?, shop_address = ?, shop_tel = ? WHERE shop_ID = ?";
    $stmt_update = $conn->prepare($sql_update);
    $stmt_update->bind_param("ssss", $shop_name, $shop_address, $shop_tel, $shop_id);

    if ($stmt_update->execute()) {
        // อัปเดตสำเร็จ
        echo "<script>
                alert('อัปเดตข้อมูลร้านค้าสำเร็จ');
                window.location.href = 'shop.php';
              </script>";
    } else {
        // เกิดข้อผิดพลาด
        echo "<script>
                alert('เกิดข้อผิดพลาดในการอัปเดตข้อมูล: " . $stmt_update->error . "');
                window.history.back();
              </script>";
    }

    $stmt_update->close();
    exit();
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>แก้ไขร้านค้า</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Kanit:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
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
        
        .dropdown { position: relative; display: inline-block; }
        .dropdown-content { display: none; position: absolute; right: 0; background-color: #f9f9f9; min-width: 160px; box-shadow: 0px 8px 16px 0px rgba(0,0,0,0.2); z-index: 1; border-radius: 4px; overflow: hidden; }
        .dropdown-content a { color: black; padding: 12px 16px; text-decoration: none; display: block; font-size: 14px; }
        .dropdown-content a:hover { background-color: #f1f1f1; }
        .show { display: block; }
        .logout { color: #e74c3c !important; }
        
        /* === สไตล์กระดิ่งแจ้งเตือน === */
        .notification-container { position: relative; display: inline-block; }
        .notification-badge { position: absolute; top: -5px; right: -5px; background: #ff4444; color: white; border-radius: 50%; width: 18px; height: 18px; display: flex; align-items: center; justify-content: center; font-size: 12px; font-weight: bold; z-index: 10; }
        .bell-icon { font-size: 20px; cursor: pointer; }
        
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
        
        .content { 
            flex: 1; 
            padding: 40px; 
            background-color: #f8f9fa; 
            margin-left: 250px; /* เพิ่ม margin-left เท่ากับความกว้างของ sidebar */
            width: calc(100% - 250px); /* ปรับความกว้างของ content */
        }
        
        .page-header { 
            color: #A44A3F; 
            font-size: 30px; 
            font-weight: bold; 
            margin-bottom: 30px; 
            text-align: left; 
        }

        /* === สไตล์ฟอร์มแก้ไขร้านค้า === */
        .form-container {
            max-width: 800px;
            margin: 0 auto;
            padding: 30px;
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }

        .form-title {
            text-align: center;
            color: #A44A3F;
            font-size: 28px;
            font-weight: bold;
            margin-bottom: 30px;
            padding-bottom: 15px;
            border-bottom: 2px solid #f0f0f0;
        }

        .form-group {
            margin-bottom: 25px;
        }

        .form-label {
            display: block;
            margin-bottom: 10px;
            font-weight: 600;
            color: #333;
            font-size: 16px;
        }

        .form-input {
            width: 100%;
            padding: 14px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 16px;
            transition: all 0.3s ease;
            background-color: #fafafa;
        }

        .form-input:focus {
            outline: none;
            border-color: #A44A3F;
            background-color: white;
            box-shadow: 0 0 0 3px rgba(164, 74, 63, 0.1);
        }

        .form-input:read-only {
            background-color: #f0f0f0;
            color: #666;
            cursor: not-allowed;
        }

        .btn-group {
            display: flex;
            justify-content: center;
            gap: 20px;
            margin-top: 40px;
        }

        .btn {
            padding: 14px 35px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 16px;
            font-weight: 100;
            transition: all 0.3s ease;
            min-width: 85px;
        }

        .btn-submit {
            background: linear-gradient(135deg, #c0392b, #a93226);
            color: white;
            box-shadow: 0 4px 12px rgba(192, 57, 43, 0.3);
        }

        .btn-submit:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(192, 57, 43, 0.4);
            background: linear-gradient(135deg, #a93226, #922b21);
        }

        .btn-cancel {
            background: linear-gradient(135deg, #6c757d, #5a6268);
            color: white;
            box-shadow: 0 4px 12px rgba(108, 117, 125, 0.3);
        }

        .btn-cancel:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(108, 117, 125, 0.4);
        }
        
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

            .btn-group {
                flex-direction: column;
                gap: 10px;
            }

            .btn {
                min-width: auto;
                width: 100%;
            }
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
        <h2 class="page-header">แก้ไขข้อมูลร้านค้า</h2>
        <div class="form-container">
            
            <form method="POST" action="">
                <div class="form-group">
                    <label for="shop_id" class="form-label">รหัสร้านค้า</label>
                    <input type="text" id="shop_id" name="shop_id" class="form-input" value="<?php echo htmlspecialchars($shop['shop_ID']); ?>" readonly>
                </div>
                
                <div class="form-group">
                    <label for="shop_name" class="form-label">ชื่อร้านค้า</label>
                    <input type="text" id="shop_name" name="shop_name" class="form-input" value="<?php echo htmlspecialchars($shop['shop_name']); ?>" required>
                </div>
                
                <div class="form-group">
                    <label for="shop_address" class="form-label">ที่อยู่ร้านค้า</label>
                    <input type="text" id="shop_address" name="shop_address" class="form-input" value="<?php echo htmlspecialchars($shop['shop_address']); ?>" required>
                </div>
                
                <div class="form-group">
                    <label for="shop_tel" class="form-label">เบอร์โทรศัพท์</label>
                    <input type="text" id="shop_tel" name="shop_tel" class="form-input" value="<?php echo htmlspecialchars($shop['shop_tel']); ?>" required>
                </div>
                
                <div class="btn-group">
                    <button type="button" class="btn btn-cancel" onclick="window.location.href='shop.php'">
                        ยกเลิก
                    </button>
                    <button type="submit" class="btn btn-submit">
                        บันทึกการเปลี่ยนแปลง
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

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