<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Lấy vai trò (role) của người dùng: ép về chữ thường để so sánh chính xác ('admin' hoặc 'user')
$auth_role = isset($_SESSION['auth_role']) ? strtolower($_SESSION['auth_role']) : 'user';

// =========================================================================
// CHỨC NĂNG BẢO MỆT: NGĂN CHẶN USER NHẬP ĐƯỜNG DẪN VÀO TRANG CỦA ADMIN
// =========================================================================
if ($auth_role !== 'admin') {
    // Lấy tên file hiện tại mà người dùng đang truy cập (ví dụ: 'products.php')
    $current_page = basename($_SERVER['SCRIPT_NAME']);

    // Danh sách các trang cấm tài khoản 'user' (nhân viên) truy cập trực tiếp
    $restricted_pages = ['index.php', 'products.php', 'warehouses.php', 'profile_users.php'];

    if (in_array($current_page, $restricted_pages)) {
        // Chuyển hướng ngay lập tức nhân viên về trang được phép truy cập (ví dụ: vouchers.php hoặc inventory.php)
        header("Location: vouchers.php");
        exit;
    }
}

// XỬ LÝ ĐỘNG PHẦN HIỂN THỊ TÊN:
// - Nếu là admin: Hiển thị cố định chữ "Quản trị viên"
// - Nếu là nhân viên (user): Hiển thị Tên nhân viên lấy từ Session, nếu trống thì để mặc định "Nhân viên"
if ($auth_role === 'admin') {
    $admin_name = 'Quản trị viên';
} else {
    $admin_name = isset($_SESSION['auth_name']) ? $_SESSION['auth_name'] : 'Nhân viên';
}

// Tạo link ảnh đại diện tự động dựa theo Tên hiển thị (Nền indigo, chữ trắng, bo tròn)
$avatar_url = "https://ui-avatars.com/api/?name=" . urlencode($admin_name) . "&background=4f46e5&color=fff&rounded=true&bold=true";


// =========================================================================
// MẢNG DỮ LIỆU TIN NHẮN (Đã thêm trường 'full_content' để hiển thị trong Popup)
// =========================================================================
$unread_messages = [
    [
        'id' => 1,
        'sender' => 'Nguyễn Văn Minh',
        'avatar' => 'https://ui-avatars.com/api/?name=Minh&background=f0f2fe&color=4f46e5&rounded=true',
        'content' => 'Sếp ơi, em vừa cập nhật lại báo cáo tuần này rồi ạ...',
        'full_content' => 'Sếp ơi, em vừa cập nhật lại toàn bộ số liệu báo cáo tồn kho của tuần này rồi ạ. Có một số mặt hàng linh kiện đang bị lệch số so với thực tế ở kho QN-02, sếp duyệt lại giúp em trong file đính kèm nhé!',
        'time' => '5 phút trước'
    ],
    [
        'id' => 2,
        'sender' => 'Hệ Thống Bảo Mật',
        'avatar' => 'https://ui-avatars.com/api/?name=SYS&background=fef2f2&color=ef4444&rounded=true',
        'content' => 'Phát hiện thiết bị lạ đăng nhập tài khoản nhân viên...',
        'full_content' => 'CẢNH BÁO: Hệ thống phát hiện một thiết bị lạ (IP: 14.232.245.89, Vị trí: Hà Nội) cố gắng đăng nhập vào tài khoản của nhân viên kho hàng lúc 19:40. Vui lòng kiểm tra lại nhật ký bảo mật hệ thống.',
        'time' => '20 phút trước'
    ]
];
$unread_count = count($unread_messages);

// =========================================================================
// XỬ LÝ ACTIVE MENU TỰ ĐỘNG DỰA TRÊN TÊN FILE HIỆN TẠI VÀ QUYỀN USER
// =========================================================================
$active_page = basename($_SERVER['SCRIPT_NAME']);

// Hàm helper để xác định class CSS cho các menu thông thường (Home, Sản phẩm, Kho, Phiếu...)
function getMenuClass($page_name, $active_page) {
    if ($active_page === $page_name) {
        // Menu được chọn: Nền trắng, chữ Indigo đậm nổi bật hoàn toàn
        return "text-indigo-700 bg-white px-3 py-1.5 rounded-lg font-bold shadow-md transition duration-150";
    }
    // Menu bình thường: Chữ trắng sáng hẳn, hover lên nền xanh đậm hơn
    return "text-white hover:text-white hover:bg-indigo-700/80 px-3 py-1.5 rounded-lg font-medium transition duration-150";
}
?>
<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quản Lý Kho</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>

