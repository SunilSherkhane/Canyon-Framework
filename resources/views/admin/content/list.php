<?php // resources/views/admin/content/list.php ?>
<?php $this->layout('layouts.admin') ?>

<?php $this->section('title') ?>Content Management<?php $this->endSection() ?>

<?php $this->section('content') ?>
<div class="admin-content">
    <h1>Content Management</h1>

    <div class="admin-actions">
        <a href="/admin/content/create" class="btn btn-primary">Create Content</a>
    </div>

    <form method="GET" action="/admin/content" class="filter-form">
        <select name="type">
            <option value="">All Types</option>
            <?php foreach ($types as $type): ?>
                <option value="<?= e($type['type']) ?>" <?= ($filters['type'] ?? '') === $type['type'] ? 'selected' : '' ?>>
                    <?= e($type['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <select name="status">
            <option value="">All Status</option>
            <option value="1" <?= ($filters['status'] ?? '') === '1' ? 'selected' : '' ?>>Published</option>
            <option value="0" <?= ($filters['status'] ?? '') === '0' ? 'selected' : '' ?>>Draft</option>
        </select>
        <input type="text" name="search" placeholder="Search..." value="<?= e($filters['search'] ?? '') ?>">
        <button type="submit" class="btn">Filter</button>
    </form>

    <table class="admin-table">
        <thead>
            <tr>
                <th>ID</th>
                <th>Title</th>
                <th>Type</th>
                <th>Author</th>
                <th>Status</th>
                <th>Created</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($content as $item): ?>
                <tr>
                    <td><?= $item['id'] ?></td>
                    <td><a href="/<?= e($item['slug']) ?>"><?= e($item['title']) ?></a></td>
                    <td><?= e($item['type']) ?></td>
                    <td><?= $item['author_id'] ?></td>
                    <td>
                        <span class="badge <?= $item['status'] == 1 ? 'badge-success' : 'badge-warning' ?>">
                            <?= $item['status'] == 1 ? 'Published' : 'Draft' ?>
                        </span>
                    </td>
                    <td><?= date('M j, Y', strtotime($item['created_at'])) ?></td>
                    <td>
                        <a href="/admin/content/<?= $item['id'] ?>" class="btn btn-sm">Edit</a>
                        <form method="POST" action="/admin/content/<?= $item['id'] ?>" style="display:inline">
                            <?= \Canyon\Core\Security::csrfField() ?>
                            <button type="submit" class="btn btn-sm btn-danger" onclick="return confirm('Delete?')">Delete</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php $this->endSection() ?>