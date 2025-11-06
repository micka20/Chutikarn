// login.js - จัดการการทำงานของฟอร์มล็อกอิน

document.addEventListener('DOMContentLoaded', function() {
    // องค์ประกอบต่างๆ
    const loginForm = document.getElementById('loginForm');
    const usernameInput = document.getElementById('username');
    const passwordInput = document.getElementById('password');
    const togglePasswordBtn = document.getElementById('togglePassword');
    const loginBtn = document.getElementById('loginBtn');
    const errorMessage = document.getElementById('errorMessage');

    // ฟังก์ชันแสดง/ซ่อนรหัสผ่าน
    togglePasswordBtn.addEventListener('click', function() {
        const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
        passwordInput.setAttribute('type', type);
        
        // เปลี่ยนไอคอน
        this.textContent = type === 'password' ? '👁️' : '🔒';
        
        // เพิ่มเอฟเฟกต์
        this.style.transform = 'scale(1.1)';
        setTimeout(() => {
            this.style.transform = 'scale(1)';
        }, 200);
    });

    // ฟังก์ชันตรวจสอบฟอร์ม
    function validateForm() {
        let isValid = true;
        
        // ล้างสถานะ error ก่อนหน้า
        clearErrors();
        
        // ตรวจสอบชื่อผู้ใช้
        if (!usernameInput.value.trim()) {
            showError(usernameInput, 'กรุณากรอกชื่อผู้ใช้');
            isValid = false;
        }
        
        // ตรวจสอบรหัสผ่าน
        if (!passwordInput.value) {
            showError(passwordInput, 'กรุณากรอกรหัสผ่าน');
            isValid = false;
        } else if (passwordInput.value.length < 3) {
            showError(passwordInput, 'รหัสผ่านต้องมีความยาวอย่างน้อย 3 ตัวอักษร');
            isValid = false;
        }
        
        return isValid;
    }

    // ฟังก์ชันแสดงข้อผิดพลาด
    function showError(input, message) {
        const formGroup = input.closest('.form-group');
        formGroup.classList.add('error');
        
        // สร้างข้อความ error ถ้ายังไม่มี
        let errorElement = formGroup.querySelector('.error-text');
        if (!errorElement) {
            errorElement = document.createElement('div');
            errorElement.className = 'error-text';
            errorElement.style.cssText = `
                color: #dc3545;
                font-size: 0.8rem;
                margin-top: 5px;
                animation: slideDown 0.3s ease;
            `;
            formGroup.appendChild(errorElement);
        }
        
        errorElement.textContent = message;
    }

    // ฟังก์ชันล้างข้อผิดพลาด
    function clearErrors() {
        const errorElements = document.querySelectorAll('.error-text');
        errorElements.forEach(element => element.remove());
        
        const formGroups = document.querySelectorAll('.form-group');
        formGroups.forEach(group => {
            group.classList.remove('error', 'success');
        });
    }

    // ฟังก์ชันแสดงสถานะ success
    function showSuccess(input) {
        const formGroup = input.closest('.form-group');
        formGroup.classList.remove('error');
        formGroup.classList.add('success');
    }

    // Event listener สำหรับการตรวจสอบ real-time
    usernameInput.addEventListener('blur', function() {
        if (this.value.trim()) {
            showSuccess(this);
        }
    });

    passwordInput.addEventListener('blur', function() {
        if (this.value && this.value.length >= 3) {
            showSuccess(this);
        }
    });

    // Event listener สำหรับการส่งฟอร์ม
    loginForm.addEventListener('submit', function(e) {
        e.preventDefault();
        
        if (!validateForm()) {
            return;
        }
        
        // แสดงสถานะ loading
        loginBtn.disabled = true;
        loginBtn.innerHTML = '<span class="loading"></span> กำลังเข้าสู่ระบบ...';
        
        // ซ่อนข้อความ error ถ้ามี
        if (errorMessage) {
            errorMessage.style.display = 'none';
        }
        
        // ส่งฟอร์มหลังจาก delay นิดหน่อยเพื่อให้เห็น loading
        setTimeout(() => {
            this.submit();
        }, 1000);
    });

    // เพิ่มการตรวจสอบเมื่อผู้ใช้กด Enter
    loginForm.addEventListener('keypress', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            loginForm.dispatchEvent(new Event('submit'));
        }
    });

    // Auto-focus ที่ช่องชื่อผู้ใช้เมื่อโหลดหน้า
    usernameInput.focus();

    // เพิ่มเอฟเฟกต์เมื่อโหลดหน้าเสร็จ
    document.body.classList.add('fade-in');

    // ฟังก์ชันสำหรับจัดการการเชื่อมต่อเครือข่าย
    function handleNetworkStatus() {
        if (!navigator.onLine) {
            showOfflineMessage();
        }
        
        window.addEventListener('online', function() {
            hideOfflineMessage();
        });
        
        window.addEventListener('offline', function() {
            showOfflineMessage();
        });
    }

    function showOfflineMessage() {
        // สร้างหรืออัปเดตข้อความ offline
        let offlineMsg = document.getElementById('offlineMessage');
        if (!offlineMsg) {
            offlineMsg = document.createElement('div');
            offlineMsg.id = 'offlineMessage';
            offlineMsg.style.cssText = `
                position: fixed;
                top: 0;
                left: 0;
                right: 0;
                background: #ffc107;
                color: #856404;
                padding: 10px;
                text-align: center;
                font-weight: bold;
                z-index: 10000;
            `;
            offlineMsg.textContent = '⚠️ คุณกำลังออฟไลน์ - ตรวจสอบการเชื่อมต่ออินเทอร์เน็ต';
            document.body.appendChild(offlineMsg);
        }
    }

    function hideOfflineMessage() {
        const offlineMsg = document.getElementById('offlineMessage');
        if (offlineMsg) {
            offlineMsg.remove();
        }
    }

    // เริ่มต้นตรวจสอบสถานะเครือข่าย
    handleNetworkStatus();

    // ฟังก์ชันสำหรับป้องกันการส่งฟอร์มซ้ำ
    let formSubmitted = false;
    
    loginForm.addEventListener('submit', function() {
        if (formSubmitted) {
            e.preventDefault();
            return;
        }
        formSubmitted = true;
    });

    // เพิ่มการตรวจสอบเบื้องต้นก่อนส่งฟอร์ม
    window.addEventListener('beforeunload', function() {
        if (loginBtn.disabled) {
            return 'กำลังเข้าสู่ระบบ กรุณารอสักครู่...';
        }
    });

    // Cleanup เมื่อหน้ายังโหลดไม่เสร็จ
    window.addEventListener('pageshow', function(e) {
        // รีเซ็ตปุ่มถ้าหน้านี้ถูกโหลดจาก cache
        if (e.persisted) {
            loginBtn.disabled = false;
            loginBtn.innerHTML = 'เข้าสู่ระบบ';
            clearErrors();
        }
    });

    // เพิ่ม accessibility features
    loginForm.setAttribute('novalidate', 'true'); // ใช้การ validate แบบ custom
    
    // ARIA labels สำหรับ accessibility
    usernameInput.setAttribute('aria-describedby', 'usernameHelp');
    passwordInput.setAttribute('aria-describedby', 'passwordHelp');
    
    // สร้าง element สำหรับ aria-describedby
    const usernameHelp = document.createElement('div');
    usernameHelp.id = 'usernameHelp';
    usernameHelp.className = 'sr-only';
    usernameHelp.textContent = 'กรอกชื่อผู้ใช้ของคุณ';
    
    const passwordHelp = document.createElement('div');
    passwordHelp.id = 'passwordHelp';
    passwordHelp.className = 'sr-only';
    passwordHelp.textContent = 'กรอกรหัสผ่านของคุณ';
    
    loginForm.appendChild(usernameHelp);
    loginForm.appendChild(passwordHelp);
});

// CSS สำหรับ screen readers
const style = document.createElement('style');
style.textContent = `
    .sr-only {
        position: absolute;
        width: 1px;
        height: 1px;
        padding: 0;
        margin: -1px;
        overflow: hidden;
        clip: rect(0, 0, 0, 0);
        white-space: nowrap;
        border: 0;
    }
`;
document.head.appendChild(style);

// Error boundary สำหรับ JavaScript
window.addEventListener('error', function(e) {
    console.error('JavaScript Error:', e.error);
    // สามารถเพิ่มการแจ้งเตือนผู้ใช้ได้ที่นี่ถ้าต้องการ
});

// Performance monitoring
window.addEventListener('load', function() {
    // วัดเวลาโหลดหน้า
    const loadTime = performance.timing.loadEventEnd - performance.timing.navigationStart;
    console.log(`Page load time: ${loadTime}ms`);
});