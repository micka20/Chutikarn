<?php
session_start();

// ตรวจสอบว่าผู้ใช้ล็อกอินอยู่หรือไม่
if(!isset($_SESSION['user_username'])) {
    // ถ้ายังไม่ได้ล็อกอิน ให้ redirect ไปหน้า login
    header("Location: login.php");
    exit();
}

// เชื่อมต่อฐานข้อมูล
$servername = "localhost";
$username = "root";
$password = "12345678";
$dbname = "chutikarnceramic1";

// สร้างการเชื่อมต่อ
$conn = new mysqli($servername, $username, $password, $dbname);

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
$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>หน้าแรก</title>
    <style>
        *{
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        h1 {
            border: 2.6px solid #ffffff;
            padding: 20px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            background-color: #fff;
        }

        .menu {
            float: left;
            width: 20%;
            text-align: center;
        }

        .menu a {
            display: block;
            background-color: #ffffff;
            margin: 0;
            padding: 20px;
            margin-top: 1px;
            display: block;
            width: 100%;
            color: black;
            text-decoration: none;
        }

        .menu a:hover {
            background-color: #e0e0e0;
        }
  
        .menu a:last-child {
            border-bottom: none;
        }

        .right {
            background-color: #ffffff;
            float: left;
            width: 20%;
            padding: 15px;
            margin-top: 7px;
            text-align: center;
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
            min-height: calc(100vh - 80px);
        }

        
        .sidebar {
            width: 250px;
            background: linear-gradient(180deg, #ffffff 0%, #ffffff 100%);
            padding: 0;
            box-shadow: 2px 0 15px rgba(0,0,0,0.1);
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
            background: rgba(255,255,255,0.1);
            border-left-color: transparent;
            transform: translateX(1.5px);
        }

        .menu-item.active {
            background: linear-gradient(90deg, #f4d4c7 0%, #edd5c7 100%);
            border-left-color: transparent;
            color: #2c3e50;
            justify-content: center;
        }

        .menu-icon {
            width: 20px;
            height: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .icon-medium { font-size: 25px; }
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
            padding: 2.5rem;
            position: relative;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
        }

        /* ส่วนหัวเรื่องที่อยู่ด้านซ้าย */
        .page-header {
            position: absolute;
            top: 30px;
            left: 30px;
            color: #A44A3F;
            font-size: 30px;
            font-weight: bold;
            margin: 0;
            text-align: left;
        }

        h3 {
            text-align: center;
            text-transform: uppercase;
            color: #000000;
        }

        p {
            text-align: center;
        }

        /* ปรับแต่งส่วนที่ว่างเปล่า */
        .empty-state {
            text-align: center;
            width: 100%;
            max-width: 500px;
        }

        /*ออกแบบปุ่มเพิ่มสินค้าใหม่*/
        .add-btn {
            display: block;
            width: 300px;
            padding: 14px;
            margin: 20px auto;
            background: linear-gradient(135deg, #e74c3c, #c0392b);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 18px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(50, 26, 60, 0.3);
        }

        /*เลื่อนเมาส์มาที่ปุ่มเพิ่มสินค้าใหม่ให้มีเอฟเฟกต์*/
        .add-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(50, 26, 60, 0.4);
            background: linear-gradient(135deg, #c0392b, #a93226);
        }

        .add-btn:active {
            transform: translateY(1px);
            box-shadow: 0 2px 8px rgba(35, 26, 60, 0.3);
        }
        
    </style>
</head>
<body>

    <!--แถบด้านบนสุด-->
    <h1 style="font-size:25px; font-family: 'Microsoft Sans Serif'; display: flex; justify-content: space-between; align-items: center;">
        <!-- ส่วนซ้าย - Logo และชื่อ -->
        <div style="display: flex; align-items: center;">
            <img src="logo1.png" width="50" height="50" style="vertical-align: middle;">
            <span style="vertical-align: middle;">Chutikarn</span>
        </div>
        
        <!-- ส่วนขวา - User-->
        <div class="user-section" style="display: flex; align-items: center; gap: 15px;">
            <div class="user-section" style="display: flex; align-items: center; gap: 15px;">
                <!-- กระดิ่งแจ้งเตือนพร้อมเลขทับ -->
                <div class="notification-container" onclick="showNotification()">
                    <div class="bell-icon">&#128276;</div>
                    <div class="notification-badge">0</div>
                </div>
            
            <!--ส่วนของรูปผู้ดูแลระบบ-->
            <div class="dropdown">
                <div class="user-avatar" onclick="toggleDropdown()" 
                    style="font-size:25px; width: 40px; height: 40px; 
                            border-radius: 50%; background: #f0f0f0; 
                            display: flex; align-items: center; justify-content: center; 
                            cursor: pointer;">&#128100;</div>
                
                <div id="userDropdown" class="dropdown-content">
                    <a href="settings.php">ข้อมูลส่วนตัว</a>
                    <a href="Login.php" class="logout">Logout</a>
                </div>
            </div>
            
            <!--ข้อความที่บอกชื่อและประเภทผู้ใช้งาน-->
            <div class="user-info" style="text-align: right;">
                <div class="user-username" style="font-size:16px; font-weight: bold;"><?php echo htmlspecialchars($user_username); ?></div>
                <div class="user-role" style="font-size:16px; color: #666;"><?php echo htmlspecialchars($user_type); ?></div>
            </div>
        </div>
    </h1>

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
    </script>

    <!--แถบเมนู-->
    <div class="main-container">
        <div class="sidebar">
            <a href="#" class="menu-item active" onclick="setActiveMenu(this)">
                <div class="icon-medium">&#127968;</div>
                <span style="font-size:18px; color: #A44A3F;">หน้าแรก</span>
            </a>

            <a href="Manage_products.php" class="menu-item" onclick="setActiveMenu(this)">
                <div class="icon-medium">&#128230;</div>
                <span style="font-size:18px; color: #000000;">จัดการสินค้า</span>
            </a>

            <a href="manage_types.php" class="menu-item" onclick="setActiveMenu(this)">
                <div class="icon-medium">&#128193;</div>
                <span style="font-size:18px; color: #000000;">จัดการประเภทสินค้า</span>
            </a>

            <a href="receive_stock.php" class="menu-item" onclick="setActiveMenu(this)">
                <div class="icon-medium">&#128229;</div>
                <span style="font-size:18px; color: #000000;">รับสินค้าเข้า</span>
            </a>

            <a href="Order.php" class="menu-item" onclick="setActiveMenu(this)">
                <div class="icon-medium">&#128722;</div>
                <span style="font-size:18px; color: #000000;">สั่งซื้อสินค้า</span>
            </a>

            <a href="storage.php" class="menu-item" onclick="setActiveMenu(this)">
                <div class="icon-medium">&#128190;</div>
                <span style="font-size:18px; color: #000000;">จัดเก็บสินค้า</span>
            </a>

            <a href="Employee_Account.php" class="menu-item" onclick="setActiveMenu(this)">
                <div class="icon-medium">&#128101;</div>
                <span style="font-size:18px; color: #000000;">จัดการพนักงาน</span>
            </a>

            <a href="shop.php" class="menu-item" onclick="setActiveMenu(this)">
                <div class="icon-medium">&#127978;</div>
                <span style="font-size:18px; color: #000000;">จัดการร้านค้า</span>
            </a>

            <a href="reports.php" class="menu-item" onclick="setActiveMenu(this)">
                <div class="icon-medium">&#128200;</div>
                <span style="font-size:18px; color: #000000;">รายงาน</span>
            </a>

            <a href="settings.php" class="menu-item" onclick="setActiveMenu(this)">
                <div class="icon-medium">⚙️</div>
                <span style="font-size:18px; color: #000000;">ตั้งค่าระบบ</span>
            </a>
        </div>

        <div class="content">
            <!-- หัวเรื่องที่อยู่ด้านซ้าย -->
            <h2 class="page-header">ระบบจัดการคลังสินค้า</h2>
            
            <div class="empty-state">
                <div class="icon-medium2"><h3>💼</h3></div>
                <br>
                <div class="empty-title"><h3 style="font-size:24px;">ยินดีต้อนรับสู่ระบบจัดการคลังสินค้า</h3></div>
                <br>
                <div class="empty-description"><p>
                    ระบบพร้อมใช้งานแล้ว แต่ยังไม่มีข้อมูลสินค้าในคลัง<br>
                    คุณสามารถเริ่มต้นด้วยการเพิ่มสินค้าใหม่เข้าคลัง</p>
                </div>
                <br><br>
                <button class="add-btn" onclick="window.location.href='Addproduct.php'">
                    + เพิ่มสินค้าใหม่
                </button>
            </div>
        </div>
    </div>

</body>
</html>