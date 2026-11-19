<?php
// Bắt đầu Session để lưu trữ thông báo sau khi chuyển hướng trang (Redirect)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require 'db.php';
// Nhúng file r2_api.php để lấy cấu hình $r2_config và class R2Manager khi cần xóa ảnh
require 'r2_api.php';
global $r2_config; // Khai báo global để đảm bảo toàn bộ file nhận diện được cấu hình R2

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

// 1. TẢI DỮ LIỆU ĐỂ SỬA (Giải mã JSON từ cột details)
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

// 2. XỬ LÝ XÓA CHỨNG TỪ (Đồng bộ xóa ảnh trên Cloudflare R2)
if (isset($_GET['delete_id'])) {
    $delete_id = $_GET['delete_id'];
    try {
        $pdo->beginTransaction();
        
        // Lấy đường dẫn ảnh cũ để thực hiện xóa trên R2
        $stmt_img = $pdo->prepare("SELECT voucher_image, ticket_code FROM documents WHERE id = ?");
        $stmt_img->execute([$delete_id]);
        $row_del = $stmt_img->fetch();
        
        if ($row_del) {
            $old_img = $row_del['voucher_image'];
            $del_code = $row_del['ticket_code'];
            
            if (!empty($old_img)) {
                $public_path_prefix = rtrim($r2_config['public_path'], '/') . '/';
                if (strpos($old_img, $public_path_prefix) === 0) {
                    $object_key = str_replace($public_path_prefix, '', $old_img);
                    
                    // Khởi tạo R2Manager gọi API xóa file trực tiếp
                    $r2_del = new R2Manager(
                        $r2_config['access_key'],
                        $r2_config['secret_key'],
                        $r2_config['account_id'],
                        $r2_config['bucket'],
                        $r2_config
                    );
                    $r2_del->deleteFile($object_key);
                }
            }

            // Xóa dòng dữ liệu trong CSDL
            $stmt = $pdo->prepare("DELETE FROM documents WHERE id = ?");
            $stmt->execute([$delete_id]);
            
            $pdo->commit();
            $_SESSION['flash_success'] = "🗑️ Đã xóa thành công chứng từ số: <b>{$del_code}</b>!";
        } else {
            $pdo->rollBack();
        }
        
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
    
    // Nhận link ảnh R2 đã được JavaScript xử lý up trực tiếp gửi về qua input hidden
    $voucher_image = trim($_POST['r2_image_url'] ?? '');

    // Mảng dữ liệu sản phẩm từ form gửi lên
    $products_input = $_POST['products'] ?? [];

    if (!empty($ticket_code) && !empty($date_issued) && !empty($warehouse_id) && !empty($products_input)) {

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

        // KIỂM TRA TỒN KHO TRƯỚC (Nếu là phiếu XUẤT)
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

        // Kiểm tra trùng mã phiếu
        if (empty($error)) {
            $check_code_sql = "SELECT COUNT(*) FROM documents WHERE ticket_code = ?";
            if ($is_edit) {
                $check_code_sql .= " AND id != " . (int)$edit_id;
            }
            $check_code_stmt = $pdo->prepare($check_code_sql);
            $check_code_stmt->execute([$ticket_code]);
            if ($check_code_stmt->fetchColumn() > 0) {
                $error = "Mã chứng từ <b>{$ticket_code}</b> đã tồn tại trên hệ thống!";
            }
        }

        // TIẾN HÀNH LƯU DATABASE
        if (empty($error)) {
            try {
                $pdo->beginTransaction();
                $json_details = json_encode($clean_details, JSON_UNESCAPED_UNICODE);

                if ($is_edit) {
                    // Nếu không tải ảnh mới lên thì giữ lại ảnh cũ
                    if (empty($voucher_image)) {
                        $voucher_image = $edit_doc['voucher_image'];
                    } else {
                        // Nếu có ảnh mới thay thế, tiến hành dọn dẹp xóa file ảnh cũ trên Cloudflare R2
                        if (!empty($edit_doc['voucher_image'])) {
                            $public_path_prefix = rtrim($r2_config['public_path'], '/') . '/';
                            if (strpos($edit_doc['voucher_image'], $public_path_prefix) === 0) {
                                $old_key = str_replace($public_path_prefix, '', $edit_doc['voucher_image']);
                                $r2_del = new R2Manager($r2_config['access_key'], $r2_config['secret_key'], $r2_config['account_id'], $r2_config['bucket'], $r2_config);
                                $r2_del->deleteFile($old_key);
                            }
                        }
                    }

                    $stmt = $pdo->prepare("UPDATE documents SET ticket_code = ?, type = ?, warehouse_id = ?, date_issued = ?, created_by = ?, contact_person = ?, voucher_image = ?, note = ?, details = ? WHERE id = ?");
                    $stmt->execute([$ticket_code, $type, $warehouse_id, $date_issued, $created_by, $contact_person, $voucher_image, $note, $json_details, $edit_id]);
                    $_SESSION['flash_success'] = "📝 Đã cập nhật thành công chứng từ số: <b>{$ticket_code}</b>!";
                } else {
                    $stmt = $pdo->prepare("INSERT INTO documents (ticket_code, type, warehouse_id, date_issued, created_by, contact_person, voucher_image, note, details) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$ticket_code, $type, $warehouse_id, $date_issued, $created_by, $contact_person, $voucher_image, $note, $json_details]);
                    $_SESSION['flash_success'] = "✨ Đã tạo mới thành công chứng từ số: <b>{$ticket_code}</b>!";
                }

                $pdo->commit();
                header("Location: vouchers.php");
                exit;
            } catch (Exception $e) {
                $pdo->rollBack();
                $error = "Đã xảy ra lỗi hệ thống: " . $e->getMessage();
            }
        }
    } else {
        $error = "Vui lòng điền đầy đủ thông tin chứng từ!";
    }
}

// Tải dữ liệu danh mục đổ vào form
$all_products = $pdo->query("SELECT id, name, code FROM products ORDER BY name ASC")->fetchAll();
$all_warehouses = $pdo->query("SELECT * FROM warehouses ORDER BY name ASC")->fetchAll();

// NHẬN DỮ LIỆU CÁC BỘ LỌC
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

// PHÂN TRANG
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
    @keyframes shrink { from { width: 100%; } to { width: 0%; } }
    .progress-bar-timer { animation: shrink 5s linear forwards; }
    .alert-toast { transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1); }
    .alert-toast.fade-out { opacity: 0; transform: translateY(-20px); }
    .custom-scrollbar::-webkit-scrollbar { width: 6px; }
    .custom-scrollbar::-webkit-scrollbar-track { background: #f1f1f1; border-radius: 8px; }
    .custom-scrollbar::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 8px; }
    .filter-transition { transition: all 0.3s ease-in-out; max-height: 0; opacity: 0; overflow: hidden; }
    .filter-transition.show { max-height: 500px; opacity: 1; overflow: visible; }
    #loading-modal { background: rgba(0,0,0,0.5); backdrop-filter: blur(4px); }
</style>

<div id="loading-modal" class="hidden fixed inset-0 z-50 flex flex-col items-center justify-center text-white font-medium text-xs">
    <div class="bg-gray-900/90 p-6 rounded-2xl flex flex-col items-center gap-3 shadow-2xl border border-gray-800">
        <svg class="animate-spin h-7 w-7 text-blue-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
        </svg>
        <span>Đang nén tối ưu & đồng bộ ảnh lên Cloudflare R2...</span>
    </div>
</div>

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
                <button type="button" onclick="closeToast()" class="text-red-400 hover:text-red-700 text-xs font-bold px-2 focus:outline-none">✕</button>
            </div>
            <div class="absolute bottom-0 left-0 h-1 bg-green-500 progress-bar-timer"></div>
        </div>
    <?php endif; ?>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
        
        <div class="lg:col-span-1">
            <form method="POST" id="voucher_form" class="bg-white p-5 rounded-2xl shadow-sm border border-gray-100 sticky top-6">
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
                            <input type="text" name="ticket_code" placeholder="VD: PX-100111" required value="<?= $is_edit ? htmlspecialchars($edit_doc['ticket_code']) : htmlspecialchars($_POST['ticket_code'] ?? '') ?>" class="mt-1 block w-full rounded-lg border-gray-200 bg-gray-50/50 p-2 border text-xs font-mono font-bold text-blue-700 outline-none uppercase">
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
                                    <input type="text" placeholder="Gõ tên hoặc mã vật tư để tìm..." autocomplete="off" value="<?= htmlspecialchars($current_product_text) ?>" class="search-product-input mt-1 block w-full rounded-lg border-gray-200 bg-gray-50/50 p-2 border text-xs outline-none focus:bg-white focus:ring-1 focus:ring-blue-500 transition-all font-medium">
                                    <input type="hidden" name="products[<?= $index ?>][product_id]" value="<?= $current_product_id ?>" class="product-hidden-id">
                                    
                                    <div class="search-suggestions-box hidden absolute left-0 right-0 mt-1 max-h-50 overflow-y-auto bg-white border border-gray-200 rounded-lg shadow-xl z-30 divide-y divide-gray-50 custom-scrollbar">
                                        <?php foreach ($all_products as $p): ?>
                                            <div data-id="<?= $p['id'] ?>" data-search="<?= htmlspecialchars(strtolower($p['code'] . ' ' . $p['name'])) ?>" class="suggestion-item p-2 text-xs text-gray-700 hover:bg-blue-50 hover:text-blue-700 font-medium cursor-pointer transition-colors">
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
                    <label class="block text-[11px] font-bold text-gray-400 uppercase tracking-wider mb-1">Hình ảnh phiếu xuất / nhập đính kèm (R2 Cloud)</label>
                    <div class="relative border border-dashed border-gray-300 bg-gray-50/30 rounded-xl p-3 text-center hover:border-blue-400 transition-all flex flex-col items-center justify-center min-h-[90px]">
                        <input type="file" id="img_input_field" accept="image/*" class="absolute inset-0 w-full h-full opacity-0 cursor-pointer z-10">
                        
                        <input type="hidden" name="r2_image_url" id="r2_image_url" value="<?= $is_edit ? htmlspecialchars($edit_doc['voucher_image']) : '' ?>">

                        <div id="img_placeholder_view" class="space-y-1 <?= ($is_edit && !empty($edit_doc['voucher_image'])) ? 'hidden' : '' ?>">
                            <div class="text-xl text-gray-400">📸</div>
                            <p class="text-[11px] font-semibold text-gray-500">Nhấp hoặc kéo thả ảnh hóa đơn vào đây</p>
                            <p class="text-[9px] text-gray-400 font-normal">Tự động nén tối ưu & đẩy trực tiếp lên Cloudflare R2</p>
                        </div>

                        <div id="img_preview_container" class="relative group <?= ($is_edit && !empty($edit_doc['voucher_image'])) ? '' : 'hidden' ?> z-20 w-full">
                            <img id="img_preview_tag" src="<?= ($is_edit && !empty($edit_doc['voucher_image'])) ? htmlspecialchars($edit_doc['voucher_image']) : '#' ?>" class="mx-auto max-h-[140px] rounded-lg shadow-sm border border-gray-100 object-contain">
                            <button type="button" id="remove_preview_btn" class="absolute -top-2 -right-2 bg-red-500 text-white hover:bg-red-600 rounded-full w-5 h-5 flex items-center justify-center text-[10px] font-bold shadow focus:outline-none transition-all">
                                ✕
                            </button>
                        </div>
                    </div>
                </div>

                <div class="border-t border-gray-100 pt-3 mb-5">
                    <label class="block text-[11px] font-bold text-gray-400 uppercase tracking-wider">Ghi chú nội bộ</label>
                    <textarea name="note" rows="2" placeholder="Nhập lý do xuất nhập kho hoặc thông tin bổ sung..." class="mt-1 block w-full rounded-lg border-gray-200 bg-gray-50/50 p-2 border text-xs outline-none focus:bg-white transition-all resize-none"><?= $is_edit ? htmlspecialchars($edit_doc['note'] ?? '') : htmlspecialchars($_POST['note'] ?? '') ?></textarea>
                </div>

                <button type="submit" name="add_voucher" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-bold p-3 rounded-xl text-xs shadow-sm transition-all flex items-center justify-center gap-2 cursor-pointer">
                    <?= $is_edit ? '💾 CẬP NHẬT CHỨNG TỪ' : '✨ HOÀN TẤT LẬP PHIẾU' ?>
                </button>
            </form>
        </div>

        <div class="lg:col-span-2 space-y-4">
            <div class="bg-white p-4 rounded-2xl shadow-sm border border-gray-100">
                <div class="flex items-center justify-between border-b border-gray-50 pb-3 mb-3">
                    <div class="flex items-center gap-2">
                        <span class="text-sm">🔍</span>
                        <h3 class="text-xs font-bold text-gray-700 uppercase tracking-wider">Bộ lọc tìm kiếm mở rộng</h3>
                    </div>
                    <button type="button" onclick="toggleFilterSection()" class="text-xs text-blue-600 hover:text-blue-800 font-semibold flex items-center gap-1 focus:outline-none">
                        <span id="filter_toggle_icon"><?= $has_filter ? '▲ Thu gọn' : '▼ Mở rộng nâng cao' ?></span>
                    </button>
                </div>

                <form method="GET" id="filter_form" class="filter-transition <?= $has_filter ? 'show' : '' ?> space-y-3">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                        <div>
                            <label class="block text-[10px] font-bold text-gray-400 uppercase tracking-wider">Kho hàng cần lọc</label>
                            <select name="f_warehouse" class="mt-1 block w-full rounded-lg border-gray-200 bg-gray-50/50 p-2 border text-xs outline-none">
                                <option value="">--- Tất cả các kho ---</option>
                                <?php foreach ($all_warehouses as $w): ?>
                                    <option value="<?= $w['id'] ?>" <?= $filter_warehouse == $w['id'] ? 'selected' : '' ?>><?= htmlspecialchars($w['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="block text-[10px] font-bold text-gray-400 uppercase tracking-wider">Tên hoặc mã hàng hóa trong phiếu</label>
                            <input type="text" name="f_product_text" value="<?= htmlspecialchars($filter_product_text) ?>" placeholder="Nhập tên hoặc mã sản phẩm..." class="mt-1 block w-full rounded-lg border-gray-200 bg-gray-50/50 p-2 border text-xs outline-none">
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                        <div>
                            <label class="block text-[10px] font-bold text-gray-400 uppercase tracking-wider">Từ ngày phát hành</label>
                            <input type="date" name="f_start_date" value="<?= htmlspecialchars($filter_start_date) ?>" class="mt-1 block w-full rounded-lg border-gray-200 bg-gray-50/50 p-2 border text-xs outline-none">
                        </div>
                        <div>
                            <label class="block text-[10px] font-bold text-gray-400 uppercase tracking-wider">Đến ngày phát hành</label>
                            <input type="date" name="f_end_date" value="<?= htmlspecialchars($filter_end_date) ?>" class="mt-1 block w-full rounded-lg border-gray-200 bg-gray-50/50 p-2 border text-xs outline-none">
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                        <div>
                            <label class="block text-[10px] font-bold text-gray-400 uppercase tracking-wider">Nhân viên lập</label>
                            <input type="text" name="f_created_by" value="<?= htmlspecialchars($filter_created_by) ?>" placeholder="Tên nhân viên..." class="mt-1 block w-full rounded-lg border-gray-200 bg-gray-50/50 p-2 border text-xs outline-none">
                        </div>
                        <div>
                            <label class="block text-[10px] font-bold text-gray-400 uppercase tracking-wider">Người giao nhận / Đối tác</label>
                            <input type="text" name="f_contact_person" value="<?= htmlspecialchars($filter_contact_person) ?>" placeholder="Tên đối tác bên ngoài..." class="mt-1 block w-full rounded-lg border-gray-200 bg-gray-50/50 p-2 border text-xs outline-none">
                        </div>
                    </div>

                    <div class="flex items-center justify-end gap-2 pt-2 border-t border-gray-50">
                        <?php if ($has_filter): ?>
                            <a href="vouchers.php" class="bg-gray-100 hover:bg-gray-200 text-gray-600 font-bold px-4 py-2 rounded-lg text-xs transition-all">✕ Xóa bộ lọc</a>
                        <?php endif; ?>
                        <button type="submit" class="bg-gray-800 hover:bg-gray-900 text-white font-bold px-5 py-2 rounded-lg text-xs shadow-sm transition-all cursor-pointer">
                            🔍 Tiến Hành Tìm Kiếm
                        </button>
                    </div>
                </form>
            </div>

            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                <div class="p-4 bg-gray-50/50 border-b border-gray-100 flex items-center justify-between">
                    <h3 class="text-xs font-bold text-gray-700 uppercase tracking-wider flex items-center gap-2">
                        📋 Nhật Ký Chứng Từ Hệ Thống <span class="bg-blue-100 text-blue-700 rounded-full px-2 py-0.5 text-[10px] font-extrabold"><?= $total_vouchers ?></span>
                    </h3>
                    <span class="text-[11px] text-gray-400 font-medium">Trang <?= $page ?> / <?= $total_pages ?></span>
                </div>

                <div class="divide-y divide-gray-100 custom-scrollbar overflow-x-auto">
                    <?php if (empty($grouped_vouchers)): ?>
                        <div class="p-12 text-center text-xs text-gray-400 italic bg-white">
                            🕊️ Hệ thống chưa ghi nhận dữ liệu chứng từ nào phù hợp với điều kiện tìm kiếm!
                        </div>
                    <?php else: ?>
                        <?php foreach ($grouped_vouchers as $v): ?>
                            <div class="p-5 hover:bg-gray-50/40 transition-all group">
                                <div class="flex flex-col md:flex-row md:items-start md:justify-between gap-4 mb-4">
                                    <div class="space-y-1">
                                        <div class="flex items-center gap-2 flex-wrap">
                                            <span class="text-xs font-mono font-bold tracking-tight text-blue-700 bg-blue-50 border border-blue-100 px-2 py-0.5 rounded-md">
                                                #<?= htmlspecialchars($v['ticket_code']) ?>
                                            </span>
                                            <?php if ($v['type'] === 'import'): ?>
                                                <span class="bg-green-50 text-green-700 border border-green-100 text-[10px] font-bold px-2 py-0.5 rounded-md flex items-center gap-1">📥 NHẬP KHO</span>
                                            <?php else: ?>
                                                <span class="bg-amber-50 text-amber-700 border border-amber-100 text-[10px] font-bold px-2 py-0.5 rounded-md flex items-center gap-1">📤 XUẤT KHO</span>
                                            <?php endif; ?>
                                            <span class="text-[11px] text-gray-400 font-medium flex items-center gap-1 ml-1">
                                                📅 <?= date('d/m/Y', strtotime($v['date_issued'])) ?>
                                            </span>
                                        </div>
                                        <p class="text-xs font-bold text-gray-800 flex items-center gap-1 pt-1">
                                            🏢 Kho chứa: <span class="text-blue-900 font-extrabold"><?= htmlspecialchars($v['warehouse_name']) ?></span>
                                        </p>
                                        <div class="text-[11px] text-gray-500 font-medium grid grid-cols-1 sm:grid-cols-2 gap-x-4 gap-y-1 pt-1">
                                            <p>👤 Người lập: <span class="font-semibold text-gray-700"><?= htmlspecialchars($v['created_by'] ?: 'N/A') ?></span></p>
                                            <p>🤝 Đối tác giao nhận: <span class="font-semibold text-gray-700"><?= htmlspecialchars($v['contact_person'] ?: 'N/A') ?></span></p>
                                        </div>
                                    </div>

                                    <div class="flex items-center gap-3 md:self-start">
                                        <?php if (!empty($v['voucher_image'])): ?>
                                            <button type="button" onclick="openLightbox('<?= htmlspecialchars($v['voucher_image']) ?>')" class="border border-gray-200 hover:border-blue-400 p-1 rounded-xl bg-white shadow-sm transition-all focus:outline-none flex-shrink-0 cursor-pointer">
                                                <img src="<?= htmlspecialchars($v['voucher_image']) ?>" class="w-12 h-12 rounded-lg object-cover">
                                            </button>
                                        <?php endif; ?>

                                        <div class="flex flex-row md:flex-col gap-1.5">
                                            <a href="vouchers.php?edit_id=<?= $v['id'] ?>" class="text-center bg-white hover:bg-gray-50 text-gray-700 font-bold px-3 py-1.5 rounded-lg border border-gray-200 text-[11px] shadow-xs transition-all">
                                                ⚙️ Sửa
                                            </a>
                                            <a href="vouchers.php?delete_id=<?= $v['id'] ?>" onclick="return confirm('⚠️ Bạn chắc chắn muốn xóa vĩnh viễn chứng từ này?')" class="text-center bg-red-50 hover:bg-red-100 text-red-600 font-bold px-3 py-1.5 rounded-lg text-[11px] transition-all">
                                                🗑️ Xóa
                                            </a>
                                        </div>
                                    </div>
                                </div>

                                <div class="bg-gray-50/50 border border-gray-100 rounded-xl p-3 overflow-hidden">
                                    <table class="w-full text-left text-[11px] border-collapse">
                                        <thead>
                                            <tr class="text-gray-400 font-bold uppercase tracking-wider border-b border-gray-200/60">
                                                <th class="pb-2 font-bold">Tên / Mã sản phẩm vật tư</th>
                                                <th class="pb-2 font-bold text-center">Số lô SX</th>
                                                <th class="pb-2 font-bold text-center">Hạn sử dụng</th>
                                                <th class="pb-2 font-bold text-right">Số lượng</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-gray-100 font-medium text-gray-700">
                                            <?php foreach ($v['items'] as $item): ?>
                                                <tr class="hover:bg-white/40 transition-colors">
                                                    <td class="py-2 pr-2 font-semibold text-gray-900">
                                                        [<?= htmlspecialchars($item['product_code']) ?>] <?= htmlspecialchars($item['product_name']) ?>
                                                    </td>
                                                    <td class="py-2 text-center font-mono text-gray-500 uppercase"><?= htmlspecialchars($item['lot_number'] ?: '---') ?></td>
                                                    <td class="py-2 text-center text-gray-500"><?= !empty($item['expiry_date']) ? date('d/m/Y', strtotime($item['expiry_date'])) : '---' ?></td>
                                                    <td class="py-2 text-right font-bold text-blue-600"><?= number_format($item['quantity']) ?> <span class="text-[10px] text-gray-400 font-normal"><?= htmlspecialchars($item['unit']) ?></span></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>

                                <?php if (!empty($v['note'])): ?>
                                    <div class="mt-2.5 text-[11px] text-gray-400 italic bg-yellow-50/30 border border-dashed border-yellow-100/60 p-2 rounded-lg">
                                        📝 <b>Ghi chú:</b> <?= htmlspecialchars($v['note']) ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <?php if ($total_pages > 1): ?>
                    <div class="p-4 bg-gray-50/30 border-t border-gray-100 flex items-center justify-center gap-1">
                        <a href="?<?= http_build_query(array_merge($_GET, ['page' => max(1, $page - 1)])) ?>" class="p-2 rounded-lg border border-gray-200 bg-white hover:bg-gray-50 text-xs font-bold transition-all <?= $page <= 1 ? 'opacity-40 pointer-events-none' : '' ?>">◀</a>
                        <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                            <a href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>" class="w-8 h-8 flex items-center justify-center rounded-lg text-xs font-bold transition-all <?= $page == $i ? 'bg-blue-600 text-white shadow-sm' : 'border border-gray-200 bg-white hover:bg-gray-50 text-gray-600' ?>">
                                <?= $i ?>
                            </a>
                        <?php endfor; ?>
                        <a href="?<?= http_build_query(array_merge($_GET, ['page' => min($total_pages, $page + 1)])) ?>" class="p-2 rounded-lg border border-gray-200 bg-white hover:bg-gray-50 text-xs font-bold transition-all <?= $page >= $total_pages ? 'opacity-40 pointer-events-none' : '' ?>">▶</a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div id="lightbox_modal" class="hidden fixed inset-0 bg-black/80 backdrop-blur-xs z-50 flex items-center justify-center p-4">
    <div class="relative max-w-4xl max-h-full flex flex-col items-center justify-center">
        <button type="button" onclick="closeLightbox()" class="absolute -top-10 right-0 text-white hover:text-gray-300 text-sm font-bold bg-white/10 hover:bg-white/20 px-3 py-1.5 rounded-xl transition-all focus:outline-none">
            ✕ ĐÓNG KHUNG ẢNH (ESC)
        </button>
        <img id="lightbox_img" src="#" class="max-w-full max-h-[85vh] rounded-2xl shadow-2xl border border-white/10 object-contain">
    </div>
</div>

<script>
    // 1. Đóng thông báo Toast tự động
    const toastAlert = document.getElementById('toast_alert');
    if (toastAlert) {
        let secondsLeft = 5;
        const countdownNum = document.getElementById('countdown_num');
        const interval = setInterval(() => {
            secondsLeft--;
            if (countdownNum) countdownNum.innerText = secondsLeft;
            if (secondsLeft <= 0) {
                clearInterval(interval);
                closeToast();
            }
        }, 1000);
    }
    function closeToast() {
        if (toastAlert) {
            toastAlert.classList.add('fade-out');
            setTimeout(() => toastAlert.remove(), 400);
        }
    }

    function toggleFilterSection() {
        const section = document.getElementById('filter_form');
        const icon = document.getElementById('filter_toggle_icon');
        if (section.classList.contains('show')) {
            section.classList.remove('show');
            icon.innerText = '▼ Mở rộng nâng cao';
        } else {
            section.classList.add('show');
            icon.innerText = '▲ Thu gọn';
        }
    }

    // 2. Thêm dòng / Xóa dòng sản phẩm linh hoạt
    const container = document.getElementById('product_rows_container');
    const addBtn = document.getElementById('add_row_btn');
    if (addBtn && container) {
        addBtn.addEventListener('click', function() {
            const rows = container.getElementsByClassName('product-row');
            const nextIndex = rows.length;
            const newRow = document.createElement('div');
            newRow.className = 'product-row bg-white p-4 rounded-xl border border-gray-100 shadow-sm relative group hover:border-blue-100 transition-all pt-8';
            newRow.innerHTML = `
                <button type="button" class="remove-row-btn absolute top-2 right-3 text-gray-400 hover:text-red-500 text-[11px] font-bold cursor-pointer">✕ Gỡ dòng</button>
                <div class="mb-3 relative search-product-container">
                    <label class="block text-[11px] font-bold text-gray-400 uppercase tracking-wider">Tìm tên / mã mặt hàng</label>
                    <input type="text" placeholder="Gõ tên hoặc mã vật tư để tìm..." autocomplete="off" class="search-product-input mt-1 block w-full rounded-lg border-gray-200 bg-gray-50/50 p-2 border text-xs outline-none focus:bg-white focus:ring-1 focus:ring-blue-500 transition-all font-medium">
                    <input type="hidden" name="products[${nextIndex}][product_id]" class="product-hidden-id" value="0">
                    <div class="search-suggestions-box hidden absolute left-0 right-0 mt-1 max-h-50 overflow-y-auto bg-white border border-gray-200 rounded-lg shadow-xl z-30 divide-y divide-gray-50 custom-scrollbar">
                        <?php foreach ($all_products as $p): ?>
                            <div data-id="<?= $p['id'] ?>" data-search="<?= htmlspecialchars(strtolower($p['code'] . ' ' . $p['name'])) ?>" class="suggestion-item p-2 text-xs text-gray-700 hover:bg-blue-50 hover:text-blue-700 font-medium cursor-pointer transition-colors">[<?= htmlspecialchars($p['code']) ?>] <?= htmlspecialchars($p['name']) ?></div>
                        <?php endforeach; ?>
                        <div class="no-suggestions-item hidden p-3 text-xs text-center text-gray-400 italic bg-gray-50/50">Không có sản phẩm nào khớp từ khóa</div>
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-3 mb-3">
                    <div>
                        <label class="block text-[11px] font-bold text-gray-400 uppercase tracking-wider">Mã lô SX</label>
                        <input type="text" name="products[${nextIndex}][lot_number]" placeholder="LOT-..." class="mt-1 block w-full rounded-lg border-gray-200 bg-gray-50/50 p-2 border text-xs uppercase tracking-wider outline-none">
                    </div>
                    <div>
                        <label class="block text-[11px] font-bold text-gray-400 uppercase tracking-wider">Hạn dùng</label>
                        <input type="date" name="products[${nextIndex}][expiry_date]" required class="mt-1 block w-full rounded-lg border-gray-200 bg-gray-50/50 p-2 border text-xs outline-none">
                    </div>
                </div>
                <div class="grid grid-cols-1">
                    <div>
                        <label class="block text-[11px] font-bold text-gray-400 uppercase tracking-wider">Số lượng</label>
                        <input type="number" name="products[${nextIndex}][quantity]" min="1" required placeholder="0" class="mt-1 block w-full rounded-lg border-gray-200 bg-gray-50/50 p-2 border text-xs font-bold text-blue-600 outline-none">
                    </div>
                </div>
            `;
            container.appendChild(newRow);
            bindProductSearchEvents(newRow);
        });
    }

    if (container) {
        container.addEventListener('click', function(e) {
            if (e.target.classList.contains('remove-row-btn')) {
                e.preventDefault();
                const row = e.target.closest('.product-row');
                if (row) {
                    row.remove();
                    reindexProductRows();
                }
            }
        });
    }

    function reindexProductRows() {
        const rows = container.getElementsByClassName('product-row');
        Array.from(rows).forEach((row, idx) => {
            const idInput = row.querySelector('.product-hidden-id');
            const lotInput = row.querySelector('input[name*="[lot_number]"]');
            const expInput = row.querySelector('input[name*="[expiry_date]"]');
            const qtyInput = row.querySelector('input[name*="[quantity]"]');
            if (idInput) idInput.name = `products[${idx}][product_id]`;
            if (lotInput) lotInput.name = `products[${idx}][lot_number]`;
            if (expInput) expInput.name = `products[${idx}][expiry_date]`;
            if (qtyInput) qtyInput.name = `products[${idx}][quantity]`;
        });
    }

    function bindProductSearchEvents(rowElement) {
        const textInput = rowElement.querySelector('.search-product-input');
        const hiddenInput = rowElement.querySelector('.product-hidden-id');
        const box = rowElement.querySelector('.search-suggestions-box');
        const items = box.getElementsByClassName('suggestion-item');
        const noResultItem = box.querySelector('.no-suggestions-item');

        if (!textInput || !box) return;
        textInput.addEventListener('focus', () => box.classList.remove('hidden'));
        textInput.addEventListener('input', function() {
            const query = textInput.value.trim().toLowerCase();
            let hasVisible = false;
            Array.from(items).forEach(item => {
                if ((item.getAttribute('data-search') || '').includes(query)) {
                    item.classList.remove('hidden');
                    hasVisible = true;
                } else {
                    item.classList.add('hidden');
                }
            });
            if (hasVisible) noResultItem?.classList.add('hidden');
            else noResultItem?.classList.remove('hidden');
        });
        box.addEventListener('click', function(e) {
            const item = e.target.closest('.suggestion-item');
            if (item) {
                textInput.value = item.innerText.trim();
                hiddenInput.value = item.getAttribute('data-id');
                box.classList.add('hidden');
            }
        });
        document.addEventListener('click', (e) => {
            if (!rowElement.contains(e.target)) box.classList.add('hidden');
        });
    }
    document.querySelectorAll('.product-row').forEach(row => bindProductSearchEvents(row));

    // 3. XỬ LÝ NÉN ẢNH VÀ ĐẨY TRỰC TIẾP LÊN R2 QUA PRESIGNED URL (BỔ SUNG LOGS DEBUG CHI TIẾT)
    const imgInput = document.getElementById('img_input_field');
    const imgPlaceholder = document.getElementById('img_placeholder_view');
    const imgPreviewContainer = document.getElementById('img_preview_container');
    const imgPreviewTag = document.getElementById('img_preview_tag');
    const removePreviewBtn = document.getElementById('remove_preview_btn');
    const r2ImageUrlInput = document.getElementById('r2_image_url');
    const loadingModal = document.getElementById('loading-modal');

    if (imgInput) {
        imgInput.addEventListener('change', async function() {
            const file = this.files[0];
            if (!file) return;

            // Bật màn hình loading bảo vệ
            loadingModal.classList.remove('hidden');

            const img = new Image();
            img.src = URL.createObjectURL(file);
            
            img.onload = async () => {
                try {
                    const canvas = document.createElement('canvas');
                    let width = img.width;
                    let height = img.height;
                    const maxSize = 1200; // Tự động resize ảnh tối đa 1200px nếu ảnh quá lớn

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

                    // Chuyển đổi sang định dạng blob nhị phân
                    canvas.toBlob(async (blob) => {
                        if (!blob) {
                            alert('Không thể xử lý dữ liệu hình ảnh!');
                            loadingModal.classList.add('hidden');
                            return;
                        }

                        // ================= KHU VỰC DEBUG NÉN ẢNH =================
                        const originalSize = (file.size / 1024 / 1024).toFixed(2);
                        const compressedSize = (blob.size / 1024 / 1024).toFixed(2);
                        const compressionRatio = ((1 - (blob.size / file.size)) * 100).toFixed(1);

                        console.log("%c--- THÔNG TIN DEBUG NÉN ẢNH ---", "color: #007bff; font-weight: bold; font-size: 14px;");
                        console.log(`- Tên file gốc: ${file.name}`);
                        console.log(`- Kích thước gốc: ${originalSize} MB (${file.size} bytes)`);
                        console.log(`- Kích thước sau nén: ${compressedSize} MB (${blob.size} bytes)`);
                        console.log(`- Tỷ lệ giảm dung lượng: Đã giảm được ${compressionRatio}%`);
                        console.log("---------------------------------");
                        // =========================================================

                        // Tạo tên tệp tin ngẫu nhiên trên Cloud R2
                        const extension = file.name.split('.').pop().toLowerCase();
                        const randomFileName = 'vouchers/' + Date.now() + '_' + Math.random().toString(36).substring(2, 8) + '.' + extension;

                        console.log("%c[1] GỬI YÊU CẦU LẤY PRESIGNED URL...", "color: #fd7e14; font-weight: bold;");
                        console.log(`- Tên file dự kiến lưu trên R2: ${randomFileName}`);
                        console.log(`- Định dạng tệp tin (Mime-Type): ${blob.type}`);

                        // Bước A: Lấy Presigned URL từ file r2_api.php backend
                        const preRes = await fetch(`r2_api.php?action=get-presigned-url&file=${encodeURIComponent(randomFileName)}&type=${encodeURIComponent(blob.type)}`);
                        const preData = await preRes.json();

                        // LOG URL UPLOAD LẤY ĐƯỢC TỪ BACKEND
                        console.log("%c[2] ĐÃ NHẬN ĐƯỢC URL UPLOAD (PRESIGNED URL):", "color: #28a745; font-weight: bold;");
                        console.log(preData?.upload_url);

                        if (preData && preData.upload_url) {
                            
                            console.log("%c[3] TIẾN HÀNH PUT FILE TRỰC TIẾP LÊN BUCKET CLOUDFLARE R2...", "color: #007bff; font-weight: bold;");
                            // LOG ĐỐI TƯỢNG BLOB NHỊ PHÂN (BODY FILE) SẼ GỬI ĐI
                            console.log("- Dữ liệu Body nhị phân gửi đi (Blob Object):", blob);

                            // Bước B: Đẩy file trực tiếp từ trình duyệt lên Cloudflare R2 qua liên kết bảo mật
                            const uploadRes = await fetch(preData.upload_url, {
                                method: 'PUT',
                                body: blob,
                                headers: { 'Content-Type': blob.type }
                            });

                            // LOG KẾT QUẢ PHẢN HỒI (RESPONSE STATUS) TỪ CLOUDFLARE R2 TRẢ VỀ
                            console.log("%c[4] KẾT QUẢ PHẢN HỒI TỪ CLOUDFLARE R2:", "color: #6f42c1; font-weight: bold;");
                            console.log(`- Mã HTTP Status: ${uploadRes.status} (${uploadRes.statusText})`);
                            console.log(`- Upload thành công hay không (OK): ${uploadRes.ok}`);

                            if (uploadRes.ok) {
                                const publicUrl = `<?= rtrim($r2_config['public_path'], '/') ?>/` + randomFileName;
                                r2ImageUrlInput.value = publicUrl;

                                console.log(`%c=> ĐƯỜNG DẪN ẢNH PUBLIC CUỐI CÙNG: ${publicUrl}`, "color: #28a745; font-weight: bold; background: #e8f5e9; padding: 4px;");

                                // Cập nhật trạng thái preview
                                imgPreviewTag.src = publicUrl;
                                imgPlaceholder.classList.add('hidden');
                                imgPreviewContainer.classList.remove('hidden');
                            } else {
                                alert('Lỗi: Không thể tải ảnh trực tiếp lên Cloudflare R2.');
                            }
                        } else {
                            alert('Lỗi: Hệ thống không thể cấp quyền Presigned URL.');
                        }
                        loadingModal.classList.add('hidden');
                    }, 'image/jpeg', 0.85); // Nén chất lượng 85% để trang load siêu tốc

                } catch (err) {
                    console.error("Lỗi xảy ra trong quá trình Debug upload:", err);
                    alert('Có lỗi xảy ra trong quá trình đồng bộ hình ảnh lên Cloud!');
                    loadingModal.classList.add('hidden');
                }
            };
        });
    }

    if (removePreviewBtn) {
        removePreviewBtn.addEventListener('click', function(e) {
            e.preventDefault();
            if (imgInput) imgInput.value = ''; 
            r2ImageUrlInput.value = '';
            imgPreviewTag.src = '#';
            imgPreviewContainer.classList.add('hidden');
            imgPlaceholder.classList.remove('hidden');
        });
    }

    // 4. Lightbox xem phóng to hình ảnh
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
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape') window.closeLightbox?.(); });
</script>