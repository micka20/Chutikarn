<?php
session_start();
ob_start();

// === การตั้งค่าพื้นฐานและการเชื่อมต่อฐานข้อมูล ===
if (!isset($_SESSION['user_username'])) { 
    header("Location: login.php"); 
    exit(); 
}

// ฟังก์ชันจัดการข้อผิดพลาด
function handleError($message, $redirect_tab = 'pane-profile') {
    $_SESSION['error_message'] = $message;
    header("Location: settings.php?active_tab=" . $redirect_tab);
    exit();
}

function handleSuccess($message, $redirect_tab = 'pane-profile') {
    $_SESSION['success_message'] = $message;
    header("Location: settings.php?active_tab=" . $redirect_tab);
    exit();
}

// เชื่อมต่อฐานข้อมูล
$conn = new mysqli("localhost", "root", "12345678", "chutikarnceramic1");
$conn->set_charset("utf8");

if ($conn->connect_error) { 
    error_log("Database connection failed: " . $conn->connect_error);
    die("เกิดข้อผิดพลาดในการเชื่อมต่อฐานข้อมูล กรุณาลองใหม่อีกครั้ง");
}

// ใช้ user_ID จาก session ที่เก็บไว้ตอนล็อกอิน
$user_id_session = $_SESSION['user_ID'] ?? '0';

// สร้าง CSRF Token เพื่อความปลอดภัยของฟอร์ม
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// จดจำ Tab ที่เปิดล่าสุด
$active_tab = $_GET['active_tab'] ?? 'pane-profile';

// === ดึงข้อมูลการแจ้งเตือน (เหมือนในหน้าการจัดการร้านค้า) ===
$low_stock_products = [];
$recent_received_orders = [];
$low_stock_count = 0;
$recent_received_count = 0;
$user_data = [];
$reorder_point = 10;

