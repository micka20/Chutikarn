<?php
// --- get_order_details.php ---

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

// รับ ID ใบสั่งซื้อ
$buypd_id = isset($_GET['id']) ? $_GET['id'] : '';

if (empty($buypd_id)) {
    echo '<p style="text-align: center; padding: 20px; color: red;">ไม่พบรหัสใบสั่งซื้อ</p>';
    exit;
}

// 1. ดึงข้อมูลหลักของใบสั่งซื้อ
$sql_main = "SELECT b.buypd_ID, b.buypd_date, b.buypd_total, s.shop_name, u.user_name, b.buypd_status
             FROM buypd b
             JOIN shop s ON b.shop_ID = s.shop_ID
             JOIN user u ON b.user_ID = u.user_ID
             WHERE b.buypd_ID = ?";
$stmt_main = $conn->prepare($sql_main);
$stmt_main->bind_param("s", $buypd_id);
$stmt_main->execute();
$order = $stmt_main->get_result()->fetch_assoc();
$stmt_main->close();

if (!$order) {
    echo '<p style="text-align: center; padding: 20px; color: red;">ไม่พบข้อมูลใบสั่งซื้อ</p>';
    exit;
}

// 2. (ปรับปรุง) ดึงรายการสินค้าทั้งหมด พร้อมสถานะ
$sql_items = "SELECT pd_name, OD_number, OD_status 
              FROM orderdetails 
              WHERE buypd_ID = ?
              ORDER BY pd_name ASC";
$stmt_items = $conn->prepare($sql_items);
$stmt_items->bind_param("s", $buypd_id);
$stmt_items->execute();
$result_items = $stmt_items->get_result();

$items = [];
while ($item = $result_items->fetch_assoc()) {
    $items[] = $item;
}
$stmt_items->close();
$conn->close();

// กำหนดสถานะ (สำหรับแสดงผล)
$status_text = '';
$status_class = '';
if ($order['buypd_status'] == 'printed') {
    $status_text = 'รอรับสินค้า';
    $status_class = 'status-printed';
} elseif ($order['buypd_status'] == 'partially_received') {
    $status_text = 'รับบางส่วนแล้ว';
    $status_class = 'status-partially';
} elseif ($order['buypd_status'] == 'received') {
    $status_text = 'รับสินค้าแล้ว';
    $status_class = 'status-received';
}
?>

<div class="details-grid">
    <div class="detail-item">
        <strong>เลขที่ใบสั่งซื้อ:</strong>
        <span><?php echo htmlspecialchars($order['buypd_ID']); ?></span>
    </div>
    <div class="detail-item">
        <strong>สถานะ:</strong>
        <span><span class="status-badge <?php echo $status_class; ?>"><?php echo $status_text; ?></span></span>
    </div>
    <div class="detail-item">
        <strong>วันที่สั่งซื้อ:</strong>
        <span><?php echo date("d/m/Y", strtotime($order['buypd_date'])); ?></span>
    </div>
    <div class="detail-item">
        <strong>ร้านค้า:</strong>
        <span><?php echo htmlspecialchars($order['shop_name']); ?></span>
    </div>
    <div class="detail-item">
        <strong>ผู้จัดทำ:</strong>
        <span><?php echo htmlspecialchars($order['user_name']); ?></span>
    </div>
</div>

<hr>
<h4>รายการสินค้า</h4>

<table class="products-table">
    <thead>
        <tr>
            <th>ชื่อสินค้า</th>
            <th class="text-right">จำนวน (ชิ้น)</th>
            <th class="text-center" style="width: 150px;">สถานะการรับ</th>
        </tr>
    </thead>
    <tbody>
        <?php if (count($items) > 0): ?>
            <?php foreach ($items as $item): ?>
            <tr>
                <td><?php echo htmlspecialchars($item['pd_name']); ?></td>
                <td class="text-right"><?php echo number_format($item['OD_number']); ?></td>
                
                <td class="text-center">
                    <?php if ($item['OD_status'] == 'received'): ?>
                        <span class="status-item-received" style="color: #28a745; font-weight: bold;">✔ รับแล้ว</span>
                    <?php else: ?>
                        <span class="status-item-pending" style="color: #6c757d; font-style: italic;">- รอดำเนินการ</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        <?php else: ?>
            <tr>
                <td colspan="3" class="text-center">ไม่พบรายการสินค้า</td>
            </tr>
        <?php endif; ?>
    </tbody>
</table>

<div class="summary-box">
    <span>ยอดรวมสุทธิ</span>
    <span class="total-amount">฿<?php echo number_format($order['buypd_total'], 2); ?></span>
</div>

<div class="modal-actions" style="margin-top: 30px;">
    <button type="button" class="btn btn-cancel" onclick="closeModal()">ปิด</button>
</div>