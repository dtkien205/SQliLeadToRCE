<section class="hero-band">
    <div class="hero-copy">
        <p class="eyebrow">Internal commerce and content hub</p>
        <h1>BlueMarket CMS</h1>
        <p>Catalog, seller activity, media, templates, and system reporting share one operational surface.</p>
        <form class="search-form" action="/search" method="get">
            <input type="search" name="q" value="" placeholder="Search products, categories, or sellers">
            <button type="submit">Search</button>
        </form>
    </div>
    <div class="hero-image" role="img" aria-label="BlueMarket product workspace"></div>
</section>

<section class="section-grid">
    <div>
        <div class="section-heading">
            <h2>Featured Products</h2>
            <a href="/products">View all</a>
        </div>
        <div class="product-grid">
            <?php foreach ($products as $product): ?>
                <article class="product-card">
                    <img src="<?= h($product['image_url'] ?? '') ?>" alt="<?= h($product['name']) ?>" loading="lazy">
                    <div>
                        <span class="category"><?= h($product['category']) ?></span>
                        <h3><?= h($product['name']) ?></h3>
                        <p><?= h($product['description']) ?></p>
                    </div>
                    <footer>
                        <strong>$<?= h(number_format((float) $product['price'], 2)) ?></strong>
                        <span><?= h($product['seller']) ?></span>
                    </footer>
                </article>
            <?php endforeach; ?>
        </div>
    </div>

    <aside class="side-panel">
        <h2>Seller Desk</h2>
        <div class="seller-list">
            <?php foreach ($sellers as $seller): ?>
                <a class="seller-row" href="/profile?id=<?= h($seller['id']) ?>">
                    <span><?= h(strtoupper(substr($seller['username'], 0, 2))) ?></span>
                    <div>
                        <strong><?= h($seller['username']) ?></strong>
                        <small><?= h($seller['description']) ?></small>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    </aside>
</section>

<section class="content-band">
    <div class="section-heading">
        <h2>Operations Feed</h2>
        <span>Latest internal posts</span>
    </div>
    <div class="post-list">
        <?php foreach ($posts as $post): ?>
            <article>
                <time><?= h(date('M d, H:i', strtotime($post['created_at']))) ?></time>
                <h3><?= h($post['title']) ?></h3>
                <p><?= h($post['body']) ?></p>
            </article>
        <?php endforeach; ?>
    </div>
</section>
