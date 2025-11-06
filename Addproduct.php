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
$conn->set_charset("utf8"); // เพิ่มการตั้งค่า charset

// ตรวจสอบการเชื่อมต่อ
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// === ส่วนเพิ่มเติม: ดึงข้อมูลสำหรับแจ้งเตือน ===
// ดึงข้อมูลสินค้าใกล้หมด (Reorder Point)
$low_stock_products = [];
$low_stock_sql = "SELECT pd_ID, pd_name, pd_number, pd_unit FROM product WHERE CAST(pd_number AS UNSIGNED) <= 10 AND CAST(pd_number AS UNSIGNED) > 0 ORDER BY CAST(pd_number AS UNSIGNED) ASC";
$low_stock_result = $conn->query($low_stock_sql);
if ($low_stock_result && $low_stock_result->num_rows > 0) {
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

// ตรวจสอบการ submit
$show_alert = false;
$error_message = "";

// ดึงรหัสสินค้าล่าสุดสำหรับแสดงในฟอร์ม
$sql_last_id = "SELECT pd_ID FROM product ORDER BY pd_ID DESC LIMIT 1";
$result_last_id = $conn->query($sql_last_id);

if ($result_last_id && $result_last_id->num_rows > 0) {
    $row_last_id = $result_last_id->fetch_assoc();
    $last_id = $row_last_id['pd_ID'];
    $last_number = intval(substr($last_id, 3));
    $new_number = $last_number + 1;
} else {
    $new_number = 1;
}
$pd_ID = "PD-" . sprintf('%04d', $new_number);

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // รับค่าจากฟอร์ม
    $pd_name = trim($_POST['productName']);
    $pd_number = intval($_POST['quantity']);
    $pd_unit = trim($_POST['unit']);
    $pd_details = trim($_POST['productDescription']);
    $pd_costprice = floatval($_POST['costPrice']);
    $pd_saleprice = floatval($_POST['sellPrice']);
    $zone_id = trim($_POST['storageLocation']);
    $selected_type_id = trim($_POST['typeProduct']);
    
    // ตรวจสอบประเภทสินค้าใหม่
    if ($selected_type_id === 'new' && !empty($_POST['newTypeProduct'])) {
        // สร้างรหัสประเภทสินค้าใหม่
        $sql_last_type = "SELECT typepd_ID FROM typepd ORDER BY typepd_ID DESC LIMIT 1";
        $result_last_type = $conn->query($sql_last_type);
        
        if ($result_last_type && $result_last_type->num_rows > 0) {
            $row_last_type = $result_last_type->fetch_assoc();
            $last_type_id = $row_last_type['typepd_ID'];
            $last_type_number = intval(substr($last_type_id, 2));
            $new_type_number = $last_type_number + 1;
        } else {
            $new_type_number = 1;
        }
        
        $typepd_ID = "T-" . sprintf('%03d', $new_type_number);
        $typepd_name = trim($_POST['newTypeProduct']);
        
        // บันทึกประเภทสินค้าใหม่
        $stmt_type = $conn->prepare("INSERT INTO typepd (typepd_ID, typepd_name) VALUES (?, ?)");
        $stmt_type->bind_param("ss", $typepd_ID, $typepd_name);
        
        if ($stmt_type->execute()) {
            $selected_type_id = $typepd_ID;
        } else {
            $error_message = "เกิดข้อผิดพลาดในการเพิ่มประเภทสินค้า: " . $stmt_type->error;
        }
        $stmt_type->close();
    } elseif ($selected_type_id === 'new' && empty($_POST['newTypeProduct'])) {
        $error_message = "กรุณากรอกชื่อประเภทสินค้าใหม่";
    }
    
    // บันทึกสินค้า
    if (empty($error_message)) {
        // การจัดการอัปโหลดรูปภาพ
        $uploadDir = 'uploads/';
        if (!is_dir($uploadDir)) {
            if (!mkdir($uploadDir, 0755, true)) {
                $error_message = "ไม่สามารถสร้างโฟลเดอร์ uploads ได้";
            }
        }

        $image_names = [];
        
        if (isset($_FILES['images']) && !empty($_FILES['images']['name'][0])) {
            for ($i = 0; $i < count($_FILES['images']['name']) && $i < 3; $i++) {
                if ($_FILES['images']['error'][$i] === UPLOAD_ERR_OK) {
                    $tmp_name = $_FILES['images']['tmp_name'][$i];
                    $original_name = basename($_FILES['images']['name'][$i]);
                    $fileExtension = pathinfo($original_name, PATHINFO_EXTENSION);
                    $newFileName = uniqid() . time() . '.' . $fileExtension;
                    $targetPath = $uploadDir . $newFileName;
                    
                    if (move_uploaded_file($tmp_name, $targetPath)) {
                        $image_names[] = $newFileName;
                    } else {
                        $error_message = "ไม่สามารถอัปโหลดไฟล์รูปภาพได้";
                    }
                }
            }
        }

        // เตรียมข้อมูลสำหรับบันทึก
        $pd_image1 = isset($image_names[0]) ? $image_names[0] : '';
        $pd_image2 = isset($image_names[1]) ? $image_names[1] : '';
        $pd_image3 = isset($image_names[2]) ? $image_names[2] : '';
        $shop_ID = 'S001'; // กำหนดร้านค้าเริ่มต้น

        // บันทึกลงฐานข้อมูล (เอา pd_pointofpurchase ออกตามโครงสร้างฐานข้อมูล)
        $sql_product = "INSERT INTO product (pd_ID, pd_name, pd_number, pd_unit, pd_details, pd_costprice, pd_saleprice, pd_image1, pd_image2, pd_image3, typepd_ID, zone_id, shop_ID) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
        $stmt_product = $conn->prepare($sql_product);
        if ($stmt_product) {
            $stmt_product->bind_param("sssssddssssss", $pd_ID, $pd_name, $pd_number, $pd_unit, $pd_details, $pd_costprice, $pd_saleprice, $pd_image1, $pd_image2, $pd_image3, $selected_type_id, $zone_id, $shop_ID);

            if ($stmt_product->execute()) {
                $_SESSION['success_message'] = "เพิ่มสินค้าสำเร็จ!";
                header("Location: Manage_products.php");
                exit();
            } else {
                $error_message = "เกิดข้อผิดพลาดในการเพิ่มสินค้า: " . $stmt_product->error;
            }
            $stmt_product->close();
        } else {
            $error_message = "เกิดข้อผิดพลาดในการเตรียมคำสั่ง SQL: " . $conn->error;
        }
    }
}

