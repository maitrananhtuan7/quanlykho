<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <title>Upload Ảnh lên R2</title>
    <style>
        .hidden { display: none; }
        .preview-img { max-width: 300px; margin-top: 10px; border: 1px solid #ddd; }
        #loading-modal { padding: 20px; background: #f0f0f0; border: 1px solid #ccc; margin-top: 10px; }
    </style>
</head>
<body>

    <h2>Trang Upload Ảnh</h2>

    <input type="file" id="file-input" accept="image/*" 
           data-config="<?= base64_encode(json_encode(['max_size' => 1200, 'draw_timestamp' => true])) ?>" 
           onchange="handleImageProcess(this, 'avatar')">

    <input type="hidden" id="input-avatar" name="avatar">

    <div id="img-placeholder-avatar">Vui lòng chọn ảnh...</div>
    <img id="img-view-avatar" class="preview-img hidden" src="" alt="Preview">

    <div id="loading-modal" class="hidden">Đang xử lý ảnh và upload lên Cloudflare...</div>

    <br>
    <button onclick="saveImageEdit()">Lưu thay đổi</button>

    <script>
        // CÁC BIẾN CẤU HÌNH MÔI TRƯỜNG (Cần khai báo để file upload_r2.js chạy)
        const currentTable = 'products'; 
        const currentFormID = 'item_001';
        window.locationData = { id: 'SKU-001', name: 'Sản phẩm mẫu', region: 'VN' };
        
        // Cấu hình đối tượng để hàm saveImageEdit làm việc
        let currentImgData = {
            id: 1, 
            col: 'avatar',
            val: null, // Sẽ được cập nhật từ giá trị của input-avatar
            elImage: document.getElementById('img-view-avatar')
        };

        // Hàm hỗ trợ cho file JS
        function showLoading() { document.getElementById('loading-modal').classList.remove('hidden'); }
        function hideLoading() { document.getElementById('loading-modal').classList.add('hidden'); }
        function closeImageModal() { console.log('Đã đóng modal'); }
    </script>

    <script src="../Quan ly kho/upload_r2.js"></script>
</body>
</html>