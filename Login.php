<?php
session_start();

// ตรวจสอบว่ามีการส่งข้อมูลฟอร์มแบบ POST หรือไม่
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // รายละเอียดการเชื่อมต่อฐานข้อมูล
    $servername = "localhost";
    $username = "root";
    $password = "12345678";
    $dbname = "chutikarnceramic1";

    // สร้างการเชื่อมต่อ
    $conn = new mysqli($servername, $username, $password, $dbname);

    // ตรวจสอบการเชื่อมต่อ
    if ($conn->connect_error) {
        die("Connection failed: " . $conn->connect_error);
    }

    // รับค่า username และ password จากฟอร์ม
    $input_username = $_POST['username'];
    $input_password = $_POST['password'];

    // เตรียม SQL statement เพื่อป้องกัน SQL Injection
    $sql = "SELECT * FROM user WHERE user_username = ? AND user_password = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ss", $input_username, $input_password);
    $stmt->execute();
    $result = $stmt->get_result();

    // ตรวจสอบว่าพบผู้ใช้หรือไม่
    if ($result->num_rows > 0) {
        // ดึงข้อมูลผู้ใช้
        $user = $result->fetch_assoc();
        
        // ล็อกอินสำเร็จ ตั้งค่า session
        $_SESSION['user_username'] = $input_username;
        $_SESSION['loggedin'] = true;
        $_SESSION['user_position'] = $user['user_position']; // บันทึกตำแหน่งผู้ใช้ใน session
        $_SESSION['user_type'] = $user['user_type']; // บันทึกประเภทผู้ใช้ใน session
        $_SESSION['user_name'] = $user['user_name']; // บันทึกชื่อผู้ใช้ใน session
        $_SESSION['user_ID'] = $user['user_ID']; // บันทึกรหัสผู้ใช้ใน session

        // ตรวจสอบตำแหน่งผู้ใช้เพื่อนำทางไปยังหน้าที่เหมาะสม
        if ($user['user_position'] == 'ผู้ดูแลระบบ' || $user['user_type'] == 'ผู้ดูแลระบบ') {
            // ถ้าเป็นผู้ดูแลระบบ ให้ไปที่หน้า Dashboard.php
            header("Location: Dashboard.php");
        } else {
            // ถ้าเป็นพนักงาน ให้ไปที่หน้า Dashboard2.php
            header("Location: Dashboard2.php");
        }
        exit();
    } else {
        // ล็อกอินไม่สำเร็จ
        $error = "ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง";
    }

    // ปิด statement และการเชื่อมต่อ
    $stmt->close();
    $conn->close();
}
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login to Chutikarn</title>
    <link href="https://fonts.googleapis.com/css2?family=Kanit:wght@300;400;500;600&display=swap" rel="stylesheet">
    <style>
        /* Reset และพื้นฐาน */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Kanit', 'Arial', sans-serif;
            background-image: url('c0df913d-580b-4be1-8033-dc217b80fdfe.JPEG'); /* Background image */
            background-size: cover;
            background-position: center;
            background-attachment: fixed;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            padding: 20px;
            position: relative;
        }

        /* Container หลัก */
        .login-container {
            width: 100%;
            max-width: 480px;
            text-align: center;
            position: relative;
            z-index: 1;
        }

        /* การ์ดล็อกอิน */
        .login-card {
            /* Frosted Glass Effect */
            background: rgba(255, 255, 255, 0.15); 
            padding: 3rem 2.5rem;
            border-radius: 12px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.4); 
            backdrop-filter: blur(20px) saturate(1.8); 
            border: 1px solid rgba(255, 255, 255, 0.2);
            transition: background 0.3s ease, border 0.3s ease, box-shadow 0.3s ease;
        }

        .login-card:hover {
            background: rgba(255, 255, 255, 0.25); 
            border-color: rgba(255, 255, 255, 0.4);
            box-shadow: 0 12px 45px rgba(0,0,0,0.5);
        }

        .login-card h2 {
            color: #000; 
            margin-bottom: 2rem;
            font-size: 2rem;
            font-weight: 700;
            letter-spacing: 0px;
            text-shadow: 1px 1px 2px rgba(255,255,255,0.8); 
        }

        /* ข้อความผิดพลาด - ปรับให้ชัดและเข้มขึ้น */
        .error-message {
            /* พื้นหลังสีแดงอ่อนที่ทึบและเข้มขึ้น */
            background: rgba(255, 200, 200, 0.95); 
            /* สีข้อความแดงเข้ม */
            color: #A00; 
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            /* ขอบสีแดงเข้ม */
            border: 1px solid #D00; 
            font-size: 0.95rem; /* เพิ่มขนาดเล็กน้อย */
            font-weight: 500; /* เพิ่มความหนา */
            animation: slideDown 0.3s ease;
            display: none;
            text-shadow: 0 0 1px rgba(255, 255, 255, 0.5); /* ช่วยให้เด่นขึ้น */
        }

        .error-message.show {
            display: block;
        }

        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* ฟอร์ม */
        .form-group {
            margin-bottom: 1.5rem;
            text-align: left;
            position: relative;
        }

        .form-group label {
            color: #000; 
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            font-size: 0.95rem;
            text-shadow: 0 0 2px rgba(255,255,255,0.5); 
        }

        .form-group input {
            width: 100%;
            padding: 14px 16px;
            border: 1px solid rgba(255, 255, 255, 0.3);
            border-radius: 8px;
            font-size: 1rem;
            transition: all 0.3s ease;
            background: rgba(255, 255, 255, 0.1);
            font-family: 'Kanit', 'Arial', sans-serif;
            color: #000; 
            box-shadow: inset 0 1px 3px rgba(0,0,0,0.3);
        }
        
        /* เมื่อชี้ (Hover) ขอบจะเป็นสีแดงอมส้มอ่อน */
        .form-group input:hover {
            border-color: rgba(217, 83, 79, 0.6);
            background: rgba(255, 255, 255, 0.15);
        }

        /* เมื่อกด/เลือก (Focus) ขอบจะเป็นสีแดงอมส้มเข้ม */
        .form-group input:focus {
            outline: none;
            border-color: #D9534F;
            background: rgba(255, 255, 255, 0.2);
            box-shadow: 0 0 0 3px rgba(217, 83, 79, 0.5);
        }

        .form-group input::placeholder {
            color: #888; 
        }

        /* ปุ่มแสดงรหัสผ่าน */
        .toggle-password {
            position: absolute;
            right: 12px;
            top: 38px;
            background: none;
            border: none;
            cursor: pointer;
            padding: 6px;
            border-radius: 4px;
            transition: all 0.2s;
            color: #333; 
            display: flex;
            align-items: center;
            justify-content: center;
            text-shadow: 0 0 2px rgba(255,255,255,0.5); 
        }

        .toggle-password:hover {
            background: rgba(255, 255, 255, 0.1);
            color: #000;
        }

        .toggle-password svg {
            display: block;
            width: 24px;
            height: 24px;
        }

        /* ปุ่มล็อกอิน */
        .btn-login {
            width: 100%;
            padding: 16px;
            background: #d9534f;
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 1.1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            margin-top: 1.5rem;
            font-family: 'Kanit', 'Arial', sans-serif;
            box-shadow: 0 4px 15px rgba(217, 83, 79, 0.5);
        }

        .btn-login:hover {
            background: #c9302c;
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(217, 83, 79, 0.6);
        }

        .btn-login:active {
            transform: translateY(0);
            box-shadow: 0 2px 8px rgba(217, 83, 79, 0.4);
        }

        .btn-login:disabled {
            background: #ccc;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }

        /* เอฟเฟกต์ loading */
        .loading {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 3px solid rgba(255,255,255,.3);
            border-radius: 50%;
            border-top-color: #fff;
            animation: spin 1s ease-in-out infinite;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        /* เอฟเฟกต์เมื่อโหลดหน้า */
        .fade-in {
            animation: fadeIn 0.8s ease;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* Responsive Design */
        @media (max-width: 480px) {
            .login-container {
                padding: 10px;
            }
            
            .login-card {
                padding: 2rem 1.5rem;
            }
            
            .login-card h2 {
                font-size: 1.5rem;
            }
        }

        /* Accessibility */
        .form-group input:focus-visible {
            outline: 2px solid #888;
            outline-offset: 2px;
        }

        /* Reduced motion */
        @media (prefers-reduced-motion: reduce) {
            * {
                animation-duration: 0.01ms !important;
                animation-iteration-count: 1 !important;
                transition-duration: 0.01ms !important;
            }
        }
    </style>
</head>
<body>
    <div class="login-container fade-in">
        <div class="login-card">
            <h2>Login to Chutikarn</h2>
            
            <?php if (isset($error)): ?>
            <div id="errorMessage" class="error-message show">
                <?php echo $error; ?>
            </div>
            <?php else: ?>
            <div id="errorMessage" class="error-message">
                ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง
            </div>
            <?php endif; ?>
            
            <form id="loginForm" method="POST" action="">
                <div class="form-group">
                    <label for="username">Username</label>
                    <input 
                        type="text" 
                        id="username" 
                        name="username" 
                        required
                        autocomplete="username"
                        value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username']) : ''; ?>"
                    >
                </div>
                
                <div class="form-group">
                    <label for="password">Password</label>
                    <input 
                        type="password" 
                        id="password" 
                        name="password" 
                        required
                        autocomplete="current-password"
                    >
                    <button type="button" class="toggle-password" id="togglePassword" aria-label="แสดง/ซ่อนรหัสผ่าน">
                        <svg id="eyeOpenIcon" width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <path d="M1 12C1 12 5 4 12 4C19 4 23 12 23 12C23 12 19 20 12 20C5 20 1 12 1 12Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                            <path d="M12 15C13.6569 15 15 13.6569 15 12C15 10.3431 13.6569 9 12 9C10.3431 9 9 10.3431 9 12C9 13.6569 10.3431 15 12 15Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                        
                        <svg id="eyeClosedIcon" width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" style="display: none;">
                            <path d="M17.94 17.94C16.2306 19.243 14.1491 19.9649 12 20C5 20 1 12 1 12C2.24389 9.68192 3.96914 7.65663 6.06 6.06L17.94 17.94Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                            <path d="M9.9 4.24C10.5883 4.0789 11.2931 3.99836 12 4.00001C19 4.00001 23 12 23 12C22.393 13.1356 21.6691 14.2048 20.84 15.19L9.9 4.24Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                            <path d="M1 1L23 23" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </button>
                </div>
                
                <button type="submit" class="btn-login" id="loginBtn">
                    Login
                </button>
            </form>
        </div>
    </div>

    <script>
        const loginForm = document.getElementById('loginForm');
        const loginBtn = document.getElementById('loginBtn');
        const errorMessage = document.getElementById('errorMessage');
        const togglePassword = document.getElementById('togglePassword');
        const passwordInput = document.getElementById('password');
        const eyeOpenIcon = document.getElementById('eyeOpenIcon');
        const eyeClosedIcon = document.getElementById('eyeClosedIcon');
        
        // Toggle password visibility
        togglePassword.addEventListener('click', function() {
            const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
            passwordInput.setAttribute('type', type);
            
            // สลับการแสดงผลไอคอนตาเปิด/ปิด
            if (type === 'password') {
                eyeOpenIcon.style.display = 'block';
                eyeClosedIcon.style.display = 'none';
            } else {
                eyeOpenIcon.style.display = 'none';
                eyeClosedIcon.style.display = 'block';
            }
        });
        
        // Form submission
        loginForm.addEventListener('submit', function(e) {
            // ไม่ต้องป้องกันการส่งฟอร์มแบบ default เพราะเราต้องการให้ฟอร์มส่งข้อมูลจริงๆ
            
            // Disable button and show loading
            loginBtn.disabled = true;
            loginBtn.innerHTML = '<span class="loading"></span> กำลังเข้าสู่ระบบ...';
        });
        
        // Remove error message when user starts typing
        document.getElementById('username').addEventListener('input', () => {
            errorMessage.classList.remove('show');
        });
        
        document.getElementById('password').addEventListener('input', () => {
            errorMessage.classList.remove('show');
        });
    </script>
</body>
</html>