<section class="profile-header">
    <span class="avatar large"><?= h(strtoupper(substr($user['username'], 0, 2))) ?></span>
    <div>
        <p class="eyebrow"><?= h($user['role']) ?></p>
        <h1><?= h($user['username']) ?></h1>
        <p><?= h($user['description']) ?></p>
        <small><?= h($user['email']) ?></small>
    </div>
</section>

<section class="section-grid">
    <div>
        <div class="section-heading">
            <h2>Seller Activity Report</h2>
            <span>Profile backend</span>
        </div>

        <?php if ($activityError): ?>
            <div class="notice danger"><?= h($activityError) ?></div>
        <?php endif; ?>

        <div class="timeline">
            <?php foreach ($activity as $item): ?>
                <article>
                    <time><?= h(date('M d, H:i', strtotime($item['created_at']))) ?></time>
                    <h3><?= h($item['title']) ?></h3>
                    <p><?= h($item['body']) ?></p>
                </article>
            <?php endforeach; ?>
            <?php if (!$activity): ?>
                <article>
                    <time>now</time>
                    <h3>No activity rows</h3>
                    <p>The reporting backend returned no seller activity for this profile.</p>
                </article>
            <?php endif; ?>
        </div>
    </div>

    <aside class="side-panel">
        <h2>Products</h2>
        <table class="mini-table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Category</th>
                    <th>Price</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($products as $product): ?>
                    <tr>
                        <td><?= h($product['name']) ?></td>
                        <td><?= h($product['category']) ?></td>
                        <td>$<?= h(number_format((float) $product['price'], 2)) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <table class="mini-table spaced">
            <tbody>
                <tr>
                    <td>Backend</td>
                    <td>seller_activity</td>
                </tr>
                <tr>
                    <td>Role</td>
                    <td>app_user</td>
                </tr>
                <tr>
                    <td>Primitive</td>
                    <td><code>PostgreSQL Extension</code></td>
                </tr>
            </tbody>
        </table>

        <?php if (($currentUser['id'] ?? null) === (int) $user['id']): ?>
            <form class="stacked-form" action="/profile/update" method="post">
                <label>
                    Email
                    <input name="email" value="<?= h($user['email']) ?>">
                </label>
                <label>
                    Description
                    <textarea name="description" rows="4"><?= h($user['description']) ?></textarea>
                </label>
                <button type="submit">Save Profile</button>
            </form>
        <?php endif; ?>
    </aside>
</section>
