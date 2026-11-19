<?php
// Bắt đầu Session để lưu trữ thông báo sau khi chuyển hướng trang (Redirect)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require 'db.php';
// Nhúng file cấu hình và class R2Manager của bạn để xử lý lưu trữ đám mây
require 'r2_api.php';

$error = '';
$success = '';

// Kiểm tra xem có thông báo thành công hoặc lỗi từ lượt chuyển trang trước không
if (isset($_SESSION['flash_success'])) {
    if (strpos($_SESSION['flash_success'], 'Đăng nhập') !== false || strpos($_SESSION['flash_success'], 'Quản trị viên') !== false) {
        unset($_SESSION['flash_success']);
        $success = '';
    } else {
        $success = $_SESSION['flash_success'];
        unset($_SESSION['flash_success']);
    }
}

if (isset($_SESSION['flash_error'])) {
    $error = $_SESSION['flash_error'];
    unset($_SESSION['flash_error']);
}

// Biến phục vụ cho chức năng SỬA (Chế độ Edit)
$is_edit = false;
$edit_id = $_GET['edit_id'] ?? null;
$edit_doc = null;
$edit_details = [];

// 1. TẢI DỮ LIỆU ĐỂ SỬA 
if ($edit_id) {
    $stmt = $pdo->prepare("SELECT * FROM documents WHERE id = ?");
    $stmt->execute([$edit_id]);
    $edit_doc = $stmt->fetch();

    if ($edit_doc) {
        $is_edit = true;
        $edit_details = json_decode($edit_doc['details'], true);
        if (!is_array($edit_details)) {
            $edit_details = [];
        }
    }
}

// 2. XỬ LÝ XÓA CHỨNG TỪ (Xóa file vật lý trên Cloudflare R2)
if (isset($_GET['delete_id'])) {
    $delete_id = $_GET['delete_id'];
    try {
        $pdo->beginTransaction();

        // Lấy mã số chứng từ trước khi xóa để làm thông báo
        $stmt_code = $pdo->prepare("SELECT ticket_code FROM documents WHERE id = ?");
        $stmt_code->execute([$delete_id]);
        $del_code = $stmt_code->fetchColumn();

        // Xóa dòng trực tiếp tại bảng cha duy nhất
        $stmt = $pdo->prepare("DELETE FROM documents WHERE id = ?");
        $stmt->execute([$delete_id]);

        $pdo->commit();

        $_SESSION['flash_success'] = "🗑️ Đã xóa thành công chứng từ số: <b>{$del_code}</b> ra khỏi hệ thống!";
        header("Location: vouchers.php");
        exit;
    } catch (Exception $e) {
        $pdo->rollBack();
        $_SESSION['flash_error'] = "Lỗi khi xóa chứng từ: " . $e->getMessage();
        header("Location: vouchers.php");
        exit;
    }
}

// 3. XỬ LÝ THÊM HOẶC CẬP NHẬT PHIẾU
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_voucher'])) {
    $ticket_code = trim($_POST['ticket_code']);
    $type = $_POST['type'];
    $warehouse_id = (int)$_POST['warehouse_id'];
    $date_issued = $_POST['date_issued'];
    $created_by = trim($_POST['created_by'] ?? '');
    $contact_person = trim($_POST['contact_person'] ?? '');
    $note = trim($_POST['note']);

    // Mảng dữ liệu sản phẩm từ form gửi lên
    $products_input = $_POST['products'] ?? [];

    if (!empty($ticket_code) && !empty($date_issued) && !empty($warehouse_id) && !empty($products_input)) {

        // Chuẩn hóa mảng sản phẩm đầu vào thành mảng sạch để lưu JSON
        $clean_details = [];
        foreach ($products_input as $item) {
            $p_id = isset($item['product_id']) ? (int)$item['product_id'] : 0;
            $lot = trim($item['lot_number'] ?? '');
            $exp = $item['expiry_date'] ?? '';
            $qty = isset($item['quantity']) ? (int)$item['quantity'] : 0;

            if ($qty > 0 && !empty($p_id) && !empty($exp)) {
                $clean_details[] = [
                    'product_id' => $p_id,
                    'lot_number' => $lot,
                    'expiry_date' => $exp,
                    'quantity' => $qty
                ];
            }
        }

        if (empty($clean_details)) {
            $error = "Vui lòng nhập ít nhất một sản phẩm hợp lệ với số lượng lớn hơn 0!";
        }

        // KIỂM TRA TỒN KHO TRƯỚC BẰNG THUẬT TOÁN PHP JSON (Nếu là phiếu XUẤT)
        if (empty($error) && $type === 'export') {
            $calc_sql = "SELECT id, type, details FROM documents WHERE warehouse_id = ?";
            if ($is_edit) {
                $calc_sql .= " AND id != " . (int)$edit_id;
            }
            $calc_stmt = $pdo->prepare($calc_sql);
            $calc_stmt->execute([$warehouse_id]);
            $existing_docs = $calc_stmt->fetchAll(PDO::FETCH_ASSOC);

            $stock_map = [];
            foreach ($existing_docs as $edoc) {
                $edet = json_decode($edoc['details'], true);
                if (!is_array($edet)) continue;
                foreach ($edet as $item) {
                    $k_id = $item['product_id'];
                    $k_exp = $item['expiry_date'];
                    $k_lot = trim($item['lot_number'] ?? '');
                    $k_qty = (int)$item['quantity'];

                    $key = "{$k_id}_{$k_exp}_{$k_lot}";
                    if (!isset($stock_map[$key])) {
                        $stock_map[$key] = 0;
                    }
                    if ($edoc['type'] === 'import') {
                        $stock_map[$key] += $k_qty;
                    } else {
                        $stock_map[$key] -= $k_qty;
                    }
                }
            }

            foreach ($clean_details as $index => $check_item) {
                $c_id = $check_item['product_id'];
                $c_exp = $check_item['expiry_date'];
                $c_lot = $check_item['lot_number'];
                $c_qty = $check_item['quantity'];

                $key = "{$c_id}_{$c_exp}_{$c_lot}";
                $current_stock = $stock_map[$key] ?? 0;

                if ($c_qty > $current_stock) {
                    $p_stmt = $pdo->prepare("SELECT name FROM products WHERE id = ?");
                    $p_stmt->execute([$c_id]);
                    $p_name = $p_stmt->fetchColumn();

                    $error = "Lỗi dòng " . ($index + 1) . ": Sản phẩm <b>{$p_name}</b> (Lô: {$c_lot} | Hạn: {$c_exp}) chỉ còn tồn kho {$current_stock} (Yêu cầu xuất: {$c_qty})!";
                    break;
                }
            }
        }

        // Kiểm tra xem mã phiếu đã tồn tại chưa
        if (empty($error)) {
            $check_code_sql = "SELECT COUNT(*) FROM documents WHERE ticket_code = ?";
            if ($is_edit) {
                $check_code_sql .= " AND id != " . (int)$edit_id;
            }
            $check_code_stmt = $pdo->prepare($check_code_sql);
            $check_code_stmt->execute([$ticket_code]);
            if ($check_code_stmt->fetchColumn() > 0) {
                $error = "Mã chứng từ <b>{$ticket_code}</b> đã tồn tại trên hệ thống! Vui lòng nhập mã khác.";
            }
        }

        // XỬ LÝ LẤY ĐƯỜNG DẪN ẢNH ĐƯỢC UPLOAD TỪ JS CLIENT GỬI LÊN
        if (empty($error) && !empty($_POST['client_uploaded_image_url'])) {
            // Nếu người dùng chọn ảnh mới và đang ở chế độ sửa, xóa ảnh cũ trên R2 để tránh rác dung lượng
            if ($is_edit && !empty($edit_doc['voucher_image']) && $edit_doc['voucher_image'] !== $_POST['client_uploaded_image_url']) {
                $public_path_prefix = rtrim($r2_config['public_path'], '/') . '/';
                if (strpos($edit_doc['voucher_image'], $public_path_prefix) === 0) {
                    $r2_key = str_replace($public_path_prefix, '', $edit_doc['voucher_image']);
                    $r2->deleteFile($r2_key);
                }
            }
            $uploaded_file_path = $_POST['client_uploaded_image_url'];
        }

        // TIẾN HÀNH LƯU DATABASE VỚI ĐỊNH DẠNG CHUỖI JSON
        if (empty($error)) {
            try {
                $pdo->beginTransaction();
                $json_details = json_encode($clean_details, JSON_UNESCAPED_UNICODE);

                if ($is_edit) {
                    $stmt = $pdo->prepare("UPDATE documents SET ticket_code = ?, type = ?, warehouse_id = ?, date_issued = ?, created_by = ?, contact_person = ?, voucher_image = ?, note = ?, details = ? WHERE id = ?");
                    $stmt->execute([$ticket_code, $type, $warehouse_id, $date_issued, $created_by, $contact_person, $uploaded_file_path, $note, $json_details, $edit_id]);

                    $_SESSION['flash_success'] = "📝 Đã cập nhật thành công chứng từ số: <b>{$ticket_code}</b>!";
                } else {
                    $stmt = $pdo->prepare("INSERT INTO documents (ticket_code, type, warehouse_id, date_issued, created_by, contact_person, voucher_image, note, details) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$ticket_code, $type, $warehouse_id, $date_issued, $created_by, $contact_person, $uploaded_file_path, $note, $json_details]);

                    $_SESSION['flash_success'] = "✨ Đã tạo mới thành công chứng từ số: <b>{$ticket_code}</b>!";
                }

                $pdo->commit();
                header("Location: vouchers.php");
                exit;
            } catch (Exception $e) {
                $pdo->rollBack();
                $error = "Đã xảy ra lỗi hệ thống khi xử lý chứng từ: " . $e->getMessage();
            }
        }
    } else {
        $error = "Vui lòng điền đầy đủ thông tin chứng từ và danh sách sản phẩm đính kèm!";
    }
}