<body class="bg-gray-100 min-h-screen font-sans">
    <nav class="bg-indigo-600 text-white shadow-lg">
        <div class="container mx-auto px-6 py-2 flex justify-between items-center">
            
            <a href="index.php" class="flex items-center space-x-3 text-xl font-bold tracking-wider group">
                <span>QUẢN LÝ KHO</span>
            </a>

            <div class="flex items-center space-x-8">
                <div class="flex space-x-4 font-medium items-center border-r border-indigo-500/40 pr-6">
                    
                    <?php 
                    $is_inventory_active = ($active_page === 'inventory.php' || ($auth_role === 'user' && !in_array($active_page, ['vouchers.php'])));
                    $inventory_class = $is_inventory_active 
                        ? "text-indigo-700 bg-white px-3 py-1.5 rounded-lg font-bold shadow-md transition duration-150" 
                        : "text-white bg-indigo-500/40 hover:bg-indigo-700 hover:text-white px-3 py-1.5 rounded-lg font-medium transition duration-150";
                    ?>
                    <a href="inventory.php" class="<?= $inventory_class ?>">Báo Cáo Tồn Kho</a>
                    
                    <?php if ($auth_role === 'admin'): ?>
                        <?php 
                        $is_home_active = ($active_page === 'index.php' || !in_array($active_page, ['products.php', 'warehouses.php', 'vouchers.php', 'inventory.php']));
                        $home_class = $is_home_active 
                            ? "text-indigo-700 bg-white px-3 py-1.5 rounded-lg font-bold shadow-md transition duration-150" 
                            : "text-white hover:text-white hover:bg-indigo-700/80 px-3 py-1.5 rounded-lg font-medium transition duration-150";
                        ?>
                        <a href="index.php" class="<?= $home_class ?>">Home</a>
                        
                        <a href="products.php" class="<?= getMenuClass('products.php', $active_page) ?>">Sản Phẩm</a>
                        <a href="warehouses.php" class="<?= getMenuClass('warehouses.php', $active_page) ?>">Kho Hàng</a>
                    <?php endif; ?>
                    
                    <a href="vouchers.php" class="<?= getMenuClass('vouchers.php', $active_page) ?>">Phiếu Nhập / Xuất Kho</a>
                </div>

                <div class="flex items-center space-x-4">
                    
                    <div class="relative inline-block text-left">
                        <div>
                            <button type="button" id="avatarMenuBtn" class="flex items-center focus:outline-none group">
                                <img src="<?= $avatar_url ?>" alt="Avatar" class="w-11 h-11 rounded-full object-cover border-2 border-indigo-400/60 shadow-md group-hover:border-white transition-all duration-200">
                            </button>
                        </div>

                        <div id="avatarDropdown" class="hidden absolute right-0 mt-3 w-52 rounded-xl bg-white text-gray-800 shadow-2xl border border-gray-100 ring-1 ring-black/5 focus:outline-none z-50 overflow-hidden transform transition duration-150 origin-top-right">
                            <div class="px-4 py-3 bg-indigo-50/60 border-b border-indigo-100">
                                <p class="text-[10px] uppercase font-bold text-indigo-500 tracking-wider">Đang đăng nhập</p>
                                <p class="text-sm font-bold text-gray-800 mt-0.5 truncate"><?= htmlspecialchars($admin_name) ?></p>
                            </div>

                            <div class="py-1.5">
    <?php 
    $is_profile_active = ($active_page === 'profile.php');
    $profile_class = $is_profile_active 
        ? "bg-indigo-600 text-white font-bold" 
        : "text-gray-700 hover:bg-indigo-600 hover:text-white font-bold";
    ?>
    <a href="profile.php" class="flex items-center px-4 py-2.5 text-xs transition-colors duration-150 <?= $profile_class ?>">
        <span class="mr-2.5 text-sm <?= $is_profile_active ? 'text-white' : '' ?>">👤</span> Thông tin người dùng
    </a>

    <?php if ($auth_role === 'admin'): ?>
        <?php 
        $is_users_active = ($active_page === 'profile_users.php');
        $users_class = $is_users_active 
            ? "bg-indigo-600 text-white font-bold" 
            : "text-gray-700 hover:bg-indigo-600 hover:text-white font-bold";
        ?>
        <a href="profile_users.php" class="flex items-center px-4 py-2.5 text-xs transition-colors duration-150 <?= $users_class ?>">
            <span class="mr-2.5 text-sm <?= $is_users_active ? 'text-white' : '' ?>">👥</span> Quản lý user
        </a>
    <?php endif; ?>

    <hr class="border-gray-100 my-1">

    <a href="logout.php"
        onclick="return confirm('Bạn có chắc chắn muốn đăng xuất khỏi hệ thống?');"
        class="flex items-center px-4 py-2.5 text-xs font-bold text-red-600 hover:bg-red-600 hover:text-white transition-colors duration-150">
        <span class="mr-2.5 text-sm">➔</span> Đăng xuất
    </a>
