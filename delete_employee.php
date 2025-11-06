<?php
header('Content-Type: application/json');
$servername = "localhost";
$username = "root";
$password = "12345678";
$dbname = "chutikarnceramic1";

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
    echo json_encode(["success" => false, "message" => "เชื่อมต่อฐานข้อมูลล้มเหลว"]);
    exit();
}

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["user_ID"])) {
    $user_ID = $_POST["user_ID"];

    $stmt = $conn->prepare("DELETE FROM user WHERE user_ID = ?");
    $stmt->bind_param("s", $user_ID);

    if ($stmt->execute()) {
        echo json_encode(["success" => true]);
    } else {
        echo json_encode(["success" => false, "message" => $conn->error]);
    }

    $stmt->close();
} else {
    echo json_encode(["success" => false, "message" => "คำขอไม่ถูกต้อง"]);
}

$conn->close();
