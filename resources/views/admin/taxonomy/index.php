<?php // resources/views/admin/taxonomy/index.php ?>
<?php $this->layout('layouts.admin') ?>

<?php $this->section('title') ?>Taxonomy Management<?php $this->endSection() ?>

<?php $this->section('content') ?>
<div class="admin-content">
    <h1>Taxonomy Management</h1>

    <h2>Vocabularies</h2>
    <table class="admin-table">
        <thead>
            <tr>
                <th>Name</th>
                <th>ID</th>
                <th>Description</th>
                <th>Terms</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($vocabularies as $vocab): ?>
                <tr>
                    <td><?= e($vocab['name']) ?></td>
                    <td><?= e($vocab['vid']) ?></td>
                    <td><?= e($vocab['description']) ?></td>
                    <td>
                        <?php
                        $terms = \Canyon\Core\Taxonomy\TaxonomyModule::getTerms($vocab['vid']);
                        echo count($terms);
                        ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <h2>Create Vocabulary</h2>
    <form method="POST" action="/admin/taxonomy/vocabulary" class="admin-form inline-form">
        <?= \Canyon\Core\Security::csrfField() ?>
        <input type="text" name="name" placeholder="Vocabulary name" required>
        <input type="text" name="description" placeholder="Description">
        <button type="submit" class="btn btn-primary">Create</button>
    </form>
</div>
<?php $this->endSection() ?>