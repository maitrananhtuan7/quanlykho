<?php
require 'db.php';

// 1. LẤY DANH SÁCH KHO HÀNG ĐỂ ĐỔ VÀO THÈ SELECT FILTER
$warehouses = $pdo->query("SELECT * FROM warehouses ORDER BY name ASC")->fetchAll();

// 2. TẢI TOÀN BỘ CHI TIẾT CHỨNG TỪ TỪ 1 BẢNG DUY NHẤT (Giải mã JSON và map thông tin sản phẩm)
$sql = "SELECT warehouse_id, type AS doc_type, details FROM documents";
$raw_docs = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

// Lấy danh sách sản phẩm để Map dữ liệu tên/code/đơn vị
$products = $pdo->query("SELECT id, name, code, unit FROM products")->fetchAll(PDO::FETCH_UNIQUE | PDO::FETCH_ASSOC);

$all_vouchers = [];

foreach ($raw_docs as $doc) {
    $details = json_decode($doc['details'], true);
    if (!is_array($details)) continue;

    foreach ($details as $det) {
        $p_id = $det['product_id'];
        $prod = $products[$p_id] ?? ['name' => 'Unknown', 'code' => 'N/A', 'unit' => ''];
        
        $all_vouchers[] = [
            'warehouse_id' => (int)$doc['warehouse_id'],
            'doc_type'     => $doc['doc_type'],
            'product_id'   => (int)$p_id,
            'product_name' => $prod['name'],
            'product_code' => $prod['code'],
            'product_unit' => $prod['unit'],
            'lot_number'   => $det['lot_number'] ?? '',
            'expiry_date'  => $det['expiry_date'] ?? '',
            'quantity'     => (int)($det['quantity'] ?? 0)
        ];
    }
}

// Giả lập lại câu lệnh ORDER BY det.expiry_date ASC, p.name ASC bằng PHP để JS xử lý chính xác
usort($all_vouchers, function ($a, $b) {
    if ($a['expiry_date'] === $b['expiry_date']) {
        return strcmp($a['product_name'], $b['product_name']);
    }
    return strcmp($a['expiry_date'], $b['expiry_date']);
});

require 'header.php';
?>

<!-- Tích hợp Tailwind CSS bản thu gọn -->
<link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">