// Lấy dữ liệu danh mục đổ vào các select option trên form lập phiếu
$all_products = $pdo->query("SELECT id, name, code FROM products ORDER BY name ASC")->fetchAll();
$all_warehouses = $pdo->query("SELECT * FROM warehouses ORDER BY name ASC")->fetchAll();

// NHẬN DỮ LIỆU CÁC BỘ LỌC TÌM KIẾM MỞ RỘNG
$filter_warehouse = $_GET['f_warehouse'] ?? '';
$filter_product_text = trim($_GET['f_product_text'] ?? '');
$filter_start_date = $_GET['f_start_date'] ?? '';
$filter_end_date = $_GET['f_end_date'] ?? '';
$filter_created_by = trim($_GET['f_created_by'] ?? '');
$filter_contact_person = trim($_GET['f_contact_person'] ?? '');

$has_filter = (!empty($filter_warehouse) || !empty($filter_product_text) || !empty($filter_start_date) || !empty($filter_end_date) || !empty($filter_created_by) || !empty($filter_contact_person));

$where_clauses = ["1=1"];
$where_params = [];

if (!empty($filter_warehouse)) {
    $where_clauses[] = "doc.warehouse_id = ?";
    $where_params[] = $filter_warehouse;
}

if (!empty($filter_product_text)) {
    $search_p_stmt = $pdo->prepare("SELECT id FROM products WHERE name LIKE ? OR code LIKE ?");
    $search_p_stmt->execute(["%{$filter_product_text}%", "%{$filter_product_text}%"]);
    $matched_product_ids = $search_p_stmt->fetchAll(PDO::FETCH_COLUMN);

    $product_sub_clauses = [];
    if (!empty($matched_product_ids)) {
        foreach ($matched_product_ids as $pid) {
            $product_sub_clauses[] = "doc.details LIKE ?";
            $where_params[] = '%"product_id":' . (int)$pid . '%';

            $product_sub_clauses[] = "doc.details LIKE ?";
            $where_params[] = '%"product_id": ' . (int)$pid . '%';
        }
    }
    $product_sub_clauses[] = "doc.details LIKE ?";
    $where_params[] = "%" . $filter_product_text . "%";
    $where_clauses[] = "(" . implode(" OR ", $product_sub_clauses) . ")";
}

if (!empty($filter_start_date)) {
    $where_clauses[] = "doc.date_issued >= ?";
    $where_params[] = $filter_start_date;
}
if (!empty($filter_end_date)) {
    $where_clauses[] = "doc.date_issued <= ?";
    $where_params[] = $filter_end_date;
}
if (!empty($filter_created_by)) {
    $where_clauses[] = "doc.created_by LIKE ?";
    $where_params[] = "%" . $filter_created_by . "%";
}
if (!empty($filter_contact_person)) {
    $where_clauses[] = "doc.contact_person LIKE ?";
    $where_params[] = "%" . $filter_contact_person . "%";
}

$where_sql = implode(" AND ", $where_clauses);

// --- XỬ LÝ PHÂN TRANG CHUẨN HÓA (PAGINATION) ---
$limit = 10;
$count_query = "SELECT COUNT(*) FROM documents doc WHERE $where_sql";
$count_stmt = $pdo->prepare($count_query);
$count_stmt->execute($where_params);
$total_vouchers = (int)$count_stmt->fetchColumn();

$total_pages = max(1, ceil($total_vouchers / $limit));

$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) $page = 1;
if ($page > $total_pages) $page = $total_pages;
$offset = ($page - 1) * $limit;

// Tải danh sách chứng từ thuộc trang hiện tại
$docs_query = "
    SELECT doc.*, w.name AS warehouse_name 
    FROM documents doc
    JOIN warehouses w ON doc.warehouse_id = w.id
    WHERE $where_sql 
    ORDER BY doc.id DESC 
    LIMIT $limit OFFSET $offset
";
$docs_stmt = $pdo->prepare($docs_query);
$docs_stmt->execute($where_params);
$raw_documents = $docs_stmt->fetchAll(PDO::FETCH_ASSOC);

$products_map = $pdo->query("SELECT id, name, code, unit FROM products")->fetchAll(PDO::FETCH_UNIQUE | PDO::FETCH_ASSOC);

