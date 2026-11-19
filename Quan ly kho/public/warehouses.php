<?php 
// Khởi động session ở đầu trang để truyền thông báo khi chuyển hướng trang
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require 'db.php';

$error = '';
$success = '';

// Kiểm tra xem có thông báo thành công từ lượt thao tác trước gửi qua Session không
if (isset($_SESSION['success_msg'])) {
    $success = $_SESSION['success_msg'];
    unset($_SESSION['success_msg']); // Hiển thị xong thì xóa ngay để không bị lặp lại khi F5
}

// 1. XỬ LÝ THÊM KHO
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_warehouse'])) {
    $name = trim($_POST['name']);
    $code = strtoupper(trim($_POST['code']));
    $address = trim($_POST['address']);
    
    if (!empty($name) && !empty($code)) {
        try {
            $stmt = $pdo->prepare("INSERT INTO warehouses (name, code, address) VALUES (?, ?, ?)");
            $stmt->execute([$name, $code, $address]);
            
            // Lưu thông báo thành công vào session trước khi chuyển hướng
            $_SESSION['success_msg'] = "Thêm mới kho [$code] thành công!";
            header("Location: warehouses.php");
            exit;
        } catch (PDOException $e) {
            if ($e->errorInfo[1] == 1062) { // Lỗi trùng UNIQUE định danh mã kho
                $error = "Mã ký hiệu kho này đã tồn tại trên hệ thống!";
            } else {
                $error = "Lỗi: " . $e->getMessage();
            }
        }
    } else {
        $error = "Vui lòng điền đầy đủ Tên kho và Mã ký hiệu!";
    }
}

// 2. XỬ LÝ CẬP NHẬT (SỬA) KHO
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_warehouse'])) {
    $id = (int)$_POST['id'];
    $name = trim($_POST['name']);
    $code = strtoupper(trim($_POST['code']));
    $address = trim($_POST['address']);
    
    if (!empty($id) && !empty($name) && !empty($code)) {
        try {
            $stmt = $pdo->prepare("UPDATE warehouses SET name = ?, code = ?, address = ? WHERE id = ?");
            $stmt->execute([$name, $code, $address, $id]);
            
            // Lưu thông báo thành công vào session trước khi chuyển hướng
            $_SESSION['success_msg'] = "Cập nhật thông tin kho [$code] thành công!";
            header("Location: warehouses.php");
            exit;
        } catch (PDOException $e) {
            if ($e->errorInfo[1] == 1062) {
                $error = "Mã ký hiệu kho cập nhật bị trùng với một kho khác!";
            } else {
                $error = "Lỗi: " . $e->getMessage();
            }
        }
    }
}

// 3. XỬ LÝ XÓA KHO
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    try {
        // Lấy mã code của kho trước khi xóa để làm thông báo trực quan hơn
        $stmt_info = $pdo->prepare("SELECT code FROM warehouses WHERE id = ?");
        $stmt_info->execute([$id]);
        $wh_info = $stmt_info->fetch();
        $code_deleted = $wh_info ? $wh_info['code'] : '';

        $stmt = $pdo->prepare("DELETE FROM warehouses WHERE id = ?");
        $stmt->execute([$id]);
        
        // Lưu thông báo thành công vào session trước khi chuyển hướng
        $_SESSION['success_msg'] = "Xóa kho [$code_deleted] thành công khỏi hệ thống!";
        header("Location: warehouses.php");
        exit;
    } catch (PDOException $e) {
        $error = "Không thể xóa kho này vì đang có dữ liệu hàng hóa tồn kho hoặc lịch sử phiếu liên quan!";
    }
}

// Lấy thông tin kho cần sửa khi người dùng nhấn nút Sửa
$edit_data = null;
if (isset($_GET['edit'])) {
    $edit_id = (int)$_GET['edit'];
    $stmt = $pdo->prepare("SELECT * FROM warehouses WHERE id = ?");
    $stmt->execute([$edit_id]);
    $edit_data = $stmt->fetch();
}

// Lấy danh sách toàn bộ các kho hàng đang có sắp xếp theo ID mới nhất
$warehouses = $pdo->query("SELECT * FROM warehouses ORDER BY id DESC")->fetchAll();

require 'header.php';
?>

