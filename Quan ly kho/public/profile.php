<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Bật thông báo lỗi để dễ debug
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Kết nối Cơ sở dữ liệu
if (file_exists('db.php')) {
    require 'db.php';
} else {
    die("Lỗi: Không tìm thấy file db.php trong thư mục này!");
}

// Kiểm tra quyền đăng nhập (Nếu chưa đăng nhập thì đẩy về trang login)
if (!isset($_SESSION['admin_logged']) && !isset($_SESSION['user_logged'])) {
    $_SESSION['flash_error'] = "Vui lòng đăng nhập để truy cập trang này!";
    header("Location: admin_login.php");
    exit;
}

// =========================================================================
// CƠ CHẾ QUÉT ID THÔNG MINH: Tự động nhận diện mọi kiểu lưu Session ID của bạn
// =========================================================================
$user_id = null;
if (isset($_SESSION['auth_id'])) {
    $user_id = $_SESSION['auth_id'];
} elseif (isset($_SESSION['user_id'])) {
    $user_id = $_SESSION['user_id'];
} elseif (isset($_SESSION['id'])) {
    $user_id = $_SESSION['id'];
} elseif (isset($_SESSION['account_id'])) {
    $user_id = $_SESSION['account_id'];
}

// Lấy vai trò (role) hiện tại từ session
$auth_role = isset($_SESSION['auth_role']) ? strtolower($_SESSION['auth_role']) : 'user';

// ==========================================
// 1. XỬ LÝ KHI CÓ YÊU CẦU CẬP NHẬT THÔNG TIN (POST)
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    $full_name = trim($_POST['full_name']);
    $phone = trim($_POST['phone']);
    $new_password = trim($_POST['new_password']);

    if (!empty($full_name)) {
        try {
            if (!empty($new_password)) {
                // Nếu người dùng muốn đổi cả mật khẩu mới (Mã hóa bcrypt luôn)
                $hashed_password = password_hash($new_password, PASSWORD_BCRYPT);
                $stmt = $pdo->prepare("UPDATE accounts SET full_name = ?, phone = ?, password = ? WHERE id = ?");
                $stmt->execute([$full_name, $phone, $hashed_password, $user_id]);
            } else {
                // Nếu chỉ cập nhật Họ tên và Số điện thoại (Giữ nguyên mật khẩu cũ)
                $stmt = $pdo->prepare("UPDATE accounts SET full_name = ?, phone = ? WHERE id = ?");
                $stmt->execute([$full_name, $phone, $user_id]);
            }
            
            // Cập nhật lại Session hiển thị trên thanh Menu
            $_SESSION['auth_name'] = $full_name;
            
            // Ghi thông báo thành công vào SESSION
            $_SESSION['profile_success'] = "🎉 Cập nhật thông tin tài khoản thành công!";
            
        } catch (PDOException $e) {
            $_SESSION['profile_error'] = "Không thể cập nhật: " . $e->getMessage();
        }
    } else {
        $_SESSION['profile_error'] = "Họ và tên hiển thị không được bỏ trống!";
    }

    // NGĂN CHẶN F5 TRỰC DIỆN: Xử lý POST xong lập tức chuyển hướng tải lại trang sạch (GET)
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

// ==========================================
// 2. TẢI THÔNG TIN USER HIỆN TẠI TỪ DATABASE (GET)
// ==========================================
$user_info = null;

