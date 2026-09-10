# Threat model

BlueMarket CMS là lab nội bộ chạy trong Docker. Mục tiêu là minh họa khi SQL Injection kết hợp với quyền database quá cao thì tác động có thể vượt khỏi đọc dữ liệu và chạm tới command execution.

## Tài sản cần bảo vệ

- Bảng `users`, `products`, `posts`, `orders`.
- Session và role admin trong ứng dụng.
- Filesystem của container web và PostgreSQL.
- Các primitive PostgreSQL nhạy cảm: `COPY FROM PROGRAM`, Large Object file export, `CREATE FUNCTION ... LANGUAGE C`.

## Boundary

- Web app dùng `app_user` cho nghiệp vụ bình thường.
- Report backend trong vulnerable mode dùng `report_user` để demo `COPY FROM PROGRAM`.
- Seller activity backend trong vulnerable mode dùng `extension_user` để demo PostgreSQL Extension.
- Fixed mode thu hẹp tất cả luồng về prepared statements và least privilege.

## Rủi ro chính

| Khu vực | Rủi ro | Điều kiện |
|---|---|---|
| Admin Reports | SQLi nâng thành OS command execution | Stacked query và `pg_execute_server_program` |
| Seller Activity | Second-order SQLi nâng thành native code execution | Email độc hại được lưu trước và backend dùng role quá cao |
| Search Analytics | SQLite SQLi/file write | Cache nối chuỗi và web process ghi được file đích |
| Media/Templates | RCE tầng ứng dụng | Admin takeover và upload/template thiếu kiểm soát |

## Giới hạn lab

Không chạy lab trên hệ thống thật. Không dùng database superuser cho web app ngoài môi trường trình diễn được cấp quyền.
