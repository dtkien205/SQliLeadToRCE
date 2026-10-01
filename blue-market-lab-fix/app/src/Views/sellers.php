<section class="page-heading">
    <p class="eyebrow">Marketplace</p>
    <h1>Sellers</h1>
</section>

<section class="seller-grid">
    <?php foreach ($sellers as $seller): ?>
        <a class="seller-card" href="/profile?id=<?= h($seller['id']) ?>">
            <span class="avatar"><?= h(strtoupper(substr($seller['username'], 0, 2))) ?></span>
            <div>
                <h2><?= h($seller['username']) ?></h2>
                <p><?= h($seller['description']) ?></p>
                <small><?= h($seller['email']) ?></small>
            </div>
        </a>
    <?php endforeach; ?>
</section>
