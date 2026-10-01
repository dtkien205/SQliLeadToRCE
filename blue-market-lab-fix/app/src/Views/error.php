<section class="error-panel">
    <p class="eyebrow"><?= h((string) http_response_code()) ?></p>
    <h1><?= h($title) ?></h1>
    <p><?= h($message) ?></p>
    <?php if ($details): ?>
        <pre class="terminal"><?= h($details) ?></pre>
    <?php endif; ?>
</section>
