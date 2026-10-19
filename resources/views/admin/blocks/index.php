<?php // resources/views/admin/blocks/index.php ?>
<?php $this->layout('layouts.admin') ?>

<?php $this->section('title') ?>Block Management<?php $this->endSection() ?>

<?php $this->section('content') ?>
<div class="admin-content">
    <h1>Block Management</h1>

    <h2>Regions</h2>
    <table class="admin-table">
        <thead>
            <tr>
                <th>Name</th>
                <th>Label</th>
                <th>Theme</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($regions as $region): ?>
                <tr>
                    <td><?= e($region['name']) ?></td>
                    <td><?= e($region['label']) ?></td>
                    <td><?= e($region['theme']) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <h2>Blocks</h2>
    <table class="admin-table">
        <thead>
            <tr>
                <th>Name</th>
                <th>Label</th>
                <th>Type</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($blocks as $block): ?>
                <tr>
                    <td><?= e($block['name']) ?></td>
                    <td><?= e($block['label']) ?></td>
                    <td><?= e($block['type']) ?></td>
                    <td><?= $block['status'] ? 'Enabled' : 'Disabled' ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php $this->endSection() ?>