<div class="container mx-auto px-4 py-4 max-w-7xl">
    
    <!-- TIÊU ĐỀ TRANG THU GỌN -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between pb-3 mb-4 border-b border-gray-200 gap-2">
        <div>
            <h1 class="text-lg font-bold text-slate-900 tracking-tight flex items-center gap-2">
                📊 Báo Cáo Tồn Kho Động
            </h1>
            <p class="text-[11px] text-slate-400 mt-0.5 font-medium">Hệ thống tính toán lượng tồn kho thời gian thực theo từng bộ lọc cấu hình.</p>
        </div>
        <div id="summaryText" class="inline-flex items-center gap-2 px-3 py-1 bg-slate-100 text-slate-600 rounded-lg text-xs font-semibold border border-slate-200 shadow-sm transition-all self-start sm:self-center">
            <span class="w-1.5 h-1.5 rounded-full bg-slate-400 animate-pulse"></span>
            Vui lòng chọn kho để bắt đầu tính toán.
        </div>
    </div>
    
    <!-- THANH BỘ LỌC ĐỘNG THU GỌN CAO CẤP -->
    <div class="bg-white p-3 rounded-xl shadow-sm border border-slate-150 mb-4">
        <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
            <!-- Bước 1: Chọn kho hàng -->
            <div class="space-y-1">
                <label class="block text-[10px] font-bold text-slate-400 uppercase tracking-wider">Bước 1: Chọn Kho Hàng</label>
                <div class="relative">
                    <select id="warehouseFilter" class="block w-full rounded-lg border-slate-200 p-2 border text-xs font-bold text-indigo-900 focus:ring-1 focus:ring-indigo-500 bg-indigo-50/20 cursor-pointer appearance-none outline-none">
                        <option value="">-- Chọn kho thực tế --</option>
                        <?php foreach ($warehouses as $wh): ?>
                            <option value="<?= $wh['id'] ?>"><?= htmlspecialchars($wh['name']) ?> [<?= htmlspecialchars($wh['code']) ?>]</option>
                        <?php endforeach; ?>
                    </select>
                    <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-2 text-slate-400">
                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>
                    </div>
                </div>
            </div>

            <!-- Bước 2: Tìm kiếm sản phẩm -->
            <div class="space-y-1">
                <label class="block text-[10px] font-bold text-slate-400 uppercase tracking-wider">Bước 2: Tìm sản phẩm</label>
                <div class="relative">
                    <input type="text" id="productSearchInput" placeholder="Nhập mã SKU hoặc tên..." class="block w-full rounded-lg border-slate-200 p-2 pl-7 border text-xs focus:ring-1 focus:ring-indigo-500 placeholder-slate-400 outline-none">
                    <div class="absolute inset-y-0 left-0 pl-2.5 flex items-center pointer-events-none text-slate-400">
                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg>
                    </div>
                </div>
            </div>

            <!-- Bước 3: Lọc hạn sử dụng -->
            <div class="space-y-1">
                <label class="block text-[10px] font-bold text-slate-400 uppercase tracking-wider">Bước 3: Số ngày cận hạn</label>
                <div class="relative rounded-lg shadow-sm">
                    <input type="number" id="expiryDaysFilter" min="0" placeholder="Xem tất cả hạn sử dụng..." class="block w-full rounded-lg border-slate-200 p-2 pr-10 border text-xs focus:ring-1 focus:ring-indigo-500 placeholder-slate-400 outline-none">
                    <div class="absolute inset-y-0 right-0 pr-3 flex items-center pointer-events-none text-slate-400 text-[10px] font-bold uppercase">
                        ngày
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- BẢNG DỮ LIỆU TỒN KHO THU GỌN -->
    <div class="bg-white rounded-xl shadow-sm border border-slate-150 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="min-w-full text-left border-collapse whitespace-nowrap">
                <thead id="inventoryTableHeader" class="bg-slate-900 text-slate-200 text-[11px] font-bold uppercase tracking-wider">
                    <tr>
                        <th class="py-2.5 px-4">Mã SKU</th>
                        <th class="py-2.5 px-4">Tên Sản Phẩm / Quy Cách</th>
                        <th class="py-2.5 px-4 text-right">Số Lượng Tồn Kho</th>
                    </tr>
                </thead>
                <tbody id="inventoryTableBody" class="divide-y divide-slate-100 text-xs text-slate-700">
                    <tr>
                        <td id="initialTdMessage" colspan="3" class="py-12 text-center text-slate-400 font-medium bg-slate-50/50">
                            <div class="flex flex-col items-center justify-center gap-2">
                                <span class="p-2 bg-indigo-50 text-indigo-500 rounded-full animate-bounce">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 15l-2 5L9 9l11 4-5 2zm0 0l5 5M7.188 2.239l.777 2.897M5.136 7.965l-2.898-.777M13.95 4.05l-2.122 2.122m-5.657 5.656l-2.12 2.122"></path></svg>
                                </span>
                                <span class="text-slate-500 font-semibold text-xs">Vui lòng chọn một kho hàng cụ thể ở bộ lọc phía trên</span>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- DIỆN TÍCH PHÂN TRANG ĐỘNG -->
        <div id="paginationPanel" class="hidden bg-slate-50 px-4 py-2 border-t border-slate-100 flex items-center justify-between text-xs">
            <div id="paginationInfo" class="text-slate-500 font-medium"></div>
            <div id="paginationButtons" class="flex items-center gap-1"></div>
        </div>
    </div>
</div>

<!-- CHUYỂN DỮ LIỆU VOUCHERS TỪ PHP SANG BIẾN JS CỦA TRÌNH DUYỆT -->
<script>
    const allVouchersData = <?= json_encode($all_vouchers); ?>;
</script>