try {
    // ดึงข้อมูลสินค้าใกล้หมด (Reorder Point)
    // (ปรับปรุง) ดึง reorder_point มาก่อน
    $settings = [];
    $result_settings = $conn->query("SELECT setting_key, setting_value FROM settings WHERE setting_key = 'reorder_point'");
    if ($result_settings) {
        $settings = $result_settings->fetch_assoc();
    }
    $reorder_point = $settings['setting_value'] ?? 10; // ใช้ค่าที่ดึงได้ หรือ 10 เป็นค่าเริ่มต้น

    // (ปรับปรุง) ใช้ $reorder_point ใน query
    $low_stock_sql = "SELECT pd_ID, pd_name, pd_number, pd_unit FROM product WHERE pd_number <= ? AND pd_number > 0 ORDER BY pd_number ASC";
    $stmt_low_stock = $conn->prepare($low_stock_sql);
    $stmt_low_stock->bind_param("i", $reorder_point);
    $stmt_low_stock->execute();
    $low_stock_result = $stmt_low_stock->get_result();

    if ($low_stock_result->num_rows > 0) {
        while($row = $low_stock_result->fetch_assoc()) {
            $low_stock_products[] = $row;
        }
    }
    $low_stock_count = count($low_stock_products);
    $stmt_low_stock->close();


    // ดึงข้อมูลสำหรับแจ้งเตือนสินค้าเข้าใหม่ (7 วันที่ผ่านมา)
    $recent_received_query = $conn->query("
        SELECT b.buypd_ID, b.buypd_date, s.shop_name, b.buypd_total
        FROM buypd b
        JOIN shop s ON b.shop_ID = s.shop_ID
        WHERE b.buypd_status = 'received' 
        AND b.buypd_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
        ORDER BY b.buypd_date DESC
    ");

    if ($recent_received_query) {
        while($row = $recent_received_query->fetch_assoc()){
            $recent_received_orders[] = $row;
            $recent_received_count++;
        }
    }

    // (ย้ายการดึง $reorder_point ไปไว้ด้านบนแล้ว)
    // ดึงข้อมูลการตั้งค่า (เผื่อมีอย่างอื่น)
    $settings_all = [];
    $result = $conn->query("SELECT setting_key, setting_value FROM settings");
    if ($result) {
        while($row = $result->fetch_assoc()) {
            $settings_all[$row['setting_key']] = $row['setting_value'];
        }
    }
    // ตรวจสอบอีกครั้งเผื่อ $reorder_point ยังไม่ได้ตั้ง
    $reorder_point = $settings_all['reorder_point'] ?? 10;


    // ดึงข้อมูลผู้ใช้
    $stmt_user = $conn->prepare("SELECT * FROM user WHERE user_ID = ?");
    if ($stmt_user) {
        $stmt_user->bind_param("s", $user_id_session);
        $stmt_user->execute();
        $user_data = $stmt_user->get_result()->fetch_assoc();
        $stmt_user->close();
    } else {
        throw new Exception("ไม่สามารถดึงข้อมูลผู้ใช้ได้");
    }

} catch (Exception $e) {
    error_log("Data retrieval error: " . $e->getMessage());
    $user_data = [];
    $low_stock_count = 0;
    $reorder_point = 10;
}

// === ส่วนจัดการการบันทึกข้อมูล (POST Request) ===
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // ตรวจสอบ CSRF Token ก่อนประมวลผล
    if (!isset($_POST['csrf_token']) || !hash_equals($csrf_token, $_POST['csrf_token'])) {
        handleError("โทเค็นไม่ถูกต้อง กรุณาลองใหม่อีกครั้ง", $_POST['active_tab'] ?? 'pane-profile');
    }
    
    $action = $_POST['action'] ?? '';
    $redirect_tab = $_POST['active_tab'] ?? 'pane-profile';

    try {
        // อัปเดตโปรไฟล์ผู้ใช้
        if ($action === 'update_user_profile') {
            $user_name = trim($_POST['user_name'] ?? '');
            $user_tel = trim($_POST['user_tel'] ?? '');
            $user_address = trim($_POST['uesr_address'] ?? '');
            
            // ตรวจสอบข้อมูลที่จำเป็น
            if (empty($user_name)) {
                handleError("กรุณากรอกชื่อ-สกุล", $redirect_tab);
            }
            
            // ตรวจสอบเบอร์โทรศัพท์
            if (!empty($user_tel) && !preg_match('/^[0-9]{9,10}$/', $user_tel)) { // ปรับปรุง regex ให้ตรงกับเบอร์โทรไทย
                handleError("รูปแบบเบอร์โทรศัพท์ไม่ถูกต้อง (9-10 หลัก)", $redirect_tab);
            }
            
            $stmt = $conn->prepare("UPDATE user SET user_name = ?, user_tel = ?, uesr_address = ? WHERE user_ID = ?");
            if (!$stmt) {
                throw new Exception("เตรียมคำสั่ง SQL ล้มเหลว: " . $conn->error);
            }
            
            $stmt->bind_param("ssss", $user_name, $user_tel, $user_address, $user_id_session);
            
            if ($stmt->execute()) {
                $_SESSION['user_name'] = $user_name; // อัปเดต session
                handleSuccess("อัปเดตโปรไฟล์สำเร็จ", $redirect_tab);
            } else {
                throw new Exception("การอัปเดตโปรไฟล์ล้มเหลว: " . $stmt->error);
            }
            if ($stmt instanceof mysqli_stmt) {
                $stmt->close();
            }
        }

        // เปลี่ยนรหัสผ่าน - แก้ไขให้เก็บเป็น plain text ตามที่ผู้ใช้กรอก
        if ($action === 'change_password') {
            $current_password = $_POST['current_password'] ?? '';
            $new_password = $_POST['new_password'] ?? '';
            $confirm_password = $_POST['confirm_password'] ?? '';
            
            // ตรวจสอบความยาวรหัสผ่าน
            if (strlen($new_password) < 8) {
                handleError("รหัสผ่านใหม่ต้องมีความยาวอย่างน้อย 8 ตัวอักษร", $redirect_tab);
            }
            
            if ($new_password !== $confirm_password) {
                handleError("รหัสผ่านใหม่และการยืนยันไม่ตรงกัน", $redirect_tab);
            }
            
            // ดึงข้อมูลผู้ใช้พร้อมรหัสผ่าน
            $stmt = $conn->prepare("SELECT user_password FROM user WHERE user_ID = ?");
            if (!$stmt) {
                throw new Exception("เตรียมคำสั่ง SQL ล้มเหลว: " . $conn->error);
            }
            
            $stmt->bind_param("s", $user_id_session);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows === 0) {
                $stmt->close();
                handleError("ไม่พบข้อมูลผู้ใช้", $redirect_tab);
            }
            
            $user = $result->fetch_assoc();
            $stmt->close();

            // ตรวจสอบรหัสผ่านปัจจุบัน
            $stored_password = $user['user_password'];
            $password_valid = false;

            // ตรวจสอบว่ารหัสผ่านในฐานข้อมูลเป็น hash หรือ plain text
            if (password_verify($current_password, $stored_password)) {
                // รหัสผ่านเป็น hash และตรงกัน
                $password_valid = true;
            } else if ($current_password === $stored_password) {
                // รหัสผ่านเป็น plain text และตรงกัน
                $password_valid = true;
            }
            
            if (!$password_valid) {
                handleError("รหัสผ่านปัจจุบันไม่ถูกต้อง", $redirect_tab);
            }
            
            // (แนะนำอย่างยิ่ง) ถ้าจะเปลี่ยน ให้ hash รหัสผ่านใหม่
            // $hashed_new_password = password_hash($new_password, PASSWORD_DEFAULT);
            
            // (ตามโค้ดเดิม) อัปเดตรหัสผ่านใหม่ในฐานข้อมูลเป็น plain text
            $update_stmt = $conn->prepare("UPDATE user SET user_password = ? WHERE user_ID = ?");
            
            if (!$update_stmt) {
                throw new Exception("เตรียมคำสั่ง SQL ล้มเหลว: " . $conn->error);
            }
            
            // ใช้ $new_password (plain text) ตามโค้ดเดิม
            $update_stmt->bind_param("ss", $new_password, $user_id_session); 
            
            if ($update_stmt->execute()) {
                handleSuccess("เปลี่ยนรหัสผ่านสำเร็จ", $redirect_tab);
            } else {
                throw new Exception("เกิดข้อผิดพลาดในการอัปเดตรหัสผ่าน: " . $update_stmt->error);
            }
            if ($update_stmt instanceof mysqli_stmt) {
                $update_stmt->close();
            }
        }

        // อัปเดตการแจ้งเตือน
        if ($action === 'update_notifications') {
            $reorder_point = filter_input(INPUT_POST, 'reorder_point', FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 1, 'max_range' => 1000]
            ]);
            
            if ($reorder_point === false || $reorder_point === null) {
                handleError("จุดสั่งซื้อต้องเป็นตัวเลขระหว่าง 1-1000", $redirect_tab);
            }
            
            $stmt = $conn->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('reorder_point', ?) ON DUPLICATE KEY UPDATE setting_value = ?");
            if (!$stmt) {
                throw new Exception("เตรียมคำสั่ง SQL ล้มเหลว: " . $conn->error);
            }
            
            $stmt->bind_param("ss", $reorder_point, $reorder_point);
            
            if ($stmt->execute()) {
                handleSuccess("บันทึกจุดสั่งซื้อเรียบร้อยแล้ว", $redirect_tab);
            } else {
                throw new Exception("การบันทึกจุดสั่งซื้อล้มเหลว: " . $stmt->error);
            }
            if ($stmt instanceof mysqli_stmt) {
                $stmt->close();
            }
        }
        
    } catch (Exception $e) {
        error_log("Settings error: " . $e->getMessage());
        handleError("เกิดข้อผิดพลาดในระบบ กรุณาลองใหม่อีกครั้ง", $redirect_tab);
    }

    // Redirect กลับมาหน้าเดิมพร้อมกับ tab ที่เปิดอยู่
    // header("Location: settings.php?active_tab=" . $redirect_tab); // ถูกจัดการโดย handleError/handleSuccess แล้ว
    exit();
}

