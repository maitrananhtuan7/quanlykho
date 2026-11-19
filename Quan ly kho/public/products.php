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

// Lấy danh sách danh mục để đổ vào thẻ Select trong Form
$categories = $pdo->query("SELECT * FROM categories ORDER BY name ASC")->fetchAll();

// 1. XỬ LÝ THÊM SẢN PHẨM
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_product'])) {
    $code = strtoupper(trim($_POST['code']));
    $name = trim($_POST['name']);
    $category_id = !empty($_POST['category_id']) ? (int)$_POST['category_id'] : null;
    $unit = mb_strtoupper(trim($_POST['unit']), 'UTF-8'); // Quy cách (hủ, viên, lốc, thùng)
    
    if (!empty($code) && !empty($name) && !empty($unit)) {
        try {
            $stmt = $pdo->prepare("INSERT INTO products (code, name, category_id, unit) VALUES (?, ?, ?, ?)");
            $stmt->execute([$code, $name, $category_id, $unit]);
            
            $_SESSION['success_msg'] = "Thêm mới sản phẩm [$code] thành công!";
            header("Location: products.php");
            exit;
        } catch (PDOException $e) {
            if ($e->errorInfo[1] == 1062) { // Lỗi trùng SKU/Mã sản phẩm
                $error = "Mã sản phẩm (SKU) này đã tồn tại!";
            } else {
                $error = "Lỗi: " . $e->getMessage();
            }
        }
    } else {
        $error = "Vui lòng điền đầy đủ các thông tin bắt buộc!";
    }
}

// 2. XỬ LÝ CẬP NHẬT (SỬA) SẢN PHẨM
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_product'])) {
    $id = (int)$_POST['id'];
    $code = strtoupper(trim($_POST['code']));
    $name = trim($_POST['name']);
    $category_id = !empty($_POST['category_id']) ? (int)$_POST['category_id'] : null;
    $unit = mb_strtoupper(trim($_POST['unit']), 'UTF-8'); // Quy cách cập nhật
    
    if (!empty($id) && !empty($code) && !empty($name) && !empty($unit)) {
        try {
            $stmt = $pdo->prepare("UPDATE products SET code = ?, name = ?, category_id = ?, unit = ? WHERE id = ?");
            $stmt->execute([$code, $name, $category_id, $unit, $id]);
            
            $_SESSION['success_msg'] = "Cập nhật sản phẩm [$code] thành công!";
            header("Location: products.php");
            exit;
        } catch (PDOException $e) {
            if ($e->errorInfo[1] == 1062) {
                $error = "Mã sản phẩm cập nhật trùng với sản phẩm khác!";
            } else {
                $error = "Lỗi: " . $e->getMessage();
            }
        }
    }
}

// 3. XỬ LÝ XÓA SẢN PHẨM
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    try {
        $stmt_info = $pdo->prepare("SELECT code FROM products WHERE id = ?");
        $stmt_info->execute([$id]);
        $prod_info = $stmt_info->fetch();
        $code_deleted = $prod_info ? $prod_info['code'] : '';

        $stmt = $pdo->prepare("DELETE FROM products WHERE id = ?");
        $stmt->execute([$id]);
        
        $_SESSION['success_msg'] = "Xóa sản phẩm [$code_deleted] thành công!";
        header("Location: products.php");
        exit;
    } catch (PDOException $e) {
        $error = "Không thể xóa sản phẩm này do đã phát sinh lịch sử Nhập / Xuất kho theo quy cách này!";
    }
}

// Lấy thông tin sản phẩm cần sửa khi nhấn nút Sửa
$edit_data = null;
if (isset($_GET['edit'])) {
    $edit_id = (int)$_GET['edit'];
    $stmt = $pdo->prepare("SELECT * FROM products WHERE id = ?");
    $stmt->execute([$edit_id]);
    $edit_data = $stmt->fetch();
}

// ==========================================
// CẤU HÌNH TÌM KIẾM, SẮP XẾP & PHÂN TRANG THEO SỐ DÒNG LỰA CHỌ
// ==========================================

// Từ khóa tìm kiếm
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

// Sắp xếp
$sort = isset($_GET['sort']) ? trim($_GET['sort']) : 'id_desc';
$order_by = "p.id DESC"; // Mặc định
if ($sort === 'name_asc') {
    $order_by = "p.name ASC";
} elseif ($sort === 'name_desc') {
    $order_by = "p.name DESC";
}

