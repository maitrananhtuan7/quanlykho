<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Bật thông báo lỗi để dễ debug
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Kết nối Cơ sở dữ liệu của bạn
if (file_exists('db.php')) {
    require 'db.php';
} else {
    die("Lỗi: Không tìm thấy file db.php!");
}

// Kiểm tra quyền Admin (Tùy biến lại theo session của hệ thống bạn)
if (!isset($_SESSION['admin_logged'])) {
    $_SESSION['flash_error'] = "Bạn không có quyền truy cập trang quản trị!";
    header("Location: admin_login.php");
    exit;
}

// ==========================================
// 1. TẢI CẤU HÌNH TỪ DATABASE (GET)
// ==========================================
$current_settings = [];
try {
    $stmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $current_settings[$row['setting_key']] = $row['setting_value'];
    }
} catch (PDOException $e) {
    die("Lỗi: Chưa tạo bảng 'system_settings' trong DB hoặc kết nối thất bại! Chi tiết: " . $e->getMessage());
}

// Mảng dự phòng nếu DB trống rỗng
$default_keys = ['site_title', 'site_email', 'maintenance_mode', 'smtp_host', 'smtp_port', 'smtp_user', 'smtp_pass', 'allow_registration'];
foreach ($default_keys as $key) {
    if (!isset($current_settings[$key])) {
        $current_settings[$key] = ($key === 'maintenance_mode' || $key === 'allow_registration') ? '0' : '';
    }
}

// ==========================================
// 2. XỬ LÝ LƯU CÀI ĐẶT VÀO DATABASE (POST)
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_settings'])) {
    
    // Thu thập dữ liệu từ form
    $post_data = [
        'site_title'         => trim($_POST['site_title']),
        'site_email'        => trim($_POST['site_email']),
        'maintenance_mode'   => isset($_POST['maintenance_mode']) ? '1' : '0',
        'smtp_host'          => trim($_POST['smtp_host']),
        'smtp_port'          => trim($_POST['smtp_port']),
        'smtp_user'          => trim($_POST['smtp_user']),
        'allow_registration' => isset($_POST['allow_registration']) ? '1' : '0'
    ];
    
    // Xử lý riêng mật khẩu SMTP (Nếu bỏ trống thì giữ nguyên mật khẩu cũ trong DB)
    if (!empty($_POST['smtp_pass'])) {
        $post_data['smtp_pass'] = trim($_POST['smtp_pass']);
    } else {
        $post_data['smtp_pass'] = $current_settings['smtp_pass'];
    }

    try {
        $pdo->beginTransaction();
        
        // Cập nhật từng key vào database
        $stmt = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?");
        foreach ($post_data as $key => $value) {
            $stmt->execute([$key, $value, $value]);
        }
        
        $pdo->commit();
        $_SESSION['settings_success'] = "🎉 Đã lưu toàn bộ cấu hình vào Database thành công!";
    } catch (PDOException $e) {
        $pdo->rollBack();
        $_SESSION['settings_error'] = "❌ Lỗi database: " . $e->getMessage();
    }

    // PRG Pattern: Chống trùng lặp dữ liệu khi F5
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

$success_msg = isset($_SESSION['settings_success']) ? $_SESSION['settings_success'] : '';
$error_msg = isset($_SESSION['settings_error']) ? $_SESSION['settings_error'] : '';

unset($_SESSION['settings_success']);
unset($_SESSION['settings_error']);

if (file_exists('header.php')) { require 'header.php'; }
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cài Đặt Hệ Thống</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        @keyframes shrink { from { width: 100%; } to { width: 0%; } }
        .progress-timer { animation: shrink 4s linear forwards; }
    </style>
