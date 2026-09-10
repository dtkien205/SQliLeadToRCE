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

    public function store(array $file, string $mode): void
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new \RuntimeException('No valid file was provided for upload.');
        }

        $name = basename((string) $file['name']);
        $target = $this->dir . '/' . $name;

        if ($mode === 'fixed') {
            $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
            $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (!in_array($extension, $allowed, true)) {
                throw new \InvalidArgumentException('Fixed mode only accepts valid media files.');
            }
        }

        if (!move_uploaded_file((string) $file['tmp_name'], $target)) {
            throw new \RuntimeException('The uploaded file could not be saved.');
        }
    }
}
