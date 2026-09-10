<section class="page-heading compact">
    <p class="eyebrow">Admin</p>
    <h1>Media Library</h1>
</section>

<section class="section-grid">
    <form class="side-panel stacked-form" action="/admin/media" method="post" enctype="multipart/form-data">
        <h2>Upload</h2>
        <label>
            File
            <input type="file" name="media">
        </label>
        <button type="submit">Upload Media</button>
    </form>

    <div>
        <div class="section-heading">
            <h2>Files</h2>
            <span><?= count($files) ?> items</span>
        </div>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Size</th>
                    <th>Updated</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($files as $file): ?>
                    <tr>
                        <td><?= h($file['name']) ?></td>
                        <td><?= h(number_format((int) $file['size'])) ?> B</td>
                        <td><?= h($file['updated_at']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
