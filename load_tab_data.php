<?php
// load_tab_data.php
session_start();

// การเชื่อมต่อฐานข้อมูล
$servername = "localhost";
$username = "root";
$password = "12345678";
$dbname = "chutikarnceramic1";

$conn = new mysqli($servername, $username, $password, $dbname);
$conn->set_charset("utf8");

if ($conn->connect_error) {
    die(json_encode(['success' => false, 'error' => 'Database connection failed: ' . $conn->connect_error]));
}

$tab = $_GET['tab'] ?? 'receive';

// เปิด error reporting สำหรับ debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

$response = ['success' => true, 'data' => []];

try {
    if ($tab == 'receive') {
        // ดึงข้อมูลใบสั่งซื้อที่รอรับ
        $sql = "SELECT b.buypd_ID, b.buypd_date, b.buypd_total, s.shop_name, u.user_name, b.buypd_status
                FROM buypd b
                JOIN shop s ON b.shop_ID = s.shop_ID
                JOIN user u ON b.user_ID = u.user_ID
                WHERE b.buypd_status IN ('printed', 'partially_received')
                ORDER BY 
                    CASE 
                        WHEN b.buypd_status = 'partially_received' THEN 1
                        WHEN b.buypd_status = 'printed' THEN 2
                        ELSE 3
                    END,
                    CAST(SUBSTRING(b.buypd_ID, 4) AS UNSIGNED) ASC";

        $result = $conn->query($sql);
        $pending_orders = [];
        if ($result && $result->num_rows > 0) {
            while($row = $result->fetch_assoc()) {
                $pending_orders[] = $row;
            }
        }
        $response['data']['pending_orders'] = $pending_orders;
        
    } else if ($tab == 'history') {
        // ดึงข้อมูลประวัติการรับสินค้า
        $history_sql = "SELECT b.buypd_ID, b.buypd_date, b.buypd_total, s.shop_name, u.user_name, b.buypd_status
                       FROM buypd b
                       JOIN shop s ON b.shop_ID = s.shop_ID
                       JOIN user u ON b.user_ID = u.user_ID
                       WHERE b.buypd_status = 'received'
                       ORDER BY b.buypd_date DESC, CAST(SUBSTRING(b.buypd_ID, 4) AS UNSIGNED) DESC";

        $history_result = $conn->query($history_sql);
        $received_history = [];
        if ($history_result && $history_result->num_rows > 0) {
            while($row = $history_result->fetch_assoc()) {
                $received_history[] = $row;
            }
        }
        $response['data']['received_history'] = $received_history;
    }
} catch (Exception $e) {
    $response = ['success' => false, 'error' => $e->getMessage()];
}

$conn->close();

header('Content-Type: application/json');
echo json_encode($response, JSON_UNESCAPED_UNICODE);
?>