$grouped_vouchers = [];
foreach ($raw_documents as $doc) {
    $doc_id = $doc['id'];
    $items_arr = json_decode($doc['details'], true);
    $processed_items = [];

    if (is_array($items_arr)) {
        foreach ($items_arr as $item) {
            $p_id = $item['product_id'];
            $p_info = $products_map[$p_id] ?? ['name' => 'Sản phẩm không xác định', 'code' => 'N/A', 'unit' => ''];

            $processed_items[] = [
                'product_name' => $p_info['name'],
                'product_code' => $p_info['code'],
                'unit' => $p_info['unit'],
                'lot_number' => $item['lot_number'] ?? '',
                'expiry_date' => $item['expiry_date'] ?? '',
                'quantity' => $item['quantity'] ?? 0
            ];
        }
    }

    $grouped_vouchers[$doc_id] = [
        'id' => $doc_id,
        'ticket_code' => $doc['ticket_code'],
        'type' => $doc['type'],
        'date_issued' => $doc['date_issued'],
        'warehouse_name' => $doc['warehouse_name'],
        'created_by' => $doc['created_by'],
        'contact_person' => $doc['contact_person'],
        'voucher_image' => $doc['voucher_image'],
        'note' => $doc['note'],
        'items' => $processed_items
    ];
}

require 'header.php';
?>

<link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">

<style>
    @keyframes shrink {
        from {
            width: 100%;
        }

        to {
            width: 0%;
        }
    }

    .progress-bar-timer {
        animation: shrink 5s linear forwards;
    }

    .alert-toast {
        transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
    }

    .alert-toast.fade-out {
        opacity: 0;
        transform: translateY(-20px);
    }

    .custom-scrollbar::-webkit-scrollbar {
        width: 6px;
    }

    .custom-scrollbar::-webkit-scrollbar-track {
        background: #f1f1f1;
        border-radius: 8px;
    }

    .custom-scrollbar::-webkit-scrollbar-thumb {
        background: #cbd5e1;
        border-radius: 8px;
    }

    .custom-scrollbar::-webkit-scrollbar-thumb:hover {
        background: #94a3b8;
    }

    .filter-transition {
        transition: all 0.3s ease-in-out;
        max-height: 0;
        opacity: 0;
        overflow: hidden;
    }

    .filter-transition.show {
        max-height: 500px;
        opacity: 1;
        overflow: visible;
    }
