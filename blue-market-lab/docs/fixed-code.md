# Fixed mode notes

Khi `APP_MODE=fixed`, BlueMarket chuyển các điểm lỗi chính sang parameterized query và role ít quyền.

## Product search analytics

```php
$stmt = $this->pdo->prepare(
    'INSERT INTO search_logs(keyword, created_at) VALUES (:keyword, datetime("now"))'
);
$stmt->execute(['keyword' => $keyword]);
```

## Admin system report

```php
$templates = $db->params(
    'SELECT type, label, description, query_name
     FROM report_templates
     WHERE type = $1
     ORDER BY id',
    [$type]
);
```

Report dùng `app_user`, không dùng `report_user`, nên không có `pg_execute_server_program`.

## Seller activity report

```php
$activity = $activityDb->params(
    'SELECT title, body, created_at
     FROM posts
     WHERE author_email = $1
     ORDER BY created_at DESC',
    [$user['email']]
);
```

Activity dùng `app_user`, không dùng `extension_user`, nên không có quyền load C function hoặc export file tùy ý.

## Cấu hình quyền

- Web app không dùng PostgreSQL superuser.
- Không grant `pg_execute_server_program` cho `app_user`.
- Không dùng `LANGUAGE C` từ role ứng dụng.
- SQLite cache nằm ngoài webroot.
- Upload fixed mode chỉ nhận media extension hợp lệ.
