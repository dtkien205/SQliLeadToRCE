# BlueMarket CMS demo flow

Tài liệu này map đúng nội dung `command_execution_postgresql.md` vào một website BlueMarket CMS duy nhất như README mô tả. App không tạo hai trang lab rời kiểu `/postgres-copy` và `/postgres-extension`; hai method được đặt trong luồng nghiệp vụ thật.

## Method 1: COPY FROM PROGRAM

Vị trí trong website:

```text
Admin -> Reports -> System Reports
Route: /admin/reports?type=health
Controller: app/src/Controllers/ReportController.php
Database role vulnerable mode: report_user
```

Điểm nối với tài liệu:

```text
SQLi trong report filter
-> stacked query
-> COPY report_worker_output(line) FROM PROGRAM '<command>'
-> SELECT output từ worker log table
-> RCE
```

Trong code vulnerable mode, tham số `type` được nối trực tiếp vào SQL:

```php
$sql = "SELECT type, label, description, query_name
        FROM report_templates
        WHERE type = '" . $type . "'
        ORDER BY id";
```

Role `report_user` được grant `pg_execute_server_program`, nên lab có thể chứng minh command execution bằng các lệnh an toàn như `id`, `whoami`, `hostname`, hoặc `pwd`. Bảng `report_worker_output` là log output có sẵn của worker; trang Reports đọc các dòng mới nhất và hiển thị ở khối Worker Output.

Trong fixed mode, controller dùng parameterized query và kết nối bằng `app_user`, role không có `pg_execute_server_program`.

## Method 2: PostgreSQL Extensions

Vị trí trong website:

```text
Sellers -> Profile -> Seller Activity Report
Route: /profile?id=<seller_id>
Controller: app/src/Controllers/ProfileController.php
Database role vulnerable mode: extension_user
```

Điểm nối với tài liệu:

```text
SQLi
-> Large Object
-> ghi từng page của pg_rev_shell.so vào pg_largeobject
-> lo_export() ghi file ra /tmp
-> CREATE FUNCTION LANGUAGE C
-> SELECT rev_shell(...)
-> reverse shell
-> RCE
```

Trong code vulnerable mode, profile lấy email từ database rồi dùng lại trong truy vấn activity:

```php
$sql = "SELECT title, body, created_at
        FROM posts
        WHERE author_email = '" . $user['email'] . "'
        ORDER BY created_at DESC";
```

Đây là second-order SQLi: input có thể được lưu trước ở `users.email`, sau đó backend report dùng lại trong SQL khác. Từ ngày `2026-08-31`, Hướng 2 của lab này không còn dựa vào file `.so` được đặt sẵn trong runtime container. Thay vào đó, attacker chuẩn bị `pg_rev_shell.so` ở máy host, chia file thành từng page 2048 byte, rồi bơm từng page vào `pg_largeobject` đúng như PoC kiểu script.

Role `extension_user` là superuser trong lab vì `LANGUAGE C`, Large Object export và native function loading đều yêu cầu quyền rất cao.

Trong fixed mode, profile activity dùng parameterized query và `app_user`, nên email được xử lý như dữ liệu, không phải cú pháp SQL.

## Tài khoản seed

```text
admin / admin123
analyst / analyst123
lan_store / seller123
minh_audio / seller123
greenlab / seller123
```

## Chế độ chạy

```text
APP_MODE=vulnerable
APP_MODE=fixed
```

`vulnerable` cho phép quan sát đúng hai chain trong lab. `fixed` dùng prepared statements và role ít quyền để chứng minh biện pháp phòng chống.
