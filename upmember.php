<?php
$servername = "localhost"; 
$username   = "root"; 
$password   = ""; 
$dbname     = "chutikarn1";

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) { die("Connection failed: " . $conn->connect_error); }

$cus_ID      = $_POST['cus_ID'];
$cus_name    = $_POST['cus_name'];
$cus_tel     = $_POST['cus_tel'];
$cus_password= $_POST['cus_password'];
$cus_address = $_POST['cus_address'];

$sql = "UPDATE customer1 
        SET cus_name='$cus_name',
            cus_tel='$cus_tel',
            cus_password='$cus_password',
            cus_address='$cus_address'
        WHERE cus_ID='$cus_ID'";

if ($conn->query($sql) === TRUE) {
    echo "<script>alert('แก้ไขข้อมูลเรียบร้อยแล้ว'); window.location='member.php';</script>";
} else {
    echo "Error: " . $conn->error;
}

$conn->close();
?>
