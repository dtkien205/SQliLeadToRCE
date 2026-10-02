# RealWorld SQLi to RCE Lab

## Ý tưởng tổng thể

Website được thiết kế như một nền tảng nội dung/thương mại điện tử nội bộ có tên:

```text
BlueMarket CMS
```

Ứng dụng có các chức năng giống một website thật:

- Trang chủ hiển thị sản phẩm, bài viết, người bán.
- Tìm kiếm sản phẩm/người dùng.
- Đăng ký, đăng nhập, quên mật khẩu.
- Trang hồ sơ người dùng.
- Trang quản trị nội dung dành cho admin.
- Chức năng upload media/theme/template.
- Chức năng sinh báo cáo hệ thống cho admin.
- Cơ chế cache/analytics nội bộ sử dụng SQLite.
- Database chính sử dụng PostgreSQL.

Các hướng SQLi to RCE không được trình bày như 4 bài lab riêng, mà được ẩn trong các chức năng bình thường của website.

---

## Bốn hướng được nhúng vào một website

| Hướng | Nằm trong chức năng thật của website | Bản chất |
|---|---|---|
| Hướng 1 | Product search / analytics cache | SQLite `ATTACH DATABASE` để ghi file, sau đó file được web server thực thi |
| Hướng 2 | User profile / report backend | PostgreSQL Large Object + C extension để tạo reverse shell |
| Hướng 3 | Admin system report | PostgreSQL `COPY FROM PROGRAM` để chạy command hệ điều hành |
| Hướng 4 | Admin takeover + content management | SQLi chiếm quyền admin, sau đó upload/sửa template để RCE |

Điểm quan trọng: người dùng nhìn thấy **một website duy nhất**. Các hướng chỉ là các **attack chain khác nhau** phát sinh từ những chức năng có vẻ hợp lệ.

### Vị trí các lỗ hổng trong website

| Chức năng | File xử lý | Loại lỗi |
|---|---|---|
| Tìm kiếm sản phẩm | `SearchController.php` | SQLi trên SQLite cache |
| Tìm kiếm người dùng | `SearchController.php` | SQLi trên PostgreSQL |
| Xem profile người bán | `ProfileController.php` | second-order SQLi |
| Xuất báo cáo hệ thống | `ReportController.php` | PostgreSQL command execution nếu DB user có quyền cao |
| Quản lý template/media | `AdminController.php` | upload/template abuse sau khi chiếm admin |
