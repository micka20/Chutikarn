<?php
session_start();

// ตรวจสอบว่าผู้ใช้ล็อกอินอยู่หรือไม่ (ใช้แบบเดียวกับหน้าแรก)
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

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// ดึงข้อมูลผู้ใช้ที่ล็อกอินจาก session
$logged_in_username = $_SESSION['user_username'];
$sql = "SELECT user_ID, user_username, user_type FROM user WHERE user_username = '$logged_in_username'";
$result = $conn->query($sql);

$user_username = "ไม่พบข้อมูล";
$user_type = "ไม่พบข้อมูล";
$logged_in_user_id = "";

if ($result->num_rows > 0) {
    $row = $result->fetch_assoc();
    $user_username = $row["user_username"];
    $user_type = $row["user_type"];
    $logged_in_user_id = $row["user_ID"];
    
    // ตั้งค่า user_id ใน session เพื่อให้หน้าอื่นใช้ได้
    $_SESSION['user_id'] = $logged_in_user_id;
}

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

// ดึงข้อมูลพนักงานทั้งหมดจากฐานข้อมูล
$sql = "SELECT * FROM user ORDER BY user_ID ASC";
$result = $conn->query($sql);
$employees = array();

if ($result->num_rows > 0) {
    while($row = $result->fetch_assoc()) {
        $employees[] = $row;
    }
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>จัดการพนักงาน</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            background-color: #f8f9fa;
            padding-top: 84px; /* เพิ่ม padding-top เท่ากับความสูงของ header */
            padding-left: 250px; /* เพิ่ม padding-left เท่ากับความกว้างของ sidebar */
        }
        
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
            font-weight: 600; /* เพิ่มตัวหนา */
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

        .main-container {
            display: flex;
            min-height: calc(100vh - 84px); /* Adjust height based on header height */
        }

        .sidebar { 
            width: 250px; 
            background: #ffffff; 
            padding: 0; 
            box-shadow: 2px 0 15px rgba(0,0,0,0.1); 
            position: fixed; /* ทำให้ sidebar คงที่ */
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
        .menu-item span { flex: 1; text-align: left; font-size: 18px; }
        .menu-item:hover { background: rgba(244, 212, 199, 0.5); border-left-color: transparent; transform: translateX(1.5px); }
        .menu-item.active { background: linear-gradient(90deg, #f4d4c7 0%, #edd5c7 100%); border-left-color: transparent; color: #A44A3F; }
        .menu-item.active span, .menu-item.active div { color: #A44A3F; }
        .menu-icon { width: 20px; height: 20px; display: flex; align-items: center; justify-content: center; }
        .icon-medium { font-size: 25px; }

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

        .content {
            flex: 1;
            padding: 40px;
            background-color: #f8f9fa;
            width: calc(100% - 250px); /* ปรับความกว้างของ content */
            margin-left: 0; /* ลบ margin-left */
        }

        .page-header {
            color: #A44A3F;
            font-size: 30px;
            margin-bottom: 30px;
            text-align: left;
            font-weight: 700; /* เพิ่มตัวหนาให้หัวข้อหน้า */
        }

        .employee-controls {
            display: flex;
            gap: 15px;
            margin-bottom: 20px;
            align-items: center;
            flex-wrap: wrap; /* Allow wrapping on smaller screens */
        }

        .search-container {
            flex: 1;
            position: relative;
            min-width: 200px; /* Ensure search input has minimum width */
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
            transition: background-color 0.3s ease;
            font-weight: 600; /* เพิ่มตัวหนาให้ปุ่ม */
        }
        .search-btn:hover {
            background-color: #a93225;
        }

        .add-employee-btn {
            background: #c0392b; /* Match brand color */
            color: white;
            border: none;
            padding: 12px 20px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 16px;
            white-space: nowrap;
            transition: background-color 0.3s ease, transform 0.2s ease;
            font-weight: 600; /* เพิ่มตัวหนาให้ปุ่ม */
        }
        .add-employee-btn:hover {
            background-color: #a93225;
            transform: translateY(-2px);
        }

        .employee-table {
            background: white;
            border-radius: 8px;
            overflow: auto; /* Allow horizontal scroll for table on small screens */
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 700px; /* Ensure table doesn't get too small */
        }

        th {
            background-color: #f8f9fa;
            padding: 15px;
            text-align: center;
            font-weight: 700; /* เพิ่มตัวหนาให้หัวตาราง */
            color: #333;
            border-bottom: 2px solid #dee2e6;
        }

        td {
            padding: 15px;
            text-align: center;
            border-bottom: 1px solid #dee2e6;
            vertical-align: middle;
            font-weight: 500; /* เพิ่มตัวหนาปานกลางให้เนื้อหาในตาราง */
        }

        tr:hover {
            background-color: #f8f9fa;
        }

        .action-buttons {
            display: flex;
            gap: 8px;
            justify-content: center;
        }

        .edit-btn {
            background-color: #ffc107;
            color: #333;
            border: none;
            padding: 8px 15px;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
            transition: background-color 0.3s ease;
            font-weight: 600; /* เพิ่มตัวหนาให้ปุ่ม */
        }
        .edit-btn:hover {
            background-color: #e0a800;
        }

        .delete-btn {
            background-color: #dc3545;
            color: white;
            border: none;
            padding: 8px 15px;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
            transition: background-color 0.3s ease;
            font-weight: 600; /* เพิ่มตัวหนาให้ปุ่ม */
        }
        .delete-btn:hover {
            background-color: #c82333;
        }

        .pagination {
            text-align: center;
            margin-top: 20px;
            padding: 20px;
            background: white;
            border-radius: 8px;
            font-size: 16px;
            color: #666;
            position: relative; /* เปลี่ยนจาก fixed เป็น relative */
            width: 100%;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            background-color: #fff;
            z-index: 40;
            display: flex;
            justify-content: center;
            align-items: center;
            font-weight: 600; /* เพิ่มตัวหนาให้ pagination */
        }
        
        /* Modal Styles */
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
            font-weight: 700; /* เพิ่มตัวหนาให้หัวข้อ modal */
            margin-bottom: 15px;
            color: #dc3545;
        }

        .modal-body {
            font-size: 16px;
            line-height: 1.5;
            margin-bottom: 25px;
            color: #333;
            font-weight: 500; /* เพิ่มตัวหนาปานกลางให้เนื้อหา modal */
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
            font-weight: 600; /* เพิ่มตัวหนาให้ปุ่ม modal */
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
            font-weight: 700; /* เพิ่มตัวหนาให้หัวข้อการแจ้งเตือน */
        }
        .notification-item { 
            padding: 8px 0; 
            border-bottom: 1px solid #f0f0f0; 
            font-weight: 500; /* เพิ่มตัวหนาปานกลางให้รายการแจ้งเตือน */
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
            .employee-controls {
                flex-direction: column;
                align-items: stretch;
            }
            .search-container {
                width: 100%;
            }
            .add-employee-btn {
                width: 100%;
            }
            .pagination {
                left: 0;
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

        /* เพิ่มสไตล์สำหรับลิงก์ปุ่ม */
        .add-employee-link {
            display: inline-block;
            background: #c0392b;
            color: white;
            border: none;
            padding: 12px 20px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 16px;
            text-decoration: none;
            white-space: nowrap;
            transition: background-color 0.3s ease, transform 0.2s ease;
            font-weight: 600; /* เพิ่มตัวหนาให้ลิงก์ */
        }
        .add-employee-link:hover {
            background-color: #a93225;
            transform: translateY(-2px);
            color: white;
            text-decoration: none;
        }

        /* เพิ่มตัวหนาให้กับชื่อบริษัทใน header */
        .top-header span {
            font-weight: 700; /* ตัวหนามากขึ้นสำหรับชื่อบริษัท */
        }

        /* เพิ่มตัวหนาให้กับข้อมูลผู้ใช้ */
        .user-username {
            font-weight: 700 !important; /* ตัวหนามากขึ้นสำหรับชื่อผู้ใช้ */
        }

        .user-role {
            font-weight: 600 !important; /* ตัวหนาปานกลางสำหรับบทบาท */
        }
    </style>
</head>
<body>
    <!-- แทนที่ h1 เดิมด้วย div ที่มี class top-header -->
    <div class="top-header">
        <div style="display: flex; align-items: center;">
            <img src="logo1.png" width="50" height="50" style="vertical-align: middle;">
            <span style="vertical-align: middle; font-size: 25px; font-weight: 700;">Chutikarn</span>
        </div>
        
        <div class="user-section" style="display: flex; align-items: center; gap: 15px;">
            <!-- === ส่วนเพิ่มเติม: ระบบแจ้งเตือนกระดิ่ง === -->
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
                <div class="user-username" style="font-size:16px; font-weight: 700;"><?php echo htmlspecialchars($user_username); ?></div>
                <div class="user-role" style="font-size:16px; color: #666; font-weight: 600;"><?php echo htmlspecialchars($user_type); ?></div>
            </div>
        </div>
    </div>

    <script>
        // Global variables for logged-in user info
        const loggedInUserType = "<?php echo $user_type; ?>";
        const newEmployeeId = "<?php echo $logged_in_user_id; ?>";

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

        function setActiveMenu(element) {
            const menuItems = document.querySelectorAll('.sidebar .menu-item');
            menuItems.forEach(item => item.classList.remove('active'));
            element.classList.add('active');
        }

        document.addEventListener('DOMContentLoaded', () => {
            const currentPath = window.location.pathname.split('/').pop();
            const employeeAccountLink = document.querySelector('a[href="Employee_Account.php"]');
            if (employeeAccountLink && currentPath === 'Employee_Account.php') {
                setActiveMenu(employeeAccountLink);
            }
        });

        function searchEmployee() {
            const searchTerm = document.getElementById('searchInput').value.toLowerCase();
            const tableBody = document.getElementById('employeeTableBody');
            const rows = tableBody.getElementsByTagName('tr');

            for (let i = 0; i < rows.length; i++) {
                const cells = rows[i].getElementsByTagName('td');
                let found = false;
                
                for (let j = 0; j < cells.length - 1; j++) {
                    if (cells[j].textContent.toLowerCase().includes(searchTerm)) {
                        found = true;
                        break;
                    }
                }
                
                rows[i].style.display = found ? '' : 'none';
            }
        }

        // --- Modal Logic - สำหรับการลบพนักงาน ---
        const deleteModal = document.getElementById('deleteConfirmModal');
        let employeeToDeleteId = null;

        function showDeleteModal(userId, userName) {
            employeeToDeleteId = userId;
            const confirmText = document.getElementById('deleteConfirmText');
            confirmText.innerHTML = `คุณแน่ใจว่าต้องการลบพนักงาน <br><b>"${userName}" (รหัส: ${userId})</b> หรือไม่? <br><br><span style="color: #dc3545;">การกระทำนี้ไม่สามารถกู้คืนได้</span>`;
            
            const modal = document.getElementById('deleteConfirmModal');
            modal.style.display = 'flex';
            setTimeout(() => {
                modal.classList.add('show');
            }, 10);
        }

        function hideDeleteModal() {
            const modal = document.getElementById('deleteConfirmModal');
            modal.classList.remove('show');
            setTimeout(() => {
                modal.style.display = 'none';
            }, 300);
            employeeToDeleteId = null;
        }

        function confirmDelete() {
            if (!employeeToDeleteId) return;

            fetch("delete_employee.php", {
                method: "POST",
                headers: { "Content-Type": "application/x-www-form-urlencoded" },
                body: "user_ID=" + encodeURIComponent(employeeToDeleteId)
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    alert("ลบพนักงานสำเร็จ!");
                    // หาแถวที่ต้องการลบและนำออกจากหน้าเว็บ
                    const rows = document.querySelectorAll('#employeeTableBody tr');
                    rows.forEach(row => {
                        if (row.cells[0] && row.cells[0].textContent.trim() === employeeToDeleteId) {
                            row.remove();
                        }
                    });
                } else {
                    alert("เกิดข้อผิดพลาด: " + data.message);
                }
            })
            .catch(err => {
                console.error('Fetch Error:', err);
                alert("เกิดข้อผิดพลาดในการเชื่อมต่อกับเซิร์ฟเวอร์");
            })
            .finally(() => {
                hideDeleteModal(); // ซ่อน Modal หลังจากการทำงานเสร็จสิ้น
            });
        }
        
        // ปิด Modal เมื่อคลิกนอกกล่อง
        window.addEventListener('click', function(event) {
            if (event.target == deleteModal) {
                hideDeleteModal();
            }
        });

        // ฟังก์ชันลบพนักงาน - แก้ไขใหม่
        function deleteEmployee(userId, btn) {
            // หาชื่อพนักงานจากแถวปัจจุบัน
            const row = btn.closest("tr");
            const employeeName = row.cells[1].textContent; // คอลัมน์ที่ 2 คือชื่อพนักงาน
            
            // เรียกใช้ Modal แทน confirm()
            showDeleteModal(userId, employeeName);
        }

        // === ส่วนเพิ่มเติม: JavaScript สำหรับการแจ้งเตือน (เหมือนในหน้า จัดการประเภทสินค้า) ===
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
        }

    </script>

    <div class="main-container">
        <div class="sidebar">
            <a href="Dashboard.php" class="menu-item" onclick="setActiveMenu(this)">
                <div class="icon-medium">&#127968;</div>
                <span style="font-size:18px; color: #000000;">หน้าแรก</span>
            </a>

            <a href="Manage_products.php" class="menu-item" onclick="setActiveMenu(this)">
                <div class="icon-medium">&#128230;</div>
                <span style="font-size:18px; color: #000000;">จัดการสินค้า</span>
            </a>

            <a href="manage_types.php" class="menu-item">
                <div class="icon-medium">&#128193;</div>
                <span>จัดการประเภทสินค้า</span>
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

            <a href="Employee_Account.php" class="menu-item active" onclick="setActiveMenu(this)">
                <div class="icon-medium">&#128101;</div>
                <span style="font-size:18px; color: #A44A3F;">จัดการพนักงาน</span>
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
            <h2 class="page-header">จัดการพนักงาน</h2>
            <!--ตารางแสดงรายชื่อพนักงาน-->
            <div class="employee-controls">
                <div class="search-container">
                    <input type="text" id="searchInput" class="search-input" placeholder="ค้นหาพนักงาน..." onkeyup="searchEmployee()">
                    <button class="search-btn" onclick="searchEmployee()">ค้นหา</button>
                </div>
                <!-- แสดงปุ่มเพิ่มพนักงานใหม่เสมอ (ไม่ต้องตรวจสอบสิทธิ์) -->
                <a href="AddEmployee.php" class="add-employee-link">+ เพิ่มพนักงานใหม่</a>
            </div>

            <div class="employee-table">
                <table>
                    <thead>
                        <tr>
                            <th>รหัสผู้ใช้</th>
                            <th>ชื่อ-นามสกุล</th>
                            <th>เบอร์โทร</th>
                            <th>ตำแหน่ง</th>
                            <th>การกระทำ</th>
                        </tr>
                    </thead>
                    <tbody id="employeeTableBody">
                        <?php if (count($employees) > 0): ?>
                            <?php foreach ($employees as $employee): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($employee['user_ID']); ?></td>
                                    <td><?php echo htmlspecialchars($employee['user_name']); ?></td>
                                    <td><?php echo htmlspecialchars($employee['user_tel']); ?></td>
                                    <td><?php echo htmlspecialchars($employee['user_position']); ?></td>
                                    <td>
                                        <div class="action-buttons">
                                        <button class="edit-btn" onclick="window.location.href='EditEmployee.php?user_ID=<?php echo $employee['user_ID']; ?>'">แก้ไข</button>
                                            <button class="delete-btn" onclick="deleteEmployee('<?php echo $employee['user_ID']; ?>', this)">ลบ</button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="9" style="text-align: center;">ไม่พบข้อมูลพนักงาน</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <div class="pagination">
                <div>หน้า 1 จาก 1</div>
            </div>
        </div>
    </div>

    <!-- === ส่วนเพิ่มเติม: Modal สำหรับแจ้งเตือน (เหมือนในหน้า จัดการประเภทสินค้า) === -->
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

    <!-- Modal ยืนยันการลบ - สำหรับพนักงาน -->
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

</body>
</html>