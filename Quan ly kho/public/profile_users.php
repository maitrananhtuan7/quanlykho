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

// Kiểm tra quyền đăng nhập (Chỉ cho phép tài khoản có role là 'admin' truy cập)
if (!isset($_SESSION['auth_role']) || strtolower($_SESSION['auth_role']) !== 'admin') {
    die("<div style='padding: 20px; text-align: center; font-family: sans-serif; color: red;'>
            <strong>Truy cập bị từ chối:</strong> Bạn không có quyền quản trị để xem trang này! 
            <br><a href='inventory.php'>Quay lại trang chủ</a>
         </div>");
}

// ==========================================
// XỬ LÝ CÁC HÀNH ĐỘNG (POST)
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // 1. CHỨC NĂNG: THÊM THÀNH VIÊN
    if (isset($_POST['action_add'])) {
        $username = trim($_POST['username']);
        $full_name = trim($_POST['full_name']);
        $phone = trim($_POST['phone']);
        $password = trim($_POST['password']); 
        $role = $_POST['role'];
        $status = (int)$_POST['status'];

        if (empty($username) || empty($password) || empty($full_name)) {
            $_SESSION['error_msg'] = "Vui lòng nhập đầy đủ Tên đăng nhập, Mật khẩu và Họ tên!";
        } else {
            try {
                $check_stmt = $pdo->prepare("SELECT id FROM accounts WHERE username = ?");
                $check_stmt->execute([$username]);
                if ($check_stmt->fetch()) {
                    $_SESSION['error_msg'] = "Tên đăng nhập này đã tồn tại trên hệ thống!";
                } else {
                    $stmt = $pdo->prepare("INSERT INTO accounts (username, password, full_name, phone, role, status) VALUES (?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$username, $password, $full_name, $phone, $role, $status]);
                    $_SESSION['success_msg'] = "🎉 Thêm thành viên mới thành công!";
                }
            } catch (PDOException $e) {
                $_SESSION['error_msg'] = "Lỗi thêm thành viên: " . $e->getMessage();
            }
        }
        // CHỐNG F5: Xử lý xong lập tức chuyển hướng tải lại trang mới hoàn toàn
        header("Location: profile_users.php");
        exit();
    }

    // 2. CHỨC NĂNG: SỬA THÀNH VIÊN
    if (isset($_POST['action_edit'])) {
        $user_id = (int)$_POST['user_id'];
        $full_name = trim($_POST['full_name']);
        $phone = trim($_POST['phone']);
        $password = trim($_POST['password']);
        $role = $_POST['role'];
        $status = (int)$_POST['status'];

        if (empty($full_name)) {
            $_SESSION['error_msg'] = "Họ và tên không được để trống!";
        } else {
            try {
                if (!empty($password)) {
                    $stmt = $pdo->prepare("UPDATE accounts SET full_name = ?, phone = ?, password = ?, role = ?, status = ? WHERE id = ?");
                    $stmt->execute([$full_name, $phone, $password, $role, $status, $user_id]);
                } else {
                    $stmt = $pdo->prepare("UPDATE accounts SET full_name = ?, phone = ?, role = ?, status = ? WHERE id = ?");
                    $stmt->execute([$full_name, $phone, $role, $status, $user_id]);
                }
                
                if ($user_id == $_SESSION['auth_id']) {
                    $_SESSION['auth_name'] = $full_name;
                    $_SESSION['auth_role'] = $role;
                }

                $_SESSION['success_msg'] = "🎉 Cập nhật thông tin thành viên thành công!";
            } catch (PDOException $e) {
                $_SESSION['error_msg'] = "Lỗi cập nhật: " . $e->getMessage();
            }
        }
        // CHỐNG F5: Xử lý xong lập tức chuyển hướng tải lại trang mới hoàn toàn
        header("Location: profile_users.php");
        exit();
    }

    // 3. CHỨC NĂNG: XÓA THÀNH VIÊN
    if (isset($_POST['action_delete'])) {
        $user_id = (int)$_POST['user_id'];

        if ($user_id == $_SESSION['auth_id']) {
            $_SESSION['error_msg'] = "Bạn không thể tự xóa tài khoản của chính mình!";
        } else {
            try {
                $stmt = $pdo->prepare("DELETE FROM accounts WHERE id = ?");
                $stmt->execute([$user_id]);
                $_SESSION['success_msg'] = "💥 Đã xóa thành viên khỏi hệ thống thành công!";
            } catch (PDOException $e) {
                $_SESSION['error_msg'] = "Lỗi khi xóa: " . $e->getMessage();
            }
        }
        // CHỐNG F5: Xử lý xong lập tức chuyển hướng tải lại trang mới hoàn toàn
        header("Location: profile_users.php");
        exit();
    }
}

// Lấy thông báo từ Session ra biến tạm để hiển thị (nếu có)
$success_msg = isset($_SESSION['success_msg']) ? $_SESSION['success_msg'] : '';
$error_msg = isset($_SESSION['error_msg']) ? $_SESSION['error_msg'] : '';

// Xóa ngay thông báo trong Session để lần sau F5 không bị lặp lại nữa
unset($_SESSION['success_msg']);
unset($_SESSION['error_msg']);

// Lấy danh sách tất cả tài khoản
try {
    $stmt = $pdo->prepare("SELECT id, username, password, full_name, phone, role, status, created_at FROM accounts ORDER BY role ASC, username ASC");
    $stmt->execute();
    $all_users = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Lỗi truy vấn dữ liệu: " . $e->getMessage());
}

require 'header.php';
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quản Lý Thành Viên</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        @keyframes shrinkWidth { from { width: 100%; } to { width: 0%; } }
        .progress-timer { animation: shrinkWidth 5s linear forwards; }
        .alert-box { transition: opacity 0.5s ease, transform 0.5s ease; }
        .alert-hidden { opacity: 0; transform: translateY(-10px); pointer-events: none; }
    </style>
</head>
<body class="bg-gray-100 min-h-screen font-sans">

    <main class="container mx-auto px-6 py-3">
        
        <!-- THÔNG BÁO THÀNH CÔNG -->
        <?php if (!empty($success_msg)): ?>
            <div id="toast-success" class="alert-box bg-emerald-50 border-l-4 border-emerald-500 rounded-xl p-4 mb-6 shadow-sm relative overflow-hidden flex justify-between items-center">
                <p class="text-xs font-bold text-emerald-800"><?= htmlspecialchars($success_msg) ?></p>
                <button onclick="document.getElementById('toast-success').classList.add('alert-hidden')" class="text-emerald-400 hover:text-emerald-600 font-bold ml-2 text-sm">&times;</button>
                <div class="absolute bottom-0 left-0 h-0.5 bg-emerald-500 progress-timer"></div>
            </div>
        <?php endif; ?>
        
        <!-- THÔNG BÁO LỖI -->
        <?php if (!empty($error_msg)): ?>
            <div id="toast-error" class="alert-box bg-red-50 border-l-4 border-red-500 rounded-xl p-4 mb-6 shadow-sm relative overflow-hidden flex justify-between items-center">
                <p class="text-xs font-bold text-red-800"><?= htmlspecialchars($error_msg) ?></p>
                <button onclick="document.getElementById('toast-error').classList.add('alert-hidden')" class="text-red-400 hover:text-red-600 font-bold ml-2 text-sm">&times;</button>
                <div class="absolute bottom-0 left-0 h-0.5 bg-red-500 progress-timer"></div>
            </div>
        <?php endif; ?>
        
        <!-- TIÊU ĐỀ TRANG VÀ THỐNG KÊ NHANH -->
        <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center mb-3 gap-4">
            <div>
                <h1 class="text-xl font-bold text-gray-800 tracking-tight flex items-center">
                    <span class="mr-2 text-indigo-600">👥</span> Quản lý danh sách thành viên
                </h1>
                <p class="text-xs text-gray-500 mt-1">Hiển thị trực tiếp thông tin mật khẩu bằng chữ và số của các tài khoản.</p>
            </div>
            
            <div class="flex items-center space-x-3 w-full sm:w-auto justify-end">
                <div class="bg-white border border-gray-200 px-4 py-2.5 rounded-xl shadow-sm text-xs font-bold text-gray-700">
                    Tổng số tài khoản: <span class="text-indigo-600 text-sm pl-1"><?= count($all_users) ?></span>
                </div>
                <button onclick="openAddModal()" class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold text-xs px-4 py-2.5 rounded-xl shadow-md transition flex items-center gap-1 cursor-pointer">
                    ➕ Thêm thành viên
                </button>
            </div>
        </div>

        <!-- BẢNG DANH SÁCH USER -->
        <div class="bg-white border border-gray-200 rounded-2xl shadow-sm overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse">
                    <thead>
                        <tr class="bg-gray-50/75 border-b border-gray-200 text-[11px] font-bold text-gray-400 uppercase tracking-wider">
                            <th class="px-6 py-4">Thành viên</th>
                            <th class="px-6 py-4">Tên đăng nhập</th>
                            <th class="px-6 py-4 text-indigo-600">Mật khẩu công khai</th>
                            <th class="px-6 py-4">Số điện thoại</th>
                            <th class="px-6 py-4">Vai trò</th>
                            <th class="px-6 py-4">Trạng thái</th>
                            <th class="px-6 py-4">Ngày tham gia</th>
                            <th class="px-6 py-4 text-center">Hành động</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 text-xs text-gray-700 font-medium">
                        <?php if (count($all_users) > 0): ?>
                            <?php foreach ($all_users as $user): 
                                $user_avatar = "https://ui-avatars.com/api/?name=" . urlencode($user['full_name']) . "&background=e0e7ff&color=4f46e5&rounded=true&bold=true&size=64";
                            ?>
                                <tr class="hover:bg-slate-50/80 transition-colors">
                                    <td class="px-6 py-4">
                                        <div class="flex items-center space-x-3">
                                            <img src="<?= $user_avatar ?>" alt="Avatar" class="w-8 h-8 rounded-full shadow-sm">
                                            <span class="font-bold text-gray-800"><?= htmlspecialchars($user['full_name']) ?></span>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 font-mono text-gray-600 tracking-wide"><?= htmlspecialchars($user['username']) ?></td>
                                    <td class="px-6 py-4 font-bold text-indigo-600 font-mono tracking-wider select-all"><?= htmlspecialchars($user['password']) ?></td>
                                    <td class="px-6 py-4 text-gray-500"><?= !empty($user['phone']) ? htmlspecialchars($user['phone']) : '<span class="text-gray-300 italic">Chưa cập nhật</span>' ?></td>
                                    <td class="px-6 py-4">
                                        <?php if (strtolower($user['role']) === 'admin'): ?>
                                            <span class="text-[10px] uppercase font-bold text-red-600 bg-red-50 px-2.5 py-1 rounded-md">⚙️ Admin</span>
                                        <?php else: ?>
                                            <span class="text-[10px] uppercase font-bold text-blue-600 bg-blue-50 px-2.5 py-1 rounded-md">👤 Nhân viên</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-6 py-4">
                                        <?php if ((int)$user['status'] === 1): ?>
                                            <div class="flex items-center text-emerald-600 font-bold space-x-1.5">
                                                <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 inline-block"></span>
                                                <span>Hoạt động</span>
                                            </div>
                                        <?php else: ?>
                                            <div class="flex items-center text-red-400 font-bold space-x-1.5">
                                                <span class="w-1.5 h-1.5 rounded-full bg-red-400 inline-block"></span>
                                                <span>Bị khóa</span>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-6 py-4 text-gray-400 font-semibold"><?= date('d/m/Y H:i', strtotime($user['created_at'])) ?></td>
                                    <td class="px-6 py-4 text-center whitespace-nowrap">
                                        <div class="flex items-center justify-center space-x-2">
                                            <button onclick="openEditModal(<?= htmlspecialchars(json_encode($user)) ?>)" class="text-indigo-600 hover:bg-indigo-50 p-1.5 rounded-lg transition">✏️</button>
                                            <button onclick="openDeleteModal(<?= $user['id'] ?>, '<?= htmlspecialchars($user['full_name']) ?>')" class="text-red-600 hover:bg-red-50 p-1.5 rounded-lg transition">🗑️</button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="8" class="px-6 py-10 text-center text-gray-400 italic">Không tìm thấy tài khoản nào!</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>

    <!-- MODAL 1: THÊM THÀNH VIÊN -->
    <div id="addModal" class="hidden fixed inset-0 bg-black/40 backdrop-blur-sm flex items-center justify-center z-50 p-4">
        <div class="bg-white border border-gray-200 rounded-2xl w-full max-w-md shadow-2xl overflow-hidden">
            <div class="px-6 py-4 bg-gray-50 border-b border-gray-100 flex justify-between items-center">
                <h3 class="text-sm font-bold text-gray-800">➕ Thêm thành viên mới</h3>
                <button onclick="closeAddModal()" class="text-gray-400 hover:text-gray-600 text-lg">&times;</button>
            </div>
            <form method="POST" class="p-6 space-y-4">
                <input type="hidden" name="action_add" value="1">
                <div>
                    <label class="block text-[10px] font-bold text-gray-500 uppercase tracking-wider mb-1">Tên đăng nhập *</label>
                    <input type="text" name="username" required class="w-full rounded-xl border border-gray-200 p-2.5 text-xs outline-none">
                </div>
                <div>
                    <label class="block text-[10px] font-bold text-gray-500 uppercase tracking-wider mb-1">Mật khẩu hiển thị công khai *</label>
                    <input type="text" name="password" required class="w-full rounded-xl border border-gray-200 p-2.5 text-xs font-mono outline-none text-indigo-600 font-bold">
                </div>
                <div>
                    <label class="block text-[10px] font-bold text-gray-500 uppercase tracking-wider mb-1">Họ và tên hiển thị *</label>
                    <input type="text" name="full_name" required class="w-full rounded-xl border border-gray-200 p-2.5 text-xs outline-none">
                </div>
                <div>
                    <label class="block text-[10px] font-bold text-gray-500 uppercase tracking-wider mb-1">Số điện thoại</label>
                    <input type="text" name="phone" class="w-full rounded-xl border border-gray-200 p-2.5 text-xs outline-none">
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-[10px] font-bold text-gray-500 uppercase tracking-wider mb-1">Vai trò</label>
                        <select name="role" class="w-full rounded-xl border border-gray-200 p-2.5 text-xs outline-none bg-white">
                            <option value="user">Nhân viên</option>
                            <option value="admin">Quản trị viên (Admin)</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-[10px] font-bold text-gray-500 uppercase tracking-wider mb-1">Trạng thái</label>
                        <select name="status" class="w-full rounded-xl border border-gray-200 p-2.5 text-xs outline-none bg-white">
                            <option value="1">Hoạt động</option>
                            <option value="0">Khóa tài khoản</option>
                        </select>
                    </div>
                </div>
                <div class="pt-2 flex justify-end space-x-2 border-t border-gray-100 mt-4">
                    <button type="button" onclick="closeAddModal()" class="px-4 py-2 bg-gray-100 rounded-xl text-xs font-bold text-gray-600">Hủy</button>
                    <button type="submit" class="px-4 py-2 bg-indigo-600 rounded-xl text-xs font-bold text-white">Lưu lại</button>
                </div>
            </form>
        </div>
    </div>

    <!-- MODAL 2: SỬA THÔNG TIN -->
    <div id="editModal" class="hidden fixed inset-0 bg-black/40 backdrop-blur-sm flex items-center justify-center z-50 p-4">
        <div class="bg-white border border-gray-200 rounded-2xl w-full max-w-md shadow-2xl overflow-hidden">
            <div class="px-6 py-4 bg-gray-50 border-b border-gray-100 flex justify-between items-center">
                <h3 class="text-sm font-bold text-gray-800">✏️ Chỉnh sửa thành viên</h3>
                <button onclick="closeEditModal()" class="text-gray-400 hover:text-gray-600 text-lg">&times;</button>
            </div>
            <form method="POST" class="p-6 space-y-4">
                <input type="hidden" name="action_edit" value="1">
                <input type="hidden" id="edit_user_id" name="user_id">
                <div>
                    <label class="block text-[10px] font-bold text-gray-400 uppercase tracking-wider mb-1">Tên đăng nhập (Không thể sửa)</label>
                    <input type="text" id="edit_username" disabled class="w-full rounded-xl border border-gray-200 bg-gray-50 p-2.5 text-xs text-gray-400 outline-none cursor-not-allowed">
                </div>
                <div>
                    <label class="block text-[10px] font-bold text-gray-500 uppercase tracking-wider mb-1">Đổi mật khẩu mới (Bỏ trống nếu giữ nguyên)</label>
                    <input type="text" id="edit_password" name="password" placeholder="Nhập số/chữ mật khẩu mới nếu muốn đổi..." class="w-full rounded-xl border border-gray-200 p-2.5 text-xs font-mono text-indigo-600 font-bold outline-none">
                </div>
                <div>
                    <label class="block text-[10px] font-bold text-gray-500 uppercase tracking-wider mb-1">Họ và tên hiển thị *</label>
                    <input type="text" id="edit_full_name" name="full_name" required class="w-full rounded-xl border border-gray-200 p-2.5 text-xs outline-none">
                </div>
                <div>
                    <label class="block text-[10px] font-bold text-gray-500 uppercase tracking-wider mb-1">Số điện thoại</label>
                    <input type="text" id="edit_phone" name="phone" class="w-full rounded-xl border border-gray-200 p-2.5 text-xs outline-none">
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-[10px] font-bold text-gray-500 uppercase tracking-wider mb-1">Vai trò</label>
                        <select id="edit_role" name="role" class="w-full rounded-xl border border-gray-200 p-2.5 text-xs outline-none bg-white">
                            <option value="user">Nhân viên</option>
                            <option value="admin">Quản trị viên (Admin)</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-[10px] font-bold text-gray-500 uppercase tracking-wider mb-1">Trạng thái</label>
                        <select id="edit_status" name="status" class="w-full rounded-xl border border-gray-200 p-2.5 text-xs outline-none bg-white">
                            <option value="1">Hoạt động</option>
                            <option value="0">Khóa tài khoản</option>
                        </select>
                    </div>
                </div>
                <div class="pt-2 flex justify-end space-x-2 border-t border-gray-100 mt-4">
                    <button type="button" onclick="closeEditModal()" class="px-4 py-2 bg-gray-100 rounded-xl text-xs font-bold text-gray-600">Hủy</button>
                    <button type="submit" class="px-4 py-2 bg-indigo-600 rounded-xl text-xs font-bold text-white">Lưu thay đổi</button>
                </div>
            </form>
        </div>
    </div>

    <!-- MODAL 3: XÓA TÀI KHOẢN -->
    <div id="deleteModal" class="hidden fixed inset-0 bg-black/40 backdrop-blur-sm flex items-center justify-center z-50 p-4">
        <div class="bg-white border border-gray-200 rounded-2xl w-full max-w-sm shadow-2xl overflow-hidden">
            <div class="px-6 py-4 bg-red-50 border-b border-red-100 flex justify-between items-center">
                <h3 class="text-sm font-bold text-red-800">⚠️ Xác nhận xóa tài khoản</h3>
                <button onclick="closeDeleteModal()" class="text-red-400 hover:text-red-600 text-lg">&times;</button>
            </div>
            <form method="POST" class="p-6 text-center">
                <input type="hidden" name="action_delete" value="1">
                <input type="hidden" id="delete_user_id" name="user_id">
                <span class="text-3xl inline-block mb-3">🗑️</span>
                <p class="text-xs text-gray-600 font-medium">Bạn có chắc chắn muốn xóa vĩnh viễn tài khoản của <strong id="delete_user_name" class="text-gray-900 font-bold text-sm"></strong>?</p>
                <div class="pt-4 flex justify-center space-x-2 border-t border-gray-100 mt-5">
                    <button type="button" onclick="closeDeleteModal()" class="px-4 py-2 bg-gray-100 rounded-xl text-xs font-bold text-gray-600">Hủy</button>
                    <button type="submit" class="px-4 py-2 bg-red-600 rounded-xl text-xs font-bold text-white">Đồng ý xóa</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Tự động ẩn thông báo sau 5 giây (JavaScript)
        document.addEventListener("DOMContentLoaded", function() {
            const successAlert = document.getElementById('toast-success');
            if (successAlert) { setTimeout(() => { successAlert.classList.add('alert-hidden'); }, 5000); }

            const errorAlert = document.getElementById('toast-error');
            if (errorAlert) { setTimeout(() => { errorAlert.classList.add('alert-hidden'); }, 5000); }
        });

        function openAddModal() { document.getElementById('addModal').classList.remove('hidden'); }
        function closeAddModal() { document.getElementById('addModal').classList.add('hidden'); }

        function openEditModal(userObj) {
            document.getElementById('edit_password').value = ""; 
            document.getElementById('edit_user_id').value = userObj.id;
            document.getElementById('edit_username').value = userObj.username;
            document.getElementById('edit_full_name').value = userObj.full_name;
            document.getElementById('edit_phone').value = userObj.phone || '';
            document.getElementById('edit_role').value = userObj.role;
            document.getElementById('edit_status').value = userObj.status;
            document.getElementById('editModal').classList.remove('hidden');
        }
        function closeEditModal() { document.getElementById('editModal').classList.add('hidden'); }

        function openDeleteModal(id, fullName) {
            document.getElementById('delete_user_id').value = id;
            document.getElementById('delete_user_name').innerText = fullName;
            document.getElementById('deleteModal').classList.remove('hidden');
        }
        function closeDeleteModal() { document.getElementById('deleteModal').classList.add('hidden'); }

        window.onclick = function(event) {
            if (event.target === document.getElementById('addModal')) closeAddModal();
            if (event.target === document.getElementById('editModal')) closeEditModal();
            if (event.target === document.getElementById('deleteModal')) closeDeleteModal();
        }
    </script>
</body>
</html>