</div>
                        </div>
                    </div>

                </div>
            </div>
        </div>
    </nav>
    
    <div id="messageModal" class="hidden fixed inset-0 bg-black/50 flex items-center justify-center z-[100] transition-opacity duration-200">
        <div class="bg-white rounded-xl shadow-2xl w-full max-w-md mx-4 overflow-hidden transform transition-all">
            <div class="px-5 py-4 border-b border-gray-100 flex items-center justify-between bg-gray-50/80">
                <div class="flex items-center">
                    <img id="modalAvatar" src="" alt="Avatar" class="w-9 h-9 rounded-full mr-3 border border-gray-200">
                    <div>
                        <h4 id="modalSender" class="text-sm font-bold text-gray-800"></h4>
                        <p id="modalTime" class="text-[10px] text-gray-400 mt-0.5"></p>
                    </div>
                </div>
                <button type="button" id="closeModalBtn" class="text-gray-400 hover:text-gray-600 p-1.5 hover:bg-gray-100 rounded-lg transition outline-none">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" class="w-4 h-4">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>
            <div class="p-6">
                <p id="modalContent" class="text-xs text-gray-600 leading-relaxed whitespace-pre-line"></p>
            </div>
            <div class="px-5 py-3.5 bg-gray-50/50 border-t border-gray-100 flex justify-end">
                <button type="button" id="closeModalBtn2" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-bold rounded-lg shadow transition">
                    Đã hiểu
                </button>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const avatarBtn = document.getElementById('avatarMenuBtn');
            const avatarDropdown = document.getElementById('avatarDropdown');
            
            const msgBtn = document.getElementById('msgMenuBtn');
            const msgDropdown = document.getElementById('msgDropdown');

            const msgCountBadge = document.getElementById('msgCountBadge');
            const msgCountText = document.getElementById('msgCountText');
            const clearAllMsgBtn = document.getElementById('clearAllMsgBtn');
            const emptyPlaceholder = document.getElementById('emptyMsgPlaceholder');

            const modal = document.getElementById('messageModal');
            const modalAvatar = document.getElementById('modalAvatar');
            const modalSender = document.getElementById('modalSender');
            const modalTime = document.getElementById('modalTime');
            const modalContent = document.getElementById('modalContent');
            const closeBtn = document.getElementById('closeModalBtn');
            const closeBtn2 = document.getElementById('closeModalBtn2');

            function updateMessageCounters() {
                const currentItems = document.querySelectorAll('.msg-item').length;
                if (!msgCountText) return;
                msgCountText.textContent = currentItems + " Mới";
                
                if (currentItems === 0) {
                    if(msgCountBadge) msgCountBadge.classList.add('hidden');
                    if(clearAllMsgBtn) clearAllMsgBtn.classList.add('hidden');
                    if(emptyPlaceholder) emptyPlaceholder.classList.remove('hidden');
                } else {
                    if(msgCountBadge) msgCountBadge.classList.remove('hidden');
                    if(clearAllMsgBtn) clearAllMsgBtn.classList.remove('hidden');
                    if(emptyPlaceholder) emptyPlaceholder.classList.add('hidden');
                }
            }

            if(avatarBtn && avatarDropdown) {
                avatarBtn.addEventListener('click', function(event) {
                    event.stopPropagation();
                    avatarDropdown.classList.toggle('hidden');
                    if(msgDropdown) msgDropdown.classList.add('hidden');
                });
            }

            if(msgBtn && msgDropdown) {
                msgBtn.addEventListener('click', function(event) {
                    event.stopPropagation();
                    msgDropdown.classList.toggle('hidden');
                    avatarDropdown.classList.add('hidden');
                });
            }

            document.querySelectorAll('.open-msg-btn').forEach(button => {
                button.addEventListener('click', function(e) {
                    e.stopPropagation();
                    if(modalAvatar) modalAvatar.src = this.getAttribute('data-avatar');
                    if(modalSender) modalSender.textContent = this.getAttribute('data-sender');
                    if(modalTime) modalTime.textContent = this.getAttribute('data-time');
                    if(modalContent) modalContent.textContent = this.getAttribute('data-content');

                    if(msgDropdown) msgDropdown.classList.add('hidden');
                    if(modal) modal.classList.remove('hidden');
                });
            });

            document.querySelectorAll('.delete-single-msg-btn').forEach(btn => {
                btn.addEventListener('click', function(e) {
                    e.stopPropagation();
                    const parentItem = this.closest('.msg-item');
                    if (parentItem) {
                        parentItem.remove();
                        updateMessageCounters();
                    }
                });
            });

            if(clearAllMsgBtn) {
                clearAllMsgBtn.addEventListener('click', function(e) {
                    e.stopPropagation();
                    if(confirm('Bạn có chắc chắn muốn xóa toàn bộ thông báo hiển thị?')) {
                        document.querySelectorAll('.msg-item').forEach(item => {
                            item.remove();
                        });
                        updateMessageCounters();
                    }
                });
            }

            function closeModal() {
                if(modal) modal.classList.add('hidden');
            }

            if(closeBtn) closeBtn.addEventListener('click', closeModal);
            if(closeBtn2) closeBtn2.addEventListener('click', closeModal);

            if(modal) {
                modal.addEventListener('click', function(event) {
                    if (event.target === modal) {
                        closeModal();
                    }
                });
            }

            document.addEventListener('click', function(event) {
                if (avatarBtn && avatarDropdown && !avatarBtn.contains(event.target) && !avatarDropdown.contains(event.target)) {
                    avatarDropdown.classList.add('hidden');
                }
                if (msgBtn && msgDropdown && !msgBtn.contains(event.target) && !msgDropdown.contains(event.target)) {
                    msgDropdown.classList.add('hidden');
                }
            });
        });
    </script>
</body>
</html>