try {
    if ($user_id) {
        // Cách 1: Tìm theo ID tìm được từ Session
        $stmt = $pdo->prepare("SELECT * FROM accounts WHERE id = ? LIMIT 1");
        $stmt->execute([$user_id]);
        $user_info = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    // Cách 2 dự phòng: Nếu không tìm thấy bằng ID, tìm bằng Username/Tên đăng nhập được lưu trong session
    if (!$user_info) {
        $username_backup = $_SESSION['user_logged'] ?? $_SESSION['username'] ?? $_SESSION['admin_logged'] ?? null;
        if ($username_backup && is_string($username_backup)) {
            $stmt = $pdo->prepare("SELECT * FROM accounts WHERE username = ? LIMIT 1");
            $stmt->execute([$username_backup]);
            $user_info = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Nếu tìm thấy theo cách dự phòng, cập nhật lại biến $user_id để phục vụ lệnh UPDATE (POST) phía trên
            if ($user_info) {
                $user_id = $user_info['id'];
            }
        }
    }

    // Nếu đã thử cả 2 cách vẫn không tìm ra dữ liệu
    if (!$user_info) {
        die("Không tìm thấy dữ liệu tài khoản trong cơ sở dữ liệu! Vui lòng đăng xuất và đăng nhập lại.");
    }

} catch (PDOException $e) {
    die("Lỗi kết nối dữ liệu: " . $e->getMessage());
}

// Lấy thông báo tạm thời từ Session ra biến để hiển thị
$success_msg = isset($_SESSION['profile_success']) ? $_SESSION['profile_success'] : '';
$error_msg = isset($_SESSION['profile_error']) ? $_SESSION['profile_error'] : '';

// Xóa ngay lập tức để tránh hiển thị lại khi F5 trang
unset($_SESSION['profile_success']);
unset($_SESSION['profile_error']);

require 'header.php';

// Xác định tên hiển thị theo vai trò đồng bộ với header
if ($auth_role === 'admin') {
    $display_name = 'Quản trị viên';
} else {
    $display_name = !empty($user_info['full_name']) ? $user_info['full_name'] : 'Nhân viên';
}

// Tạo link ảnh đại diện tự động dựa theo Tên hiển thị tương ứng
$profile_avatar = "https://ui-avatars.com/api/?name=" . urlencode($display_name) . "&background=4f46e5&color=fff&rounded=true&bold=true&size=128";
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Thông Tin Tài Khoản</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        @keyframes shrink { from { width: 100%; } to { width: 0%; } }
        .progress-timer { animation: shrink 4s linear forwards; }
    </style>
</head>
<body class="bg-gray-100 min-h-screen font-sans">
    <main class="container mx-auto px-6 py-10 max-w-4xl">
        
        <?php if (!empty($success_msg)): ?>
            <div id="toast-success" class="bg-emerald-50 border-l-4 border-emerald-500 rounded-xl p-4 mb-6 shadow-sm relative overflow-hidden">
                <p class="text-xs font-bold text-emerald-800"><?= htmlspecialchars($success_msg) ?></p>
                <div class="absolute bottom-0 left-0 h-0.5 bg-emerald-500 progress-timer"></div>
            </div>
        <?php endif; ?>
        
        <?php if (!empty($error_msg)): ?>
            <div id="toast-error" class="bg-red-50 border-l-4 border-red-500 rounded-xl p-4 mb-6 shadow-sm relative overflow-hidden">
                <p class="text-xs font-bold text-red-800"><?= htmlspecialchars($error_msg) ?></p>
                <div class="absolute bottom-0 left-0 h-0.5 bg-red-500 progress-timer"></div>
            </div>
        <?php endif; ?>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
            
            <div class="bg-white border border-gray-200 rounded-2xl p-6 text-center shadow-sm h-fit">
                <div class="relative inline-block mb-4">
                    <img src="<?= $profile_avatar ?>" alt="User Avatar" class="w-24 h-24 rounded-full mx-auto border-4 border-indigo-50 shadow-md">
                    <span class="absolute bottom-0 right-1 bg-green-500 border-2 border-white w-4 h-4 rounded-full shadow-sm"></span>
                </div>
                <h2 class="text-base font-bold text-gray-800 tracking-tight"><?= htmlspecialchars($display_name) ?></h2>
                <span class="text-[10px] uppercase font-bold tracking-wider text-indigo-600 bg-indigo-50 px-2.5 py-1 rounded-full mt-1.5 inline-block">
                    📌 Quyền: <?= htmlspecialchars(strtoupper($user_info['role'] ?? 'user')) ?>
                </span>
                
                <hr class="border-gray-100 my-4">
                
                <div class="text-left space-y-2 text-xs text-gray-500 font-medium">
                    <p>📅 Ngày tạo: <span class="text-gray-800 font-bold"><?= isset($user_info['created_at']) ? date('d/m/Y', strtotime($user_info['created_at'])) : '--/--/----' ?></span></p>
                    <p>🟢 Trạng thái: <span class="text-emerald-600 font-bold"><?= (isset($user_info['status']) && (int)$user_info['status'] === 1) ? 'Đang hoạt động' : 'Bị khóa' ?></span></p>
                </div>
            </div>

            <div class="bg-white border border-gray-200 rounded-2xl p-8 shadow-sm md:col-span-2">
                <h3 class="text-base font-bold text-gray-800 border-b border-gray-100 pb-4 mb-6 flex items-center">
                    <span class="mr-2 text-indigo-600">📝</span> Thiết lập tài khoản cá nhân
                </h3>

                <form method="POST" class="space-y-5">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-[11px] font-bold text-gray-400 uppercase tracking-wider mb-1.5">Tên đăng nhập (Mã số cố định)</label>
                            <input type="text" value="<?= htmlspecialchars($user_info['username'] ?? '') ?>" disabled 
                                   class="w-full rounded-xl border border-gray-200 bg-gray-50 p-3 text-xs text-gray-400 font-bold cursor-not-allowed outline-none">
                        </div>
                        
                        <div>
                            <label class="block text-[11px] font-bold text-gray-500 uppercase tracking-wider mb-1.5">Số điện thoại</label>
                            <input type="text" name="phone" value="<?= htmlspecialchars($user_info['phone'] ?? '') ?>" placeholder="Nhập số điện thoại..." 
                                   class="w-full rounded-xl border border-gray-200 bg-white p-3 text-xs text-gray-800 font-semibold focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 transition-all outline-none">
                        </div>
                    </div>

                    <div>
                        <label class="block text-[11px] font-bold text-gray-500 uppercase tracking-wider mb-1.5">Họ và Tên hiển thị</label>
                        <input type="text" name="full_name" required value="<?= htmlspecialchars($user_info['full_name'] ?? '') ?>" placeholder="Nhập họ và tên..." 
                               class="w-full rounded-xl border border-gray-200 bg-white p-3 text-xs text-gray-800 font-semibold focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 transition-all outline-none">
                    </div>

                    <hr class="border-gray-100 my-2">

                    <div>
                        <label class="block text-[11px] font-bold text-gray-500 uppercase tracking-wider mb-1.5">Mật khẩu mới (Bỏ trống nếu không muốn đổi)</label>
                        <input type="password" name="new_password" placeholder="••••••••" 
                               class="w-full rounded-xl border border-gray-200 bg-white p-3 text-xs text-gray-800 font-mono tracking-widest focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 transition-all outline-none">
                        <p class="text-[10px] text-gray-400 mt-1">Để bảo mật, mật khẩu mới của bạn sẽ tự động mã hóa dạng chuẩn bcrypt khi lưu.</p>
                    </div>

                    <div class="pt-2 flex justify-end">
                        <button type="submit" name="update_profile" class="bg-indigo-600 text-white px-6 py-2.5 rounded-xl shadow-md shadow-indigo-100 hover:bg-indigo-700 font-bold text-xs tracking-wider uppercase transition-all transform active:scale-[0.99] cursor-pointer">
                            Lưu Thay Đổi ➔
                        </button>
                    </div>
                </form>
            </div>

        </div>
    </main>

    <script>
        // Tự động ẩn hiệu ứng DOM thông báo sau 4 giây để trải nghiệm mượt mà
        document.addEventListener("DOMContentLoaded", function() {
            const sBox = document.getElementById('toast-success');
            if(sBox) { setTimeout(() => { sBox.style.display = 'none'; }, 4000); }
            const eBox = document.getElementById('toast-error');
            if(eBox) { setTimeout(() => { eBox.style.display = 'none'; }, 4000); }
        });
    </script>
</body>
</html>