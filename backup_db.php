<?php
session_start();

// ตรวจสอบการล็อกอิน
if (!isset($_SESSION['user_username'])) { 
    header("Location: login.php"); 
    exit(); 
}

// ตั้งค่าฐานข้อมูล
$db_host = "localhost";
$db_user = "root";
$db_pass = "12345678";
$db_name = "chutikarnceramic1";

// ตั้งชื่อไฟล์ backup
$backup_file_name = "chutikarnceramic1_backup_" . date("Y-m-d_H-i-s") . ".sql";

try {
    // เชื่อมต่อฐานข้อมูล
    $conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
    $conn->set_charset("utf8");

    if ($conn->connect_error) {
        throw new Exception("การเชื่อมต่อฐานข้อมูลล้มเหลว: " . $conn->connect_error);
    }

    // เริ่มสร้างเนื้อหา SQL
    $sql_script = "-- Chutikarn Ceramic Database Backup\n";
    $sql_script .= "-- Generated: " . date("Y-m-d H:i:s") . "\n";
    $sql_script .= "-- Database: " . $db_name . "\n\n";
    
    // ตั้งค่า SQL mode
    $sql_script .= "SET SQL_MODE = \"NO_AUTO_VALUE_ON_ZERO\";\n";
    $sql_script .= "SET AUTOCOMMIT = 0;\n";
    $sql_script .= "START TRANSACTION;\n";
    $sql_script .= "SET time_zone = \"+00:00\";\n\n";
    
    // ดึงข้อมูลทั้งหมดจากตาราง
    $tables = array();
    $result = $conn->query("SHOW TABLES");
    
    while($row = $result->fetch_row()) {
        $tables[] = $row[0];
    }
    
    // สร้าง backup สำหรับแต่ละตาราง
    foreach($tables as $table) {
        $sql_script .= "--\n-- Table structure for table `$table`\n--\n\n";
        
        // สร้างคำสั่ง CREATE TABLE
        $create_table = $conn->query("SHOW CREATE TABLE `$table`");
        $create_row = $create_table->fetch_row();
        $sql_script .= $create_row[1] . ";\n\n";
        
        // ดึงข้อมูลจากตาราง
        $sql_script .= "--\n-- Dumping data for table `$table`\n--\n\n";
        
        $data_result = $conn->query("SELECT * FROM `$table`");
        $num_fields = $data_result->field_count;
        
        while($row = $data_result->fetch_row()) {
            $sql_script .= "INSERT INTO `$table` VALUES(";
            
            for($j = 0; $j < $num_fields; $j++) {
                $row[$j] = addslashes($row[$j]);
                $row[$j] = str_replace("\n", "\\n", $row[$j]);
                
                if (isset($row[$j])) {
                    $sql_script .= '"' . $row[$j] . '"';
                } else {
                    $sql_script .= '""';
                }
                
                if ($j < ($num_fields - 1)) {
                    $sql_script .= ',';
                }
            }
            
            $sql_script .= ");\n";
        }
        
        $sql_script .= "\n";
        $data_result->free();
    }
    
    $sql_script .= "COMMIT;\n";
    
    // ปิดการเชื่อมต่อ
    $conn->close();
    
    // ส่ง header สำหรับดาวน์โหลดไฟล์
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $backup_file_name . '"');
    header('Content-Transfer-Encoding: binary');
    header('Expires: 0');
    header('Cache-Control: must-revalidate');
    header('Pragma: public');
    header('Content-Length: ' . strlen($sql_script));
    
    // ส่งเนื้อหา SQL
    echo $sql_script;
    exit;
    
} catch (Exception $e) {
    // หากเกิดข้อผิดพลาด ให้ redirect กลับไปที่ settings พร้อมแสดงข้อความ error
    $_SESSION['error_message'] = "การสำรองข้อมูลล้มเหลว: " . $e->getMessage();
    header("Location: settings.php?active_tab=pane-backup");
    exit();
}
?>