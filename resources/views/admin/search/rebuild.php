<?php // resources/views/admin/search/rebuild.php ?>
<?php $this->layout('layouts.admin') ?>

<?php $this->section('title') ?>Search Index<?php $this->endSection() ?>

<?php $this->section('content') ?>
<div class="admin-content">
    <h1>Search Index Management</h1>

    <div class="stats">
        <p><strong>Indexed items:</strong> <?= $stats['indexed'] ?></p>
        <p><strong>Total content:</strong> <?= $stats['total'] ?></p>
    </div>

    <form method="POST" action="/admin/search/rebuild" class="admin-form">
        <?= \Canyon\Core\Security::csrfField() ?>
        <p>Rebuild the search index from all published content.</p>
        <button type="submit" class="btn btn-primary">Rebuild Search Index</button>
    </form>
</div>
<?php $this->endSection() ?>