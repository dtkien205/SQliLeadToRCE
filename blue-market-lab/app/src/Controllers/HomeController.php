<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\PostgresService;

final class HomeController extends BaseController
{
    public function index(): void
    {
        $db = new PostgresService();

        $products = $db->params(
            'SELECT p.id, p.name, p.price, p.category, p.description, p.image_url, u.username AS seller
             FROM products p
             JOIN users u ON u.id = p.owner_id
             ORDER BY p.id
             LIMIT 6',
            []
        );

        $posts = $db->params(
            'SELECT title, body, created_at FROM posts ORDER BY created_at DESC LIMIT 3',
            []
        );

        $sellers = $db->params(
            "SELECT id, username, description FROM users WHERE role = 'seller' ORDER BY id LIMIT 4",
            []
        );

        $this->render('home', [
            'title' => 'BlueMarket CMS',
            'products' => $products,
            'posts' => $posts,
            'sellers' => $sellers,
        ]);
    }

}
