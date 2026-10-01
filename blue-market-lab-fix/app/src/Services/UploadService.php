<?php

declare(strict_types=1);

namespace App\Services;

final class UploadService
{
    private string $dir;

    public function __construct()
    {
        $this->dir = (string) \app_config('paths.uploads');
        if (!is_dir($this->dir)) {
            mkdir($this->dir, 0775, true);
        }
    }

    public function list(): array
    {
        $files = array_filter(glob($this->dir . '/*') ?: [], 'is_file');
        return array_map(static fn (string $file): array => [
            'name' => basename($file),
            'size' => filesize($file) ?: 0,
            'updated_at' => date('Y-m-d H:i', filemtime($file) ?: time()),
        ], $files);
    }

    public function store(array $file): void
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new \RuntimeException('No valid file was provided for upload.');
        }

        $name = basename((string) $file['name']);

        $allowed = [
            'jpg' => ['image/jpeg'],
            'jpeg' => ['image/jpeg'],
            'png' => ['image/png'],
            'gif' => ['image/gif'],
            'webp' => ['image/webp'],
        ];
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!array_key_exists($extension, $allowed)) {
            throw new \InvalidArgumentException('Only image files (jpg, png, gif, webp) are accepted.');
        }

        $size = (int) ($file['size'] ?? 0);
        if ($size <= 0 || $size > 2_000_000) {
            throw new \InvalidArgumentException('Media files must be under 2 MB.');
        }

        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file((string) $file['tmp_name']) ?: '';
        if (!in_array($mime, $allowed[$extension], true)) {
            throw new \InvalidArgumentException('Only valid media files are accepted.');
        }

        $target = $this->dir . '/' . bin2hex(random_bytes(16)) . '.' . $extension;

        if (!move_uploaded_file((string) $file['tmp_name'], $target)) {
            throw new \RuntimeException('The uploaded file could not be saved.');
        }
    }
}
