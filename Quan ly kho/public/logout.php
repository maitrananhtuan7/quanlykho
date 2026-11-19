<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 1. KIỂM TRA VAI TRÒ TRƯỚC KHI XÓA SESSION TOÀN CỤC
$redirect_page = 'user_login.php'; // Mặc định nếu không xác định được sẽ về trang user

if (isset($_SESSION['auth_role'])) {
    $role = strtolower($_SESSION['auth_role']);
    if ($role === 'admin') {
        $redirect_page = 'admin_login.php';
    } else {
        $redirect_page = 'user_login.php';
    }
} 
// Cách kiểm tra dự phòng dựa trên các biến login đặc trưng của bạn
elseif (isset($_SESSION['admin_logged'])) {
    $redirect_page = 'admin_login.php';
} elseif (isset($_SESSION['user_logged'])) {
    $redirect_page = 'user_login.php';
}

// 2. XÓA SẠCH TOÀN BỘ SESSION ĐĂNG NHẬP (Giữ nguyên cơ chế bảo mật cũ)
$_SESSION = array();

if (ini_get("session_use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

session_destroy();

// 3. CHUYỂN HƯỚNG CHÍNH XÁC THEO ĐỐI TƯỢNG VỪA ĐĂNG XUẤT
header("Location: " . $redirect_page);
exit;
?>