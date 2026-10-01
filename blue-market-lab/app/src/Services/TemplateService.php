<?php

declare(strict_types=1);

namespace App\Services;

final class TemplateService
{
    private string $dir;

    public function __construct()
    {
        $this->dir = (string) \app_config('paths.templates');
        if (!is_dir($this->dir)) {
            mkdir($this->dir, 0775, true);
        }
    }

    public function list(): array
    {
        $files = glob($this->dir . '/*.html') ?: [];
        return array_map(static fn (string $file): array => [
            'name' => basename($file),
            'updated_at' => date('Y-m-d H:i', filemtime($file) ?: time()),
            'content' => file_get_contents($file) ?: '',
        ], $files);
    }

    public function save(string $name, string $content): void
    {
        $name = basename($name ?: 'campaign.html');
        file_put_contents($this->dir . '/' . $name, $content);
    }
}
