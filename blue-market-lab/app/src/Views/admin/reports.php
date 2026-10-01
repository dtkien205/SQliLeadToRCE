<section class="page-heading compact">
    <p class="eyebrow">Admin</p>
    <h1>System Reports</h1>
    <form class="search-form wide" action="/admin/reports" method="get">
        <input name="type" value="<?= h($type) ?>" placeholder="health, inventory, sellers">
        <button type="submit">Load Report</button>
    </form>
</section>

<?php if ($error): ?>
    <div class="notice danger"><?= h($error) ?></div>
<?php endif; ?>

<section class="stat-grid">
    <?php foreach ($stats as $label => $value): ?>
        <article class="stat-card">
            <span><?= h($label) ?></span>
            <strong><?= h($value) ?></strong>
        </article>
    <?php endforeach; ?>
</section>

<section class="section-grid">
    <div>
        <div class="section-heading">
            <h2>Report Templates</h2>
            <span><?= count($templates) ?> rows</span>
        </div>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Type</th>
                    <th>Label</th>
                    <th>Description</th>
                    <th>Query</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($templates as $template): ?>
                    <tr>
                        <td><?= h($template['type']) ?></td>
                        <td><?= h($template['label']) ?></td>
                        <td><?= h($template['description']) ?></td>
                        <td><code><?= h($template['query_name']) ?></code></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <aside class="side-panel">
        <h2>Worker Output</h2>
        <?php if ($commandOutput): ?>
            <pre class="terminal"><?php foreach ($commandOutput as $row): ?><?= h($row['line']) . "\n" ?><?php endforeach; ?></pre>
        <?php else: ?>
            <table class="mini-table">
                <tbody>
                    <tr>
                        <td>Status</td>
                        <td>ready</td>
                    </tr>
                    <tr>
                        <td>Role</td>
                        <td>report_user</td>
                    </tr>
                    <tr>
                        <td>Primitive</td>
                        <td><code>COPY FROM PROGRAM</code></td>
                    </tr>
                </tbody>
            </table>
        <?php endif; ?>
    </aside>
</section>