</style>

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-2">

    <?php if (!empty($error)): ?>
        <div id="toast_alert" class="alert-toast bg-red-50 border-l-4 border-red-500 rounded-xl mb-6 shadow-md overflow-hidden relative">
            <div class="p-4 flex items-start justify-between">
                <div class="flex">
                    <div class="flex-shrink-0 text-base">⚠️</div>
                    <div class="ml-3">
                        <p class="text-xs font-semibold text-red-800"><?= $error ?></p>
                        <p class="text-[10px] text-red-400 mt-1 font-normal">Thông báo tự đóng sau <span id="countdown_num" class="font-bold">5</span>s...</p>
                    </div>
                </div>
                <button type="button" onclick="closeToast()" class="text-red-400 hover:text-red-700 text-xs font-bold px-2 focus:outline-none">✕</button>
            </div>
            <div class="absolute bottom-0 left-0 h-1 bg-red-500 progress-bar-timer"></div>
        </div>
    <?php endif; ?>

    <?php if (!empty($success)): ?>
        <div id="toast_alert" class="alert-toast bg-green-50 border-l-4 border-green-500 rounded-xl mb-6 shadow-md overflow-hidden relative">
            <div class="p-4 flex items-start justify-between">
                <div class="flex">
                    <div class="flex-shrink-0 text-base">✅</div>
                    <div class="ml-3">
                        <p class="text-xs font-semibold text-green-800"><?= $success ?></p>
                        <p class="text-[10px] text-green-400 mt-1 font-normal">Thông báo tự đóng sau <span id="countdown_num" class="font-bold">5</span>s...</p>
                    </div>
                </div>
                <button type="button" onclick="closeToast()" class="text-green-400 hover:text-green-700 text-xs font-bold px-2 focus:outline-none">✕</button>
            </div>
            <div class="absolute bottom-0 left-0 h-1 bg-green-500 progress-bar-timer"></div>
        </div>
    <?php endif; ?>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">

        <div class="lg:col-span-1">
            <form method="POST" id="voucher_form" enctype="multipart/form-data" class="bg-white p-5 rounded-2xl shadow-sm border border-gray-100 sticky top-6">
                <input type="hidden" name="remove_current_image" id="remove_current_image_flag" value="0">
                <input type="hidden" name="client_uploaded_image_url" id="client_uploaded_image_url" value="<?= $is_edit ? htmlspecialchars($edit_doc['voucher_image'] ?? '') : '' ?>">

                <div class="flex items-center justify-between border-b border-gray-100 pb-4 mb-5">
                    <h2 class="text-sm font-bold text-gray-800 tracking-tight flex items-center gap-2">
                        <?= $is_edit ? '📝 Cập Nhật Phiếu #' . htmlspecialchars($edit_doc['ticket_code']) : 'Lập Phiếu Kho Mới' ?>
                    </h2>
                    <?php if ($is_edit): ?>
                        <a href="vouchers.php" class="text-xs text-red-500 hover:text-red-700 font-semibold transition-colors">✕ Hủy sửa</a>
                    <?php endif; ?>
                </div>

                <div class="space-y-4 mb-5">
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-[11px] font-bold text-gray-400 uppercase tracking-wider">Loại nghiệp vụ</label>
                            <select name="type" class="mt-1 block w-full rounded-lg border-gray-200 bg-gray-50/50 p-2 border text-xs font-semibold focus:ring-1 focus:ring-blue-500 outline-none">
                                <option value="import" <?= (($is_edit && $edit_doc['type'] === 'import') || (isset($_POST['type']) && $_POST['type'] === 'import')) ? 'selected' : '' ?>>📥 Nhập Kho</option>
                                <option value="export" <?= (($is_edit && $edit_doc['type'] === 'export') || (isset($_POST['type']) && $_POST['type'] === 'export')) ? 'selected' : '' ?>>📤 Xuất Kho</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-[11px] font-bold text-gray-400 uppercase tracking-wider">Mã số chứng từ</label>
                            <input type="text" id="form_ticket_code" name="ticket_code" placeholder="VD: PX-100111" required value="<?= $is_edit ? htmlspecialchars($edit_doc['ticket_code']) : htmlspecialchars($_POST['ticket_code'] ?? '') ?>" class="mt-1 block w-full rounded-lg border-gray-200 bg-gray-50/50 p-2 border text-xs font-mono font-bold text-blue-700 outline-none uppercase">
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-[11px] font-bold text-gray-400 uppercase tracking-wider">Ngày lập</label>
                            <input type="date" name="date_issued" required value="<?= $is_edit ? htmlspecialchars($edit_doc['date_issued']) : ($_POST['date_issued'] ?? date('Y-m-d')) ?>" class="mt-1 block w-full rounded-lg border-gray-200 bg-gray-50/50 p-2 border text-xs outline-none">
                        </div>
                        <div>
                            <label class="block text-[11px] font-bold text-gray-400 uppercase tracking-wider">Kho mục tiêu</label>
                            <select name="warehouse_id" required class="mt-1 block w-full rounded-lg border-gray-200 bg-gray-50/50 p-2 border text-xs outline-none">
                                <?php foreach ($all_warehouses as $w): ?>
                                    <option value="<?= $w['id'] ?>" <?= (($is_edit && $edit_doc['warehouse_id'] == $w['id']) || (isset($_POST['warehouse_id']) && $_POST['warehouse_id'] == $w['id'])) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($w['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="border-t border-gray-100 pt-4 mb-3">
                    <div class="flex items-center justify-between mb-3">
                        <label class="text-xs font-bold text-gray-700 uppercase tracking-wider">Chi tiết mặt hàng</label>
                        <button type="button" id="add_row_btn" class="bg-blue-50 text-blue-600 hover:bg-blue-100 px-2.5 py-1 rounded-md text-[11px] font-bold transition-all cursor-pointer">
                            ➕ Thêm hàng hóa
                        </button>
                    </div>

                    <div id="product_rows_container" class="space-y-4 max-h-[700px] overflow-y-auto pr-1">
                        <?php
                        $display_items = [[]];
                        if (!empty($products_input)) {
                            $display_items = $products_input;
                        } elseif ($is_edit && !empty($edit_details)) {
                            $display_items = $edit_details;
                        }

                        foreach ($display_items as $index => $det):
                            $current_product_id = isset($det['product_id']) ? (int)$det['product_id'] : 0;
                            $current_product_text = '';
                            if ($current_product_id > 0 && isset($products_map[$current_product_id])) {
                                $current_product_text = "[" . $products_map[$current_product_id]['code'] . "] " . $products_map[$current_product_id]['name'];
                            }
                        ?>
                            <div class="product-row bg-white p-4 rounded-xl border border-gray-100 shadow-sm relative group hover:border-blue-100 transition-all <?= $index > 0 ? 'pt-8' : '' ?>">
                                <?php if ($index > 0): ?>
                                    <button type="button" class="remove-row-btn absolute top-2 right-3 text-gray-400 hover:text-red-500 text-[11px] font-bold cursor-pointer">
                                        ✕ Gỡ dòng
                                    </button>
                                <?php endif; ?>

                                <div class="mb-3 relative search-product-container">
                                    <label class="block text-[11px] font-bold text-gray-400 uppercase tracking-wider">Tìm tên / mã mặt hàng</label>
                                    <input type="text"
                                        placeholder="Gõ tên hoặc mã vật tư để tìm..."
                                        autocomplete="off"
                                        value="<?= htmlspecialchars($current_product_text) ?>"
                                        class="search-product-input mt-1 block w-full rounded-lg border-gray-200 bg-gray-50/50 p-2 border text-xs outline-none focus:bg-white focus:ring-1 focus:ring-blue-500 transition-all font-medium">
                                    <input type="hidden"
                                        name="products[<?= $index ?>][product_id]"
                                        value="<?= $current_product_id ?>"
                                        class="product-hidden-id">

                                    <div class="search-suggestions-box hidden absolute left-0 right-0 mt-1 max-h-50 overflow-y-auto bg-white border border-gray-200 rounded-lg shadow-xl z-30 divide-y divide-gray-50 custom-scrollbar">
                                        <?php foreach ($all_products as $p): ?>
                                            <div data-id="<?= $p['id'] ?>"
                                                data-search="<?= htmlspecialchars(strtolower($p['code'] . ' ' . $p['name'])) ?>"
                                                class="suggestion-item p-2 text-xs text-gray-700 hover:bg-blue-50 hover:text-blue-700 font-medium cursor-pointer transition-colors">
                                                [<?= htmlspecialchars($p['code']) ?>] <?= htmlspecialchars($p['name']) ?>
                                            </div>
                                        <?php endforeach; ?>
                                        <div class="no-suggestions-item hidden p-3 text-xs text-center text-gray-400 italic bg-gray-50/50">
                                            Không có sản phẩm nào khớp từ khóa
                                        </div>
                                    </div>
                                </div>

                                <div class="grid grid-cols-2 gap-3 mb-3">
                                    <div>
                                        <label class="block text-[11px] font-bold text-gray-400 uppercase tracking-wider">Mã lô SX</label>
                                        <input type="text" name="products[<?= $index ?>][lot_number]" value="<?= htmlspecialchars($det['lot_number'] ?? '') ?>" placeholder="LOT-..." class="mt-1 block w-full rounded-lg border-gray-200 bg-gray-50/50 p-2 border text-xs uppercase tracking-wider outline-none">
                                    </div>
                                    <div>
                                        <label class="block text-[11px] font-bold text-gray-400 uppercase tracking-wider">Hạn dùng</label>
                                        <input type="date" name="products[<?= $index ?>][expiry_date]" value="<?= htmlspecialchars($det['expiry_date'] ?? '') ?>" required class="mt-1 block w-full rounded-lg border-gray-200 bg-gray-50/50 p-2 border text-xs outline-none">
                                    </div>
                                </div>
                                <div class="grid grid-cols-1">
                                    <div>
                                        <label class="block text-[11px] font-bold text-gray-400 uppercase tracking-wider">Số lượng</label>
                                        <input type="number" name="products[<?= $index ?>][quantity]" value="<?= $det['quantity'] ?? '' ?>" min="1" required placeholder="0" class="mt-1 block w-full rounded-lg border-gray-200 bg-gray-50/50 p-2 border text-xs font-bold text-blue-600 outline-none">
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="border-t border-gray-100 pt-3 mb-3">
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-[11px] font-bold text-gray-400 uppercase tracking-wider">Người lập phiếu</label>
                            <input type="text" name="created_by" value="<?= $is_edit ? htmlspecialchars($edit_doc['created_by'] ?? '') : htmlspecialchars($_POST['created_by'] ?? '') ?>" placeholder="Tên nhân viên..." class="mt-1 block w-full rounded-lg border-gray-200 bg-gray-50/50 p-2 border text-xs outline-none focus:bg-white transition-all">
                        </div>
                        <div>
                            <label class="block text-[11px] font-bold text-gray-400 uppercase tracking-wider">Người giao / nhận hàng</label>
                            <input type="text" name="contact_person" value="<?= $is_edit ? htmlspecialchars($edit_doc['contact_person'] ?? '') : htmlspecialchars($_POST['contact_person'] ?? '') ?>" placeholder="Tên đối tác..." class="mt-1 block w-full rounded-lg border-gray-200 bg-gray-50/50 p-2 border text-xs outline-none focus:bg-white transition-all">
                        </div>
                    </div>
                </div>

                <div class="border-t border-gray-100 pt-3 mb-3">
                    <label class="block text-[11px] font-bold text-gray-400 uppercase tracking-wider mb-1">Hình ảnh phiếu xuất / nhập (Cloudflare R2)</label>
                    <div class="relative border border-dashed border-gray-300 bg-gray-50/30 rounded-xl p-3 text-center hover:border-blue-400 transition-all flex flex-col items-center justify-center min-h-[90px]">
                        <input type="file" id="img_input_field" accept="image/*" class="absolute inset-0 w-full h-full opacity-0 cursor-pointer z-10">

                        <div id="img_placeholder_view" class="space-y-1 <?= ($is_edit && !empty($edit_doc['voucher_image'])) ? 'hidden' : '' ?>">
                            <div class="text-gray-400 text-lg">📷</div>
                            <p class="text-[11px] text-gray-500 font-medium">Nhấp chọn hoặc kéo thả file ảnh hóa đơn tại đây</p>
                        </div>

                        <div id="img_preview_container" class="relative max-h-24 <?= ($is_edit && !empty($edit_doc['voucher_image'])) ? '' : 'hidden' ?>">
                            <img id="img_preview_tag" src="<?= ($is_edit && !empty($edit_doc['voucher_image'])) ? htmlspecialchars($edit_doc['voucher_image']) : '#' ?>" class="object-contain max-h-24 rounded border border-gray-200">
                            <button type="button" id="remove_preview_img_btn" class="absolute -top-1.5 -right-1.5 bg-red-500 text-white rounded-full w-4 h-4 text-[9px] font-bold flex items-center justify-center hover:bg-red-600 z-20">✕</button>
                        </div>
                    </div>
                </div>

                <div class="mb-5">
                    <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider">Ghi chú diễn giải</label>
                    <textarea name="note" rows="2" class="mt-1.5 block w-full rounded-lg border-gray-200 bg-gray-50/50 p-2.5 border text-xs outline-none resize-none" placeholder="Lý do xuất nhập kho..."><?= $is_edit ? htmlspecialchars($edit_doc['note']) : htmlspecialchars($_POST['note'] ?? '') ?></textarea>
                </div>
                <button type="submit" name="add_voucher" class="w-full bg-blue-600 text-white py-3 rounded-xl shadow-md hover:bg-blue-700 font-bold text-xs tracking-wider uppercase cursor-pointer transition-all">
                    <?= $is_edit ? '💾 Cập Nhật Chứng Từ' : '💾 Hoàn Thành Lập Phiếu' ?>
                </button>
            </form>
        </div>

        <div class="lg:col-span-2 space-y-2">

            <div class="flex justify-end px-1">
                <button type="button" id="toggle_filter_btn" class="bg-white hover:bg-gray-50 text-gray-700 border border-gray-200 px-4 py-2 rounded-xl text-xs font-bold shadow-xs transition-all flex items-center gap-1.5 cursor-pointer">
                    <span id="toggle_filter_icon">🔍</span> <span id="toggle_filter_text">Hiện bộ lọc tìm kiếm</span>
                </button>
            </div>

            <div id="filter_container" class="filter-transition <?= $has_filter ? 'show' : '' ?>">
                <form method="GET" class="bg-white p-4 rounded-xl shadow-sm border border-gray-100 space-y-3">
                    <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-3">
                        <div>
                            <label class="block text-[11px] font-bold text-gray-400 uppercase tracking-wider">Lọc theo Kho</label>
                            <select name="f_warehouse" class="mt-1 block w-full rounded-lg border-gray-200 bg-gray-50/50 p-2 border text-xs outline-none focus:bg-white">
                                <option value="">Tất cả kho bãi</option>
                                <?php foreach ($all_warehouses as $w): ?>
                                    <option value="<?= $w['id'] ?>" <?= $filter_warehouse == $w['id'] ? 'selected' : '' ?>><?= htmlspecialchars($w['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div>
                            <label class="block text-[11px] font-bold text-gray-400 uppercase tracking-wider">Lọc theo Hàng Hóa</label>
                            <input type="text" name="f_product_text" value="<?= htmlspecialchars($filter_product_text) ?>" placeholder="Nhập tên hoặc mã sản phẩm..." class="mt-1 block w-full rounded-lg border-gray-200 bg-gray-50/50 p-2 border text-xs outline-none focus:bg-white transition-all">
                        </div>

                        <div>
                            <label class="block text-[11px] font-bold text-gray-400 uppercase tracking-wider">Người lập phiếu</label>
                            <input type="text" name="f_created_by" value="<?= htmlspecialchars($filter_created_by) ?>" placeholder="Tên nhân viên..." class="mt-1 block w-full rounded-lg border-gray-200 bg-gray-50/50 p-2 border text-xs outline-none focus:bg-white">
                        </div>
                        <div>
                            <label class="block text-[11px] font-bold text-gray-400 uppercase tracking-wider">Từ ngày</label>
                            <input type="date" name="f_start_date" value="<?= htmlspecialchars($filter_start_date) ?>" class="mt-1 block w-full rounded-lg border-gray-200 bg-gray-50/50 p-2 border text-xs outline-none focus:bg-white">
                        </div>
                        <div>
                            <label class="block text-[11px] font-bold text-gray-400 uppercase tracking-wider">Đến ngày</label>
                            <input type="date" name="f_end_date" value="<?= htmlspecialchars($filter_end_date) ?>" class="mt-1 block w-full rounded-lg border-gray-200 bg-gray-50/50 p-2 border text-xs outline-none focus:bg-white">
                        </div>
                        <div>
                            <label class="block text-[11px] font-bold text-gray-400 uppercase tracking-wider">Người giao / nhận</label>
                            <input type="text" name="f_contact_person" value="<?= htmlspecialchars($filter_contact_person) ?>" placeholder="Tên đối tác..." class="mt-1 block w-full rounded-lg border-gray-200 bg-gray-50/50 p-2 border text-xs outline-none focus:bg-white">
                        </div>
                    </div>

                    <div class="flex justify-end gap-2 pt-2 border-t border-gray-50">
                        <?php if ($has_filter): ?>
                            <a href="vouchers.php" class="bg-gray-100 hover:bg-gray-200 text-gray-600 px-4 py-2 rounded-lg text-xs font-bold transition-all flex items-center">✕ Xóa bộ lọc</a>
                        <?php endif; ?>
                        <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-5 py-2 rounded-lg text-xs font-bold tracking-wider uppercase transition-all shadow-sm cursor-pointer">🔍 Áp dụng bộ lọc</button>
                    </div>
                </form>
            </div>

            <div class="space-y-2">
                <h2 class="text-sm font-bold text-gray-800 tracking-tight px-1">Nhật Ký Kho Hàng</h2>

                <?php if (empty($grouped_vouchers)): ?>
                    <div class="bg-white rounded-2xl p-12 border border-gray-100 shadow-sm text-center">
                        <div class="text-3xl mb-2">📁</div>
                        <p class="text-xs font-bold text-gray-400 uppercase">Không tìm thấy dữ liệu chứng từ phù hợp</p>
                    </div>
                <?php else: ?>
                    <div class="max-h-[600px] overflow-y-auto pr-1 space-y-2 custom-scrollbar">
                        <?php foreach ($grouped_vouchers as $voucher): ?>
                            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden hover:shadow-md transition-all">
                                <div class="bg-gray-50/40 p-4 border-b border-gray-100 flex flex-wrap items-center justify-between gap-3">
                                    <div class="flex items-center gap-3">
                                        <span class="text-xs font-bold px-2.5 py-1 rounded-md tracking-wide uppercase <?= $voucher['type'] === 'import' ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700' ?>">
                                            <?= $voucher['type'] === 'import' ? '📥 Nhập' : '📤 Xuất' ?>
                                        </span>
                                        <div>
                                            <h3 class="text-xs font-mono font-bold text-blue-800 tracking-wide uppercase"><?= htmlspecialchars($voucher['ticket_code']) ?></h3>
                                            <p class="text-[10px] text-gray-400 mt-0.5 font-medium">📅 Ngày lập: <?= date('d/m/Y', strtotime($voucher['date_issued'])) ?> | 🏢 Kho: <span class="text-gray-600 font-semibold"><?= htmlspecialchars($voucher['warehouse_name']) ?></span></p>
                                        </div>
                                    </div>
                                    <div class="flex items-center gap-2">
                                        <a href="vouchers.php?edit_id=<?= $voucher['id'] ?>" class="bg-white hover:bg-yellow-50 text-yellow-600 p-1.5 rounded-lg border border-gray-200 shadow-xs text-xs font-semibold transition-all" title="Chỉnh sửa chứng từ">✏️ Sửa</a>
                                        <a href="vouchers.php?delete_id=<?= $voucher['id'] ?>" onclick="return confirm('⚠️ HÀNH ĐỘNG KHÔNG THỂ PHỤC HỒI!\n\nBạn có chắc chắn muốn xóa toàn bộ chứng từ số [<?= htmlspecialchars($voucher['ticket_code']) ?>] khỏi hệ thống không?')" class="bg-white hover:bg-red-50 text-red-600 p-1.5 rounded-lg border border-gray-200 shadow-xs text-xs font-semibold transition-all" title="Xóa chứng từ">🗑️ Xóa</a>
                                    </div>
                                </div>
                                <div class="p-4">
                                    <div class="overflow-x-auto">
                                        <table class="min-w-full divide-y divide-gray-100 text-left">
                                            <thead>
                                                <tr class="bg-gray-50/10">
                                                    <th class="py-2 text-[10px] font-bold text-gray-400 uppercase tracking-wider">Tên vật tư hàng hóa</th>
                                                    <th class="py-2 text-[10px] font-bold text-gray-400 uppercase tracking-wider">Lô SX</th>
                                                    <th class="py-2 text-[10px] font-bold text-gray-400 uppercase tracking-wider">Hạn dùng</th>
                                                    <th class="py-2 text-[10px] font-bold text-gray-400 uppercase tracking-wider text-right">Số lượng</th>
                                                </tr>
                                            </thead>
                                            <tbody class="divide-y divide-gray-50 text-xs">
                                                <?php foreach ($voucher['items'] as $item): ?>
                                                    <tr>
                                                        <td class="py-2.5 font-medium text-gray-800">
                                                            <?= htmlspecialchars($item['product_name']) ?>
                                                            <span class="block text-[10px] text-gray-400 font-mono">Mã: <?= htmlspecialchars($item['product_code']) ?></span>
                                                        </td>
                                                        <td class="py-2.5 font-mono text-gray-500 uppercase tracking-wider"><?= !empty($item['lot_number']) ? htmlspecialchars($item['lot_number']) : '-' ?></td>
                                                        <td class="py-2.5 text-gray-500"><?= date('d/m/Y', strtotime($item['expiry_date'])) ?></td>
                                                        <td class="py-2.5 text-right font-bold text-gray-700"><?= number_format($item['quantity']) ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>

                                    <div class="mt-4 pt-3 border-t border-gray-100 flex flex-col sm:flex-row sm:items-center justify-between gap-3 text-[11px]">
                                        <div class="space-y-1 text-gray-500 max-w-lg">
                                            <?php if (!empty($voucher['note'])): ?>
                                                <p class="italic"><b>📝 Diễn giải:</b> <?= htmlspecialchars($voucher['note']) ?></p>
                                            <?php endif; ?>
                                            <div class="flex flex-wrap gap-x-4 gap-y-1 text-gray-400">
                                                <?php if (!empty($voucher['created_by'])): ?>
                                                    <span><b>👤 Người lập:</b> <span class="text-gray-600 font-medium"><?= htmlspecialchars($voucher['created_by']) ?></span></span>
                                                <?php endif; ?>
                                                <?php if (!empty($voucher['contact_person'])): ?>
                                                    <span><b>🚚 Đối tác giao/nhận:</b> <span class="text-gray-600 font-medium"><?= htmlspecialchars($voucher['contact_person']) ?></span></span>
                                                <?php endif; ?>
                                            </div>
                                        </div>

                                        <?php if (!empty($voucher['voucher_image'])): ?>
                                            <div class="flex-shrink-0 flex items-center gap-2 bg-gray-50 p-1.5 rounded-xl border border-gray-100">
                                                <?php
                                                // Khai báo sẵn domain public chứa ảnh của bạn
                                                $r2_public_domain = "https://pub-653f2eecbee141b2a9ed804cbf5196d9.r2.dev/";
                                                // Ghép nối domain với tên file lấy ra từ Database
                                                $full_image_url = $r2_public_domain . $voucher['voucher_image'];
                                                ?>

                                                <img src="<?= htmlspecialchars($full_image_url) ?>"
                                                    onclick="openLightbox('<?= htmlspecialchars($full_image_url) ?>')"
                                                    class="w-10 h-10 object-cover rounded-lg cursor-zoom-in hover:opacity-80 transition-opacity"
                                                    title="Bấm để xem phóng to ảnh hóa đơn gốc">
                                                <span class="text-[10px] text-gray-400 pr-1 font-medium">Ảnh đính kèm</span>
                                            </div>
                                        <?php endif; ?>
                                    </div>

                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <?php if ($total_pages > 1): ?>
                <div class="bg-white p-4 rounded-xl border border-gray-100 shadow-sm flex flex-col sm:flex-row items-center justify-between gap-4 text-xs">
                    <div class="text-gray-500 font-medium">
                        Hiển thị từ chứng từ thứ <b><?= $total_vouchers > 0 ? $offset + 1 : 0 ?></b> đến <b><?= min($offset + $limit, $total_vouchers) ?></b> trong tổng số <b><?= $total_vouchers ?></b> chứng từ
                    </div>

                    <div class="inline-flex items-center gap-1 bg-white p-1">
                        <?php $query_params = $_GET; ?>

                        <?php if ($page > 1): $query_params['page'] = $page - 1; ?>
                            <a href="vouchers.php?<?= http_build_query($query_params) ?>" class="px-3 py-2 text-gray-500 bg-white hover:bg-gray-50 border border-gray-200 rounded-lg font-medium transition-colors">Trước</a>
                        <?php else: ?>
                            <span class="px-3 py-2 text-gray-300 bg-gray-50/70 border border-gray-100 rounded-lg cursor-not-allowed">Trước</span>
                        <?php endif; ?>

                        <?php
                        $range = 2;
                        for ($i = 1; $i <= $total_pages; $i++):
                            if ($i == 1 || $i == $total_pages || ($i >= $page - $range && $i <= $page + $range)):
                                $query_params['page'] = $i;
                                if ($i === $page):
                        ?>
                                    <span class="px-3 py-2 text-white bg-blue-600 border border-blue-600 rounded-lg font-bold shadow-xs"><?= $i ?></span>
                                <?php else: ?>
                                    <a href="vouchers.php?<?= http_build_query($query_params) ?>" class="px-3 py-2 text-gray-600 bg-white hover:bg-gray-50 border border-gray-200 rounded-lg font-semibold transition-colors"><?= $i ?></a>
                        <?php
                                endif;
                            elseif ($i == 2 || $i == $total_pages - 1):
                                echo '<span class="px-2 text-gray-400 font-medium">...</span>';
                            endif;
                        endfor;
                        ?>

                        <?php if ($page < $total_pages): $query_params['page'] = $page + 1; ?>
                            <a href="vouchers.php?<?= http_build_query($query_params) ?>" class="px-3 py-2 text-gray-500 bg-white hover:bg-gray-50 border border-gray-200 rounded-lg font-medium transition-colors">Sau</a>
                        <?php else: ?>
                            <span class="px-3 py-2 text-gray-300 bg-gray-50/70 border border-gray-100 rounded-lg cursor-not-allowed">Sau</span>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

        </div>
    </div>
</div>

<div id="lightbox_modal" onclick="closeLightbox()" class="fixed inset-0 bg-black/80 backdrop-blur-xs z-50 hidden flex items-center justify-center p-4 transition-all duration-300">
    <div class="relative max-w-3xl max-h-full" onclick="event.stopPropagation()">
        <button onclick="closeLightbox()" class="absolute -top-10 right-0 text-white text-xl font-bold bg-white/10 hover:bg-white/20 w-8 h-8 rounded-full flex items-center justify-center transition-colors">✕</button>
        <img id="lightbox_img" src="#" class="max-w-full max-h-[85vh] rounded-xl object-contain shadow-2xl border border-white/10">
    </div>
</div>

<script>
    function updateFilterButtonState() {
        const filterContainer = document.getElementById('filter_container');
        const btnText = document.getElementById('toggle_filter_text');
        const btnIcon = document.getElementById('toggle_filter_icon');

        if (filterContainer && filterContainer.classList.contains('show')) {
            if (btnText) btnText.innerText = 'Ẩn bộ lọc tìm kiếm';
            if (btnIcon) btnIcon.innerText = '🔼';
        } else {
            if (btnText) btnText.innerText = 'Hiện bộ lọc tìm kiếm';
            if (btnIcon) btnIcon.innerText = '🔍';
        }
    }

    const toggleFilterBtn = document.getElementById('toggle_filter_btn');
    if (toggleFilterBtn) {
        updateFilterButtonState();
        toggleFilterBtn.addEventListener('click', function() {
            const filterContainer = document.getElementById('filter_container');
            if (filterContainer) {
                filterContainer.classList.toggle('show');
                updateFilterButtonState();
            }
        });
    }

    const toast = document.getElementById('toast_alert');
    const countdownNum = document.getElementById('countdown_num');
    if (toast) {
        let timeLeft = 5;
        const interval = setInterval(() => {
            timeLeft--;
            if (countdownNum) countdownNum.innerText = timeLeft;
            if (timeLeft <= 0) {
                clearInterval(interval);
                closeToast();
            }
        }, 1000);
    }

    function closeToast() {
        if (toast) {
            toast.classList.add('fade-out');
            setTimeout(() => {
                toast.remove();
            }, 400);
        }
    }

    function initProductSearchAutocomplete(containerElement) {
        const inputField = containerElement.querySelector('.search-product-input');
        const hiddenIdField = containerElement.querySelector('.product-hidden-id');
        const suggestionsBox = containerElement.querySelector('.search-suggestions-box');
        const items = suggestionsBox.querySelectorAll('.suggestion-item');
        const noSuggestionsItem = suggestionsBox.querySelector('.no-suggestions-item');

        if (!inputField || !suggestionsBox) return;

        inputField.addEventListener('focus', function() {
            document.querySelectorAll('.search-suggestions-box').forEach(box => box.classList.add('hidden'));
            suggestionsBox.classList.remove('hidden');
            filterItems(this.value);
        });

        inputField.addEventListener('input', function() {
            suggestionsBox.classList.remove('hidden');
            filterItems(this.value);
        });

        function filterItems(keyword) {
            const cleanKeyword = keyword.trim().toLowerCase();
            let hasVisible = false;

            items.forEach(item => {
                const searchStr = item.getAttribute('data-search') || '';
                if (cleanKeyword === '' || searchStr.includes(cleanKeyword)) {
                    item.classList.remove('hidden');
                    hasVisible = true;
                } else {
                    item.classList.add('hidden');
                }
            });

            if (hasVisible) {
                if (noSuggestionsItem) noSuggestionsItem.classList.add('hidden');
            } else {
                if (noSuggestionsItem) noSuggestionsItem.classList.remove('hidden');
            }
        }

        document.addEventListener('click', function(e) {
            if (!containerElement.contains(e.target)) {
                suggestionsBox.classList.add('hidden');
            }
        });

        suggestionsBox.addEventListener('click', function(e) {
            const clickedItem = e.target.closest('.suggestion-item');
            if (clickedItem) {
                const pId = clickedItem.getAttribute('data-id');
                const pText = clickedItem.innerText.trim();

                inputField.value = pText;
                hiddenIdField.value = pId;
                suggestionsBox.classList.add('hidden');
            }
        });
    }

    document.querySelectorAll('.search-product-container').forEach(container => {
        initProductSearchAutocomplete(container);
    });

    const container = document.getElementById('product_rows_container');
    const addRowBtn = document.getElementById('add_row_btn');

    if (addRowBtn && container) {
        addRowBtn.addEventListener('click', function() {
            const rows = container.getElementsByClassName('product-row');
            const newIndex = rows.length;

            const firstRow = rows[0];
            const newRow = firstRow.cloneNode(true);

            newRow.classList.add('pt-8', 'border-t', 'border-gray-100');

            const oldDelBtn = newRow.querySelector('.remove-row-btn');
            if (oldDelBtn) oldDelBtn.remove();

            const delBtn = document.createElement('button');
            delBtn.type = 'button';
            delBtn.className = 'remove-row-btn absolute top-2 right-3 text-gray-400 hover:text-red-500 text-[11px] font-bold cursor-pointer';
            delBtn.innerText = '✕ Gỡ dòng';
            newRow.appendChild(delBtn);

            const hiddenInput = newRow.querySelector('.product-hidden-id');
            if (hiddenInput) {
                hiddenInput.name = `products[${newIndex}][product_id]`;
                hiddenInput.value = '0';
            }

            const txtInput = newRow.querySelector('.search-product-input');
            if (txtInput) txtInput.value = '';

            const lotInput = newRow.querySelector('[name*="[lot_number]"]');
            if (lotInput) {
                lotInput.name = `products[${newIndex}][lot_number]`;
                lotInput.value = '';
            }

            const expInput = newRow.querySelector('[name*="[expiry_date]"]');
            if (expInput) {
                expInput.name = `products[${newIndex}][expiry_date]`;
                expInput.value = '';
            }

            const qtyInput = newRow.querySelector('[name*="[quantity]"]');
            if (qtyInput) {
                qtyInput.name = `products[${newIndex}][quantity]`;
                qtyInput.value = '';
            }

            const toggBox = newRow.querySelector('.search-suggestions-box');
            if (toggBox) toggBox.classList.add('hidden');

            container.appendChild(newRow);

            const newSearchContainer = newRow.querySelector('.search-product-container');
            initProductSearchAutocomplete(newSearchContainer);

            container.scrollTop = container.scrollHeight;
        });
    }

    if (container) {
        container.addEventListener('click', function(e) {
            if (e.target && e.target.classList.contains('remove-row-btn')) {
                const row = e.target.closest('.product-row');
                if (row) {
                    row.remove();
                }
            }
        });
    }

    // --- LUỒNG XỬ LÝ CHỌN ẢNH, NÉN CANVAS & TRUYỀN TẢI CLIENT-SIDE THẲNG LÊN R2 CLOUD ---
const imgInput = document.getElementById('img_input_field');
const imgPlaceholder = document.getElementById('img_placeholder_view');
const imgPreviewContainer = document.getElementById('img_preview_container');
const imgPreviewTag = document.getElementById('img_preview_tag');
const removePreviewBtn = document.getElementById('remove_preview_img_btn');
const removeImgFlag = document.getElementById('remove_current_image_flag');
const clientUploadedImageUrl = document.getElementById('client_uploaded_image_url');

if (imgInput) {
    imgInput.addEventListener('change', async function() {
        const file = this.files[0];
        if (!file) return;

        // Lưu lại dung lượng file gốc để tính toán tỷ lệ nén
        const originalSizeInKB = (file.size / 1024).toFixed(2);

        if (removeImgFlag) removeImgFlag.value = "0"; 

        try {
            // 1. Đọc file ảnh gốc nạp vào đối tượng Image
            const img = new Image();
            img.src = URL.createObjectURL(file);
            await new Promise((resolve, reject) => {
                img.onload = resolve;
                img.onerror = () => reject(new Error("Không thể đọc định dạng file ảnh này!"));
            });

            // 2. Tạo Canvas để resize hạ kích thước ảnh xuống tối đa 1200px
            const canvas = document.createElement('canvas');
            let width = img.width;
            let height = img.height;
            const maxSize = 1200; 

            if (width > height && width > maxSize) { 
                height *= maxSize / width; 
                width = maxSize; 
            } else if (height > maxSize) { 
                width *= maxSize / height; 
                height = maxSize; 
            }

            canvas.width = width;
            canvas.height = height;
            const ctx = canvas.getContext('2d');
            ctx.drawImage(img, 0, 0, width, height);

            // 3. Nén ảnh sang định dạng nhị phân BLOB (JPEG - Chất lượng 85%)
            const blob = await new Promise((resolve) => {
                canvas.toBlob(resolve, 'image/jpeg', 0.85); 
            });

            if (!blob || blob.size === 0) {
                throw new Error("Quá trình nén hình ảnh gặp lỗi!");
            }

            // In thông số kiểm tra nén ảnh ra Console (F12)
            const compressedSizeInKB = (blob.size / 1024).toFixed(2);
            const savedPercentage = (((file.size - blob.size) / file.size) * 100).toFixed(1);

            console.log("=== KIỂM TRA TRẠNG THÁI NÉN ẢNH ===");
            console.log("- Ảnh gốc: " + file.name + " (" + originalSizeInKB + " KB)");
            console.log("- Kích thước sau Canvas: " + canvas.width + "x" + canvas.height + " px");
            console.log("- Dung lượng sau khi nén: " + compressedSizeInKB + " KB");
            if (file.size > blob.size) {
                console.log(`=> KẾT QUẢ: Đã nén thành công! Tiết kiệm được ${savedPercentage}% dung lượng.`);
            } else {
                console.log("=> KẾT QUẢ: Giữ nguyên dung lượng (Ảnh gốc đã tối ưu).");
            }
            console.log("====================================");

            // 4. LẤY TRỰC TIẾP TÊN FILE ẢNH GỐC CỦA NGƯỜI DÙNG (ĐÃ BỎ KIỂM TRA MÃ CHỨNG TỪ)
            let originalName = file.name; // Ví dụ: "hinh 1.jpg"
            let nameWithoutExt = originalName.substring(0, originalName.lastIndexOf('.'));
            
            // Chuẩn hóa tên file: bỏ dấu tiếng Việt, thay khoảng trắng và ký tự lạ bằng dấu gạch dưới '_'
            let cleanName = nameWithoutExt
                .normalize('NFD').replace(/[\u0300-\u036f]/g, '') // Bỏ dấu tiếng Việt
                .replace(/[^A-Za-z0-9_]/g, '_')                    // Thay khoảng trắng/ký tự lạ bằng '_'
                .replace(/_+/g, '_');                             // Tránh lặp lại ký tự '__'
                
            // Đuôi file cố định là .jpg vì Canvas xuất ra định dạng 'image/jpeg'
            const r2FileName = cleanName + ".jpg"; 

            // 5. Gọi API php lấy link upload trung gian (Presigned URL)
            const preRes = await fetch(`r2_api.php?action=get-presigned-url&file=${encodeURIComponent(r2FileName)}&type=${encodeURIComponent(blob.type)}`);
            
            const rawText = await preRes.text();
            let preData;
            try {
                preData = JSON.parse(rawText);
            } catch(jsonErr) {
                console.error("Dữ liệu lỗi từ PHP:", rawText);
                throw new Error("Không thể xử lý dữ liệu JSON từ máy chủ.");
            }

            if (!preData || !preData.upload_url) {
                throw new Error(preData.error || "Không nhận được liên kết hợp lệ từ máy chủ.");
            }

            // 6. Thực hiện đẩy trực tiếp khối blob lên link Cloudflare R2
            const uploadRes = await fetch(preData.upload_url, {
                method: 'PUT',
                body: blob, 
                headers: { 'Content-Type': blob.type }
            });

            if (!uploadRes.ok) {
                throw new Error(`Cloudflare từ chối tải file. Mã lỗi: ${uploadRes.status}`);
            }

            // 7. CHỈ LƯU TÊN HÌNH ẢNH SẠCH VÀO DATABASE (Ví dụ: hinh_1.jpg)
            if (clientUploadedImageUrl) {
                clientUploadedImageUrl.value = r2FileName; 
            }

            // Cập nhật hiển thị giao diện xem trước ảnh ngay lập tức bằng link Public r2.dev
            const publicR2Url = "https://pub-653f2eecbee141b2a9ed804cbf5196d9.r2.dev/" + r2FileName;
            imgPreviewTag.src = publicR2Url;
            imgPlaceholder.classList.add('hidden');
            imgPreviewContainer.classList.remove('hidden');

        } catch (err) {
            console.error("Lỗi hệ thống luồng R2:", err);
            alert("🔴 Lỗi tải ảnh: " + err.message);
            
            // Reset lại giao diện nếu có lỗi
            this.value = ''; 
            if (clientUploadedImageUrl) clientUploadedImageUrl.value = '';
            imgPreviewTag.src = '#';
            imgPreviewContainer.classList.add('hidden');
            imgPlaceholder.classList.remove('hidden');
        }
    });
}

    // Luồng hiển thị hộp thoại phóng to ảnh hóa đơn gốc (Lightbox Modal)
    const lightboxModal = document.getElementById('lightbox_modal');
    const lightboxImg = document.getElementById('lightbox_img');

    if (lightboxModal && lightboxImg) {
        window.openLightbox = function(imgSrc) {
            lightboxImg.src = imgSrc;
            lightboxModal.classList.remove('hidden');
            document.body.style.overflow = 'hidden';
        }

        window.closeLightbox = function() {
            lightboxModal.classList.add('hidden');
            document.body.style.overflow = '';
        }
    }

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            if (typeof window.closeLightbox === 'function') window.closeLightbox();
        }
    });
</script>
</main>
</body>

</html>