$conn->close();
ob_end_flush();
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ตั้งค่าระบบ - Chutikarn Ceramic</title>
    <link href="https://fonts.googleapis.com/css2?family=Kanit:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
        /* รีเซ็ตค่าพื้นฐาน */
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        /* พื้นหลังและเลย์เอาต์หลัก */
        body { 
            background-color: #f8f9fa; 
            color: #333;
            padding-top: 84px;      /* เพิ่ม padding เท่ากับความสูง header */
            padding-left: 250px;    /* เพิ่ม padding เท่ากับความกว้าง sidebar */
            transition: all 0.3s ease;
        }

        /* --- ส่วนหัวหน้าเว็บ --- */
        .top-header { 
            font-family: 'Microsoft Sans Serif'; 
            font-weight: bold; 
            display: flex; 
            justify-content: space-between; 
            align-items: center; 
            padding: 10px 25px; 
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.04); 
            background-color: #fff; 
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 84px;
            z-index: 1000;
            transition: all 0.3s ease;
        }
        .main-container { display: flex; }

        /* --- ส่วนประกอบในหัวหน้าเว็บ --- */
        .logo-section { 
            display: flex; 
            align-items: center; 
            gap: 0px; 
        }
        .logo-section span { 
            font-size: 25px; 
            font-weight: bold;
            color: #000000; 
        }
        .user-section { display: flex; align-items: center; gap: 20px; }
        .user-info { text-align: right; }
        .user-username { font-size: 16px; font-weight: bold; }
        .user-role { font-size: 16px; color: #666; font-weight: bold; }
        .user-avatar { font-size: 25px; width: 40px; height: 40px; border-radius: 50%; background: #f0f0f0; display: flex; align-items: center; justify-content: center; cursor: pointer; transition: all 0.2s ease; }
        .user-avatar:hover { background: #e0e0e0; }
        .dropdown { position: relative; display: inline-block; }
        .dropdown-content { display: none; position: absolute; right: 0; margin-top: 8px; background-color: #fff; min-width: 160px; box-shadow: 0px 8px 16px 0px rgba(0,0,0,0.1); z-index: 10; border-radius: 4px; overflow: hidden; }
        .dropdown-content a { color: black; padding: 12px 16px; text-decoration: none; display: block; font-size: 14px; transition: background-color 0.2s; }
        .dropdown-content a:hover { background-color: #f1f1f1; }
        .dropdown-content .logout { color: #dc3545 !important; }
        .show { display: block; }
        
        /* --- การแจ้งเตือน (Notification) --- */
        .notification-container { position: relative; display: inline-block; cursor: pointer; }
        .notification-badge { 
            position: absolute; 
            top: -5px; 
            right: -5px; 
            background: #dc3545; 
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
        
        /* === (ปรับปรุง) === */
        .bell-icon { 
            font-size: 22px; 
            color: #666; 
            transition: color 0.2s; 
        }
        .bell-icon:hover { 
            color: #A44A3F; 
        }
        /* === (สิ้นสุดการปรับปรุง) === */


        /* Modal การแจ้งเตือน */
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

        /* --- เมนูด้านข้าง (Sidebar) --- */
        .sidebar { 
            width: 250px; 
            background: #ffffff; 
            box-shadow: 2px 0 15px rgba(0,0,0,0.05); 
            flex-shrink: 0; 
            position: fixed;
            top: 84px;
            left: 0;
            height: calc(100vh - 84px);
        }
        .menu-item { display: flex; align-items: center; gap: 1rem; padding: 1rem 2rem; color: #333; text-decoration: none; transition: all 0.2s ease; cursor: pointer; }
        .menu-item span { flex: 1; text-align: left; font-size: 18px; }
        .menu-item:hover { background: rgba(244, 212, 199, 0.5); }
        .menu-item.active { 
            background: linear-gradient(90deg, #f4d4c7 0%, #edd5c7 100%);
            color: #A44A3F; 
        }
        .menu-item.active > span, .menu-item.active > div { color: #A44A3F; }
        .icon-medium { font-size: 25px; width: 25px; text-align: center; }
        
        /* --- เมนูย่อยแบบเลื่อนออกด้านข้าง --- */
        .menu-item-container { position: relative; }
        .submenu-toggle { display: flex; justify-content: space-between; width: 100%; align-items: center; cursor: pointer; }
        .submenu { display: none; opacity: 0; visibility: hidden; transition: opacity 0.3s ease, transform 0.3s ease; transform: translateX(-10px); position: absolute; left: 100%; top: 0; z-index: 1000; min-width: 250px; background-color: #fff; border-radius: 0 8px 8px 0; box-shadow: 4px 4px 15px rgba(0,0,0,0.1); border: 1px solid #f0f0f0; }
        .submenu.show { display: block; opacity: 1; visibility: visible; transform: translateX(0); }
        .submenu-item { display: block; padding: 12px 20px; text-decoration: none; color: #444; font-size: 16px; transition: background-color 0.2s ease; }
        .submenu-item:hover { background-color: #fff7f5; }
        .submenu-item.active { background-color: #fff7f5; color: #A44A3F; font-weight: 500; }
        
        /* --- พื้นที่เนื้อหาหลัก --- */
        .content { 
            flex-grow: 1; 
            padding: 40px; 
            width: calc(100% - 250px);
            margin-left: 0;
            transition: all 0.3s ease;
        }
        .page-header { color: #A44A3F; font-size: 30px; font-weight: bold; margin-bottom: 30px; }
        
        /* --- การตั้งค่าหน้าเว็บ --- */
        .settings-content { flex: 1; }
        .settings-pane { display: none; }
        .settings-pane.active { display: block; animation: fadeIn 0.5s; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }

        /* --- การ์ดและฟอร์ม --- */
        .settings-card { background: #fff; padding: 25px 30px; border-radius: 8px; box-shadow: 0 4px 15px rgba(0,0,0,0.07); margin-bottom: 20px; }
        .settings-card h3 { font-size: 22px; color: #A44A3F; margin-bottom: 25px; padding-bottom: 10px; border-bottom: 1px solid #eee; }
        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; margin-bottom: 8px; font-weight: 500; color: #333; }
        .form-control { width: 100%; padding: 12px; border: 1px solid #ddd; border-radius: 5px; font-size: 16px; transition: border-color 0.2s, box-shadow 0.2s;}
        .form-control:focus { border-color: #A44A3F; box-shadow: 0 0 0 2px #fff7f5; outline: none; }
        .form-control:disabled { background-color: #f0f0f0; cursor: not-allowed; }
        textarea.form-control { min-height: 80px; resize: vertical; }
        .btn-submit { background-color: #c0392b; color: white; padding: 12px 25px; border: none; border-radius: 5px; cursor: pointer; font-size: 16px; font-weight: 500; transition: background-color 0.2s;}
        .btn-submit:hover { background-color: #a93226; }
        .btn-submit:disabled { background-color: #cccccc; cursor: not-allowed; }
        
        /* --- การแจ้งเตือน --- */
        .alert { padding: 15px; margin-bottom: 20px; border-radius: 5px; color: #fff; font-weight: 500; animation: slideIn 0.3s ease; }
        @keyframes slideIn {
            from { transform: translateY(-10px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }
        .alert-success { background-color: #28a745; }
        .alert-error { background-color: #dc3545; }
        
        /* --- ตัวบ่งชี้ความแข็งแรงของรหัสผ่าน --- */
        .password-strength { margin-top: 5px; font-size: 14px; }
        .strength-weak { color: #dc3545; }
        .strength-medium { color: #ffc107; }
        .strength-strong { color: #28a745; }
        
        /* --- การออกแบบให้รองรับมือถือ --- */
        @media (max-width: 1024px) {
            body { padding-left: 0; }
            .sidebar { transform: translateX(-100%); }
            .sidebar.active { transform: translateX(0); }
            .content { width: 100%; padding: 20px; }
            .menu-toggle { display: block; }
            
            .logo-section span { 
                font-size: 24px;
            }
            
            .notification-modal-content {
                width: 95%;
                margin: 10% auto;
            }
        }
        
        /* --- ตัวหมุนโหลด --- */
        .loading { display: inline-block; width: 20px; height: 20px; border: 3px solid rgba(255,255,255,.3); border-radius: 50%; border-top-color: #fff; animation: spin 1s ease-in-out infinite; }
        @keyframes spin { to { transform: rotate(360deg); } }
        
        /* --- การนำทางแท็บ --- */
        .tab-nav { display: flex; margin-bottom: 20px; border-bottom: 1px solid #ddd; }
        .tab-nav-item { padding: 12px 20px; cursor: pointer; border-bottom: 3px solid transparent; transition: all 0.2s; }
        .tab-nav-item:hover { background-color: #f8f9fa; }
        .tab-nav-item.active { border-bottom-color: #A44A3F; color: #A44A3F; font-weight: 500; }
        
        /* --- ปุ่มเปิด/ปิดเมนูมือถือ --- */
        .menu-toggle { display: none; position: fixed; top: 20px; left: 20px; z-index: 1100; background: #A44A3F; color: white; border: none; border-radius: 4px; width: 40px; height: 40px; font-size: 20px; cursor: pointer; }
        
        @media (max-width: 1024px) {
            .menu-toggle { display: flex; align-items: center; justify-content: center; }
        }
    </style>
</head>
<body>
<button class="menu-toggle" id="menuToggle">☰</button>

<div class="top-header">
    <div class="logo-section">
        <img src="logo1.png" width="50" height="50" alt="Chutikarn Logo">
        <span>Chutikarn</span>
    </div>
    <div class="user-section">
        <div class="notification-container">
            <div class="bell-icon" onclick="showNotificationModal()">🔔</div>
            <?php if ($low_stock_count > 0 || $recent_received_count > 0): ?>
                <div class="notification-badge"><?php echo $low_stock_count + $recent_received_count; ?></div>
            <?php endif; ?>
        </div>

        <div class="dropdown">
            <div class="user-avatar" onclick="toggleDropdown()">👤</div>
            <div id="userDropdown" class="dropdown-content">
                <a href="settings.php">ข้อมูลส่วนตัว</a>
                <a href="login.php" class="logout">Logout</a>
            </div>
        </div>
        <div class="user-info">
            <div class="user-username"><?= htmlspecialchars($user_data['user_username'] ?? 'ผู้ใช้') ?></div>
            <div class="user-role"><?= htmlspecialchars($user_data['user_type'] ?? ' ') ?></div>
        </div>
    </div>
</div>

<div class="main-container">
    <!-- เมนูด้านข้าง -->
    <div class="sidebar" id="sidebar">
        <a href="Dashboard2.php" class="menu-item"><div class="icon-medium">🏠</div><span>หน้าแรก</span></a>
        <a href="receive_stock2.php" class="menu-item"><div class="icon-medium">📥</div><span>รับสินค้าเข้า</span></a>
        <a href="reports2.php" class="menu-item"><div class="icon-medium">&#128200;</div><span>รายงาน</span></a>
        <a href="settings2.php" class="menu-item active"><div class="icon-medium">⚙️</div><span>ตั้งค่าระบบ</span></a>
    </div>

    <!-- เนื้อหาหลัก -->
    <main class="content">
        <h2 class="page-header">ตั้งค่าระบบ</h2>
        
        <!-- การนำทางแท็บสำหรับมือถือ -->
        <div class="tab-nav" id="mobileTabs">
            <div class="tab-nav-item <?= $active_tab === 'pane-profile' ? 'active' : '' ?>" data-tab="pane-profile">โปรไฟล์</div>
            <div class="tab-nav-item <?= $active_tab === 'pane-security' ? 'active' : '' ?>" data-tab="pane-security">ความปลอดภัย</div>
        </div>
        
        <?php
        if (isset($_SESSION['success_message'])) {
            echo '<div class="alert alert-success">' . htmlspecialchars($_SESSION['success_message']) . '</div>';
            unset($_SESSION['success_message']);
        }
        if (isset($_SESSION['error_message'])) {
            echo '<div class="alert alert-error">' . htmlspecialchars($_SESSION['error_message']) . '</div>';
            unset($_SESSION['error_message']);
        }
        ?>
        
        <div class="settings-content">
            <div id="pane-profile" class="settings-pane <?= ($active_tab === 'pane-profile') ? 'active' : '' ?>">
                <div class="settings-card">
                    <h3>โปรไฟล์ของฉัน</h3>
                    <form id="profileForm" action="settings.php" method="POST">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                        <input type="hidden" name="active_tab" value="pane-profile">
                        <input type="hidden" name="action" value="update_user_profile">
                        <div class="form-group">
                            <label>Username</label>
                            <input type="text" class="form-control" value="<?= htmlspecialchars($user_data['user_username'] ?? '') ?>" disabled>
                        </div>
                        <div class="form-group">
                            <label>ชื่อ-สกุล</label>
                            <input type="text" name="user_name" class="form-control" value="<?= htmlspecialchars($user_data['user_name'] ?? '') ?>" required>
                        </div>
                        <div class="form-group">
                            <label>เบอร์โทรศัพท์</label>
                            <input type="text" name="user_tel" class="form-control" value="<?= htmlspecialchars($user_data['user_tel'] ?? '') ?>" pattern="[0-9]{9,10}" title="กรุณากรอกเบอร์โทรศัพท์ให้ถูกต้อง (ตัวเลข 9-10 หลัก)">
                        </div>
                        <div class="form-group">
                            <label>ที่อยู่</label>
                            <textarea name="uesr_address" class="form-control"><?= htmlspecialchars($user_data['uesr_address'] ?? '') ?></textarea>
                        </div>
                        <button type="submit" class="btn-submit" id="profileSubmit">บันทึกโปรไฟล์</button>
                    </form>
                </div>
            </div>

            <div id="pane-security" class="settings-pane <?= ($active_tab === 'pane-security') ? 'active' : '' ?>">
                <div class="settings-card">
                    <h3>เปลี่ยนรหัสผ่าน</h3>
                    <form id="passwordForm" action="settings.php" method="POST">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                        <input type="hidden" name="active_tab" value="pane-security">
                        <input type="hidden" name="action" value="change_password">
                        <div class="form-group">
                            <label for="current_password">รหัสผ่านปัจจุบัน</label>
                            <input type="password" id="current_password" name="current_password" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label for="new_password">รหัสผ่านใหม่</label>
                            <input type="password" id="new_password" name="new_password" class="form-control" required minlength="8">
                            <div id="password-strength" class="password-strength"></div>
                        </div>
                        <div class="form-group">
                            <label for="confirm_password">ยืนยันรหัสผ่านใหม่</label>
                            <input type="password" id="confirm_password" name="confirm_password" class="form-control" required>
                            <div id="password-match" class="password-strength"></div>
                        </div>
                        <button type="submit" class="btn-submit" id="passwordSubmit">ยืนยันการเปลี่ยนรหัสผ่าน</button>
                    </form>
                </div>
            </div>

            <div id="pane-notifications" class="settings-pane <?= ($active_tab === 'pane-notifications') ? 'active' : '' ?>">
                 <div class="settings-card">
                    <h3>การแจ้งเตือนสินค้าใกล้หมด</h3>
                    <form action="settings.php" method="POST">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                        <input type="hidden" name="active_tab" value="pane-notifications">
                        <input type="hidden" name="action" value="update_notifications">
                        <div class="form-group">
                            <label>แจ้งเตือนเมื่อสินค้าเหลือน้อยกว่าหรือเท่ากับ (จุดสั่งซื้อ)</label>
                            <input type="number" name="reorder_point" class="form-control" value="<?= htmlspecialchars($reorder_point) ?>" min="1" max="1000" required>
                            <small style="color: #666; margin-top: 5px; display: block;">ระบบจะแจ้งเตือนเมื่อจำนวนสินค้าคงเหลือต่ำกว่าหรือเท่ากับค่านี้</small>
                        </div>
                        <button type="submit" class="btn-submit">บันทึก</button>
                    </form>
                </div>
            </div>
            
            <div id="pane-backup" class="settings-pane <?= ($active_tab === 'pane-backup') ? 'active' : '' ?>">
                 <div class="settings-card">
                    <h3>สำรองฐานข้อมูล</h3>
                    <p style="margin-bottom: 1rem; line-height: 1.6;">คลิกที่ปุ่มด้านล่างเพื่อดาวน์โหลดไฟล์สำรองข้อมูล (.sql) ของฐานข้อมูลทั้งหมด</p>
                    <p><strong>คำแนะนำ:</strong> ควรทำการสำรองข้อมูลอย่างสม่ำเสมอเพื่อป้องกันข้อมูลสูญหาย</p>
                    <br>
                    <a href="backup_db.php" class="btn-submit" style="text-decoration: none; display: inline-block;" id="backupBtn">💾 ดาวน์โหลดไฟล์สำรองข้อมูล</a>
                    <div id="backupMessage" style="margin-top: 10px;"></div>
                </div>
            </div>
        </div>
    </main>
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

<script>
    // ฟังก์ชันเปิด/ปิดเมนูผู้ใช้
    function toggleDropdown() {
        document.getElementById("userDropdown").classList.toggle("show");
    }
    
    // ฟังก์ชันแสดง/ปิด Modal การแจ้งเตือน (เหมือนในหน้าการจัดการร้านค้า)
    function showNotificationModal() {
        document.getElementById("notificationModal").style.display = "block";
    }
    
    function closeNotificationModal() {
        document.getElementById("notificationModal").style.display = "none";
    }
    
    // ปิดเมนู dropdown เมื่อคลิกนอกพื้นที่
    window.onclick = function(event) {
        if (!event.target.closest('.user-avatar')) {
            var dropdowns = document.getElementsByClassName("dropdown-content");
            for (var i = 0; i < dropdowns.length; i++) {
                if (dropdowns[i].classList.contains('show')) {
                    dropdowns[i].classList.remove('show');
                }
            }
        }
        
        // ปิด modal การแจ้งเตือนเมื่อคลิกนอกพื้นที่
        var modal = document.getElementById('notificationModal');
        if (event.target == modal) {
            modal.style.display = "none";
        }
    }

    // รันโค้ดเมื่อโหลดหน้าเว็บเสร็จ
    document.addEventListener('DOMContentLoaded', function() {
        // --- ตรรกะเมนูย่อยด้านข้าง (ถ้ามี) ---
        const settingsContainer = document.querySelector('.submenu-toggle')?.closest('.menu-item-container');

        if (settingsContainer) {
            const toggle = settingsContainer.querySelector('.submenu-toggle');
            // (หมายเหตุ) โค้ดนี้จะทำงานหากคุณมี .submenu ใน HTML
            const submenu = settingsContainer.querySelector('.submenu'); 

            if (submenu) {
                // แสดงเมนูย่อยเมื่อเมาส์ hover
                settingsContainer.addEventListener('mouseenter', function() {
                    submenu.classList.add('show');
                });

                // ซ่อนเมนูย่อยเมื่อเมาส์ออก
                settingsContainer.addEventListener('mouseleave', function() {
                    submenu.classList.remove('show');
                });
            }
        }
        
        // --- การปิดอัตโนมัติของข้อความแจ้งเตือน ---
        const alerts = document.querySelectorAll('.alert');
        alerts.forEach(alert => {
            setTimeout(() => {
                alert.style.transition = 'opacity 0.5s';
                alert.style.opacity = '0';
                setTimeout(() => alert.remove(), 500);
            }, 5000);
        });
        
        // --- การเปิด/ปิดเมนูมือถือ ---
        const menuToggle = document.getElementById('menuToggle');
        const sidebar = document.getElementById('sidebar');
        
        if (menuToggle && sidebar) {
            menuToggle.addEventListener('click', function() {
                sidebar.classList.toggle('active');
            });
        }
        
        // --- การนำทางแท็บมือถือ ---
        const tabItems = document.querySelectorAll('.tab-nav-item');
        const settingsPanes = document.querySelectorAll('.settings-pane');
        
        tabItems.forEach(tab => {
            tab.addEventListener('click', function() {
                const tabId = this.getAttribute('data-tab');
                
                // อัปเดตแท็บที่ active
                tabItems.forEach(t => t.classList.remove('active'));
                this.classList.add('active');
                
                // แสดง pane ที่เกี่ยวข้อง
                settingsPanes.forEach(pane => {
                    pane.classList.remove('active');
                    if (pane.id === tabId) {
                        pane.classList.add('active');
                    }
                });
                
                // อัปเดต URL โดยไม่ต้องโหลดหน้าใหม่
                history.replaceState(null, null, `?active_tab=${tabId}`);

                // (เพิ่ม) อัปเดต hidden input ในฟอร์ม
                document.querySelectorAll('input[name="active_tab"]').forEach(input => {
                    input.value = tabId;
                });
            });
        });
        
        // --- ตัวตรวจสอบความแข็งแรงของรหัสผ่าน ---
        const newPassword = document.getElementById('new_password');
        const confirmPassword = document.getElementById('confirm_password');
        const strengthIndicator = document.getElementById('password-strength');
        const matchIndicator = document.getElementById('password-match');
        
        if (newPassword && strengthIndicator) {
            newPassword.addEventListener('input', function() {
                const password = this.value;
                let strength = 'weak';
                let message = 'รหัสผ่านอ่อน';
                let className = 'strength-weak';
                
                if (password.length >= 8) {
                    // ตรวจสอบความซับซ้อน
                    const hasUpperCase = /[A-Z]/.test(password);
                    const hasLowerCase = /[a-z]/.test(password);
                    const hasNumbers = /\d/.test(password);
                    const hasSpecial = /[!@#$%^&*()_+\-=\[\]{};':"\\|,.<>\/?]/.test(password);
                    
                    const complexityScore = [hasUpperCase, hasLowerCase, hasNumbers, hasSpecial].filter(Boolean).length;
                    
                    if (complexityScore >= 3 && password.length >= 10) {
                        strength = 'strong';
                        message = 'รหัสผ่านแข็งแรง';
                        className = 'strength-strong';
                    } else if (complexityScore >= 2) {
                        strength = 'medium';
                        message = 'รหัสผ่านปานกลาง';
                        className = 'strength-medium';
                    }
                }
                
                strengthIndicator.textContent = (password.length > 0) ? message : '';
                strengthIndicator.className = 'password-strength ' + className;
            });
        }
        
        if (confirmPassword && matchIndicator) {
            const checkMatch = () => {
                if (confirmPassword.value.length > 0) {
                    if (newPassword.value === confirmPassword.value) {
                        matchIndicator.textContent = 'รหัสผ่านตรงกัน';
                        matchIndicator.className = 'password-strength strength-strong';
                    } else {
                        matchIndicator.textContent = 'รหัสผ่านไม่ตรงกัน';
                        matchIndicator.className = 'password-strength strength-weak';
                    }
                } else {
                     matchIndicator.textContent = '';
                }
            };
            
            confirmPassword.addEventListener('input', checkMatch);
            newPassword.addEventListener('input', checkMatch); // (เพิ่ม) เช็คเมื่อรหัสผ่านใหม่เปลี่ยนด้วย
        }
        
        // --- การจัดการการส่งฟอร์ม ---
        const profileForm = document.getElementById('profileForm');
        const passwordForm = document.getElementById('passwordForm');
        
        if (profileForm) {
            profileForm.addEventListener('submit', function(e) {
                const submitBtn = document.getElementById('profileSubmit');
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<span class="loading"></span> กำลังบันทึก...';
            });
        }
        
        if (passwordForm) {
            passwordForm.addEventListener('submit', function(e) {
                if (newPassword.value !== confirmPassword.value) {
                    e.preventDefault();
                    alert('รหัสผ่านใหม่และการยืนยันรหัสผ่านไม่ตรงกัน');
                    return;
                }
                
                const submitBtn = document.getElementById('passwordSubmit');
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<span class="loading"></span> กำลังเปลี่ยนรหัสผ่าน...';
            });
        }
        
        // --- การจัดการปุ่มสำรองข้อมูล ---
        const backupBtn = document.getElementById('backupBtn');
        const backupMessage = document.getElementById('backupMessage');
        
        if (backupBtn && backupMessage) {
            backupBtn.addEventListener('click', function(e) {
                e.preventDefault();
                backupMessage.textContent = 'กำลังเตรียมไฟล์สำรองข้อมูล...';
                backupMessage.style.color = '#666';
                
                // จำลองกระบวนการสำรองข้อมูล
                setTimeout(() => {
                    window.location.href = backupBtn.href;
                    backupMessage.textContent = 'กำลังดาวน์โหลดไฟล์สำรองข้อมูล...';
                }, 1000);
            });
        }
        
        // --- ตรวจสอบการรองรับมือถือ ---
        function checkResponsive() {
            if (window.innerWidth <= 1024) {
                document.body.classList.add('mobile-view');
            } else {
                document.body.classList.remove('mobile-view');
                sidebar.classList.remove('active');
            }
        }
        
        window.addEventListener('resize', checkResponsive);
        checkResponsive();
    });
</script>
</body>
</html>