// ดึงประเภทสินค้าจากฐานข้อมูลเพื่อแสดงใน dropdown
$type_options = "";
$sql_types = "SELECT typepd_ID, typepd_name FROM typepd ORDER BY typepd_name";
$result_types = $conn->query($sql_types);

if ($result_types && $result_types->num_rows > 0) {
    while($row_type = $result_types->fetch_assoc()) {
        $type_options .= "<option value='".$row_type['typepd_ID']."'>".$row_type['typepd_name']."</option>";
    }
}

// ดึงโซนจัดเก็บจากฐานข้อมูล
$zone_options = "";
$sql_zones = "SELECT zone_id, zone_name FROM storage_zones ORDER BY zone_name";
$result_zones = $conn->query($sql_zones);

if ($result_zones && $result_zones->num_rows > 0) {
    while($row_zone = $result_zones->fetch_assoc()) {
        $zone_options .= "<option value='".$row_zone['zone_id']."'>".$row_zone['zone_name']."</option>";
    }
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>หน้าเพิ่มสินค้า</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        h1 {
            border: 2.6px solid #ffffff;
            padding: 20px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            background-color: #fff;
            position: sticky;
            top: 0;
            z-index: 100;
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
            position: sticky;
            top: 94px;
            height: calc(100vh - 94px);
            overflow-y: auto;
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

        /* ส่วนฟอร์มกรอกข้อมูล */
        .product-form-container {
            width: 100%;
            max-width: 1000px;
            margin-top: 80px;
        }

        .product-form {
            background-color: #fff;
            border: 1px solid #ccc;
            border-radius: 10px;
            padding: 30px;
            margin-bottom: 30px;
        }

        .form-section {
            margin-bottom: 20px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 1px;
            font-size: 16px;
        }

        .form-control {
            width: 100%;
            padding: 10px;
            border: 1px solid #ccc;
            border-radius: 8px;
            font-size: 16px;
            box-sizing: border-box;
        }

        textarea.form-control {
            min-height: 120px;
            resize: vertical;
        }

        /* ปุ่ม */
        .form-actions {
            display: flex;
            justify-content: center;
            gap: 15px;
            margin-top: 30px;
            width: 100%; /* เพิ่มเพื่อให้จัดกลางได้ถูกต้อง */
        }

        .btn {
            padding: 12px 24px;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            border: none;
        }

        .btn-primary {
            background: linear-gradient(135deg, #44944B, #44944B);
            color: white;
            box-shadow: 0 4px 15px rgba(50, 26, 60, 0.3);
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(50, 26, 60, 0.4);
            background: linear-gradient(135deg, #306A35, #306A35);
        }

        .btn-secondary {
            background: linear-gradient(135deg, #e74c3c, #e74c3c);
            color: white;
            box-shadow: 0 4px 15px rgba(50, 26, 60, 0.3);
        }

        .btn-secondary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(50, 26, 60, 0.4);
            background: linear-gradient(135deg, #c0392b, #c0392b);
        }

        /* ทำให้กล่องข้อความสีอ่อน*/
        select {
            padding: 10px;
            border: 1px solid #ccc;
            border-radius: 8px;
            font-size: 16px;
            width: 100%;
        }

        /* ทำให้ option แรกเป็นสีเทาอ่อน */
        select option:first-child {
            color: #aaa;
        }

        /*ทำเป็น 3 ท่อน*/
        .input-group {
            display: flex;
            justify-content: space-between;
            gap: 20px;
            margin-bottom: 20px;
            flex-wrap: nowrap;
        }

        .input-field {
            display: flex;
            flex-direction: column;
            width: 100%;
        }

        .input-field label {
            font-size: 16px;
            margin-bottom: 1px;
        }

        .input-field input {
            padding: 10px;
            border: 1px solid #ccc;
            border-radius: 8px;
            font-size: 16px;
            width: 100%;
            box-sizing: border-box;
        }

        /* ส่วนของ dropdown user */
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

        /* ข้อมูลรหัสสินค้า */
        .product-id-display {
            background-color: #f0f0f0;
            border-radius: 8px;
            padding: 10px;
            font-size: 16px;
            margin-bottom: 10px;
        }

        /* เพิ่มสไตล์สำหรับการแจ้งเตือนแบบกลางจอ */
        .alert {
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%) translateX(150%);
            padding: 20px 30px;
            border-radius: 8px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.2);
            z-index: 1000;
            display: none;
            align-items: center;
            gap: 15px;
            max-width: 80%;
            text-align: center;
            transition: transform 0.6s ease-out;
            background-color: #F3FFF4;
            border: 1px solid #055D19;
            color: #055D19;
        }

        .alert.show {
            transform: translate(-50%, -50%) translateX(0);
            display: flex;
        }

        .alert-icon {
            font-size: 24px;
        }

        .alert-close {
            background: none;
            border: none;
            cursor: pointer;
            color: inherit;
            font-size: 18px;
            margin-left: 15px;
        }

        .error-message {
            background-color: #ffebee;
            color: #c62828;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
            border: 1px solid #ffcdd2;
        }

        /* === ส่วนเพิ่มเติม: Modal สำหรับแจ้งเตือน === */
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

        /* Responsive adjustments */
        @media (max-width: 768px) {
            .input-group {
                flex-direction: column;
                gap: 15px;
            }
            
            .product-form {
                padding: 20px;
            }
            
            .content {
                padding: 1rem;
            }
            
            .alert {
                max-width: 90%;
                padding: 15px 20px;
            }
        }

        .center-wrapper {
            display: flex;
            justify-content: center;
            align-items: center;
        }

        .upload-box {
            background-color: #fff;
            border: 2px dashed #ccc;
            border-radius: 12px;
            padding: 40px;
            text-align: center;
            width: 400px;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
        }

        .upload-box img {
            width: 60px;
            margin-bottom: 20px;
        }

        .upload-box h3 {
            margin-bottom: 10px;
            font-size: 18px;
            color: #333;
        }

        .upload-box p {
            margin-bottom: 20px;
            color: #666;
        }

        .upload-box input[type="file"] {
            display: none;
        }

        .upload-box label {
            display: inline-block;
            padding: 10px 20px;
            background-color: #6D8B9A;
            color: white;
            border-radius: 6px;
            cursor: pointer;
            font-weight: bold;
        }

        .preview-row {
            display: flex;
            flex-direction: row;
            gap: 15px;
            margin-top: 20px;
            overflow-x: auto;
            padding-bottom: 10px;
        }

        .preview-item {
            position: relative;
            width: 150px;
            height: 150px;
            border: 1px solid #ccc;
            border-radius: 8px;
            overflow: hidden;
            background-color: #f9f9f9;
            flex-shrink: 0;
        }

        .preview-item img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .remove-btn {
            position: absolute;
            top: 5px;
            right: 5px;
            background-color: rgba(255, 0, 0, 0.7);
            color: white;
            border: none;
            border-radius: 50%;
            width: 24px;
            height: 24px;
            cursor: pointer;
            font-weight: bold;
        }
    </style>
</head>
<body>
    <h1 style="font-size:25px; font-family: 'Microsoft Sans Serif'; display: flex; justify-content: space-between; 
    align-items: center;">
        <div style="display: flex; align-items: center;">
            <img src="logo1.png" width="50" height="50" style="vertical-align: middle;">
            <span style="vertical-align: middle;">Chutikarn</span>
        </div>
        
        <div class="user-section" style="display: flex; align-items: center; gap: 15px;">
            <!-- กระดิ่งแจ้งเตือนพร้อมเลขทับ -->
            <div class="notification-container">
                <div class="bell-icon" onclick="showNotificationModal()">&#128276;</div>
                <?php if ($low_stock_count > 0 || $recent_received_count > 0): ?>
                    <div class="notification-badge"><?php echo $low_stock_count + $recent_received_count; ?></div>
                <?php endif; ?>
            </div>
        
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
            
            <div class="user-info" style="text-align: right;">
                <div class="user-username" style="font-size:16px; font-weight: bold;"><?php echo htmlspecialchars($user_username); ?></div>
                <div class="user-role" style="font-size:16px; color: #666;"><?php echo htmlspecialchars($user_type); ?></div>
            </div>
        </div>
    </h1>

    <!-- === ส่วนเพิ่มเติม: Modal สำหรับแจ้งเตือน === -->
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

    <div id="successAlert" class="alert alert-success">
        <span class="alert-icon">✓</span>
        <span>เพิ่มสินค้าสำเร็จ! ระบบกำลังนำคุณกลับไปที่หน้ารายการสินค้า...</span>
        <button class="alert-close" onclick="hideAlert()">×</button>
    </div>

    <div class="main-container">
        <div class="sidebar">
            <a href="Dashboard.php" class="menu-item" onclick="setActiveMenu(this)">
                <div class="icon-medium">&#127968;</div>
                <span style="font-size:18px; color: #000000;">หน้าแรก</span>
            </a>

            <a href="Manage_products.php" class="menu-item active" onclick="setActiveMenu(this)">
                <div class="icon-medium">&#128230;</div>
                <span style="font-size:18px; color: #A44A3F;">จัดการสินค้า</span>
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
            <h2 class="page-header" style="font-size:28px;">+ เพิ่มสินค้าใหม่</h2>

            <div class="product-form-container">
                <?php if (!empty($error_message)): ?>
                    <div class="error-message">
                        <strong>ข้อผิดพลาด:</strong> <?php echo htmlspecialchars($error_message); ?>
                    </div>
                <?php endif; ?>

                <form id="productForm" action="Addproduct.php" method="post" class="product-form" enctype="multipart/form-data">
                    <div class="form-section">
                        <div class="form-group">
                            <label>รหัสสินค้า</label>
                            <div class="product-id-display" style="font-size:16px; font-family: 'Microsoft Sans Serif'; 
                            display: flex; justify-content: space-between; align-items: center;">
                                <strong><?php echo $pd_ID; ?></strong>
                                <span style="color: gray;"></span>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="productName">ชื่อสินค้า</label>
                            <input type="text" id="productName" class="form-control" name="productName" required>
                        </div>
                        
                        <div class="input-group">
                            <div class="input-field">
                                <label for="typeProduct">ประเภทสินค้า</label>
                                <select id="typeProduct" class="form-control" name="typeProduct" required>
                                    <option value="">--เลือกประเภทสินค้า--</option>
                                    <?php echo $type_options; ?>
                                    <option value="new">--เพิ่มประเภทใหม่--</option>
                                </select>
                            </div>
                            <div class="input-field" id="newTypeGroup" style="display: none;">
                                <label for="newTypeProduct">ชื่อประเภทสินค้าใหม่</label>
                                <input type="text" id="newTypeProduct" class="form-control" name="newTypeProduct">
                            </div>

                            <div class="input-field">
                                <label for="unit">หน่วย</label>
                                <select id="unit" class="form-control" name="unit" required>
                                    <option value="">--เลือกหน่วยนับ--</option>
                                    <option value="ชิ้น">ชิ้น</option>
                                    <option value="อัน">อัน</option>
                                    <option value="ใบ">ใบ</option>
                                    <option value="ชุด">ชุด</option>
                                    <option value="ตัว">ตัว</option>
                                    <option value="โหล">โหล</option>
                                    <option value="เตา">เตา</option>
                                    <option value="ไห">ไห</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="input-group">
                            <div class="input-field">
                                <label for="costPrice">ราคาทุน</label>
                                <input type="number" id="costPrice" name="costPrice" step="0.01" class="form-control" required>
                            </div>
                            <div class="input-field">
                                <label for="sellPrice">ราคาขาย</label>
                                <input type="number" id="sellPrice" name="sellPrice" step="0.01" class="form-control" required>
                            </div>
                            <div class="input-field">
                                <label for="quantity">จำนวนสินค้า</label>
                                <input type="number" id="quantity" name="quantity" step="1" min="0" class="form-control" required>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="storageLocation">ตำแหน่งจัดเก็บในคลัง</label>
                            <select id="storageLocation" class="form-control" name="storageLocation" required>
                                <option value="">--เลือกตำแหน่งจัดเก็บ--</option>
                                <?php echo $zone_options; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="productDescription">รายละเอียดสินค้า</label>
                            <textarea id="productDescription" class="form-control" rows="3" name="productDescription"></textarea>
                        </div>

                        <div class="center-wrapper">
                            <div class="upload-box">
                                <img src="img01.png" alt="upload icon">
                                <h3>เลือกไฟล์ที่ต้องการอัปโหลด</h3>
                                <p>ลากและวางไฟล์รูปภาพ</p>
                                <label for="fileUpload">เลือกไฟล์</label>
                                <input type="file" id="fileUpload" name="images[]" multiple accept="image/*">

                                <div id="preview" class="preview-row"></div>
                            </div>
                        </div>

                    <div class="form-actions">
                        <button type="button" class="btn btn-secondary" onclick="window.location.href='Manage_products.php'">ยกเลิก</button>
                        <button type="submit" class="btn btn-primary">บันทึกข้อมูล</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

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
        
        // ปิด modal เมื่อคลิกนอก modal
        var modal = document.getElementById("notificationModal");
        if (event.target == modal) {
            modal.style.display = "none";
        }
    }

    // ฟังก์ชันสำหรับตั้งค่าเมนูที่ active
    function setActiveMenu(menuItem) {
        const menuItems = document.querySelectorAll('.menu-item');
        menuItems.forEach(item => {
            item.classList.remove('active');
            item.querySelector('span').style.color = '#000000';
        });
        
        menuItem.classList.add('active');
        menuItem.querySelector('span').style.color = '#A44A3F';
    }

    // ฟังก์ชันแสดงการแจ้งเตือนแบบกลางจอ
    function showSuccessAlert() {
        const alert = document.getElementById('successAlert');
        alert.style.display = 'flex';
        setTimeout(() => {
            alert.classList.add('show');
        }, 100);
        
        // หลังจาก 3 วินาที ให้ซ่อนการแจ้งเตือนและ redirect
        setTimeout(() => {
            hideAlert();
        }, 3000);
    }

    // ฟังก์ชันซ่อนการแจ้งเตือน
    function hideAlert() {
        const alert = document.getElementById('successAlert');
        alert.classList.remove('show');
        setTimeout(() => {
            alert.style.display = 'none';
            window.location.href = 'Manage_products.php';
        }, 500);
    }

    // === ส่วนเพิ่มเติม: JavaScript สำหรับการแจ้งเตือน ===
    function showNotificationModal() {
        document.getElementById("notificationModal").style.display = "block";
    }
    
    function closeNotificationModal() {
        document.getElementById("notificationModal").style.display = "none";
    }

    // จัดการการ submit form
    document.getElementById('productForm').addEventListener('submit', function(e) {
        // ตรวจสอบความถูกต้องของฟอร์มก่อน submit
        const typeProduct = document.getElementById('typeProduct').value;
        const newTypeProduct = document.getElementById('newTypeProduct').value;
        
        if (typeProduct === 'new' && !newTypeProduct) {
            e.preventDefault();
            alert('กรุณากรอกชื่อประเภทสินค้าใหม่');
            document.getElementById('newTypeProduct').focus();
            return;
        }
        
        // ตรวจสอบฟิลด์ที่จำเป็น
        const requiredFields = ['productName', 'unit', 'costPrice', 'sellPrice', 'quantity', 'storageLocation'];
        for (let field of requiredFields) {
            const element = document.getElementById(field);
            if (!element.value.trim()) {
                e.preventDefault();
                alert('กรุณากรอกข้อมูลให้ครบถ้วน');
                element.focus();
                return;
            }
        }
        
        // ตรวจสอบราคา
        const costPrice = parseFloat(document.getElementById('costPrice').value);
        const sellPrice = parseFloat(document.getElementById('sellPrice').value);
        if (costPrice <= 0 || sellPrice <= 0) {
            e.preventDefault();
            alert('ราคาทุนและราคาขายต้องมากกว่า 0');
            return;
        }
        
        if (sellPrice < costPrice) {
            e.preventDefault();
            alert('ราคาขายต้องมากกว่าหรือเท่ากับราคาทุน');
            return;
        }
    });

    // แสดงการแจ้งเตือนหากมีการ submit form สำเร็จ
    <?php if (isset($_SESSION['success_message'])): ?>
    document.addEventListener('DOMContentLoaded', function() {
        showSuccessAlert();
        <?php unset($_SESSION['success_message']); ?>
    });
    <?php endif; ?>

    // จัดการประเภทสินค้าใหม่
    document.getElementById('typeProduct').addEventListener('change', function() {
        const newTypeGroup = document.getElementById('newTypeGroup');
        if (this.value === 'new') {
            newTypeGroup.style.display = 'block';
            document.getElementById('newTypeProduct').required = true;
        } else {
            newTypeGroup.style.display = 'none';
            document.getElementById('newTypeProduct').required = false;
        }
    });

    // จัดการอัปโหลดรูปภาพ
    const fileInput = document.getElementById('fileUpload');
    const preview = document.getElementById('preview');

    fileInput.addEventListener('change', function () {
        preview.innerHTML = ''; // เคลียร์ภาพเก่า
        const files = Array.from(this.files).slice(0, 3); // จำกัดสูงสุด 3 ไฟล์
        
        if (files.length > 3) {
            alert('สามารถอัปโหลดได้สูงสุด 3 ไฟล์');
            this.value = ''; // รีเซ็ต input
            return;
        }

        files.forEach((file, index) => {
            if (file.type.startsWith('image/')) {
                const reader = new FileReader();
                reader.onload = function (e) {
                    const item = document.createElement('div');
                    item.className = 'preview-item';
                    item.dataset.index = index;

                    const img = document.createElement('img');
                    img.src = e.target.result;

                    const removeBtn = document.createElement('button');
                    removeBtn.className = 'remove-btn';
                    removeBtn.innerHTML = '×';
                    removeBtn.onclick = () => {
                        removeFile(index);
                        item.remove();
                    };

                    item.appendChild(img);
                    item.appendChild(removeBtn);
                    preview.appendChild(item);
                };
                reader.readAsDataURL(file);
            }
        });
    });

    function removeFile(index) {
        const dt = new DataTransfer();
        const files = Array.from(fileInput.files);
        files.splice(index, 1);
        
        files.forEach(file => dt.items.add(file));
        fileInput.files = dt.files;
        
        // อัพเดต preview
        const previewItems = document.querySelectorAll('.preview-item');
        previewItems.forEach(item => {
            if (parseInt(item.dataset.index) === index) {
                item.remove();
            }
        });
    }

    // ฟังก์ชันสำหรับลากและวางไฟล์
    const uploadBox = document.querySelector('.upload-box');
    uploadBox.addEventListener('dragover', (e) => {
        e.preventDefault();
        uploadBox.style.borderColor = '#6D8B9A';
        uploadBox.style.backgroundColor = '#f0f8ff';
    });

    uploadBox.addEventListener('dragleave', () => {
        uploadBox.style.borderColor = '#ccc';
        uploadBox.style.backgroundColor = '#fff';
    });

    uploadBox.addEventListener('drop', (e) => {
        e.preventDefault();
        uploadBox.style.borderColor = '#ccc';
        uploadBox.style.backgroundColor = '#fff';
        
        const files = e.dataTransfer.files;
        if (files.length > 0) {
            fileInput.files = files;
            fileInput.dispatchEvent(new Event('change'));
        }
    });

    // ตรวจสอบฟอร์มเมื่อโหลดหน้าเสร็จ
    document.addEventListener('DOMContentLoaded', function() {
        // ตรวจสอบว่ามีข้อผิดพลาดจาก PHP หรือไม่
        <?php if (!empty($error_message)): ?>
            // โฟกัสไปที่ฟิลด์แรกถ้ามีข้อผิดพลาด
            document.getElementById('productName').focus();
        <?php endif; ?>
    });
    </script>
</body>
</html>