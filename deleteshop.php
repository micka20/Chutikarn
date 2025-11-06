<?php
header('Content-Type: application/json');

// --- การตั้งค่าการเชื่อมต่อฐานข้อมูล ---
$servername = "localhost";
$username = "root";
$password = "12345678"; // กรุณาเปลี่ยนเป็นรหัสผ่านของคุณ
$dbname = "chutikarnceramic1";

// --- เริ่มการทำงาน ---

// ตรวจสอบว่าเป็นการส่งคำขอแบบ POST และมี shop_ID ส่งมาด้วยหรือไม่
if ($_SERVER["REQUEST_METHOD"] !== "POST" || !isset($_POST["shop_ID"])) {
    echo json_encode(["success" => false, "message" => "คำขอไม่ถูกต้อง"]);
    exit();
}

// สร้างการเชื่อมต่อ
$conn = new mysqli($servername, $username, $password, $dbname);

// ตรวจสอบการเชื่อมต่อ
if ($conn->connect_error) {
    echo json_encode(["success" => false, "message" => "เชื่อมต่อฐานข้อมูลล้มเหลว: " . $conn->connect_error]);
    exit();
}

// ตั้งค่า character set เป็น utf8
$conn->set_charset("utf8");

$shop_ID = $_POST["shop_ID"];

// --- ตรวจสอบว่าร้านค้ามีใบสั่งซื้อที่เกี่ยวข้องหรือไม่ ---
// ก่อนที่จะลบร้านค้า เราควรตรวจสอบก่อนว่ามีข้อมูลอื่นที่อ้างอิงถึงร้านค้านี้หรือไม่
// ในที่นี้คือตาราง `buypd` (ใบสั่งซื้อ)
$stmt_check = $conn->prepare("SELECT buypd_ID FROM buypd WHERE shop_ID = ?");
$stmt_check->bind_param("s", $shop_ID);
$stmt_check->execute();
$result_check = $stmt_check->get_result();

if ($result_check->num_rows > 0) {
    // หากพบข้อมูลที่เกี่ยวข้อง ให้ส่งข้อความแจ้งเตือนกลับไป
    echo json_encode([
        "success" => false,
        "message" => "ไม่สามารถลบร้านค้านี้ได้ เนื่องจากมีข้อมูลการสั่งซื้อสินค้าอยู่"
    ]);
    $stmt_check->close();
    $conn->close();
    exit();
}
$stmt_check->close();


// --- เตรียมคำสั่ง SQL สำหรับลบข้อมูล ---
// ใช้ Prepared Statement เพื่อป้องกัน SQL Injection
$stmt = $conn->prepare("DELETE FROM shop WHERE shop_ID = ?");

// ตรวจสอบว่าการเตรียมคำสั่งสำเร็จหรือไม่
if ($stmt === false) {
    echo json_encode(["success" => false, "message" => "เตรียมคำสั่ง SQL ล้มเหลว: " . $conn->error]);
    $conn->close();
    exit();
}

// ผูกตัวแปร shop_ID เข้ากับคำสั่ง SQL
$stmt->bind_param("s", $shop_ID);

// สั่งให้คำสั่ง SQL ทำงาน
if ($stmt->execute()) {
    // หากลบสำเร็จ
    if ($stmt->affected_rows > 0) {
        echo json_encode(["success" => true, "message" => "ลบข้อมูลร้านค้าสำเร็จ"]);
    } else {
        echo json_encode(["success" => false, "message" => "ไม่พบร้านค้าที่ต้องการลบ"]);
    }
} else {
    // หากเกิดข้อผิดพลาดในการลบ
    echo json_encode(["success" => false, "message" => "เกิดข้อผิดพลาดในการลบข้อมูล: " . $stmt->error]);
}

// ปิด statement และการเชื่อมต่อ
$stmt->close();
$conn->close();
?>