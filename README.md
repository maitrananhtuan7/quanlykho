#WEBSITE QUẢN LÝ KHO HÀNG

## 1. Giới thiệu dự án

Website Quản lý kho hàng được xây dựng nhằm hỗ trợ doanh nghiệp quản lý sản phẩm, kho lưu trữ, hoạt động nhập xuất hàng hóa và theo dõi số lượng tồn kho.

Hệ thống có phân quyền tài khoản Admin và Nhân viên, giúp quản lý dữ liệu tập trung, dễ dàng theo dõi và kiểm soát hoạt động kho hàng.

## 2. Chức năng chính

### Quản trị viên (Admin)
- Dashboard tổng quan tình hình kho hàng.
- Quản lý danh mục và thông tin sản phẩm.
- Quản lý kho hàng và địa điểm lưu trữ.
- Quản lý phiếu nhập kho và xuất kho.
- Quản lý tài khoản nhân viên và phân quyền.
- Quản lý thông tin cá nhân.
- Cấu hình và quản lý cài đặt hệ thống.

### Nhân viên (User)
- Đăng nhập và quản lý thông tin cá nhân.
- Theo dõi báo cáo tồn kho.
- Tìm kiếm và lọc sản phẩm.
- Theo dõi thông tin phiếu nhập và xuất kho theo quyền được cấp.

### Chức năng hệ thống
- Tự động tính toán số lượng hàng tồn kho.
- Kiểm tra số lượng trước khi xuất kho.
- Theo dõi hạn sử dụng và cảnh báo hàng sắp hết hạn.
- Tìm kiếm, lọc và quản lý dữ liệu.
- Lưu trữ hình ảnh chứng từ trên Cloudflare R2.
- Thống kê số lượng phiếu nhập và xuất bằng biểu đồ.

## 3. Công nghệ sử dụng

| Công nghệ | Vai trò |
|---|---|
| PHP | Xử lý logic Backend |
| MySQL | Quản lý cơ sở dữ liệu |
| HTML5 | Xây dựng cấu trúc giao diện |
| CSS3 | Thiết kế giao diện |
| JavaScript | Xử lý tương tác phía Client |
| Tailwind CSS | Thiết kế giao diện Responsive |
| Chart.js | Hiển thị biểu đồ thống kê |
| Cloudflare R2 | Lưu trữ hình ảnh và chứng từ |

## 4. Cấu trúc dự án

- `index.php` – Trang tổng quan Dashboard.
- `products.php` – Quản lý sản phẩm.
- `warehouses.php` – Quản lý kho hàng.
- `vouchers.php` – Quản lý phiếu nhập xuất.
- `inventory.php` – Báo cáo tồn kho.
- `profile_users.php` – Quản lý tài khoản nhân viên.
- `profile.php` – Quản lý thông tin cá nhân.
- `admin_login.php` – Đăng nhập quản trị viên.
- `user_login.php` – Đăng nhập và giao diện nghiệp vụ nhân viên.
- `admin_setting.php` – Cài đặt hệ thống.
- `db.php` – Cấu hình kết nối cơ sở dữ liệu.
- `r2_api.php` – Xử lý kết nối Cloudflare R2.
- `upload_r2.js` – Xử lý tải hình ảnh lên Cloudflare R2.

## 5. Hướng dẫn cài đặt

**Yêu cầu:**
- PHP 8.x
- MySQL
- XAMPP hoặc môi trường máy chủ PHP tương đương.

**Các bước thực hiện:**

1. Tải hoặc Clone dự án về máy tính.
2. Đặt thư mục dự án vào thư mục `htdocs` của XAMPP.
3. Khởi động Apache và MySQL.
4. Tạo cơ sở dữ liệu MySQL theo cấu hình trong `db.php`.
5. Chuẩn bị và import file SQL chứa cấu trúc bảng và dữ liệu ban đầu.
6. Cấu hình thông tin kết nối cơ sở dữ liệu.
7. Cấu hình thông tin Cloudflare R2 nếu sử dụng chức năng lưu trữ hình ảnh.
8. Truy cập dự án thông qua trình duyệt:

   `http://localhost/Quan%20ly%20kho/`

## 6. Mục tiêu dự án

- Xây dựng website quản lý kho hàng với các chức năng thực tế.
- Áp dụng PHP và MySQL vào phát triển ứng dụng Web.
- Rèn luyện kỹ năng xây dựng giao diện, xử lý dữ liệu và phân quyền người dùng.
- Tìm hiểu cách tích hợp dịch vụ lưu trữ hình ảnh trên nền tảng Cloudflare R2.

---

**Project:** Website Quản lý kho hàng  
**Type:** Web Application  
**Developer:** Cá nhân
