<section class="page-heading compact">
    <p class="eyebrow">Admin</p>
    <h1>Templates</h1>
</section>

<section class="section-grid">
    <form class="side-panel stacked-form" action="/admin/templates" method="post">
        <h2>Edit Template</h2>
        <label>
            Name
            <input name="name" value="campaign.html">
        </label>
        <label>
            Content
            <textarea name="content" rows="10"><section><h1>BlueMarket Campaign</h1></section></textarea>
        </label>
        <button type="submit">Save Template</button>
    </form>

    <div>
        <div class="section-heading">
            <h2>Saved Templates</h2>
            <span><?= count($templates) ?> files</span>
        </div>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Updated</th>
                    <th>Preview</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($templates as $template): ?>
                    <tr>
                        <td><?= h($template['name']) ?></td>
                        <td><?= h($template['updated_at']) ?></td>
                        <td><code><?= h(substr($template['content'], 0, 80)) ?></code></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
