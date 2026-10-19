<?php
// routes/admin.php
use Canyon\Core\Router;
use Canyon\Controllers\Admin\AdminController;

Router::group(['prefix' => 'admin', 'middleware' => ['auth']], function () {

    // Content management
    Router::get('/content', [AdminController::class, 'contentList'], 'admin.content.list');
    Router::get('/content/create', [AdminController::class, 'contentCreate'], 'admin.content.create');
    Router::post('/content', [AdminController::class, 'contentStore'], 'admin.content.store');
    Router::get('/content/{id}/edit', [AdminController::class, 'contentEdit'], 'admin.content.edit');
    Router::post('/content/{id}', [AdminController::class, 'contentUpdate'], 'admin.content.update');
    Router::post('/content/{id}/delete', [AdminController::class, 'contentDelete'], 'admin.content.delete');

    // Field management
    Router::get('/fields', [AdminController::class, 'fields'], 'admin.fields');
    Router::post('/fields', [AdminController::class, 'fieldStore'], 'admin.fields.store');

    // Taxonomy management
    Router::get('/taxonomy', [AdminController::class, 'taxonomy'], 'admin.taxonomy');
    Router::post('/taxonomy/vocabulary', [AdminController::class, 'vocabularyStore'], 'admin.taxonomy.vocabulary');
    Router::post('/taxonomy/term', [AdminController::class, 'termStore'], 'admin.taxonomy.term');

    // Block management
    Router::get('/blocks', [AdminController::class, 'blocks'], 'admin.blocks');

    // Search
    Router::get('/search/rebuild', [AdminController::class, 'searchRebuild'], 'admin.search.rebuild');
    Router::post('/search/rebuild', [AdminController::class, 'searchRebuildRun'], 'admin.search.rebuild.run');
});
