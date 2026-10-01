<section class="page-heading">
    <p class="eyebrow">Catalog</p>
    <h1>Products</h1>
</section>

<section class="product-grid">
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
</section>
