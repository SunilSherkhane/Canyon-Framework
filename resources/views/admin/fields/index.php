<?php // resources/views/admin/fields/index.php ?>
<?php $this->layout('layouts.admin') ?>

<?php $this->section('title') ?>Field Management<?php $this->endSection() ?>

<?php $this->section('content') ?>
<div class="admin-content">
    <h1>Field Management</h1>

    <form method="POST" action="/admin/fields" class="admin-form inline-form">
        <?= \Canyon\Core\Security::csrfField() ?>
        <input type="text" name="name" placeholder="Field name" required>
        <input type="text" name="label" placeholder="Label" required>
        <select name="type">
            <option value="text">Text</option>
            <option value="text_long">Long Text</option>
            <option value="integer">Integer</option>
            <option value="boolean">Boolean</option>
            <option value="select">Select</option>
            <option value="link">Link</option>
            <option value="email">Email</option>
        </select>
        <button type="submit" class="btn btn-primary">Create Field</button>
    </form>

    <table class="admin-table">
        <thead>
            <tr>
                <th>Name</th>
                <th>Label</th>
                <th>Type</th>
                <th>Widget</th>
                <th>Required</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($fields as $field): ?>
                <tr>
                    <td><?= e($field['name']) ?></td>
                    <td><?= e($field['label']) ?></td>
                    <td><?= e($field['type']) ?></td>
                    <td><?= e($field['widget']) ?></td>
                    <td><?= $field['required'] ? 'Yes' : 'No' ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php $this->endSection() ?>