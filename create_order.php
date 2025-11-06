<?php
session_start();

// === ส่วนจัดการ AJAX request สำหรับดึงข้อมูลสินค้า ===
if (isset($_GET['action']) && $_GET['action'] == 'get_products') {
    
    header('Content-Type: application/json; charset=utf-8');

    // 1. เชื่อมต่อฐานข้อมูล
    $servername = "localhost";
    $username = "root";
    $password = "12345678"; // กรุณาเปลี่ยนรหัสผ่านตามจริง
    $dbname = "chutikarnceramic1";
    $conn = new mysqli($servername, $username, $password, $dbname);
    $conn->set_charset("utf8");

    if ($conn->connect_error) {
        echo json_encode(['error' => 'Connection failed: ' . $conn->connect_error]);
        exit();
    }

    // 2. Query ข้อมูลสินค้าทั้งหมดจากตาราง product
    $products = [];
    $sql = "SELECT pd_ID, pd_name, pd_costprice FROM product ORDER BY pd_name ASC";
    
    $result = $conn->query($sql);
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $products[] = $row;
        }
    }
    
    $conn->close();

    // 3. ส่งข้อมูลกลับเป็น JSON
    echo json_encode($products);
    exit(); 
}
// === จบส่วนจัดการ AJAX request ===

// === ส่วนจัดการข้อมูลสินค้าใกล้หมดจากหน้า Order.php ===
$prefilled_products = [];
$is_prefilled = false;
$prefilled_count = 0;

// ตรวจสอบว่ามีข้อมูลสินค้าที่เตรียมไว้ใน session หรือไม่
if (isset($_SESSION['prefilled_products']) && !empty($_SESSION['prefilled_products'])) {
    $prefilled_products = $_SESSION['prefilled_products'];
    $is_prefilled = true;
    $prefilled_count = count($prefilled_products);
    
    // เก็บข้อมูลไว้ใช้ใน JavaScript แต่ยังไม่ลบ session จนกว่าจะโหลดเสร็จ
}

// ตรวจสอบพารามิเตอร์จาก URL
if (isset($_GET['prefilled']) && $_GET['prefilled'] === 'true') {
    $is_prefilled = true;
    $prefilled_count = isset($_GET['count']) ? intval($_GET['count']) : 0;
}
// === จบส่วนจัดการข้อมูลสินค้าใกล้หมด ===

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
$conn->set_charset("utf8");

// ตรวจสอบการเชื่อมต่อ
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// ดึงข้อมูลผู้ใช้ตาม username ที่ล็อกอิน
$user_username_session = $_SESSION['user_username'];
$sql = "SELECT user_ID, user_username, user_type FROM user WHERE user_username = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $user_username_session);
$stmt->execute();
$result = $stmt->get_result();

$user_ID = "";
$user_username = "ไม่พบข้อมูล";
$user_type = "ไม่พบข้อมูล";

if ($result->num_rows > 0) {
    $row = $result->fetch_assoc();
    $user_ID = $row["user_ID"];
    $user_username = $row["user_username"];
    $user_type = $row["user_type"];
}
$stmt->close();

// --- Fetch Data for Summary Cards ---
// Card 1: สินค้าทั้งหมด
$total_pieces_query = $conn->query("SELECT SUM(pd_number) as total_pieces FROM product");
$total_pieces_data = $total_pieces_query->fetch_assoc();
$total_pieces = $total_pieces_data['total_pieces'] ?? 0;

$total_items_query_count = $conn->query("SELECT COUNT(typepd_ID) as total_items FROM typepd");
$total_items_data = $total_items_query_count->fetch_assoc();
$total_items = $total_items_data['total_items'] ?? 0;