</head>
<body class="bg-slate-50 min-h-screen font-sans antialiased text-slate-600">

    <main class="container mx-auto px-6 py-10 max-w-5xl">
        
        <!-- TIÊU ĐỀ TRANG -->
        <div class="mb-8">
            <h1 class="text-xl font-bold text-slate-800 tracking-tight">⚙️ Cấu Hình Hệ Thống (Database Mode)</h1>
            <p class="text-xs text-slate-400 mt-1">Thay đổi thiết lập tổng quan, cổng gửi email và bảo mật vận hành hệ thống.</p>
        </div>

        <!-- THÔNG BÁO STATUS -->
        <?php if (!empty($success_msg)): ?>
            <div id="toast-success" class="bg-emerald-50 border-l-4 border-emerald-500 rounded-xl p-4 mb-6 shadow-sm relative overflow-hidden">
                <p class="text-xs font-bold text-emerald-800"><?= htmlspecialchars($success_msg) ?></p>
                <div class="absolute bottom-0 left-0 h-0.5 bg-emerald-500 progress-timer"></div>
            </div>
        <?php endif; ?>
        
        <?php if (!empty($error_msg)): ?>
            <div id="toast-error" class="bg-rose-50 border-l-4 border-rose-500 rounded-xl p-4 mb-6 shadow-sm relative overflow-hidden">
                <p class="text-xs font-bold text-rose-800"><?= htmlspecialchars($error_msg) ?></p>
                <div class="absolute bottom-0 left-0 h-0.5 bg-rose-500 progress-timer"></div>
            </div>
        <?php endif; ?>

        <!-- FORM CÀI ĐẶT -->
        <form method="POST" class="space-y-8">
            
            <!-- KHỐI 1: CÀI ĐẶT CHUNG -->
            <div class="bg-white border border-gray-200/60 rounded-2xl p-8 shadow-xl shadow-gray-100/40">
                <h3 class="text-sm font-bold text-slate-800 border-b border-slate-50 pb-4 mb-6 flex items-center">
                    <span class="mr-2 text-indigo-500 text-base">🌐</span> Cài đặt tổng quan Website
                </h3>
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label class="block text-[11px] font-bold text-slate-400 uppercase tracking-wider mb-2">Tên Website / Thương hiệu</label>
                        <input type="text" name="site_title" required value="<?= htmlspecialchars($current_settings['site_title']) ?>" 
                               class="w-full rounded-xl border border-gray-200 bg-white p-3.5 text-xs font-semibold text-slate-800 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 transition-all outline-none">
                    </div>
                    
                    <div>
                        <label class="block text-[11px] font-bold text-slate-400 uppercase tracking-wider mb-2">Email hệ thống (Hộp thư Admin)</label>
                        <input type="email" name="site_email" required value="<?= htmlspecialchars($current_settings['site_email']) ?>" 
                               class="w-full rounded-xl border border-gray-200 bg-white p-3.5 text-xs font-semibold text-slate-800 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 transition-all outline-none">
                    </div>
                </div>
            </div>

            <!-- KHỐI 2: CẤU HÌNH GỬI MAIL (SMTP) -->
            <div class="bg-white border border-gray-200/60 rounded-2xl p-8 shadow-xl shadow-gray-100/40">
                <h3 class="text-sm font-bold text-slate-800 border-b border-slate-50 pb-4 mb-6 flex items-center">
                    <span class="mr-2 text-indigo-500 text-base">📧</span> Cấu hình máy chủ gửi Email (SMTP)
                </h3>
                
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-5 mb-5">
                    <div class="sm:col-span-2">
                        <label class="block text-[11px] font-bold text-slate-400 uppercase tracking-wider mb-2">SMTP Host</label>
                        <input type="text" name="smtp_host" value="<?= htmlspecialchars($current_settings['smtp_host']) ?>" placeholder="e.g. smtp.gmail.com" 
                               class="w-full rounded-xl border border-gray-200 bg-white p-3.5 text-xs font-semibold text-slate-800 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 transition-all outline-none">
                    </div>
                    <div>
                        <label class="block text-[11px] font-bold text-slate-400 uppercase tracking-wider mb-2">SMTP Port</label>
                        <input type="text" name="smtp_port" value="<?= htmlspecialchars($current_settings['smtp_port']) ?>" placeholder="e.g. 587" 
                               class="w-full rounded-xl border border-gray-200 bg-white p-3.5 text-xs font-semibold text-slate-800 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 transition-all outline-none">
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label class="block text-[11px] font-bold text-slate-400 uppercase tracking-wider mb-2">Tài khoản SMTP (Username)</label>
                        <input type="text" name="smtp_user" value="<?= htmlspecialchars($current_settings['smtp_user']) ?>" placeholder="Nhập email gửi..." 
                               class="w-full rounded-xl border border-gray-200 bg-white p-3.5 text-xs font-semibold text-slate-800 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 transition-all outline-none">
                    </div>
                    <div>
                        <label class="block text-[11px] font-bold text-slate-400 uppercase tracking-wider mb-2">Mật khẩu SMTP (Ứng dụng)</label>
                        <input type="password" name="smtp_pass" placeholder="••••••••••••" 
                               class="w-full rounded-xl border border-gray-200 bg-white p-3.5 text-xs font-mono tracking-widest text-slate-800 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 transition-all outline-none">
                        <p class="text-[10px] text-slate-400 mt-1.5">Để trống ô mật khẩu nếu bạn muốn giữ lại cấu hình cũ.</p>
                    </div>
                </div>
            </div>

            <!-- KHỐI 3: BẢO MẬT & TRẠNG THÁI -->
            <div class="bg-white border border-gray-200/60 rounded-2xl p-8 shadow-xl shadow-gray-100/40">
                <h3 class="text-sm font-bold text-slate-800 border-b border-slate-50 pb-4 mb-6 flex items-center">
                    <span class="mr-2 text-indigo-500 text-base">🛡️</span> Vận hành và Bảo mật hệ thống
                </h3>

                <div class="divide-y divide-slate-100">
                    <div class="flex items-center justify-between py-4">
                        <div class="pr-4">
                            <span class="text-xs font-bold text-slate-700 block">Chế độ bảo trì (Maintenance Mode)</span>
                            <span class="text-[11px] text-slate-400">Khi bật, khách truy cập thông thường sẽ không vào được web.</span>
                        </div>
                        <label class="relative inline-flex items-center cursor-pointer flex-shrink-0">
                            <input type="checkbox" name="maintenance_mode" value="1" class="sr-only peer" <?= $current_settings['maintenance_mode'] === '1' ? 'checked' : '' ?>>
                            <div class="w-11 h-6 bg-slate-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-indigo-600"></div>
                        </label>
                    </div>

                    <div class="flex items-center justify-between py-4">
                        <div class="pr-4">
                            <span class="text-xs font-bold text-slate-700 block">Mở đăng ký tài khoản mới</span>
                            <span class="text-[11px] text-slate-400">Cho phép người dùng hoặc nhân viên mới tự đăng ký tài khoản.</span>
                        </div>
                        <label class="relative inline-flex items-center cursor-pointer flex-shrink-0">
                            <input type="checkbox" name="allow_registration" value="1" class="sr-only peer" <?= $current_settings['allow_registration'] === '1' ? 'checked' : '' ?>>
                            <div class="w-11 h-6 bg-slate-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-indigo-600"></div>
                        </label>
                    </div>
                </div>
            </div>

            <!-- BUTTON SAVE -->
            <div class="flex justify-end pt-2">
                <button type="submit" name="save_settings" 
                        class="bg-indigo-600 text-white px-8 py-3.5 rounded-xl shadow-lg shadow-indigo-100 hover:bg-indigo-700 font-bold text-xs tracking-wider uppercase transition-all transform active:scale-95 cursor-pointer">
                    💾 Lưu cấu hình hệ thống
                </button>
            </div>
            
        </form>
    </main>

    <script>
        document.addEventListener("DOMContentLoaded", function() {
            const sBox = document.getElementById('toast-success');
            if(sBox) { setTimeout(() => { sBox.style.opacity = '0'; setTimeout(() => sBox.remove(), 300); }, 4000); }
            const eBox = document.getElementById('toast-error');
            if(eBox) { setTimeout(() => { eBox.style.opacity = '0'; setTimeout(() => eBox.remove(), 300); }, 4000); }
        });
    </script>
</body>
</html>