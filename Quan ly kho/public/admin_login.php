<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Bật thông báo lỗi PHP để kiểm tra nếu bỗng dưng không đăng nhập được
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Kết nối CSDL
if (file_exists('db.php')) {
    require 'db.php';
} else {
    die("Lỗi: Không tìm thấy file db.php trong thư mục này!");
}

// ==========================================
// 1. XỬ LÝ KHI CÓ YÊU CẦU ĐĂNG NHẬP (POST)
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login_admin'])) {
    $username = trim($_POST['username']);
    $password = trim($_POST['password']);

    if (!empty($username) && !empty($password)) {
        try {
            // TRUY VẤN: Tìm tài khoản phân quyền admin
            $stmt = $pdo->prepare("SELECT * FROM accounts WHERE username = ? AND role = 'admin' LIMIT 1");
            $stmt->execute([$username]);
            $account = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($account) {
                // Kiểm tra mật khẩu (hỗ trợ cả bcrypt lẫn text thô để tránh lỗi ko đăng nhập được khi test)
                $is_password_valid = false;
                if (password_verify($password, $account['password']) || $password === $account['password']) {
                    $is_password_valid = true;
                }

                if ($is_password_valid) {
                    if ((int)$account['status'] === 0) {
                        $_SESSION['flash_error'] = "Tài khoản quản trị này hiện đang bị khóa!";
                    } else {
                        $_SESSION['admin_logged'] = true;
                        $_SESSION['auth_id'] = $account['id'];
                        $_SESSION['auth_name'] = $account['full_name'];
                        $_SESSION['auth_role'] = $account['role'];
                        
                        $_SESSION['flash_success'] = "🎉 Đăng nhập quyền Quản trị viên thành công!";
                        header("Location: index.php");
                        exit;
                    }
                } else {
                    $_SESSION['flash_error'] = "Mật khẩu nhập vào không chính xác!";
                }
            } else {
                $_SESSION['flash_error'] = "Tài khoản Admin '$username' không tồn tại trên hệ thống!";
            }
        } catch (PDOException $e) {
            $_SESSION['flash_error'] = "Lỗi truy vấn CSDL: " . $e->getMessage();
        }
    } else {
        $_SESSION['flash_error'] = "Vui lòng nhập đầy đủ thông tin tài khoản và mật mã!";
    }

    // NGĂN CHẶN F5: Sau khi gán lỗi vào Session, ép trình duyệt chuyển hướng làm sạch dữ liệu POST
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

// ==========================================
// 2. HIỂN THỊ THÔNG BÁO (GET)
// ==========================================
$error = '';
if (isset($_SESSION['flash_error'])) { 
    $error = $_SESSION['flash_error']; 
    unset($_SESSION['flash_error']); // Xóa luôn để F5 lần sau không còn lỗi
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hệ Thống Quản Trị - Admin Login</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <style>
        @keyframes shrink { from { width: 100%; } to { width: 0%; } }
        .progress-bar-timer { animation: shrink 5s linear forwards; }
        .alert-toast { transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1); }
        .alert-toast.fade-out { opacity: 0; transform: translateY(-20px); }
    </style>
</head>
<body class="bg-gray-100 flex items-center justify-center min-h-screen relative p-4 font-sans">
    
    <div class="absolute w-96 h-96 bg-blue-400/10 rounded-full blur-3xl top-10 left-10 pointer-events-none"></div>
    <div class="absolute w-96 h-96 bg-indigo-400/10 rounded-full blur-3xl bottom-10 right-10 pointer-events-none"></div>

    <div class="w-full max-w-md relative z-10">
        
        <?php if (!empty($error)): ?>
            <div id="toast_alert" class="alert-toast bg-red-50 border-l-4 border-red-500 rounded-xl mb-4 shadow-md overflow-hidden relative">
                <div class="p-4 flex items-start justify-between">
                    <div class="flex">
                        <span class="text-lg">⚠️</span>
                        <div class="ml-3">
                            <p class="text-xs font-bold text-red-800"><?= htmlspecialchars($error) ?></p>
                            <p class="text-[10px] text-red-500 mt-1 font-normal">Tự đóng sau <span id="countdown_num" class="font-bold">5</span> giây...</p>
                        </div>
                    </div>
                    <button type="button" onclick="closeToast()" class="text-red-400 hover:text-red-700 text-xs font-bold px-2 focus:outline-none">✕</button>
                </div>
                <div class="absolute bottom-0 left-0 h-1 bg-red-500 progress-bar-timer"></div>
            </div>
        <?php endif; ?>

        <div class="bg-white border border-gray-200/80 p-8 rounded-2xl shadow-xl space-y-6">
            <div class="text-center">
                <div class="inline-flex items-center justify-center w-14 h-14 rounded-xl bg-indigo-50 text-indigo-600 text-2xl mb-3 border border-indigo-100">🔐</div>
                <h1 class="text-xl font-bold text-gray-800 tracking-tight uppercase">Hệ Thống Quản Trị</h1>
            </div>

            <form method="POST" class="space-y-4">
                <div>
                    <label class="block text-[11px] font-bold text-gray-500 uppercase tracking-wider mb-1.5">Tài khoản Admin</label>
                    <input type="text" name="username" required placeholder="Nhập tên đăng nhập hoặc mã..." 
                           class="w-full rounded-xl border border-gray-300 bg-white p-3 text-xs text-gray-800 font-semibold focus:border-indigo-600 focus:ring-1 focus:ring-indigo-600 transition-all outline-none">
                </div>
                <div>
                    <label class="block text-[11px] font-bold text-gray-500 uppercase tracking-wider mb-1.5">Mật mã bảo mật</label>
                    <input type="password" name="password" required placeholder="Nhập mật khẩu..." 
                           class="w-full rounded-xl border border-gray-300 bg-white p-3 text-xs text-gray-800 font-mono tracking-widest focus:border-indigo-600 focus:ring-1 focus:ring-indigo-600 transition-all outline-none">
                </div>
                <div class="pt-2">
                    <button type="submit" name="login_admin" class="w-full bg-indigo-600 text-white py-3 rounded-xl shadow-md shadow-indigo-100 hover:bg-indigo-700 font-bold text-xs tracking-wider uppercase transition-all cursor-pointer transform active:scale-[0.99]">
                        Đăng Nhập Hệ Thống ➔
                    </button>
                </div>
            </form>

            <div class="border-t border-gray-100 pt-4 text-center">
                <a href="user_login.php" class="inline-flex items-center gap-1 mt-2 text-xs text-indigo-600 hover:text-indigo-800 font-bold transition-colors">
                    <span>👤 Đăng nhập dành cho Nhân viên</span> ➔
                </a>
            </div>
        </div>
    </div>

    <script>
        function closeToast() { const t = document.getElementById('toast_alert'); if(t){ t.classList.add('fade-out'); setTimeout(()=>t.remove(), 400); } }
        document.addEventListener('DOMContentLoaded', function() {
            const t = document.getElementById('toast_alert'); const c = document.getElementById('countdown_num');
            if(t && c) { let tl = 5; const iv = setInterval(()=>{ tl--; c.textContent = tl; if(tl<=0) clearInterval(iv); },1000); setTimeout(()=>closeToast(), 5000); }
        });
    </script>
</body>
</html>