// === ส่วนที่ 1: แก้ไข/เพิ่ม PHP เพื่อดึงข้อมูลสินค้าใกล้หมดสำหรับแจ้งเตือน ===
$low_stock_products = [];
// ดึงรายละเอียดสินค้าที่ใกล้หมด (ชื่อ, จำนวน, หน่วย)
$low_stock_details_query = $conn->query("SELECT pd_name, pd_number, pd_unit FROM product WHERE pd_number <= 10 AND pd_number > 0 ORDER BY pd_number ASC");
if ($low_stock_details_query->num_rows > 0) {
    while($row = $low_stock_details_query->fetch_assoc()) {
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
// จบส่วนแก้ไข/เพิ่ม PHP

// --- ส่วนที่ 1: เตรียมข้อมูลสำหรับแสดงผลในฟอร์ม ---

// 1. ดึงข้อมูลร้านค้าทั้งหมดสำหรับ Dropdown
$shops = [];
$shop_sql = "SELECT shop_ID, shop_name FROM shop ORDER BY shop_name ASC";
$shop_result = $conn->query($shop_sql);
if ($shop_result && $shop_result->num_rows > 0) {
    while($row = $shop_result->fetch_assoc()) {
        $shops[] = $row;
    }
}

// 2. สร้างรหัสใบสั่งซื้อใหม่ (เช่น PO-0001)
$last_buypd_sql = "SELECT buypd_ID FROM buypd ORDER BY buypd_ID DESC LIMIT 1";
$last_buypd_result = $conn->query($last_buypd_sql);
if ($last_buypd_result && $last_buypd_result->num_rows > 0) {
    $last_row = $last_buypd_result->fetch_assoc();
    $last_id_num = (int)substr($last_row['buypd_ID'], 3);
    $new_id_num = $last_id_num + 1;
} else {
    $new_id_num = 1;
}
$new_buypd_ID = 'PO-' . sprintf('%04d', $new_id_num);

// --- ส่วนที่ 2: บันทึกข้อมูลลงฐานข้อมูลเมื่อกด SUBMIT ---
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    
    // รับข้อมูลหลัก
    $buypd_ID_from_form = $_POST['buypd_id'];
    $shop_ID = $_POST['shop_id'];
    $buypd_total = $_POST['grand_total'];
    $buypd_date = date("Y-m-d");
    $user_ID_who_saves = $user_ID;
    
    // รับข้อมูลสินค้า (มาในรูปแบบ Array)
    $product_ids = $_POST['product_id'];
    $quantities = $_POST['quantity'];
    $line_totals = $_POST['line_total'];

    // เริ่ม Transaction
    $conn->begin_transaction();

    try {
        // 1. บันทึกข้อมูลหลักลงตาราง `buypd`
        $sql_buypd = "INSERT INTO buypd (buypd_ID, buypd_total, buypd_date, user_ID, shop_ID) VALUES (?, ?, ?, ?, ?)";
        $stmt_buypd = $conn->prepare($sql_buypd);
        $stmt_buypd->bind_param("sdsss", $buypd_ID_from_form, $buypd_total, $buypd_date, $user_ID_who_saves, $shop_ID);
        $stmt_buypd->execute();
        $stmt_buypd->close();
        
        // 2. เตรียมคำสั่งสำหรับบันทึกรายการสินค้าลง `orderdetails`
        $sql_details = "INSERT INTO orderdetails (OD_ID, OD_number, OD_amount, buypd_ID, pd_name) VALUES (?, ?, ?, ?, ?)";
        $stmt_details = $conn->prepare($sql_details);

        // 3. วนลูปเพื่อบันทึกสินค้าทุกรายการ
        for ($i = 0; $i < count($product_ids); $i++) {
            $pd_id = $product_ids[$i];
            $qty = $quantities[$i];
            $amount = $line_totals[$i];
            
            // สร้าง OD_ID ที่ไม่ซ้ำกัน (เช่น PO-0001-1, PO-0001-2)
            $od_id = $buypd_ID_from_form . '-' . ($i + 1);

            // ดึงชื่อสินค้าจากตาราง product โดยใช้ pd_ID
            $product_name_sql = "SELECT pd_name FROM product WHERE pd_ID = ?";
            $stmt_name = $conn->prepare($product_name_sql);
            $stmt_name->bind_param("s", $pd_id);
            $stmt_name->execute();
            $name_result = $stmt_name->get_result();
            $product_data = $name_result->fetch_assoc();
            $pd_name = $product_data['pd_name'];
            $stmt_name->close();
            
            // ส่งค่าสำหรับสินค้าแต่ละรายการเข้าไปในคำสั่ง SQL แล้ว execute
            $stmt_details->bind_param("sidss", $od_id, $qty, $amount, $buypd_ID_from_form, $pd_name);
            $stmt_details->execute();
        }
        $stmt_details->close();

        // หากทุกอย่างสำเร็จ ยืนยันการบันทึก
        $conn->commit();
        $_SESSION['success_message'] = "บันทึกใบสั่งซื้อ " . htmlspecialchars($buypd_ID_from_form) . " เรียบร้อยแล้ว!";

    } catch (mysqli_sql_exception $exception) {
        // หากมีข้อผิดพลาด ให้ยกเลิกการบันทึกทั้งหมด
        $conn->rollback();
        $_SESSION['error_message'] = "เกิดข้อผิดพลาดในการบันทึก: " . $exception->getMessage();
    }
    
    $conn->close();

    // กลับไปที่หน้ารายการ (สมมติว่าชื่อไฟล์คือ Order.php)
    header("Location: Order.php");
    exit();
}

// ปิดการเชื่อมต่อ (สำหรับกรณีที่ไม่มีการ POST)
if ($_SERVER["REQUEST_METHOD"] != "POST") {
    $conn->close();
}
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>สร้างใบสั่งซื้อ</title>
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

        .user-section { display: flex; align-items: center; gap: 20px; }
        .user-info { text-align: right; }
        .user-username { font-size: 16px; font-weight: bold; }
        .user-role { font-size: 16px; color: #666; }
        .user-avatar { font-size: 25px; width: 40px; height: 40px; border-radius: 50%; background: #f0f0f0; display: flex;
            align-items: center; justify-content: center; cursor: pointer; }
        
        .dropdown { position: relative; display: inline-block; }
        .dropdown-content { display: none; position: absolute; right: 0; background-color: #f9f9f9; min-width: 160px; box-shadow: 0px 8px 16px 0px rgba(0,0,0,0.2); z-index: 1; border-radius: 4px; overflow: hidden; }
        .dropdown-content a { color: black; padding: 12px 16px; text-decoration: none; display: block; font-size: 14px; }
        .dropdown-content a:hover { background-color: #f1f1f1; }
        .show { display: block; }
        .logout { color: #e74c3c !important; }
        
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
        
        .menu-icon { width: 20px; height: 20px; display: flex; align-items: center; justify-content: center; }
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
        
        .page-header { color: #A44A3F; font-size: 30px; font-weight: bold; margin-bottom: 30px; text-align: left; }
        .order-container { background-color: #fff; padding: 30px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .form-row { display: flex; gap: 20px; margin-bottom: 20px; }
        .form-group { flex: 1; }
        .form-group label { display: block; margin-bottom: 8px; font-weight: 500; color: #333; }
        .form-control, .form-select { width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 5px; font-size: 16px; }
        .form-control-plaintext { padding: 10px; background-color: #f8f9fa; border: 1px solid #ddd; border-radius: 5px; }
        .product-adder { display: flex; gap: 10px; align-items: flex-end; padding: 20px; background-color: #f8f9fa; border-radius: 5px; margin-bottom: 20px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        th, td { padding: 12px 15px; text-align: left; border-bottom: 1px solid #dee2e6; }
        th { background-color: #f8f9fa; font-weight: 600; }
        .text-right { text-align: right; }
        .text-center { text-align: center; }
        .summary-row { display: flex; justify-content: flex-end; }
        .summary-box { width: 300px; }
        .summary-item { display: flex; justify-content: space-between; padding: 10px; font-size: 18px; }
        .summary-item.total { font-weight: bold; font-size: 22px; color: #c0392b; border-top: 2px solid #333; margin-top: 10px; }
        .action-buttons { display: flex; justify-content: flex-end; gap: 15px; margin-top: 30px; }
        .btn { padding: 12px 25px; border: none; border-radius: 5px; font-size: 16px; font-weight: bold; cursor: pointer; transition: all 0.3s; }
        .btn:disabled { background-color: #ccc; cursor: not-allowed; }
        .btn-primary { background-color: #27a745; color: white; }
        .btn-primary:hover:not(:disabled) { background-color: #218838; }
        .btn-secondary { background-color: #6c757d; color: white; }
        .btn-secondary:hover { background-color: #5a6268; }
        .btn-danger { background-color: #dc3545; color: white; padding: 5px 10px; font-size: 14px; }
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
        .prefilled-notice { background-color: #d4edda; border: 1px solid #c3e6cb; color: #155724; padding: 15px; border-radius: 5px; margin-bottom: 20px; }
        
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

            .form-row {
                flex-direction: column;
                gap: 10px;
            }

            .product-adder {
                flex-direction: column;
                align-items: stretch;
            }

            .btn-group {
                flex-direction: column;
                gap: 10px;
            }

            .btn {
                min-width: auto;
                width: 100%;
            }

            .summary-box {
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
        <h2 class="page-header">สร้างใบสั่งซื้อ</h2>

        <!-- แสดงข้อความเมื่อโหลดข้อมูลสินค้าใกล้หมด -->
        <?php if ($is_prefilled && $prefilled_count > 0): ?>
            <div class="prefilled-notice">
                <i class="fas fa-info-circle"></i> โหลดข้อมูลสินค้าใกล้หมด <?php echo $prefilled_count; ?> รายการเรียบร้อยแล้ว
            </div>
        <?php endif; ?>

        <form id="orderForm" method="POST" action="create_order.php">
            <div class="order-container">
                <div class="form-row">
                    <div class="form-group">
                        <label>เลขที่ใบสั่งซื้อ</label>
                        <input type="text" class="form-control-plaintext" name="buypd_id" value="<?php echo htmlspecialchars($new_buypd_ID); ?>" readonly>
                    </div>
                    <div class="form-group">
                        <label>วันที่สั่งซื้อ</label>
                        <input type="text" class="form-control-plaintext" value="<?php echo date("d/m/Y"); ?>" readonly>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="shop_id">เลือกร้านค้า</label>
                        <select id="shop_id" name="shop_id" class="form-select" required>
                            <option value="">-- กรุณาเลือกร้านค้า --</option>
                            <?php foreach($shops as $shop): ?>
                                <option value="<?php echo htmlspecialchars($shop['shop_ID']); ?>"><?php echo htmlspecialchars($shop['shop_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>ผู้จัดทำ</label>
                        <input type="text" class="form-control-plaintext" value="<?php echo htmlspecialchars($user_username); ?>" readonly>
                    </div>
                </div>

                <hr style="margin: 30px 0;">

                <div class="product-adder">
                    <div class="form-group" style="flex: 3;">
                        <label for="product_select">เลือกสินค้า</label>
                        <select id="product_select" class="form-select" disabled>
                            <option value="">-- กำลังโหลดข้อมูลสินค้า... --</option>
                        </select>
                    </div>
                    <button type="button" id="addProductBtn" class="btn btn-primary" 
                    style="padding: 10px 15px; font-weight: normal; background-color:#c0392b;" disabled>+ เพิ่มรายการ</button>
                </div>

                <table>
                    <thead>
                        <tr>
                            <th style="width: 5%;">#</th>
                            <th>สินค้า</th>
                            <th style="width: 15%;" class="text-right">ราคา/หน่วย</th>
                            <th style="width: 15%;" class="text-center">จำนวน</th>
                            <th style="width: 15%;" class="text-right">ยอดรวม</th>
                            <th style="width: 10%;" class="text-center">ลบ</th>
                        </tr>
                    </thead>
                    <tbody id="orderItems">
                    </tbody>
                </table>

                <div class="summary-row">
                    <div class="summary-box">
                        <div class="summary-item total">
                            <span>ยอดรวมทั้งสิ้น</span>
                            <span id="grandTotal">0.00</span>
                            <input type="hidden" name="grand_total" id="grand_total_input" value="0">
                        </div>
                    </div>
                </div>
                
                <div class="action-buttons">
                    <button type="button" class="btn btn-secondary" onclick="window.location.href='Order.php'">ยกเลิก</button>
                    <button type="submit" id="saveOrderBtn" class="btn btn-primary" disabled>บันทึกใบสั่งซื้อ</button>
                </div>
            </div>
        </form>
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
    
    // === ส่วนจัดการข้อมูลสินค้าใกล้หมด ===
    const prefilledProducts = <?php echo json_encode($prefilled_products); ?>;
    const isPrefilled = <?php echo $is_prefilled ? 'true' : 'false'; ?>;
    const prefilledCount = <?php echo $prefilled_count; ?>;
    
    document.addEventListener('DOMContentLoaded', function() {
    
    const productSelect = document.getElementById('product_select');
    const addProductBtn = document.getElementById('addProductBtn');
    const orderItemsTbody = document.getElementById('orderItems');
    const grandTotalSpan = document.getElementById('grandTotal');
    const grandTotalInput = document.getElementById('grand_total_input');
    const saveOrderBtn = document.getElementById('saveOrderBtn');

    let productsData = []; // เก็บข้อมูลสินค้าทั้งหมดที่โหลดมา

    // 1. โหลดข้อมูลสินค้าทั้งหมดเมื่อหน้าเว็บพร้อมใช้งาน
    fetch(`create_order.php?action=get_products`)
        .then(response => response.json())
        .then(data => {
            if (data.error) {
                console.error(data.error);
                productSelect.innerHTML = '<option value="">-- เกิดข้อผิดพลาดในการโหลดสินค้า --</option>';
                return;
            }
            productsData = data;
            productSelect.innerHTML = '<option value="">-- เลือกสินค้า --</option>';
            if (data.length > 0) {
                data.forEach(product => {
                    const price = parseFloat(product.pd_costprice).toFixed(2);
                    const option = new Option(`${product.pd_name} (${price} บาท)`, product.pd_ID);
                    productSelect.add(option);
                });
                productSelect.disabled = false;
                addProductBtn.disabled = false;
                
                // 2. ถ้ามีข้อมูลสินค้าใกล้หมด ให้เติมข้อมูลอัตโนมัติ
                if (isPrefilled && prefilledProducts.length > 0) {
                    loadPrefilledProducts();
                }
            } else {
                productSelect.innerHTML = '<option value="">-- ไม่พบสินค้า --</option>';
            }
        })
        .catch(error => {
            console.error('Error fetching products:', error);
            productSelect.innerHTML = '<option value="">-- เกิดข้อผิดพลาดในการเชื่อมต่อ --</option>';
        });

    // ฟังก์ชันโหลดข้อมูลสินค้าใกล้หมดอัตโนมัติ
    function loadPrefilledProducts() {
        prefilledProducts.forEach(prefilled => {
            // หาข้อมูลสินค้าจาก productsData
            const product = productsData.find(p => p.pd_ID === prefilled.pd_ID);
            if (product) {
                addOrderItem(product, prefilled.recommended_quantity);
            }
        });
        
        // ลบ session หลังจากโหลดเสร็จ
        fetch('clear_prefilled_session.php')
            .then(response => response.json())
            .then(data => {
                console.log('Cleared prefilled session:', data);
            })
            .catch(error => {
                console.error('Error clearing session:', error);
            });
    }

    // 2. เมื่อกดปุ่ม "เพิ่มรายการ"
    addProductBtn.addEventListener('click', function() {
        const selectedProductId = productSelect.value;
        if (!selectedProductId) {
            alert('กรุณาเลือกสินค้า');
            return;
        }
        if (document.querySelector(`tr[data-id="${selectedProductId}"]`)) {
            alert('สินค้านี้ถูกเพิ่มในรายการแล้ว');
            return;
        }
        
        const product = productsData.find(p => p.pd_ID === selectedProductId);
        if (product) {
            addOrderItem(product);
        }
    });
    
    // 3. จัดการ Event ในตาราง (ลบ, เปลี่ยนจำนวน)
    orderItemsTbody.addEventListener('click', e => {
        if (e.target.classList.contains('remove-btn')) {
            e.target.closest('tr').remove();
            updateRowNumbers();
            updateGrandTotal();
        }
    });
    orderItemsTbody.addEventListener('input', e => {
        if (e.target.classList.contains('quantity-input')) {
            updateLineTotal(e.target.closest('tr'));
        }
    });

    // 4. ฟังก์ชันเพิ่มแถวสินค้าในตาราง
    function addOrderItem(product, recommendedQuantity = 1) {
        const rowCount = orderItemsTbody.rows.length;
        const newRow = orderItemsTbody.insertRow();
        newRow.setAttribute('data-id', product.pd_ID);
        const price = parseFloat(product.pd_costprice).toFixed(2);

        newRow.innerHTML = `
            <td class="text-center row-number">${rowCount + 1}</td>
            <td>
                ${product.pd_name}
                <input type="hidden" name="product_id[]" value="${product.pd_ID}">
                <input type="hidden" name="line_total[]" class="line-total-input" value="0.00">
            </td>
            <td class="text-right price-cell">${price}</td>
            <td class="text-center">
                <input type="number" name="quantity[]" class="form-control quantity-input" min="1" value="${recommendedQuantity}" style="width: 80px; text-align:center;">
            </td>
            <td class="text-right line-total">0.00</td>
            <td class="text-center">
                <button type="button" class="btn btn-danger remove-btn">X</button>
            </td>
        `;
        updateLineTotal(newRow); // คำนวณราคารวมทันที
    }
    
    // 5. ฟังก์ชันอัปเดตยอดรวมของแต่ละแถว
    function updateLineTotal(row) {
        const priceText = row.querySelector('.price-cell').textContent;
        const price = parseFloat(priceText) || 0;
        const quantityInput = row.querySelector('.quantity-input');
        let quantity = parseInt(quantityInput.value) || 0;
        
        if (quantity < 1) { 
            quantity = 1;
            quantityInput.value = 1;
        }
        
        const lineTotal = price * quantity;
        row.querySelector('.line-total').textContent = lineTotal.toFixed(2);
        row.querySelector('.line-total-input').value = lineTotal.toFixed(2);
        updateGrandTotal();
    }

    // 6. ฟังก์ชันอัปเดตยอดรวมทั้งหมด
    function updateGrandTotal() {
        const allLineTotals = document.querySelectorAll('.line-total');
        let grandTotal = 0;
        allLineTotals.forEach(td => { grandTotal += parseFloat(td.textContent); });
        
        grandTotalSpan.textContent = grandTotal.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        grandTotalInput.value = grandTotal.toFixed(2);

        // เปิด/ปิดปุ่มบันทึก
        saveOrderBtn.disabled = (orderItemsTbody.rows.length === 0);
    }

    // 7. ฟังก์ชันอัปเดตลำดับที่
    function updateRowNumbers() {
        const allRows = orderItemsTbody.querySelectorAll('tr');
        allRows.forEach((row, index) => {
            row.querySelector('.row-number').textContent = index + 1;
        });
    }

    // 8. ตรวจสอบฟอร์มก่อนส่ง
    document.getElementById('orderForm').addEventListener('submit', function(e) {
        if (orderItemsTbody.rows.length === 0) {
            alert('กรุณาเพิ่มรายการสินค้าอย่างน้อย 1 รายการ');
            e.preventDefault();
            return;
        }
        if (!document.getElementById('shop_id').value) {
            alert('กรุณาเลือกร้านค้า');
            e.preventDefault();
            return;
        }
        // ป้องกันการกดซ้ำ
        saveOrderBtn.disabled = true;
        saveOrderBtn.textContent = 'กำลังบันทึก...';
    });
});
</script>

</body>
</html>