async function handleImageProcess(input, inputKey) {
    const file = input.files[0];
    if (!file) return;
    
    // 1. Hiện Loading
    document.getElementById('loading-modal').classList.remove('hidden');
    
    // Giải mã config từ Base64 đã lưu trong dataset
    const config = JSON.parse(atob(input.dataset.config));

    const hiddenInput = document.getElementById(`input-${inputKey}`);
    const previewImg = document.getElementById(`img-view-${inputKey}`);
    const placeholderDiv = document.getElementById(`img-placeholder-${inputKey}`);
    
    const img = new Image();
    img.src = URL.createObjectURL(file);
    
    img.onload = async () => {
        const canvas = document.createElement('canvas');
        let width = img.width;
        let height = img.height;
        const maxSize = config.max_size || 1200;

        // 1. Tính toán kích thước (Scale)
        if (width > height && width > maxSize) { height *= maxSize / width; width = maxSize; }
        else if (height > maxSize) { width *= maxSize / height; height = maxSize; }

        canvas.width = width;
        canvas.height = height;
        const ctx = canvas.getContext('2d');
        ctx.drawImage(img, 0, 0, width, height);

        // 2. Vẽ Timestamp & Địa điểm (nếu bật)
        if (config.draw_timestamp) {
            const timeStr = new Date().toLocaleString('vi-VN');
            
            ctx.font = "bold 24px Arial";
            ctx.fillStyle = "rgba(255, 255, 0, 0.8)"; // Màu vàng
            ctx.shadowColor = "black";
            ctx.shadowBlur = 5;
            ctx.fillText(timeStr, 20, 40);
            ctx.fillText(window.locationData?.id || '-', 20, 70);
            ctx.fillText(window.locationData?.name || '-', 20, 100);
            ctx.fillText(window.locationData?.region || '-', 20, 130);
        }
        
        // Hiển thị preview
        placeholderDiv.classList.add('hidden');
        previewImg.src = canvas.toDataURL('image/jpeg', 0.8);
        previewImg.classList.remove('hidden');
        
        const d = new Date();
        const formatted = d.toISOString().replace('T', '_').slice(0, 19).replace(/:/g, '');
        // Cập nhật input hidden để submit form
        hiddenInput.value = `${window.currentFormID}/${inputKey}_${formatted}.jpg`;
        
        // 1. Ẩn Loading
        document.getElementById('loading-modal').classList.add('hidden');
    };

	img.onload = async () => {
    const canvas = document.createElement('canvas');
    let width = img.width;
    let height = img.height;
    const maxSize = config.max_size || 1200;

    // --- CHÈN DEBUG TRƯỚC KHI NÉN ---
    console.log("[DEBUG R2] Kích thước ảnh gốc:", img.width, "x", img.height);
    console.log("[DEBUG R2] Dung lượng file gốc:", (file.size / 1024).toFixed(2), "KB");

    // Tính toán kích thước mới (Scale)
    if (width > height && width > maxSize) { height *= maxSize / width; width = maxSize; }
    else if (height > maxSize) { width *= maxSize / height; height = maxSize; }

    canvas.width = width;
    canvas.height = height;
    const ctx = canvas.getContext('2d');
    ctx.drawImage(img, 0, 0, width, height);

    // --- CHÈN DEBUG SAU KHI SCALE KÍCH THƯỚC ---
    console.log("[DEBUG R2] Kích thước sau khi scale:", width, "x", height);

    // Đoạn code xuất dữ liệu canvas thành Blob (ảnh đã nén chất lượng...)
    canvas.toBlob(async (blob) => {
        if (!blob) {
            console.error("[DEBUG R2] Lỗi: Không thể xuất ảnh từ Canvas thành Blob!");
            return;
        }

        // --- CHÈN DEBUG DUNG LƯỢNG SAU NÉN ---
        console.log("[DEBUG R2] Định dạng nén:", blob.type);
        console.log("[DEBUG R2] Dung lượng sau khi nén:", (blob.size / 1024).toFixed(2), "KB");

        // Tiếp tục luồng lấy presigned URL và fetch PUT lên R2...
        // Bạn có thể thêm log ở đây:
        console.log("[DEBUG R2] Đang xin cấp quyền upload từ r2_api.php...");
        
        const preRes = await fetch(`r2_api.php?action=get-presigned-url...`);
        const preData = await preRes.json();
        
        console.log("[DEBUG R2] URL ký gửi nhận được từ Cloudflare:", preData?.upload_url);
        
        // Đoạn fetch PUT ảnh...
    }, 'image/jpeg', 0.85); // 0.85 là mức chất lượng nén
};
}
async function saveImageEdit() {
	if(!currentImgData?.id || !currentImgData?.col) {
		alert('Không tìm thấy [id] hoặc [key] hình!');
		return;
	}
	
	showLoading();
	
	try {
		// const updateFileName = currentImgData?.val ? false : true;
		const d = new Date();
		const formatted = d.toISOString().replace('T', '_').slice(0, 19).replace(/:/g, '');
		currentImgData.val = currentImgData?.val || `${currentTable}/${currentImgData.col}_${formatted}.jpg`;
		
		const canvas = document.getElementById('imageCanvas');
		// Chuyển canvas thành base64 hoặc blob
		const base64Data = canvas.toDataURL('image/jpeg', 0.8);
	
		if (base64Data && base64Data.startsWith('data:')) {
			// Chỉ fetch và chuyển đổi sang blob MỘT LẦN DUY NHẤT
			const response = await fetch(base64Data);
			if (!response.ok) throw new Error('Network response was not ok');
			
			const blob = await response.blob(); 
			// Sau dòng này, 'response' đã bị đóng stream, không được gọi response.blob() nữa.

			const fileName = currentImgData.val || '';

			// Gọi API lấy link presigned
			const preRes = await fetch(`r2_api.php?action=get-presigned-url&file=${encodeURIComponent(fileName)}&type=${encodeURIComponent(blob.type)}`);
			const preData = await preRes.json();
			
			

			if (preData?.upload_url) {
				const uploadRes = await fetch(preData.upload_url, {
					method: 'PUT',
					body: blob, // Gửi biến blob đã tạo ở trên
					headers: { 'Content-Type': blob.type }
				});
				if(uploadRes.ok) {
					alert("Đã cập nhật ảnh thành công!");
					// if(updateFileName) {
						const fd = new FormData();
						fd.append('table', currentTable);
						fd.append('id', currentImgData.id);
						fd.append('json_data', JSON.stringify({ [currentImgData.col] : currentImgData.val }));
						fd.append('action', 'updateFileName');
					
						fetch('api_admin.php', { method: 'POST', body: fd })
						.then(r => r.json()).then(res => {
							if(res.success) { 
								// location.reload(); 
								if(currentImgData?.elImage) {
									currentImgData.elImage.src = `<?= $r2_config['public_path'] ?>/${currentImgData.val}?${new Date().getTime()}`;
								}
							} else { alert(res.msg); }
						});
					// }
					closeImageModal();
				}
			}
		}
	} catch (err) {
		console.error("Lỗi:", err);
		alert("Có lỗi xảy ra trong quá trình lưu ảnh.");
	} finally {
		hideLoading();
	}
    
}