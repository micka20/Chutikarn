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
$conn->set_charset("utf8"); // === เพิ่มการตั้งค่า charset ที่นี่ ===

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
$stmt->close(); // === ปิด statement ที่นี่เพื่อความปลอดภัย ===

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

// === ส่วนที่ 1: ตั้งค่าตัวแปรสำหรับ Pagination ===
$items_per_page = 6; // กำหนดจำนวนรายการต่อหน้า
$current_page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1; // หาว่าอยู่หน้าไหน

// ดึงจำนวนสินค้าทั้งหมดเพื่อคำนวณจำนวนหน้า
$total_products_query = $conn->query("SELECT COUNT(pd_ID) as total FROM product");
$total_products = $total_products_query->fetch_assoc()['total'];
$total_pages = ceil($total_products / $items_per_page); // คำนวณจำนวนหน้าทั้งหมด

// คำนวณ offset สำหรับ query
$offset = ($current_page - 1) * $items_per_page;


// === ส่วนที่ 2: แก้ไข SQL Query ให้ใช้ LIMIT และ OFFSET ===
$products = array();
$product_sql = "SELECT pd_ID, pd_name, pd_number, pd_unit, pd_costprice, pd_saleprice FROM product ORDER BY pd_ID ASC LIMIT ? OFFSET ?";
$stmt_products = $conn->prepare($product_sql);
$stmt_products->bind_param("ii", $items_per_page, $offset);
$stmt_products->execute();
$product_result = $stmt_products->get_result();


if ($product_result->num_rows > 0) {
    while($row = $product_result->fetch_assoc()) {
        $products[] = $row;
    }
}

