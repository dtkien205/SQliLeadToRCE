<section class="page-heading compact">
    <p class="eyebrow">Product Search</p>
    <h1>Search Catalog</h1>
    <form class="search-form wide" action="/search" method="get">
        <input type="search" name="q" value="<?= h($query) ?>" placeholder="laptop, camera, desk">
        <button type="submit">Search</button>
    </form>
</section>

<?php if ($cacheError): ?>
    <div class="notice danger"><?= h($cacheError) ?></div>
<?php endif; ?>

<section class="section-grid">
    <div>
        <div class="section-heading">
            <h2>Results</h2>
            <span><?= count($products) ?> items</span>
        </div>
        <div class="product-grid compact-grid">
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
        <h2>Analytics Cache</h2>
        <table class="mini-table">
            <thead>
                <tr>
                    <th>Keyword</th>
                    <th>Time</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($recentSearches as $search): ?>
                    <tr>
                        <td><?= h($search['keyword']) ?></td>
                        <td><?= h($search['created_at']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </aside>
</section>
