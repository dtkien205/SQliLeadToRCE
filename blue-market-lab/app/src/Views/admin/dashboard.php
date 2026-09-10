<section class="page-heading compact">
    <p class="eyebrow">Admin</p>
    <h1>Dashboard</h1>
</section>

<section class="stat-grid">
    <?php foreach ($stats as $label => $value): ?>
        <article class="stat-card">
            <span><?= h(ucfirst($label)) ?></span>
            <strong><?= h($value) ?></strong>
        </article>
    <?php endforeach; ?>
</section>

<section class="admin-workspace">
    <a href="/admin/media">
        <strong>Media Library</strong>
        <span>Upload and review product files.</span>
    </a>
    <a href="/admin/templates">
        <strong>Templates</strong>
        <span>Edit outbound content layouts.</span>
    </a>
    <a href="/admin/reports">
        <strong>Reports</strong>
        <span>Open system and seller reports.</span>
    </a>
</section>
