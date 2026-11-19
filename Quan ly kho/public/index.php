<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require 'db.php';
require 'header.php';

// 1. THỐNG KÊ CARDS (Giữ nguyên)
$total_products = $pdo->query("SELECT COUNT(*) FROM products")->fetchColumn();
$total_warehouses = $pdo->query("SELECT COUNT(*) FROM warehouses")->fetchColumn();
$total_imports = $pdo->query("SELECT COUNT(*) FROM documents WHERE type = 'import'")->fetchColumn();
$total_exports = $pdo->query("SELECT COUNT(*) FROM documents WHERE type = 'export'")->fetchColumn();

// 2. XỬ LÝ DỮ LIỆU CẢNH BÁO HẾT HẠN (Thay thế SQL lỗi bằng JSON logic)
$expiring_soon_goods = [];
$today = time();
$limit_date = strtotime('+30 days');

$all_docs = $pdo->query("SELECT id, details, warehouse_id FROM documents WHERE details IS NOT NULL")->fetchAll();

foreach ($all_docs as $doc) {
    $items = json_decode($doc['details'], true);
    if (!is_array($items)) continue;

    foreach ($items as $item) {
        if (empty($item['expiry_date'])) continue;
        $expiry = strtotime($item['expiry_date']);
        
        if ($expiry >= $today && $expiry <= $limit_date) {
            $prod = $pdo->prepare("SELECT name FROM products WHERE id = ?");
            $prod->execute([$item['product_id'] ?? 0]);
            
            $wh = $pdo->prepare("SELECT name FROM warehouses WHERE id = ?");
            $wh->execute([$doc['warehouse_id']]);
            
            $expiring_soon_goods[] = [
                'name' => $prod->fetchColumn() ?: 'Sản phẩm ID: '.$item['product_id'],
                'lot_number' => $item['lot_number'] ?? 'N/A',
                'expiry_date' => $item['expiry_date'],
                'warehouse_name' => $wh->fetchColumn() ?: 'Kho chính',
                'quantity' => $item['quantity'] ?? 0
            ];
        }
    }
}
usort($expiring_soon_goods, fn($a, $b) => strtotime($a['expiry_date']) <=> strtotime($b['expiry_date']));
$expiring_soon_goods = array_slice($expiring_soon_goods, 0, 5);
$total_alerts = count($expiring_soon_goods);

// 3. BIỂU ĐỒ (Giữ nguyên logic query cũ)
$chart_months = []; $chart_imports = []; $chart_exports = [];
for ($i = 5; $i >= 0; $i--) {
    $month_str = date('Y-m', strtotime("-$i month"));
    $chart_months[] = date('m/Y', strtotime("-$i month"));
    $chart_imports[] = (int)$pdo->query("SELECT COUNT(*) FROM documents WHERE type = 'import' AND DATE_FORMAT(date_issued, '%Y-%m') = '$month_str'")->fetchColumn();
    $chart_exports[] = (int)$pdo->query("SELECT COUNT(*) FROM documents WHERE type = 'export' AND DATE_FORMAT(date_issued, '%Y-%m') = '$month_str'")->fetchColumn();
}

