<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\PostgresService;

final class AuthController extends BaseController
{
    public function loginForm(): void
    {
        $this->render('auth/login', ['title' => 'Login']);
    }

    public function login(): void
    {
        $username = trim((string) ($_POST['username'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $db = new PostgresService();

        if ($this->isFixed()) {
            $user = $db->paramsOne('SELECT * FROM users WHERE username = $1', [$username]);
        } else {
            $user = $db->queryOne("SELECT * FROM users WHERE username = '" . $username . "'");
        }

        if (!$user || !$this->passwordMatches($password, (string) $user['password_hash'])) {
            \flash('Invalid username or password.');
            \redirect('/login');
        }

        $_SESSION['user'] = [
            'id' => (int) $user['id'],
            'username' => $user['username'],
            'email' => $user['email'],
            'role' => $user['role'],
        ];

        \redirect(($user['role'] ?? '') === 'admin' ? '/admin' : '/profile?id=' . $user['id']);
    }

    public function registerForm(): void
    {
        $this->render('auth/register', ['title' => 'Register']);
    }

    public function register(): void
    {
        $username = trim((string) ($_POST['username'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');

        if ($username === '' || $email === '' || $password === '') {
            \flash('Please fill in all required fields.');
            \redirect('/register');
        }

        if ($this->isFixed() && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            \flash('Fixed mode requires a valid email address.');
            \redirect('/register');
        }

        $db = new PostgresService();
        $db->executeParams(
            'INSERT INTO users(username, email, password_hash, role, description)
             VALUES ($1, $2, $3, $4, $5)',
            [$username, $email, password_hash($password, PASSWORD_DEFAULT), 'member', 'New BlueMarket member.']
        );

        \flash('Your account has been created. You can sign in now.');
        \redirect('/login');
    }

    public function logout(): void
    {
        unset($_SESSION['user']);
        \redirect('/');
    }

    private function passwordMatches(string $password, string $stored): bool
    {
        return password_verify($password, $stored) || hash_equals($stored, $password);
    }
}
