<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h($title ?? $config['app_name']) ?> - <?= h($config['app_name']) ?></title>
    <link rel="stylesheet" href="/assets/styles.css">
</head>
<body>
    <header class="topbar">
        <a class="brand" href="/">
            <span class="brand-mark">BM</span>
            <span>
                <strong>BlueMarket</strong>
                <small>CMS</small>
            </span>
        </a>

        <nav class="main-nav" aria-label="User navigation">
            <a href="/">Home</a>
            <a href="/products">Products</a>
            <a href="/search">Search</a>
            <a href="/sellers">Sellers</a>
            <?php if ($currentUser): ?>
                <a href="/profile?id=<?= h($currentUser['id']) ?>">Profile</a>
            <?php else: ?>
                <a href="/login">Login</a>
                <a href="/register">Register</a>
            <?php endif; ?>
        </nav>

        <div class="session-bar">
            <span class="mode-pill <?= h($mode) ?>"><?= h($mode) ?></span>
            <?php if ($currentUser): ?>
                <span class="user-chip"><?= h($currentUser['username']) ?></span>
                <?php if (($currentUser['role'] ?? '') === 'admin'): ?>
                    <a class="icon-link" href="/admin" title="Admin">Admin</a>
                <?php endif; ?>
                <form action="/logout" method="post">
                    <button class="text-button" type="submit">Logout</button>
                </form>
            <?php endif; ?>
        </div>
    </header>

    <?php if (($currentUser['role'] ?? '') === 'admin'): ?>
        <nav class="admin-nav" aria-label="Admin navigation">
            <a href="/admin">Dashboard</a>
            <a href="/products">Products</a>
            <a href="/sellers">Users</a>
            <a href="/admin/media">Media Library</a>
            <a href="/admin/templates">Templates</a>
            <a href="/admin/reports">Reports</a>
            <a href="/admin">Settings</a>
        </nav>
    <?php endif; ?>

    <main>
        <?php if ($flash): ?>
            <div class="flash"><?= h($flash) ?></div>
        <?php endif; ?>

        <?= $content ?>
    </main>
</body>
</html>