// 4. LẤY DANH SÁCH GIAO DỊCH GẦN ĐÂY
$recent_vouchers = $pdo->query("
    SELECT doc.*, w.name as warehouse_name
    FROM documents doc
    JOIN warehouses w ON doc.warehouse_id = w.id
    ORDER BY doc.id DESC LIMIT 5
")->fetchAll();
?>

<link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-3 space-y-2">
    
    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-2 border-b border-gray-100 pb-2">
        <div>
            <h1 class="text-xl font-bold text-gray-800 tracking-tight">📊 Hệ Thống Tổng Quan Quản Lý Kho</h1>
            <p class="text-xs text-gray-400 mt-1">Xin chào Admin, dưới đây là tình hình vận hành kho bãi thời gian thực tính đến ngày <?= date('d/m/Y') ?>.</p>
        </div>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5">
        <div class="bg-white p-5 rounded-2xl shadow-sm border border-gray-100 flex items-center justify-between">
            <div>
                <p class="text-[11px] font-bold text-gray-400 uppercase tracking-wider">Danh mục sản phẩm</p>
                <h3 class="text-2xl font-bold text-gray-800 mt-1"><?= number_format($total_products) ?></h3>
                <p class="text-[10px] text-blue-500 font-medium mt-1">Mặt hàng hàng hóa định danh</p>
            </div>
            <div class="w-12 h-12 bg-blue-50 rounded-xl flex items-center justify-center text-xl">📦</div>
        </div>
        <div class="bg-white p-5 rounded-2xl shadow-sm border border-gray-100 flex items-center justify-between">
            <div>
                <p class="text-[11px] font-bold text-gray-400 uppercase tracking-wider">Cơ sở kho bãi</p>
                <h3 class="text-2xl font-bold text-gray-800 mt-1"><?= number_format($total_warehouses) ?></h3>
                <p class="text-[10px] text-indigo-500 font-medium mt-1">Điểm lưu kho đang hoạt động</p>
            </div>
            <div class="w-12 h-12 bg-indigo-50 rounded-xl flex items-center justify-center text-xl">🏢</div>
        </div>
        <div class="bg-white p-5 rounded-2xl shadow-sm border border-gray-100 flex items-center justify-between">
            <div>
                <p class="text-[11px] font-bold text-gray-400 uppercase tracking-wider">Đã nhập kho</p>
                <h3 class="text-2xl font-bold text-green-600 mt-1"><?= number_format($total_imports) ?></h3>
                <p class="text-[10px] text-green-500 font-medium mt-1">Tổng số lượng phiếu nhập</p>
            </div>
            <div class="w-12 h-12 bg-green-50 rounded-xl flex items-center justify-center text-xl">📥</div>
        </div>
        <div class="bg-white p-5 rounded-2xl shadow-sm border border-gray-100 flex items-center justify-between">
            <div>
                <p class="text-[11px] font-bold text-gray-400 uppercase tracking-wider">Đã xuất kho</p>
                <h3 class="text-2xl font-bold text-red-600 mt-1"><?= number_format($total_exports) ?></h3>
                <p class="text-[10px] text-red-500 font-medium mt-1">Tổng số lượng phiếu xuất</p>
            </div>
            <div class="w-12 h-12 bg-red-50 rounded-xl flex items-center justify-center text-xl">📤</div>
        </div>
    </div>

    <!-- <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        
        <div class="lg:col-span-2 bg-white p-5 rounded-2xl shadow-sm border border-gray-100">
            <h2 class="text-xs font-bold text-gray-700 uppercase tracking-wider mb-4">📈 Biến động tần suất chứng từ (6 Tháng qua)</h2>
            <div class="h-64 relative">
                <canvas id="ioTrendChart"></canvas>
            </div>
        </div>

        <div class="bg-white p-5 rounded-2xl shadow-sm border border-gray-100 flex flex-col justify-between">
            <div>
                <h2 class="text-xs font-bold text-red-500 uppercase tracking-wider mb-4">⏳ Cảnh báo: Hàng hóa sắp hết hạn (30 ngày)</h2>
                <?php if (empty($expiring_soon_goods)): ?>
                    <div class="text-center py-12 text-gray-400">
                        <span class="text-2xl">🛡️</span>
                        <p class="text-[11px] font-medium mt-1">Tuyệt vời! Không có hàng hóa nào sắp hết hạn.</p>
                    </div>
                <?php else: ?>
                    <div class="space-y-3 max-h-60 overflow-y-auto pr-1">
                        <?php foreach ($expiring_soon_goods as $item): 
                            // Tính toán số ngày còn lại của lô hàng vật lý
                            $days_left = (int)(strtotime($item['expiry_date']) - time()) / (60 * 60 * 24);
                            $days_left = max(0, ceil($days_left));
                        ?>
                            <div class="p-3 bg-red-50/50 rounded-xl border border-red-100 flex items-start justify-between text-xs">
                                <div class="space-y-0.5">
                                    <p class="font-bold text-gray-800"><?= htmlspecialchars($item['name']) ?></p>
                                    <p class="text-[10px] text-gray-400">Số lô: <span class="font-mono text-gray-600 font-semibold"><?= htmlspecialchars($item['lot_number']) ?></span> | Tại: <?= htmlspecialchars($item['warehouse_name']) ?></p>
                                    <p class="text-[10px] text-red-600 font-medium pt-0.5">📅 Hạn dùng: <?= date('d/m/Y', strtotime($item['expiry_date'])) ?> (<span class="font-bold">Còn <?= $days_left ?> ngày</span>)</p>
                                </div>
                                <div class="text-right flex-shrink-0">
                                    <span class="bg-red-100 text-red-700 text-[10px] font-bold px-2 py-0.5 rounded-md"><?= number_format($item['quantity']) ?></span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
            
            <div class="text-center border-t border-gray-100 pt-3 mt-3">
                <a href="expiry_alerts.php" class="text-[11px] text-red-600 hover:text-red-800 font-bold transition-colors inline-block">
                    ⚠️ Xem tất cả hàng hóa sắp hết hạn (<?= $total_alerts ?>) →
                </a>
            </div>
        </div>
    </div> -->

    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
        <div class="p-4 border-b border-gray-100 flex items-center justify-between">
            <h2 class="text-xs font-bold text-gray-700 uppercase tracking-wider">📋 Giao dịch kho hàng vừa thực hiện (Mới nhất)</h2>
            <a href="vouchers.php" class="text-xs text-blue-600 hover:text-blue-800 font-bold transition-colors">Xem tất cả nhật ký →</a>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-100 text-left">
                <thead class="bg-gray-50/50 text-[10px] font-bold text-gray-400 uppercase tracking-wider">
                    <tr>
                        <th class="p-4">Mã số phiếu</th>
                        <th class="p-4">Loại nghiệp vụ</th>
                        <th class="p-4">Kho bãi thực hiện</th>
                        <th class="p-4">Ngày lập</th>
                        <th class="p-4">Nhân viên phụ trách</th>
                        <th class="p-4 text-right">Tổng lượng vật tư</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-50 text-xs text-gray-700">
                    <?php if (empty($recent_vouchers)): ?>
                        <tr>
                            <td colspan="6" class="p-8 text-center text-gray-400 font-medium">Chưa có giao dịch chứng từ nào được thực hiện.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($recent_vouchers as $v): 
                    $details = json_decode($v['details'], true);
                    $total_qty = is_array($details) ? array_sum(array_column($details, 'quantity')) : 0;
                ?>
                            <tr class="hover:bg-gray-50/50 transition-colors">
                                <td class="p-4 font-mono font-bold text-blue-700 uppercase"><?= htmlspecialchars($v['ticket_code']) ?></td>
                                <td class="p-4">
                                    <span class="text-[10px] font-bold px-2 py-0.5 rounded-md uppercase <?= $v['type'] === 'import' ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700' ?>">
                                        <?= $v['type'] === 'import' ? '📥 Nhập kho' : '📤 Xuất kho' ?>
                                    </span>
                                </td>
                                <td class="p-4 font-medium"><?= htmlspecialchars($v['warehouse_name']) ?></td>
                                <td class="p-4 text-gray-500"><?= date('d/m/Y', strtotime($v['date_issued'])) ?></td>
                                <td class="p-4 text-gray-500"><?= !empty($v['created_by']) ? htmlspecialchars($v['created_by']) : '-' ?></td>
                                <td class="p-4 text-right font-bold text-gray-800"><?= number_format($total_qty) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>

<script>
document.addEventListener("DOMContentLoaded", function () {
    const ctx = document.getElementById('ioTrendChart').getContext('2d');
    
    // Đổ mảng dữ liệu từ PHP sang Javascript dạng định dạng JSON an toàn
    const labels = <?= json_encode($chart_months) ?>;
    const importData = <?= json_encode($chart_imports) ?>;
    const exportData = <?= json_encode($chart_exports) ?>;

    new Chart(ctx, {
        type: 'line',
        data: {
            labels: labels,
            datasets: [
                {
                    label: 'Số phiếu Nhập Kho',
                    data: importData,
                    borderColor: '#10B981', // Màu xanh lục của Tailwind CSS
                    backgroundColor: 'rgba(16, 185, 129, 0.05)',
                    borderWidth: 2.5,
                    tension: 0.3, // Bo tròn đường cong thanh mảnh mềm mại
                    pointBackgroundColor: '#10B981'
                },
                {
                    label: 'Số phiếu Xuất Kho',
                    data: exportData,
                    borderColor: '#EF4444', // Màu đỏ của Tailwind CSS
                    backgroundColor: 'rgba(239, 68, 68, 0.05)',
                    borderWidth: 2.5,
                    tension: 0.3,
                    pointBackgroundColor: '#EF4444'
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'top',
                    labels: {
                        font: { size: 11, weight: '600' },
                        boxWidth: 12
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: { font: { size: 10 } },
                    grid: { color: '#F3F4F6' }
                },
                x: {
                    ticks: { font: { size: 10, weight: '500' } },
                    grid: { display: false }
                }
            }
        }
    });
});
</script>