<?php
// --- get_order_items.php ---

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

// (ปรับปรุง) ดึงเฉพาะรายการสินค้าที่ยังคงสถานะ 'pending' (ยังไม่ได้รับ)
$sql = "SELECT pd_name, OD_number 
        FROM orderdetails 
        WHERE buypd_ID = ? AND OD_status = 'pending'
        ORDER BY pd_name ASC";
        
$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $buypd_id);
$stmt->execute();
$result = $stmt->get_result();

$items = [];
if ($result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $items[] = $row;
    }
}
$stmt->close();
$conn->close();

$total_items_pending = count($items);
?>

<?php if ($total_items_pending > 0): ?>
    <div class="select-all-section">
        <input type="checkbox" id="selectAll" class="select-all-checkbox" onclick="toggleSelectAll(this)">
        <label for="selectAll" class="select-all-label">เลือกทั้งหมด (<?php echo $total_items_pending; ?> รายการที่รอรับ)</label>
    </div>

    <div class="receive-items-container">
        <?php foreach ($items as $item): ?>
        <div class="receive-item">
            <input 
                type="checkbox" 
                class="receive-item-checkbox" 
                name="received_items[]" 
                value="<?php echo htmlspecialchars($item['pd_name']); ?>"
                onchange="updateReceiveSummary()"
            >
            <div class="receive-item-info">
                <div class="receive-item-name"><?php echo htmlspecialchars($item['pd_name']); ?></div>
                <div class="receive-item-quantity">จำนวนที่สั่ง: <?php echo $item['OD_number']; ?> ชิ้น</div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="receive-summary">
        <h4>สรุปการรับสินค้า</h4>
        <div class="summary-item summary-total">
            <span>รายการที่เลือก:</span>
            <span>
                <span id="selectedCount">0</span> / <span id="totalCount"><?php echo $total_items_pending; ?></span>
            </span>
        </div>
    </div>

    <div class="receive-actions">
        <button type="button" class="btn btn-cancel" onclick="closeReceiveModal()">ยกเลิก</button>
        <button 
            type="button" 
            id="confirmReceiveBtn" 
            class="btn btn-confirm" 
            onclick="submitReceiveForm()"
            disabled>
            ยืนยันการรับสินค้า
        </button>
    </div>

<?php else: ?>
    <div class="empty-state" style="padding: 30px;">
        <div class="icon" style="font-size: 48px;">👍</div>
        <h3>รับสินค้ารายการนี้ครบแล้ว</h3>
        <p>ไม่มีรายการสินค้ารอรับเข้าสำหรับใบสั่งซื้อนี้</p>
    </div>
    <div class="receive-actions" style="border-top: none; justify-content: center;">
        <button type="button" class="btn btn-cancel" onclick="closeReceiveModal()">ปิดหน้าต่าง</button>
    </div>
<?php endif; ?>