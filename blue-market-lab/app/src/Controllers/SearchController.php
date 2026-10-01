<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\PostgresService;
use App\Services\SqliteCacheService;

final class SearchController extends BaseController
{
    public function products(): void
    {
        $db = new PostgresService();
        $products = $db->params(
            'SELECT p.id, p.name, p.price, p.category, p.description, p.image_url, u.username AS seller
             FROM products p
             JOIN users u ON u.id = p.owner_id
             ORDER BY p.id',
            []
        );

        $this->render('products', [
            'title' => 'Products',
            'products' => $products,
        ]);
    }

    public function search(): void
    {
        $q = trim((string) ($_GET['q'] ?? ''));
        $db = new PostgresService();
        $cache = new SqliteCacheService();
        $cacheError = null;

        if ($q !== '') {
            try {
                $cache->logSearch($q);
            } catch (\Throwable $exception) {
                $cacheError = $exception->getMessage();
            }
        }

        $safeForLike = str_replace("'", "''", $q);
        $products = $db->queryAll(
            "SELECT p.id, p.name, p.price, p.category, p.description, p.image_url, u.username AS seller
             FROM products p
             JOIN users u ON u.id = p.owner_id
             WHERE p.name ILIKE '%{$safeForLike}%'
                OR p.description ILIKE '%{$safeForLike}%'
                OR p.category ILIKE '%{$safeForLike}%'
             ORDER BY p.id"
        );

        $this->render('search', [
            'title' => 'Search',
            'query' => $q,
            'products' => $products,
            'recentSearches' => $cache->recentSearches(),
            'cacheError' => $cacheError,
        ]);
    }
}
