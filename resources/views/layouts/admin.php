<?php // resources/views/layouts/admin.php ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $this->yield('title') ?> - Admin</title>
    <link rel="stylesheet" href="/assets/css/admin.css">
</head>
<body class="admin-body">
    <nav class="admin-nav">
        <a href="/admin/content">Content</a>
        <a href="/admin/fields">Fields</a>
        <a href="/admin/taxonomy">Taxonomy</a>
        <a href="/admin/blocks">Blocks</a>
        <a href="/admin/search/rebuild">Search</a>
    </nav>
    <main class="admin-main">
        <?php if ($message = flash('success')): ?>
            <div class="alert alert-success"><?= e($message) ?></div>
        <?php endif; ?>
        <?= $this->yield('content') ?>
    </main>
</body>
</html>