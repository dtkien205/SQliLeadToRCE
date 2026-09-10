<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\PostgresService;

final class ProfileController extends BaseController
{
    public function sellers(): void
    {
        $db = new PostgresService();
        $sellers = $db->params(
            "SELECT id, username, email, description FROM users WHERE role = 'seller' ORDER BY id",
            []
        );

        $this->render('sellers', [
            'title' => 'Sellers',
            'sellers' => $sellers,
        ]);
    }

    public function show(): void
    {
        $id = (string) ($_GET['id'] ?? (current_user()['id'] ?? '1'));
        $userDb = new PostgresService();

        if ($this->isFixed()) {
            $user = $userDb->paramsOne(
                'SELECT id, username, email, role, description FROM users WHERE id = $1',
                [$id]
            );
        } else {
            $user = $userDb->queryOne(
                'SELECT id, username, email, role, description FROM users WHERE id = ' . $id
            );
        }

        if (!$user) {
            $this->notFound();
            return;
        }

        $activityDb = new PostgresService($this->isFixed() ? 'app' : 'extension');
        $activityError = null;

        try {
            if ($this->isFixed()) {
                $activity = $activityDb->params(
                    'SELECT title, body, created_at
                     FROM posts
                     WHERE author_email = $1
                     ORDER BY created_at DESC',
                    [$user['email']]
                );
            } else {
                $sql = "SELECT title, body, created_at
                        FROM posts
                        WHERE author_email = '" . $user['email'] . "'
                        ORDER BY created_at DESC";
                $activity = $activityDb->queryAll($sql);
            }
        } catch (\Throwable $exception) {
            $activity = [];
            $activityError = $this->isFixed() ? 'The activity report could not be loaded.' : $exception->getMessage();
        }

        $products = $userDb->params(
            'SELECT id, name, price, category FROM products WHERE owner_id = $1 ORDER BY id',
            [$user['id']]
        );

        $this->render('profile', [
            'title' => 'Profile',
            'user' => $user,
            'products' => $products,
            'activity' => $activity,
            'activityError' => $activityError,
        ]);
    }

    public function update(): void
    {
        $currentUser = current_user();
        if (!$currentUser) {
            \flash('Please sign in to update your profile.');
            \redirect('/login');
        }

        $email = trim((string) ($_POST['email'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));

        if ($this->isFixed() && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            \flash('Fixed mode requires a valid email address.');
            \redirect('/profile?id=' . $currentUser['id']);
        }

        $db = new PostgresService();
        $db->executeParams(
            'UPDATE users SET email = $1, description = $2 WHERE id = $3',
            [$email, $description, $currentUser['id']]
        );

        $_SESSION['user']['email'] = $email;
        \flash('Profile updated.');
        \redirect('/profile?id=' . $currentUser['id']);
    }
}