// Định nghĩa các mức số dòng hiển thị trên một trang theo yêu cầu của bạn
$allowed_limits = [10, 25, 50, 100, 150, 200, 250, 300];

// Lấy số dòng hiển thị được chọn từ URL, nếu không hợp lệ thì mặc định chọn hiển thị 50 dòng
$per_page = isset($_GET['limit']) && in_array((int)$_GET['limit'], $allowed_limits) ? (int)$_GET['limit'] : 50;
$limit_key = $per_page; // Gán lại biến key để đồng bộ phần View bên dưới

// Xây dựng câu điều kiện WHERE cho bộ lọc Tìm kiếm
$where_clause = "";
$params = [];
if ($search !== '') {
    $where_clause = "WHERE p.name LIKE ? OR p.code LIKE ? ";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

// 1. Đếm tổng số bản ghi thỏa mãn bộ lọc tìm kiếm trên toàn hệ thống
$count_query = "SELECT COUNT(*) FROM products p $where_clause";
$stmt_count = $pdo->prepare($count_query);
$stmt_count->execute($params);
$total_rows = (int)$stmt_count->fetchColumn();

// 2. Tính toán tổng số trang dựa trên quy chuẩn số dòng được chọn ($per_page)
$total_pages = ceil($total_rows / $per_page);
if ($total_pages < 1) {
    $total_pages = 1;
}

// Nhận diện trang hiện tại, tránh vượt mốc tổng số trang tính toán
$page = isset($_GET['page']) && (int)$_GET['page'] > 0 ? (int)$_GET['page'] : 1;
if ($page > $total_pages) {
    $page = $total_pages;
}

// Vị trí bắt đầu lấy dữ liệu (Offset) trong câu lệnh SQL
$offset = ($page - 1) * $per_page;

// Lấy danh sách sản phẩm giới hạn chuẩn xác theo số dòng phân trang
$products = [];
if ($total_rows > 0) {
    $query = "SELECT p.*, c.name AS category_name 
              FROM products p 
              LEFT JOIN categories c ON p.category_id = c.id 
              $where_clause
              ORDER BY $order_by 
              LIMIT $per_page OFFSET $offset";
    $stmt_products = $pdo->prepare($query);
    $stmt_products->execute($params);
    $products = $stmt_products->fetchAll();
}

require 'header.php';
?>

<div class="container mx-auto px-4 py-4 max-w-6xl">
    
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between mb-4 pb-3 border-b border-gray-200 gap-2">
        <div>
            <h1 class="text-2xl font-extrabold text-slate-900 tracking-tight flex items-center gap-2.5">
                <span class="p-2 bg-indigo-600 text-white rounded-lg shadow-md shadow-indigo-200">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path></svg>
                </span>
                Quản Lý Sản Phẩm
            </h1>
            <p class="text-slate-500 mt-0.5 text-xs font-medium">Cấu hình mã SKU, quy cách đóng gói và thiết lập phân loại hàng hóa.</p>
        </div>
        <div class="sm:text-right">
            <span class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-indigo-50 text-indigo-700 rounded-lg text-xs font-semibold border border-indigo-100 shadow-sm">
                <span class="w-1.5 h-1.5 rounded-full bg-indigo-500 animate-pulse"></span>
                Tổng hệ thống: <b class="font-bold text-sm text-indigo-900"><?= $total_rows ?></b> dòng | Đang hiển thị: <b class="text-xs text-amber-700 font-mono"><?= $per_page ?> dòng/trang</b>
            </span>
        </div>
    </div>

    <?php if(!empty($error)): ?>
        <div class="flash-message bg-red-50 border border-red-200 text-red-700 px-4 py-2 rounded-xl mb-4 flex justify-between items-center transition-all duration-500 ease-in-out opacity-100 max-h-40 overflow-hidden shadow-sm">
            <div class="flex items-center gap-2 text-xs font-medium">
                <svg class="w-4 h-4 text-red-500 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                <span><strong>Lỗi:</strong> <?= $error ?></span>
            </div>
            <div class="text-[10px] bg-red-100 text-red-800 px-2 py-0.5 rounded font-bold whitespace-nowrap select-none">
                Tự đóng sau <span class="countdown-num font-mono">5</span>s
            </div>
        </div>
    <?php endif; ?>

    <?php if(!empty($success)): ?>
        <div class="flash-message bg-emerald-50 border border-emerald-200 text-emerald-800 px-4 py-2 rounded-xl mb-4 flex justify-between items-center transition-all duration-500 ease-in-out opacity-100 max-h-40 overflow-hidden shadow-sm">
            <div class="flex items-center gap-2 text-xs font-medium">
                <svg class="w-4 h-4 text-emerald-500 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                <span><strong>Thành công:</strong> <?= $success ?></span>
            </div>
            <div class="text-[10px] bg-emerald-100 text-emerald-800 px-2 py-0.5 rounded font-bold whitespace-nowrap select-none">
                Tự đóng sau <span class="countdown-num font-mono">5</span>s
            </div>
        </div>
    <?php endif; ?>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-5">
        
        <div class="lg:col-span-1">
            <div class="bg-white p-4 rounded-xl shadow-sm border border-slate-150 sticky top-4">
                <?php if ($edit_data): ?>
                    <h2 class="text-sm font-bold text-amber-600 flex items-center gap-2 mb-4 border-b border-amber-100 pb-2">
                        <svg class="w-4 h-4 text-amber-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path></svg>
                        Cập Nhật Sản Phẩm
                    </h2>
                    <form method="POST" class="space-y-3">
                        <input type="hidden" name="id" value="<?= $edit_data['id'] ?>">
                        
                        <div class="space-y-1">
                            <label class="block text-[11px] font-bold text-slate-700 uppercase tracking-wider">Mã sản phẩm (SKU) <span class="text-red-500">*</span></label>
                            <input type="text" name="code" required value="<?= htmlspecialchars($edit_data['code']) ?>" class="block w-full rounded-lg border-slate-200 shadow-sm p-2 border text-xs focus:ring-2 focus:ring-amber-500 focus:border-amber-500 font-mono font-bold text-amber-900 uppercase">
                        </div>
                        
                        <div class="space-y-1">
                            <label class="block text-[11px] font-bold text-slate-700 uppercase tracking-wider">Tên sản phẩm <span class="text-red-500">*</span></label>
                            <input type="text" name="name" required value="<?= htmlspecialchars($edit_data['name']) ?>" class="block w-full rounded-lg border-slate-200 shadow-sm p-2 border text-xs focus:ring-2 focus:ring-amber-500 focus:border-amber-500 font-medium text-slate-800">
                        </div>
                        
                        <div class="space-y-1">
                            <label class="block text-[11px] font-bold text-slate-700 uppercase tracking-wider">Danh mục nhóm hàng</label>
                            <div class="relative">
                                <select name="category_id" class="block w-full rounded-lg border-slate-200 shadow-sm p-2 border text-xs focus:ring-2 focus:ring-amber-500 focus:border-amber-500 bg-white appearance-none text-slate-700 font-medium cursor-pointer">
                                    <option value="">-- Chọn danh mục --</option>
                                    <?php foreach ($categories as $cat): ?>
                                        <option value="<?= $cat['id'] ?>" <?= $edit_data['category_id'] == $cat['id'] ? 'selected' : '' ?>><?= htmlspecialchars($cat['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-2 text-slate-500">
                                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>
                                </div>
                            </div>
                        </div>
                        
                        <div class="space-y-1">
                            <label class="block text-[11px] font-bold text-slate-700 uppercase tracking-wider">Quy cách (ĐVT) <span class="text-red-500">*</span></label>
                            <div class="relative">
                                <select name="unit" required class="block w-full rounded-lg border-slate-200 shadow-sm p-2 border text-xs focus:ring-2 focus:ring-amber-500 focus:border-amber-500 bg-white appearance-none text-slate-700 font-medium cursor-pointer">
                                    <option value="">-- Chọn quy cách --</option>
                                    <option value="hủ" <?= $edit_data['unit'] === 'HỦ' || $edit_data['unit'] === 'hủ' ? 'selected' : '' ?>>Hủ</option>
                                    <option value="viên" <?= $edit_data['unit'] === 'VIÊN' || $edit_data['unit'] === 'viên' ? 'selected' : '' ?>>Viên</option>
                                    <option value="lốc" <?= $edit_data['unit'] === 'LỐC' || $edit_data['unit'] === 'lốc' ? 'selected' : '' ?>>Lốc</option>
                                    <option value="thùng" <?= $edit_data['unit'] === 'THÙNG' || $edit_data['unit'] === 'thùng' ? 'selected' : '' ?>>Thùng</option>
                                </select>
                                <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-2 text-slate-500">
                                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>
                                </div>
                            </div>
                        </div>
                        
                        <div class="flex space-x-2 pt-1">
                            <button type="submit" name="update_product" class="flex-1 inline-flex justify-center items-center px-3 py-2 bg-amber-500 hover:bg-amber-600 active:bg-amber-700 text-white text-xs font-bold rounded-lg shadow-sm transition transform hover:-translate-y-0.5">Xác nhận sửa</button>
                            <a href="products.php" class="inline-flex justify-center items-center px-3 py-2 bg-slate-100 hover:bg-slate-200 active:bg-slate-300 text-slate-700 text-xs font-bold rounded-lg transition border border-slate-200">Hủy</a>
                        </div>
                    </form>
                <?php else: ?>
                    <h2 class="text-sm font-bold text-slate-900 flex items-center gap-2 mb-4 border-b border-slate-100 pb-2">
                        <svg class="w-4 h-4 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v3m0 0v3m0-3h3m-3 0H9m12 0a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                        Thêm Sản Phẩm Mới
                    </h2>
                    <form method="POST" class="space-y-3">
                        <div class="space-y-1">
                            <label class="block text-[11px] font-bold text-slate-700 uppercase tracking-wider">Mã sản phẩm (SKU / Mã Vạch) <span class="text-red-500">*</span></label>
                            <input type="text" name="code" placeholder="VD: MILK-ENSURE" required class="block w-full rounded-lg border-slate-200 shadow-sm p-2 border text-xs focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 font-mono font-bold text-indigo-900 uppercase placeholder-slate-400 bg-indigo-50/10">
                        </div>
                        
                        <div class="space-y-1">
                            <label class="block text-[11px] font-bold text-slate-700 uppercase tracking-wider">Tên sản phẩm <span class="text-red-500">*</span></label>
                            <input type="text" name="name" placeholder="VD: Sữa bột Ensure Gold 850g" required class="block w-full rounded-lg border-slate-200 shadow-sm p-2 border text-xs focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 placeholder-slate-400 font-medium text-slate-800">
                        </div>
                        
                        <div class="space-y-1">
                            <label class="block text-[11px] font-bold text-slate-700 uppercase tracking-wider">Danh mục nhóm hàng</label>
                            <div class="relative">
                                <select name="category_id" class="block w-full rounded-lg border-slate-200 shadow-sm p-2 border text-xs focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 bg-white appearance-none text-slate-700 font-medium cursor-pointer">
                                    <option value="">-- Chọn danh mục --</option>
                                    <?php foreach ($categories as $cat): ?>
                                        <option value="<?= $cat['id'] ?>"><?= htmlspecialchars($cat['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-2 text-slate-500">
                                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>
                                </div>
                            </div>
                        </div>
                        
                        <div class="space-y-1">
                            <label class="block text-[11px] font-bold text-slate-700 uppercase tracking-wider">Quy cách (ĐVT) <span class="text-red-500">*</span></label>
                            <div class="relative">
                                <select name="unit" required class="block w-full rounded-lg border-slate-200 shadow-sm p-2 border text-xs focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 bg-white appearance-none text-slate-700 font-medium cursor-pointer">
                                    <option value="">-- Chọn quy cách --</option>
                                    <option value="hủ">Hủ</option>
                                    <option value="viên">Viên</option>
                                    <option value="lốc">Lốc</option>
                                    <option value="thùng">Thùng</option>
                                </select>
                                <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-2 text-slate-500">
                                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>
                                </div>
                            </div>
                        </div>
                        
                        <button type="submit" name="add_product" class="w-full mt-1 inline-flex justify-center items-center gap-1.5 px-4 py-2.5 bg-indigo-600 hover:bg-indigo-700 active:bg-indigo-800 text-white text-xs font-bold rounded-lg shadow-sm transition transform hover:-translate-y-0.5">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg>
                            Lưu Sản Phẩm Mới
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        </div>

        <div class="lg:col-span-2 space-y-3">
            
            <form method="GET" action="products.php" class="bg-slate-100 p-2 rounded-xl border border-slate-200 flex flex-wrap items-center justify-between gap-2">
                <input type="hidden" name="sort" value="<?= htmlspecialchars($sort) ?>">
                
                <div class="relative flex-1 min-w-[180px]">
                    <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Tìm tên hoặc mã SKU..." class="w-full bg-white pl-8 pr-3 py-1.5 rounded-lg border border-slate-200 text-xs focus:ring-1 focus:ring-indigo-500 focus:border-indigo-500 font-medium">
                    <div class="absolute inset-y-0 left-0 pl-2.5 flex items-center pointer-events-none text-slate-400">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg>
                    </div>
                </div>
                
                <div class="flex items-center gap-2 shrink-0">
                    <?php if($sort === 'name_asc'): ?>
                        <a href="products.php?search=<?= urlencode($search) ?>&limit=<?= urlencode($limit_key) ?>&sort=name_desc" class="inline-flex items-center gap-1 px-2.5 py-1.5 bg-indigo-50 text-indigo-700 rounded-lg text-xs font-bold border border-indigo-200 hover:bg-indigo-100 transition shadow-sm" title="Bấm để đổi thành xếp Tên từ Z đến A">
                            <span>Tên sản phẩm</span>
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4h13M3 8h9m-9 4h6m4 0l4-4m0 0l4 4m-4-4v12"></path></svg>
                        </a>
                    <?php elseif($sort === 'name_desc'): ?>
                        <a href="products.php?search=<?= urlencode($search) ?>&limit=<?= urlencode($limit_key) ?>&sort=name_asc" class="inline-flex items-center gap-1 px-2.5 py-1.5 bg-indigo-50 text-indigo-700 rounded-lg text-xs font-bold border border-indigo-200 hover:bg-indigo-100 transition shadow-sm" title="Bấm để đổi thành xếp theo Mặc định">
                            <span>Tên sản phẩm</span>
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4h13M3 8h9m-9 4h9m5-4v12m0 0l-4-4m4 4l4-4"></path></svg>
                        </a>
                    <?php else: ?>
                        <a href="products.php?search=<?= urlencode($search) ?>&limit=<?= urlencode($limit_key) ?>&sort=name_asc" class="inline-flex items-center gap-1 px-2.5 py-1.5 bg-white text-slate-600 rounded-lg text-xs font-semibold border border-slate-200 hover:bg-slate-50 transition shadow-sm" title="Bấm để sắp xếp Tên từ A đến Z">
                            <span>Tên sản phẩm</span>
                            <svg class="w-3.5 h-3.5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path></svg>
                        </a>
                    <?php endif; ?>

                    <div class="flex items-center gap-1.5 text-xs font-medium text-slate-600">
                        <span class="hidden sm:inline">Hiển thị:</span>
                        <select name="limit" onchange="this.form.submit()" class="bg-white rounded-lg border border-slate-200 text-xs py-1 px-2 focus:ring-1 focus:ring-indigo-500 focus:border-indigo-500 font-bold text-slate-800 cursor-pointer">
                            <?php foreach($allowed_limits as $size): ?>
                                <option value="<?= $size ?>" <?= $limit_key == $size ? 'selected' : '' ?>><?= $size ?> dòng</option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <button type="submit" class="px-3 py-1.5 bg-indigo-600 hover:bg-indigo-700 active:bg-indigo-800 text-white rounded-lg text-xs font-bold shadow-sm transition">Lọc</button>
                    <?php if($search !== '' || $sort !== 'id_desc' || $limit_key != 50): ?>
                        <a href="products.php" class="px-2 py-1.5 bg-slate-200 hover:bg-slate-300 text-slate-700 rounded-lg text-xs font-semibold transition" title="Xóa bộ lọc về mặc định">Xóa</a>
                    <?php endif; ?>
                </div>
            </form>

            <div class="bg-white rounded-xl shadow-sm border border-slate-150 overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-xs border-collapse">
                        <thead class="bg-slate-900 text-slate-200 text-[11px] font-bold uppercase tracking-wider">
                            <tr>
                                <th class="py-2.5 px-3 text-center">STT</th>
                                <th class="py-2.5 px-4 text-left">Mã SKU</th>
                                <th class="py-2.5 px-4 text-left">Tên Sản Phẩm / Vật Tư</th>
                                <th class="py-2.5 px-3 text-left">Danh Mục</th>
                                <th class="py-2.5 px-2 text-center">Quy Cách</th>
                                <th class="py-2.5 px-4 text-center">Thao Tác</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 text-slate-700">
                            <?php if(count($products) === 0): ?>
                                <tr>
                                    <td colspan="6" class="py-10 text-center text-slate-400 font-medium bg-slate-50/50">
                                        <div class="flex flex-col items-center justify-center gap-1.5">
                                            <svg class="w-6 h-6 text-slate-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path></svg>
                                            Không tìm thấy dữ liệu sản phẩm tương ứng!
                                        </div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                            
                            <?php 
                            // Khởi tạo biến đếm số thứ tự dựa trên mốc Offset của trang hiện tại
                            $stt = $offset + 1; 
                            foreach ($products as $p): 
                            ?>
                                <tr class="hover:bg-slate-50/70 transition duration-150 group">
                                    <td class="py-2 px-3 text-center font-mono font-bold text-slate-400 text-xs whitespace-nowrap">
                                        <?= $stt++ ?>
                                    </td>

                                    <td class="py-2 px-4 font-mono font-bold text-slate-500 text-[11px] uppercase tracking-tight break-all group-hover:text-indigo-600 transition whitespace-nowrap">
                                        <?= htmlspecialchars($p['code']) ?>
                                    </td>
                                    
                                    <td class="py-2 px-4 font-semibold text-slate-800 text-xs">
                                        <?= htmlspecialchars($p['name']) ?>
                                    </td>
                                    
                                    <td class="py-2 px-3">
                                        <?php if (!empty($p['category_name'])): ?>
                                            <span class="inline-flex items-center px-1.5 py-0.5 rounded bg-slate-100 text-slate-600 text-[10px] font-medium border border-slate-200">
                                                <?= htmlspecialchars($p['category_name']) ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="text-slate-400 text-[11px] italic">Chưa phân loại</span>
                                        <?php endif; ?>
                                    </td>
                                    
                                    <td class="py-2 px-2 text-center whitespace-nowrap">
                                        <span class="inline-flex items-center justify-center bg-indigo-50 text-indigo-600 font-bold text-[10px] px-1.5 py-0.5 rounded border border-indigo-100 uppercase min-w-[45px]">
                                            <?= htmlspecialchars($p['unit']) ?>
                                        </span>
                                    </td>
                                    
                                    <td class="py-2 px-4 text-center whitespace-nowrap">
                                        <div class="inline-flex items-center justify-center gap-1">
                                            <a href="products.php?edit=<?= $p['id'] ?>&search=<?= urlencode($search) ?>&limit=<?= urlencode($limit_key) ?>&sort=<?= $sort ?>&page=<?= $page ?>" 
                                               class="p-1 px-2 text-slate-500 hover:text-amber-600 hover:bg-amber-50 rounded-md border border-transparent hover:border-amber-100 transition shadow-sm bg-slate-50 inline-flex items-center gap-1 text-[11px] font-bold" 
                                               title="Chỉnh sửa thông tin">
                                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path></svg>
                                                Sửa
                                            </a>
                                            
                                            <a href="products.php?delete=<?= $p['id'] ?>" 
                                               onclick="return confirm('Bạn chắc chắn muốn xóa sản phẩm [<?= $p['code'] ?>] này không? Hành động này không thể phục hồi.');" 
                                               class="p-1 px-2 text-slate-500 hover:text-red-600 hover:bg-red-50 rounded-md border border-transparent hover:border-red-100 transition shadow-sm bg-slate-50 inline-flex items-center gap-1 text-[11px] font-bold" 
                                               title="Xóa vật tư">
                                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg>
                                                Xóa
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="p-2.5 border-t bg-slate-50 flex items-center justify-between flex-wrap gap-2 text-xs">
                    <div class="text-slate-500 font-medium">
                        Hiển thị từ dòng <span class="text-indigo-600 font-bold font-mono"><?= $total_rows > 0 ? ($offset + 1) : 0 ?></span> đến <span class="text-indigo-600 font-bold font-mono"><?= $offset + count($products) ?></span> trong tổng số <span class="text-slate-900 font-bold font-mono"><?= $total_rows ?></span> dòng tìm thấy.
                    </div>
                    <div class="text-slate-400 italic text-[11px]">
                        * Đang hiển thị tối đa <?= $per_page ?> dòng trên một trang
                    </div>
                </div>
                
                <?php if ($total_pages > 1): ?>
                <div class="p-4 border-t border-slate-100 flex items-center justify-center gap-1 bg-slate-50 select-none">
                    
                    <?php if ($page > 1): ?>
                        <a href="?search=<?= urlencode($search) ?>&limit=<?= urlencode($limit_key) ?>&sort=<?= urlencode($sort) ?>&page=<?= ($page - 1) ?>" 
                           class="px-2.5 py-1 rounded border text-xs font-bold bg-white text-slate-600 border-slate-200 hover:bg-slate-100 transition">
                            &laquo; Trước
                        </a>
                    <?php endif; ?>

                    <?php 
                    $range = 2; // Số lượng nút trang hiển thị ở hai bên trang hiện tại (Trang giữa)
                    
                    // Luôn hiển thị trang đầu tiên
                    ?>
                    <a href="?search=<?= urlencode($search) ?>&limit=<?= urlencode($limit_key) ?>&sort=<?= urlencode($sort) ?>&page=1" 
                       class="px-3 py-1 rounded border text-xs font-bold transition <?= $page == 1 ? 'bg-indigo-600 text-white border-indigo-600' : 'bg-white text-slate-600 border-slate-200 hover:bg-slate-100' ?>">
                        1
                    </a>
                    <?php

                    // Xử lý dấu ba chấm đầu tiên nếu khoảng cách từ trang đầu tới cụm giữa quá lớn
                    if ($page > $range + 2) {
                        echo '<span class="px-2 text-slate-400 font-bold text-xs">...</span>';
                    }

                    // Tính toán phạm vi các trang ở giữa để hiển thị
                    $start_loop = max(2, $page - $range);
                    $end_loop = min($total_pages - 1, $page + $range);

                    // Điều chỉnh để đảm bảo luôn hiển thị đủ lượng nút bấm tương đối (ví dụ khi ở trang 1-2 hoặc trang cuối)
                    if ($page <= $range + 2) {
                        $end_loop = min($total_pages - 1, $range * 2 + 2);
                    }
                    if ($page >= $total_pages - $range - 1) {
                        $start_loop = max(2, $total_pages - ($range * 2 + 1));
                    }

                    // Vòng lặp in các trang ở giữa
                    for ($i = $start_loop; $i <= $end_loop; $i++) {
                        ?>
                        <a href="?search=<?= urlencode($search) ?>&limit=<?= urlencode($limit_key) ?>&sort=<?= urlencode($sort) ?>&page=<?= $i ?>" 
                           class="px-3 py-1 rounded border text-xs font-bold transition <?= $page == $i ? 'bg-indigo-600 text-white border-indigo-600' : 'bg-white text-slate-600 border-slate-200 hover:bg-slate-100' ?>">
                            <?= $i ?>
                        </a>
                        <?php
                    }

                    // Xử lý dấu ba chấm thứ hai nếu cách xa trang cuối
                    if ($page < $total_pages - $range - 1) {
                        echo '<span class="px-2 text-slate-400 font-bold text-xs">...</span>';
                    }

                    // Luôn hiển thị trang cuối cùng
                    if ($total_pages > 1) {
                        ?>
                        <a href="?search=<?= urlencode($search) ?>&limit=<?= urlencode($limit_key) ?>&sort=<?= urlencode($sort) ?>&page=<?= $total_pages ?>" 
                           class="px-3 py-1 rounded border text-xs font-bold transition <?= $page == $total_pages ? 'bg-indigo-600 text-white border-indigo-600' : 'bg-white text-slate-600 border-slate-200 hover:bg-slate-100' ?>">
                            <?= $total_pages ?>
                        </a>
                        <?php
                    }
                    ?>

                    <?php if ($page < $total_pages): ?>
                        <a href="?search=<?= urlencode($search) ?>&limit=<?= urlencode($limit_key) ?>&sort=<?= urlencode($sort) ?>&page=<?= ($page + 1) ?>" 
                           class="px-2.5 py-1 rounded border text-xs font-bold bg-white text-slate-600 border-slate-200 hover:bg-slate-100 transition">
                            Sau &raquo;
                        </a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

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
                
                message.classList.remove('opacity-100', 'max-h-40', 'mb-4');
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