<!-- JAVASCRIPT LOGIC PHÂN TÍCH TỒN KHO & PHÂN TRANG -->
<script>
document.addEventListener('DOMContentLoaded', function () {
    const warehouseFilter = document.getElementById('warehouseFilter');
    const productSearchInput = document.getElementById('productSearchInput');
    const expiryDaysFilter = document.getElementById('expiryDaysFilter');
    const inventoryTableHeader = document.getElementById('inventoryTableHeader');
    const inventoryTableBody = document.getElementById('inventoryTableBody');
    const summaryText = document.getElementById('summaryText');

    // Cấu hình phân trang
    const limit = 15; // Số dòng hiển thị trên mỗi trang
    let currentPage = 1;
    let globalInventoryList = []; 

    function formatNumber(num) {
        return new Number(num).toLocaleString('en-US');
    }

    function calculateAndRenderInventory() {
        const selectedWarehouseId = warehouseFilter.value;
        const searchQuery = productSearchInput.value.trim().toLowerCase();
        const maxExpiryDays = expiryDaysFilter.value ? parseInt(expiryDaysFilter.value) : null;
        const isFilteringExpiry = maxExpiryDays !== null;

        document.getElementById('paginationPanel').classList.add('hidden');

        if (isFilteringExpiry) {
            inventoryTableHeader.innerHTML = `
                <tr>
                    <th class="py-2.5 px-4">Mã SKU</th>
                    <th class="py-2.5 px-4">Tên Sản Phẩm / Quy Cách</th>
                    <th class="py-2.5 px-4">Hạn Sử Dụng</th>
                    <th class="py-2.5 px-4 text-center">Trạng Thế Hạn</th>
                    <th class="py-2.5 px-4 text-right">Số Lượng Tồn</th>
                </tr>
            `;
        } else {
            inventoryTableHeader.innerHTML = `
                <tr>
                    <th class="py-2.5 px-4">Mã SKU</th>
                    <th class="py-2.5 px-4">Tên Sản Phẩm / Quy Cách</th>
                    <th class="py-2.5 px-4 text-right">Số Lượng Tồn Kho</th>
                </tr>
            `;
        }

        if (!selectedWarehouseId) {
            const colspanVal = isFilteringExpiry ? 5 : 3;
            inventoryTableBody.innerHTML = `
                <tr>
                    <td colspan="${colspanVal}" class="py-12 text-center text-slate-400 font-medium bg-slate-50/50">
                        <div class="flex flex-col items-center justify-center gap-2">
                            <span class="p-2 bg-indigo-50 text-indigo-500 rounded-full animate-bounce">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 15l-2 5L9 9l11 4-5 2zm0 0l5 5M7.188 2.239l.777 2.897M5.136 7.965l-2.898-.777M13.95 4.05l-2.122 2.122m-5.657 5.656l-2.12 2.122"></path></svg>
                            </span>
                            <span class="text-slate-500 font-semibold text-xs">Vui lòng chọn một kho hàng cụ thể ở bộ lọc phía trên</span>
                        </div>
                    </td>
                </tr>`;
            summaryText.innerHTML = `<span class="w-1.5 h-1.5 rounded-full bg-slate-400 animate-pulse"></span> Vui lòng chọn kho để bắt đầu tính toán.`;
            summaryText.className = "inline-flex items-center gap-2 px-3 py-1 bg-slate-100 text-slate-600 rounded-lg text-xs font-semibold border border-slate-200 shadow-sm";
            return;
        }

        let filteredVouchers = allVouchersData.filter(v => v.warehouse_id == selectedWarehouseId);

        const stockMap = {};
        const today = new Date();
        today.setHours(0,0,0,0);

        filteredVouchers.forEach(v => {
            const qty = parseInt(v.quantity) || 0;
            const change = v.doc_type === 'import' ? qty : -qty;

            if (isFilteringExpiry) {
                const key = `${v.product_id}_${v.expiry_date}`;
                if (!stockMap[key]) {
                    stockMap[key] = {
                        product_code: v.product_code,
                        product_name: v.product_name,
                        product_unit: v.product_unit,
                        expiry_date: v.expiry_date,
                        stock_qty: 0
                    };
                }
                stockMap[key].stock_qty += change;
            } else {
                const key = v.product_id;
                if (!stockMap[key]) {
                    stockMap[key] = {
                        product_code: v.product_code,
                        product_name: v.product_name,
                        product_unit: v.product_unit,
                        expiry_date: null,
                        stock_qty: 0
                    };
                }
                stockMap[key].stock_qty += change;
            }
        });

        globalInventoryList = Object.values(stockMap).filter(item => item.stock_qty > 0);

        if (searchQuery.length > 0) {
            globalInventoryList = globalInventoryList.filter(item => 
                item.product_code.toLowerCase().includes(searchQuery) || 
                item.product_name.toLowerCase().includes(searchQuery)
            );
        }

        if (maxExpiryDays !== null) {
            globalInventoryList = globalInventoryList.filter(item => {
                if (!item.expiry_date) return false;
                const expDate = new Date(item.expiry_date);
                expDate.setHours(0,0,0,0);
                
                const timeDiff = expDate.getTime() - today.getTime();
                const daysDiff = Math.ceil(timeDiff / (1000 * 3600 * 24));
                
                return daysDiff <= maxExpiryDays;
            });
        }

        if (globalInventoryList.length === 0) {
            const colspanVal = isFilteringExpiry ? 5 : 3;
            inventoryTableBody.innerHTML = `
                <tr>
                    <td colspan="${colspanVal}" class="py-12 text-center text-slate-400 font-medium bg-slate-50/50">
                        <div class="flex flex-col items-center justify-center gap-1 text-slate-400">
                            <svg class="w-6 h-6 text-slate-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.172 16.172a4 4 0 015.656 0M9 10h.01M15 10h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                            Không có kết quả nào khớp với điều kiện lọc hiện tại!
                        </div>
                    </td>
                </tr>`;
            summaryText.innerHTML = `<span class="w-1.5 h-1.5 rounded-full bg-amber-500 animate-pulse"></span> Không tìm thấy dòng dữ liệu nào.`;
            summaryText.className = "inline-flex items-center gap-2 px-3 py-1 bg-amber-50 text-amber-700 rounded-lg text-xs font-semibold border border-amber-200 shadow-sm";
            return;
        }

        currentPage = 1;
        displayTablePage(isFilteringExpiry);
    }

    function displayTablePage(isFilteringExpiry) {
        const today = new Date();
        today.setHours(0,0,0,0);

        const totalRows = globalInventoryList.length;
        const totalPages = Math.ceil(totalRows / limit);
        
        if (currentPage > totalPages) currentPage = totalPages;
        if (currentPage < 1) currentPage = 1;

        const offset = (currentPage - 1) * limit;
        const pageItems = globalInventoryList.slice(offset, offset + limit);

        let htmlRows = '';
        let totalStockQtyOfView = globalInventoryList.reduce((sum, item) => sum + item.stock_qty, 0);

        pageItems.forEach(item => {
            if (isFilteringExpiry) {
                let expiryBadge = '';
                let displayExpiry = item.expiry_date ? item.expiry_date : 'N/A';

                if (item.expiry_date) {
                    const expDate = new Date(item.expiry_date);
                    expDate.setHours(0,0,0,0);
                    const diffTime = expDate.getTime() - today.getTime();
                    const diffDays = Math.ceil(diffTime / (1000 * 3600 * 24));

                    if (diffDays < 0) {
                        expiryBadge = `<span class="bg-red-50 text-red-700 text-[10px] px-2 py-0.5 rounded border border-red-100 font-bold inline-flex items-center">🚨 Quá hạn ${Math.abs(diffDays)} ngày</span>`;
                    } else if (diffDays <= 30) {
                        expiryBadge = `<span class="bg-amber-50 text-amber-700 text-[10px] px-2 py-0.5 rounded border border-amber-100 font-bold inline-flex items-center">⏳ Cận hạn (${diffDays} ngày)</span>`;
                    } else {
                        expiryBadge = `<span class="bg-emerald-50 text-emerald-700 text-[10px] px-2 py-0.5 rounded border border-emerald-100 font-semibold inline-flex items-center">✅ Còn ${diffDays} ngày</span>`;
                    }
                } else {
                    expiryBadge = `<span class="text-slate-300">-</span>`;
                }

                htmlRows += `
                    <tr class="hover:bg-slate-50/50 transition duration-150 ease-in-out group">
                        <td class="py-2 px-4 font-mono text-slate-500 font-bold group-hover:text-indigo-600 transition">${item.product_code}</td>
                        <td class="py-2 px-4 font-semibold text-slate-800">
                            ${item.product_name}
                            <span class="text-[10px] text-slate-400 font-normal ml-1 uppercase">(${item.product_unit ? item.product_unit.toUpperCase() : ''})</span>
                        </td>
                        <td class="py-2 px-4 font-bold text-amber-600 font-mono">${displayExpiry}</td>
                        <td class="py-2 px-4 text-center">${expiryBadge}</td>
                        <td class="py-2 px-4 text-right font-black font-mono text-indigo-600 text-sm">
                            ${formatNumber(item.stock_qty)}
                        </td>
                    </tr>
                `;
            } else {
                htmlRows += `
                    <tr class="hover:bg-slate-50/50 transition duration-150 ease-in-out group">
                        <td class="py-2 px-4 font-mono text-slate-500 font-bold group-hover:text-indigo-600 transition">${item.product_code}</td>
                        <td class="py-2 px-4 font-semibold text-slate-800">
                            ${item.product_name}
                            <span class="text-[10px] text-slate-400 font-normal ml-1">(${item.product_unit})</span>
                        </td>
                        <td class="py-2 px-4 text-right font-black font-mono text-indigo-600 text-sm">
                            ${formatNumber(item.stock_qty)}
                        </td>
                    </tr>
                `;
            }
        });

        inventoryTableBody.innerHTML = htmlRows;

        summaryText.innerHTML = `<span class="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse"></span> Tổng tồn: <b class="text-emerald-700 mx-0.5">${formatNumber(totalStockQtyOfView)}</b>`;
        summaryText.className = "inline-flex items-center gap-2 px-3 py-1 bg-emerald-50 text-emerald-700 rounded-lg text-xs font-semibold border border-emerald-200 shadow-sm";

        // XỬ LÝ THANH PHÂN TRANG
        const paginationPanel = document.getElementById('paginationPanel');
        if (totalPages > 1) {
            paginationPanel.classList.remove('hidden');
            
            const paginationInfo = document.getElementById('paginationInfo');
            paginationInfo.innerHTML = `Hiển thị dòng <b>${offset + 1}</b> - <b>${Math.min(offset + limit, totalRows)}</b> trong tổng số <b>${totalRows}</b>`;

            const paginationButtons = document.getElementById('paginationButtons');
            let btnHtml = '';

            // Nút Trước
            if (currentPage > 1) {
                btnHtml += `<button type="button" onclick="changeJsPage(${currentPage - 1}, ${isFilteringExpiry})" class="px-2 py-1 rounded border border-slate-200 bg-white hover:bg-slate-50 font-medium text-slate-600 cursor-pointer">Trước</button>`;
            } else {
                btnHtml += `<span class="px-2 py-1 rounded border border-slate-100 bg-slate-50 text-slate-300 cursor-not-allowed">Trước</span>`;
            }

            // Danh sách số trang
            for (let i = 1; i <= totalPages; i++) {
                if (i === currentPage) {
                    btnHtml += `<span class="px-2.5 py-1 rounded bg-indigo-600 text-white font-bold">${i}</span>`;
                } else {
                    btnHtml += `<button type="button" onclick="changeJsPage(${i}, ${isFilteringExpiry})" class="px-2.5 py-1 rounded border border-slate-200 bg-white hover:bg-slate-50 font-bold text-slate-600 cursor-pointer">${i}</button>`;
                }
            }

            // Nút Sau
            if (currentPage < totalPages) {
                btnHtml += `<button type="button" onclick="changeJsPage(${currentPage + 1}, ${isFilteringExpiry})" class="px-2 py-1 rounded border border-slate-200 bg-white hover:bg-slate-50 font-medium text-slate-600 cursor-pointer">Sau</button>`;
            } else {
                btnHtml += `<span class="px-2 py-1 rounded border border-slate-100 bg-slate-50 text-slate-300 cursor-not-allowed">Sau</span>`;
            }

            paginationButtons.innerHTML = btnHtml;
        } else {
            paginationPanel.classList.add('hidden');
        }
    }

    window.changeJsPage = function(pageNumber, isFilteringExpiry) {
        currentPage = pageNumber;
        displayTablePage(isFilteringExpiry);
    };

    warehouseFilter.addEventListener('change', calculateAndRenderInventory);
    productSearchInput.addEventListener('input', calculateAndRenderInventory);
    expiryDaysFilter.addEventListener('input', calculateAndRenderInventory);
});
</script>

</main>
</body>
</html>