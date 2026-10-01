<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\PostgresService;
use App\Services\TemplateService;
use App\Services\UploadService;

final class AdminController extends BaseController
{
    public function dashboard(): void
    {
        $this->requireAdmin();

        $db = new PostgresService();
        $stats = [
            'users' => $db->paramsOne('SELECT count(*) AS total FROM users', [])['total'] ?? 0,
            'products' => $db->paramsOne('SELECT count(*) AS total FROM products', [])['total'] ?? 0,
            'posts' => $db->paramsOne('SELECT count(*) AS total FROM posts', [])['total'] ?? 0,
            'reports' => $db->paramsOne('SELECT count(*) AS total FROM report_templates', [])['total'] ?? 0,
        ];

        $this->render('admin/dashboard', [
            'title' => 'Admin',
            'stats' => $stats,
        ]);
    }

    public function media(): void
    {
        $this->requireAdmin();
        $uploads = new UploadService();

        $this->render('admin/media', [
            'title' => 'Media Library',
            'files' => $uploads->list(),
        ]);
    }

    public function uploadMedia(): void
    {
        $this->requireAdmin();
        (new UploadService())->store($_FILES['media'] ?? []);
        \flash('Media has been saved.');
        \redirect('/admin/media');
    }

    public function templates(): void
    {
        $this->requireAdmin();
        $service = new TemplateService();

        $this->render('admin/templates', [
            'title' => 'Templates',
            'templates' => $service->list(),
        ]);
    }

    public function saveTemplate(): void
    {
        $this->requireAdmin();
        (new TemplateService())->save(
            (string) ($_POST['name'] ?? 'campaign.html'),
            (string) ($_POST['content'] ?? '')
        );

        \flash('Template has been saved.');
        \redirect('/admin/templates');
    }
}