$stmt_products->close();
$conn->close();
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>จัดการสินค้า</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        /* ทำให้ส่วนหัวและเมนูด้านข้างติดอยู่กับที่ */
        h1 {
            position: sticky;
            top: 0;
            z-index: 100;
            border: 2.6px solid #ffffff;
            padding: 20px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            background-color: #fff;
        }

        .main-container {
            display: flex;
            min-height: calc(100vh - 80px);
        }
        
        .sidebar {
            position: sticky;
            top: 94px; /* ความสูงของ h1 + padding */
            height: calc(100vh - 94px);
            overflow-y: auto;
            width: 250px;
            background: linear-gradient(180deg, #ffffff 0%, #ffffff 100%);
            padding: 0;
            box-shadow: 2px 0 15px rgba(0,0,0,0.1);
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
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .menu-item:hover {
            background: rgba(244, 212, 199, 0.5);
        }

        .menu-item.active {
            background: linear-gradient(90deg, #f4d4c7 0%, #edd5c7 100%);
        }

        .icon-medium { font-size: 25px; }

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
            padding: 40px;
            background-color: #f8f9fa;
            overflow-y: auto;
        }

        /* ส่วนหัวเรื่องที่อยู่ด้านซ้าย */
        .page-header {
            color: #A44A3F;
            font-size: 30px;
            font-weight: bold;
            margin-bottom: 30px;
            text-align: left;
        }
        
        /* ส่วนค้นหาและปุ่มเพิ่มสินค้า */
        .product-controls {
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
            padding: 12px 120px 12px 15px;
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
        }

        .add-product-btn {
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
        }

        .add-product-btn:hover {
            background: #a93226;
        }

        /* ตารางสินค้า */
        .product-table {
            background: white;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        table {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
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
            vertical-align: middle;
        }

        tr:hover {
            background-color: #f8f9fa;
        }

        /* ปุ่มแก้ไขและลบ */
        .action-buttons {
            display: flex;
            gap: 8px;
            justify-content: center;
        }

        .edit-btn, .delete-btn {
            color: white;
            border: none;
            padding: 8px 15px;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
            transition: background-color 0.2s;
            white-space: nowrap;
        }
        .edit-btn { background-color: #ffc107; color: #333; }
        .edit-btn:hover { background-color: #e0a800; }
        .delete-btn { background-color: #dc3545; }
        .delete-btn:hover { background-color: #c82333; }

        /* สไตล์ Modal ยืนยันการลบ */
        .modal { display: none; position: fixed; z-index: 999; left: 0; top: 0; width: 100%; height: 100%; overflow: auto; background-color: rgba(0,0,0,0.6); justify-content: center; align-items: center; }
        .modal.show { display: flex; }
        .modal-content { background-color: #fff; padding: 30px; border-radius: 12px; width: 450px; max-width: 90%; text-align: center; box-shadow: 0 10px 30px rgba(0,0,0,0.3); transform: scale(0.7); transition: transform 0.3s ease; }
        .modal.show .modal-content { transform: scale(1); }
        .modal-header { font-size: 22px; font-weight: bold; margin-bottom: 15px; color: #dc3545; }
        .modal-body { font-size: 16px; line-height: 1.5; margin-bottom: 25px; color: #333; }
        .modal-footer { margin-top: 20px; display: flex; justify-content: center; gap: 15px; }
        .modal-btn { padding: 12px 25px; border: none; border-radius: 8px; cursor: pointer; font-size: 16px; font-weight: 500; transition: all 0.3s ease; }
        .modal-btn.submit { background-color: #dc3545; color: white; }
        .modal-btn.submit:hover { background-color: #c82333; }
        .modal-btn.cancel { background-color: #6c757d; color: white; }
        .modal-btn.cancel:hover { background-color: #5a6268; }

        /* === ส่วนที่ 3: เพิ่ม CSS สำหรับ Pagination ให้เป็นสีเดียวกับปุ่มค้นหา === */
        .pagination-container { text-align: center; margin-top: 30px; padding-bottom: 20px; }
        .pagination { display: inline-flex; list-style-type: none; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.1); }
        .pagination a {
            color: #c0392b; /* สีแดงเดียวกับปุ่มค้นหา */
            padding: 12px 18px;
            text-decoration: none;
            transition: background-color .3s;
            border-right: 1px solid #ddd;
            background-color: #fff;
            font-weight: 500;
        }
        .pagination li:last-child a { border-right: none; }
        .pagination a.active {
            background-color: #c0392b; /* สีแดงเดียวกับปุ่มค้นหา */
            color: white;
        }
        .pagination a:hover:not(.active) {
            background-color: #f5b7b1; /* สีแดงอ่อนสำหรับ hover */
        }
        .pagination .disabled a { color: #ccc; pointer-events: none; cursor: default; }

        /* Modal Styles สำหรับแจ้งเตือน (จากโค้ดแดชบอร์ด) */
        .notification-modal { display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5); }
        .notification-modal-content { background-color: #fefefe; margin: 5% auto; padding: 20px; border-radius: 8px; width: 80%; max-width: 600px; box-shadow: 0 4px 8px rgba(0,0,0,0.2); }
        .close { color: #aaa; float: right; font-size: 28px; font-weight: bold; cursor: pointer; }
        .close:hover { color: black; }
        .notification-section { margin-bottom: 20px; }
        .notification-section h3 { color: #A44A3F; margin-bottom: 10px; border-bottom: 1px solid #eee; padding-bottom: 5px; }
        .notification-item { padding: 8px 0; border-bottom: 1px solid #f0f0f0; }
        .notification-item:last-child { border-bottom: none; }
        .no-notifications { color: #777; font-style: italic; }

        /* กำหนดให้ชื่อสินค้าอยู่ในบรรทัดเดียวและแสดง ... ถ้ายาวเกิน */
        .product-name {
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 200px; /* ปรับตามความเหมาะสม */
        }

        /* กำหนดความกว้างของคอลัมน์ในตาราง */
        th:nth-child(1), td:nth-child(1) { width: 10%; } /* รหัสสินค้า */
        th:nth-child(2), td:nth-child(2) { width: 25%; } /* ชื่อสินค้า */
        th:nth-child(3), td:nth-child(3) { width: 10%; } /* จำนวนคงเหลือ */
        th:nth-child(4), td:nth-child(4) { width: 10%; } /* หน่วยนับ */
        th:nth-child(5), td:nth-child(5) { width: 15%; } /* ราคาทุน */
        th:nth-child(6), td:nth-child(6) { width: 15%; } /* ราคาขาย */
        th:nth-child(7), td:nth-child(7) { width: 15%; } /* การกระทำ */

    </style>
</head>
<body>
    <h1 style="font-size:25px; font-family: 'Microsoft Sans Serif'; display: flex; justify-content: space-between; align-items: center;">
        <div style="display: flex; align-items: center;">
            <img src="logo1.png" width="50" height="50" style="vertical-align: middle;">
            <span style="vertical-align: middle;">Chutikarn</span>
        </div>
        
        <div class="user-section" style="display: flex; align-items: center; gap: 15px;">
            <!-- ระบบแจ้งเตือนกระดิ่งจากโค้ดแดชบอร์ด -->
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
            <a href="Dashboard.php" class="menu-item">
                <div class="icon-medium">&#127968;</div>
                <span style="font-size:18px; color: #000000;">หน้าแรก</span>
            </a>
            <a href="Manage_products.php" class="menu-item active">
                <div class="icon-medium">&#128230;</div>
                <span style="font-size:18px; color: #A44A3F;">จัดการสินค้า</span>
            </a>
            <a href="manage_types.php" class="menu-item">
                <div class="icon-medium">&#128193;</div>
                <span style="font-size:18px; color: #000000;">จัดการประเภทสินค้า</span>
            </a>
            <a href="receive_stock.php" class="menu-item">
                <div class="icon-medium">&#128229;</div>
                <span style="font-size:18px; color: #000000;">รับสินค้าเข้า</span>
            </a>
            <a href="Order.php" class="menu-item">
                <div class="icon-medium">&#128722;</div>
                <span style="font-size:18px; color: #000000;">สั่งซื้อสินค้า</span>
            </a>
            <a href="storage.php" class="menu-item">
                <div class="icon-medium">&#128190;</div>
                <span style="font-size:18px; color: #000000;">จัดเก็บสินค้า</span>
            </a>
            <a href="Employee_Account.php" class="menu-item">
                <div class="icon-medium">&#128101;</div>
                <span style="font-size:18px; color: #000000;">จัดการพนักงาน</span>
            </a>
            <a href="shop.php" class="menu-item">
                <div class="icon-medium">&#127978;</div>
                <span style="font-size:18px; color: #000000;">จัดการร้านค้า</span>
            </a>
            <a href="reports.php" class="menu-item">
                <div class="icon-medium">&#128200;</div>
                <span style="font-size:18px; color: #000000;">รายงาน</span>
            </a>
            <a href="settings.php" class="menu-item">
                <div class="icon-medium">⚙️</div>
                <span style="font-size:18px; color: #000000;">ตั้งค่าระบบ</span>
            </a>
        </div>

        <div class="content">
            <h2 class="page-header">จัดการสินค้า</h2>

            <div class="product-controls">
                <div class="search-container">
                    <input type="text" id="searchInput" class="search-input" placeholder="ค้นหาสินค้า..." onkeyup="searchProducts()">
                    <button class="search-btn" onclick="searchProducts()">ค้นหา</button>
                </div>
                <button class="add-product-btn" onclick="window.location.href='Addproduct.php'">+ เพิ่มสินค้าใหม่</button>
            </div>

            <div class="product-table">
                <table>
                    <thead>
                        <tr>
                            <th>รหัสสินค้า</th>
                            <th>ชื่อสินค้า</th>
                            <th>จำนวนคงเหลือ</th>
                            <th>หน่วยนับ</th>
                            <th>ราคาทุน (บาท)</th>
                            <th>ราคาขาย (บาท)</th>
                            <th>การกระทำ</th>
                        </tr>
                    </thead>
                    <tbody id="productTable">
                        <?php if (count($products) > 0): ?>
                            <?php foreach ($products as $product): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($product['pd_ID']); ?></td>
                                    <td class="product-name" title="<?php echo htmlspecialchars($product['pd_name']); ?>">
                                        <?php echo htmlspecialchars($product['pd_name']); ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($product['pd_number']); ?></td>
                                    <td><?php echo htmlspecialchars($product['pd_unit']); ?></td>
                                    <td><?php echo number_format($product['pd_costprice'], 2); ?></td>
                                    <td><?php echo number_format($product['pd_saleprice'], 2); ?></td>
                                    <td>
                                        <div class="action-buttons">
                                            <button class="edit-btn" onclick="editProduct('<?php echo htmlspecialchars($product['pd_ID']); ?>')">แก้ไข</button>
                                            <button class="delete-btn" onclick="deleteProduct('<?php echo $product['pd_ID']; ?>', this)">ลบ</button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="7" style="text-align: center;">ไม่พบข้อมูลสินค้า</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($total_pages > 1): ?>
            <div class="pagination-container">
                <ul class="pagination">
                    <li class="<?php if($current_page <= 1){ echo 'disabled'; } ?>">
                        <a href="<?php if($current_page > 1){ echo '?page=' . ($current_page - 1); } else { echo '#'; } ?>">ก่อนหน้า</a>
                    </li>

                    <?php for($page = 1; $page <= $total_pages; $page++): ?>
                    <li>
                        <a href="?page=<?php echo $page; ?>" class="<?php if($page == $current_page) {echo 'active';} ?>"><?php echo $page; ?></a>
                    </li>
                    <?php endfor; ?>

                    <li class="<?php if($current_page >= $total_pages){ echo 'disabled'; } ?>">
                        <a href="<?php if($current_page < $total_pages) { echo '?page=' . ($current_page + 1); } else { echo '#'; } ?>">ถัดไป</a>
                    </li>
                </ul>
            </div>
            <?php endif; ?>

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

    <!-- Modal สำหรับแจ้งเตือน (จากโค้ดแดชบอร์ด) -->
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
    function toggleDropdown() {
        document.getElementById("userDropdown").classList.toggle("show");
    }
    
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
        
        // สำหรับ Modal แจ้งเตือน
        var modal = document.getElementById("notificationModal");
        if (event.target == modal) {
            modal.style.display = "none";
        }
    }

    // ฟังก์ชันสำหรับแสดง Modal แจ้งเตือน (จากโค้ดแดชบอร์ด)
    function showNotificationModal() {
        document.getElementById("notificationModal").style.display = "block";
    }
    
    // ฟังก์ชันสำหรับปิด Modal แจ้งเตือน (จากโค้ดแดชบอร์ด)
    function closeNotificationModal() {
        document.getElementById("notificationModal").style.display = "none";
    }

    function editProduct(productId) {
        window.location.href = 'edit_product.php?id=' + productId;
    }

    const deleteModal = document.getElementById('deleteConfirmModal');
    let productToDeleteId = null;

    function showDeleteModal(pdId, pdName) {
        productToDeleteId = pdId;
        const confirmText = document.getElementById('deleteConfirmText');
        confirmText.innerHTML = `คุณแน่ใจว่าต้องการลบสินค้า <br><b>"${pdName}" (รหัส: ${pdId})</b> หรือไม่? <br><br><span style="color: #dc3545;">การกระทำนี้ไม่สามารถกู้คืนได้</span>`;
        
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
        productToDeleteId = null;
    }

    function confirmDelete() {
        if (!productToDeleteId) return;

        fetch("delete_product.php", {
            method: "POST",
            headers: { "Content-Type": "application/x-www-form-urlencoded" },
            body: "pd_ID=" + encodeURIComponent(productToDeleteId)
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                alert("ลบสินค้าสำเร็จ!");
                window.location.reload(); // รีเฟรชหน้าเพื่อแสดงข้อมูลล่าสุด
            } else {
                alert("เกิดข้อผิดพลาด: " + data.message);
            }
        })
        .catch(err => {
            console.error('Fetch Error:', err);
            alert("เกิดข้อผิดพลาดในการเชื่อมต่อกับเซิร์ฟเวอร์");
        })
        .finally(() => {
            hideDeleteModal();
        });
    }
    
    window.addEventListener('click', function(event) {
        if (event.target == deleteModal) {
            hideDeleteModal();
        }
    });

    function addProduct() {
        window.location.href = 'Addproduct.php';
    }

    function searchProducts() {
        const searchTerm = document.getElementById('searchInput').value.toLowerCase();
        const table = document.getElementById('productTable');
        const rows = table.getElementsByTagName('tr');

        for (let i = 0; i < rows.length; i++) {
            const cells = rows[i].getElementsByTagName('td');
            if (cells.length > 0) { // ตรวจสอบว่ามี td ในแถวหรือไม่
                let found = false;
                // ค้นหาในทุกคอลัมน์ ยกเว้นคอลัมน์สุดท้าย (การกระทำ)
                for (let j = 0; j < cells.length - 1; j++) {
                    if (cells[j].textContent.toLowerCase().includes(searchTerm)) {
                        found = true;
                        break;
                    }
                }
                rows[i].style.display = found ? '' : 'none';
            }
        }
    }

    function deleteProduct(pdId, btn) {
        const row = btn.closest("tr");
        const productName = row.cells[1].textContent;
        showDeleteModal(pdId, productName);
    }
</script>

</body>
</html>