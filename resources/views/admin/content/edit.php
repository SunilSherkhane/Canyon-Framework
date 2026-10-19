<?php // resources/views/admin/content/edit.php ?>
<?php $this->layout('layouts.admin') ?>

<?php $this->section('title') ?>Edit Content<?php $this->endSection() ?>

<?php $this->section('content') ?>
<div class="admin-content">
    <h1>Edit Content</h1>

    <form method="POST" action="/admin/content/<?= $content['id'] ?>" class="admin-form">
        <?= \Canyon\Core\Security::csrfField() ?>

        <div class="form-group">
            <label for="title">Title</label>
            <input type="text" id="title" name="title" value="<?= e($content['title']) ?>" required maxlength="255">
        </div>

        <div class="form-group">
            <label for="body">Body</label>
            <textarea id="body" name="body" rows="10" required><?= e($content['body']) ?></textarea>
        </div>

        <div class="form-group">
            <label for="status">Status</label>
            <select id="status" name="status">
                <option value="1" <?= $content['status'] == 1 ? 'selected' : '' ?>>Published</option>
                <option value="0" <?= $content['status'] == 0 ? 'selected' : '' ?>>Draft</option>
            </select>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Update Content</button>
            <a href="/admin/content" class="btn">Cancel</a>
        </div>
    </form>
</div>
<?php $this->endSection() ?>