# Vulnerable code notes

Các đoạn dưới đây là vị trí cố ý có lỗi khi `APP_MODE=vulnerable`.

## Product search analytics

```php
$sql = "INSERT INTO search_logs(keyword, created_at) VALUES ('" . $keyword . "', datetime('now'))";
$sqlite->exec($sql);
```

Vị trí: `app/src/Services/SqliteCacheService.php`

## Admin system report

```php
$sql = "SELECT type, label, description, query_name
        FROM report_templates
        WHERE type = '" . $type . "'
        ORDER BY id";
$templates = $db->queryAll($sql);
```

Vị trí: `app/src/Controllers/ReportController.php`

Đây là điểm demo Method 1 trong `command_execution_postgresql.md`: SQLi có thể chạm tới `COPY FROM PROGRAM` nếu role database có quyền.

## Seller activity report

```php
$sql = "SELECT title, body, created_at
        FROM posts
        WHERE author_email = '" . $user['email'] . "'
        ORDER BY created_at DESC";
$activity = $activityDb->queryAll($sql);
```

Vị trí: `app/src/Controllers/ProfileController.php`

Đây là điểm demo Method 2 trong `command_execution_postgresql.md`: email được lưu trước rồi dùng lại trong backend report, tạo second-order SQLi có thể dẫn tới Large Object + Extension nếu role quá cao.