<div class="container mx-auto px-4 py-3 max-w-7xl">
    
    <!-- TIÊU ĐỀ TRANG DASHBOARD -->
    <div class="flex flex-col md:flex-row md:items-center md:justify-between mb-3 pb-4 border-b border-gray-200 gap-4">
        <div>
            <h1 class="text-3xl font-extrabold text-slate-900 tracking-tight flex items-center gap-3">
                <span class="p-2.5 bg-indigo-600 text-white rounded-xl shadow-md shadow-indigo-200">
                    <svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"></path></svg>
                </span>
                Quản Lý Kho Hàng
            </h1>
            <p class="text-slate-500 mt-1.5 text-sm font-medium">Cấu hình địa điểm lưu kho, phân tách khu vực quản lý và lưu chuyển hàng hóa.</p>
        </div>
        <div>
            <span class="inline-flex items-center gap-2 px-4 py-2.5 bg-indigo-50 text-indigo-700 rounded-xl text-sm font-semibold border border-indigo-100 shadow-sm">
                <span class="w-2 h-2 rounded-full bg-indigo-500 animate-pulse"></span>
                Tổng số kho: <b class="font-bold text-base text-indigo-900"><?= count($warehouses) ?></b>
            </span>
        </div>
    </div>

    <!-- KHU VỰC HIỂN THỊ THÔNG BÁO CÓ ĐẾM NGƯỢC GIÂY -->
    <?php if(!empty($error)): ?>
        <div class="flash-message bg-red-50 border border-red-200 text-red-700 px-4 py-3.5 rounded-xl mb-6 flex justify-between items-center transition-all duration-500 ease-in-out opacity-100 max-h-40 overflow-hidden shadow-sm">
            <div class="flex items-center gap-2 text-sm font-medium">
                <svg class="w-5 h-5 text-red-500 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                <span><strong>Lỗi:</strong> <?= $error ?></span>
            </div>
            <div class="text-xs bg-red-100 text-red-800 px-2 py-1 rounded-lg font-bold whitespace-nowrap select-none">
                Tự đóng sau <span class="countdown-num font-mono">5</span>s
            </div>
        </div>
    <?php endif; ?>

    <?php if(!empty($success)): ?>
        <div class="flash-message bg-emerald-50 border border-emerald-200 text-emerald-800 px-4 py-3.5 rounded-xl mb-6 flex justify-between items-center transition-all duration-500 ease-in-out opacity-100 max-h-40 overflow-hidden shadow-sm">
            <div class="flex items-center gap-2 text-sm font-medium">
                <svg class="w-5 h-5 text-emerald-500 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                <span><strong>Thành công:</strong> <?= $success ?></span>
            </div>
            <div class="text-xs bg-emerald-100 text-emerald-800 px-2 py-1 rounded-lg font-bold whitespace-nowrap select-none">
                Tự đóng sau <span class="countdown-num font-mono">5</span>s
            </div>
        </div>
    <?php endif; ?>

    <!-- CẤU TRÚC LAYOUT 2 CỘT LINH HOẠT -->
    <!-- <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
        
        CỘT FORM: THÊM HOẶC SỬA THÔNG TIN KHO
        <div class="lg:col-span-1">
            <div class="bg-white p-6 rounded-2xl shadow-sm border border-slate-150 sticky top-6">
                <?php if ($edit_data): ?>
                    Giao diện form Sửa
                    <h2 class="text-lg font-bold text-amber-600 flex items-center gap-2 mb-6 border-b border-amber-100 pb-3">
                        <svg class="w-5 h-5 text-amber-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path></svg>
                        Cập Nhật Kho Hàng
                    </h2>
                    <form method="POST" class="space-y-4">
                        <input type="hidden" name="id" value="<?= $edit_data['id'] ?>">
                        
                        <div class="grid grid-cols-1 gap-4">
                            <div class="space-y-1.5">
                                <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider">Mã ký hiệu kho <span class="text-red-500">*</span></label>
                                <input type="text" name="code" required value="<?= htmlspecialchars($edit_data['code']) ?>" class="block w-full rounded-xl border-slate-200 shadow-sm p-3 border text-sm focus:ring-2 focus:ring-amber-500 focus:border-amber-500 font-mono font-bold text-amber-900 uppercase">
                            </div>
                            
                            <div class="space-y-1.5">
                                <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider">Tên kho hàng <span class="text-red-500">*</span></label>
                                <input type="text" name="name" required value="<?= htmlspecialchars($edit_data['name']) ?>" class="block w-full rounded-xl border-slate-200 shadow-sm p-3 border text-sm focus:ring-2 focus:ring-amber-500 focus:border-amber-500 font-medium text-slate-800">
                            </div>
                        </div>
                        
                        <div class="space-y-1.5 pt-1">
                            <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider">Địa chỉ / Vị trí kho chi tiết</label>
                            <input type="text" name="address" value="<?= htmlspecialchars($edit_data['address']) ?>" placeholder="Nhập địa điểm chính xác của kho..." class="block w-full rounded-xl border-slate-200 shadow-sm p-3 border text-sm focus:ring-2 focus:ring-amber-500 focus:border-amber-500 text-slate-800 placeholder-slate-400">
                        </div>
                        
                        <div class="flex space-x-3 pt-4">
                            <button type="submit" name="update_warehouse" class="flex-1 inline-flex justify-center items-center px-4 py-3 bg-amber-500 hover:bg-amber-600 active:bg-amber-700 text-white text-sm font-bold rounded-xl shadow-md shadow-amber-100 transition transform hover:-translate-y-0.5">Xác nhận sửa</button>
                            <a href="warehouses.php" class="inline-flex justify-center items-center px-4 py-3 bg-slate-100 hover:bg-slate-200 active:bg-slate-300 text-slate-700 text-sm font-bold rounded-xl transition border border-slate-200">Hủy</a>
                        </div>
                    </form>
                <?php else: ?>
                    Giao diện form Thêm mới
                    <h2 class="text-lg font-bold text-slate-900 flex items-center gap-2 mb-6 border-b border-slate-100 pb-3">
                        <svg class="w-5 h-5 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v3m0 0v3m0-3h3m-3 0H9m12 0a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                        Thêm Kho Hàng Mới
                    </h2>
                    <form method="POST" class="space-y-4">
                        <div class="grid grid-cols-1 gap-4">
                            <div class="space-y-1.5">
                                <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider">Mã ký hiệu kho <span class="text-red-500">*</span></label>
                                <input type="text" name="code" placeholder="VD: KHO-A, KHOMEN" required class="block w-full rounded-xl border-slate-200 shadow-sm p-3 border text-sm focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 font-mono font-bold text-indigo-900 uppercase placeholder-slate-400 bg-indigo-50/20">
                            </div>
                            
                            <div class="space-y-1.5">
                                <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider">Tên kho hàng <span class="text-red-500">*</span></label>
                                <input type="text" name="name" placeholder="VD: Kho Tổng Thành Phẩm" required class="block w-full rounded-xl border-slate-200 shadow-sm p-3 border text-sm focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 placeholder-slate-400 font-medium text-slate-800">
                            </div>
                        </div>
                        
                        <div class="space-y-1.5 pt-1">
                            <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider">Địa chỉ / Vị trí kho chi tiết</label>
                            <input type="text" name="address" placeholder="VD: Lô B2, Đường số 4, Khu Công Nghiệp Nhơn Hòa, An Nhơn, Bình Định" class="block w-full rounded-xl border-slate-200 shadow-sm p-3 border text-sm focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 placeholder-slate-400 text-slate-800">
                        </div>
                        
                        <button type="submit" name="add_warehouse" class="w-full mt-4 inline-flex justify-center items-center gap-2 px-5 py-3 bg-indigo-600 hover:bg-indigo-700 active:bg-indigo-800 text-white text-sm font-bold rounded-xl shadow-md shadow-indigo-100 transition transform hover:-translate-y-0.5">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg>
                            Lưu Kho Hàng
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        </div> -->

        <!-- CỘT DANH SÁCH BẢNG HIỂN THỊ CÁC KHO -->
        <div class="lg:col-span-2">
            <div class="bg-white rounded-2xl shadow-sm border border-slate-150 overflow-hidden">
                <div class="p-5 border-b bg-slate-50/60 flex items-center justify-between">
                    <h2 class="text-base font-bold text-slate-800 flex items-center gap-2">
                        <svg class="w-4 h-4 text-indigo-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 10h16M4 14h16M4 18h16"></path></svg>
                        Danh Sách Kho Hàng Trên Hệ Thống
                    </h2>
                </div>
                
                <div class="overflow-x-auto">
                    <!-- Loại bỏ table-fixed để các ô tự co giãn thông minh theo dữ liệu thực tế -->
                    <table class="min-w-full text-sm border-collapse">
                        <thead class="bg-slate-900 text-slate-200 text-xs font-bold uppercase tracking-wider">
                            <tr>
                                <!-- Thay đổi tỉ lệ phần trăm width giúp cột Địa Chỉ rộng rãi hơn hẳn -->
                                <th class="w-2/12 py-4 px-5 text-left whitespace-nowrap">Mã Ký Hiệu</th>
                                <th class="w-4/12 py-4 px-5 text-left whitespace-nowrap">Tên Kho Hàng</th>
                                <th class="w-4/12 py-4 px-4 text-left whitespace-nowrap">Địa Chỉ / Vị Trí</th>
                                <!-- <th class="w-2/12 py-4 px-5 text-center whitespace-nowrap">Thao Tác</th> -->
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 text-sm text-slate-700">
                            <?php if(count($warehouses) === 0): ?>
                                <tr>
                                    <td colspan="4" class="py-16 text-center text-slate-400 font-medium bg-slate-50/50">
                                        <div class="flex flex-col items-center justify-center gap-2">
                                            <svg class="w-8 h-8 text-slate-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"></path></svg>
                                            Hệ thống chưa có cấu hình kho hàng nào!
                                        </div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                            
                            <?php foreach ($warehouses as $w): ?>
                                <tr class="hover:bg-slate-50/70 transition duration-150 group">
                                    <!-- Mã Ký Hiệu -->
                                    <td class="py-3.5 px-5 font-mono font-bold text-slate-500 text-xs uppercase tracking-tight whitespace-nowrap group-hover:text-indigo-600 transition">
                                        <?= htmlspecialchars($w['code']) ?>
                                    </td>
                                    
                                    <!-- Tên kho hàng -->
                                    <td class="py-3.5 px-5 font-semibold text-slate-800 whitespace-normal break-words">
                                        <?= htmlspecialchars($w['name']) ?>
                                    </td>
                                    
                                    <!-- ĐỊA CHỈ / VỊ TRÍ: Đã được mở rộng tối đa không bị cắt cụt dấu ... -->
                                    <td class="py-3.5 px-4 text-slate-600 whitespace-normal break-words leading-relaxed font-medium">
                                        <?= !empty($w['address']) ? htmlspecialchars($w['address']) : '<span class="text-slate-400 text-xs italic font-normal">Chưa cập nhật</span>' ?>
                                    </td>
                                    
                                    <!-- Thao tác -->
                                    <!-- <td class="py-3.5 px-5 text-center whitespace-nowrap">
                                        <div class="inline-flex items-center justify-center gap-1.5">
                                            Nút Sửa
                                            <a href="warehouses.php?edit=<?= $w['id'] ?>" 
                                               class="p-2 text-slate-400 hover:text-amber-600 hover:bg-amber-50 rounded-xl border border-transparent hover:border-amber-100 transition shadow-sm bg-slate-50 inline-flex items-center gap-1 text-xs font-bold" 
                                               title="Chỉnh sửa thông tin">
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path></svg>
                                                Sửa
                                            </a>
                                            
                                            Nút Xóa
                                            <a href="warehouses.php?delete=<?= $w['id'] ?>" 
                                               onclick="return confirm('Bạn chắc chắn muốn xóa kho [<?= $w['code'] ?>] này không? Hành động này không thể phục hồi.');" 
                                               class="p-2 text-slate-400 hover:text-red-600 hover:bg-red-50 rounded-xl border border-transparent hover:border-red-100 transition shadow-sm bg-slate-50 inline-flex items-center gap-1 text-xs font-bold" 
                                               title="Xóa kho">
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg>
                                                Xóa
                                            </a>
                                        </div>
                                    </td> -->
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- JAVASCRIPT XỬ LÝ ĐẾM NGƯỢC GIÂY VÀ TỰ ĐỘNG ẨN MƯỢT MÀ -->
<script>
document.addEventListener("DOMContentLoaded", function() {
    const flashMessages = document.querySelectorAll('.flash-message');
    
    flashMessages.forEach(function(message) {
        let timeLeft = 5;
        const countdownElement = message.querySelector('.countdown-num');
        
        const timer = setInterval(function() {
            timeLeft--;
            if (countdownElement) {
                countdownElement.textContent = timeLeft;
            }
            
            if (timeLeft <= 0) {
                clearInterval(timer);
                
                message.classList.remove('opacity-100', 'max-h-40', 'mb-6');
                message.classList.add('opacity-0', 'max-h-0', 'mb-0', 'py-0', 'border-none');
                
                setTimeout(function() {
                    message.remove();
                }, 500);
            }
        }, 1000);
    });
});
</script>

</body>
</html>