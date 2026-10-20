<?php

namespace Canyon\Controllers\Admin;

use Canyon\Core\Controller;
use Canyon\Core\Request;
use Canyon\Core\Response;
use Canyon\Core\Content\ContentModule;
use Canyon\Core\Field\FieldModule;
use Canyon\Core\Taxonomy\TaxonomyModule;
use Canyon\Core\Block\BlockModule;
use Canyon\Core\Search\SearchModule;

/**
 * Admin Controller - Content Management
 *
 * Provides admin UI for managing content, fields, taxonomy, blocks, and search.
 */
class AdminController extends Controller
{
    /**
     * Content list page.
     */
    public function contentList(Request $request): string
    {
        $type = $request->query('type', null);
        $status = $request->query('status', null);
        $search = $request->query('search', null);

        $options = [
            'limit' => 20,
            'offset' => (int)($request->query('page', 1) - 1) * 20,
        ];

        if ($type) $options['type'] = $type;
        if ($status !== null) $options['status'] = (int)$status;
        if ($search) $options['search'] = $search;

        $content = ContentModule::list($options);
        $types = ContentModule::getTypes();

        return view('admin.content.list', [
            'content' => $content,
            'types' => $types,
            'filters' => compact('type', 'status', 'search'),
        ]);
    }

    /**
     * Create content page.
     */
    public function contentCreate(Request $request): string
    {
        $types = ContentModule::getTypes();

        return view('admin.content.create', [
            'types' => $types,
        ]);
    }

    /**
     * Store new content.
     */
    public function contentStore(Request $request): never
    {
        $data = $request->validate([
            'title' => 'required|string|max:255',
            'body' => 'required|string',
            'type' => 'required|string',
            'status' => 'integer',
        ]);

        $slug = ContentModule::slugify($data['title']);
        $data['slug'] = $slug;

        $id = ContentModule::create($data['type'], $data, auth()->user()->id);

        // Index for search
        SearchModule::index('content', $id, [
            'title' => $data['title'],
            'body' => $data['body'],
            'status' => $data['status'] ?? 1,
        ]);

        Response::redirect('/admin/content')->with('success', 'Content created successfully.');
    }

    /**
     * Edit content page.
     */
    public function contentEdit(Request $request, int $id): string
    {
        $content = ContentModule::load($id);
        if (!$content) {
            abort(404, 'Content not found');
        }

        $types = ContentModule::getTypes();

        return view('admin.content.edit', [
            'content' => $content,
            'types' => $types,
        ]);
    }

    /**
     * Update content.
     */
    public function contentUpdate(Request $request, int $id): never
    {
        $data = $request->validate([
            'title' => 'required|string|max:255',
            'body' => 'required|string',
            'status' => 'integer',
        ]);

        ContentModule::update($id, $data);

        // Update search index
        SearchModule::index('content', $id, [
            'title' => $data['title'],
            'body' => $data['body'],
            'status' => $data['status'] ?? 1,
        ]);

        Response::redirect('/admin/content')->with('success', 'Content updated successfully.');
    }

    /**
     * Delete content.
     */
    public function contentDelete(Request $request, int $id): never
    {
        ContentModule::delete($id);
        SearchModule::remove('content', $id);

        Response::redirect('/admin/content')->with('success', 'Content deleted successfully.');
    }

    /**
     * Fields management page.
     */
    public function fields(Request $request): string
    {
        $fields = \Canyon\Core\Database::query('SELECT * FROM fields ORDER BY weight, label');
        $types = ContentModule::getTypes();

        return view('admin.fields.index', [
            'fields' => $fields,
            'types' => $types,
        ]);
    }

    /**
     * Create field.
     */
    public function fieldStore(Request $request): never
    {
        $data = $request->validate([
            'name' => 'required|string|max:64',
            'type' => 'required|string|max:64',
            'label' => 'required|string|max:255',
        ]);

        FieldModule::createField($data['name'], $data['type'], $data);

        Response::redirect('/admin/fields')->with('success', 'Field created successfully.');
    }

    /**
     * Taxonomy management page.
     */
    public function taxonomy(Request $request): string
    {
        $vocabularies = TaxonomyModule::getVocabularies();

        return view('admin.taxonomy.index', [
            'vocabularies' => $vocabularies,
        ]);
    }

    /**
     * Create vocabulary.
     */
    public function vocabularyStore(Request $request): never
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
        ]);

        TaxonomyModule::createVocabulary($data);

        Response::redirect('/admin/taxonomy')->with('success', 'Vocabulary created successfully.');
    }

    /**
     * Create term.
     */
    public function termStore(Request $request): never
    {
        $data = $request->validate([
            'vid' => 'required|string|max:64',
            'name' => 'required|string|max:255',
        ]);

        TaxonomyModule::createTerm($data['vid'], $data);

        Response::redirect('/admin/taxonomy')->with('success', 'Term created successfully.');
    }

    /**
     * Blocks management page.
     */
    public function blocks(Request $request): string
    {
        $blocks = BlockModule::getBlocks();
        $regions = BlockModule::getRegions();

        return view('admin.blocks.index', [
            'blocks' => $blocks,
            'regions' => $regions,
        ]);
    }

    /**
     * Search rebuild page.
     */
    public function searchRebuild(Request $request): string
    {
        $stats = [
            'indexed' => \Canyon\Core\Database::query('SELECT COUNT(*) as cnt FROM search_index')[0]['cnt'] ?? 0,
            'total' => \Canyon\Core\Database::query('SELECT COUNT(*) as cnt FROM content')[0]['cnt'] ?? 0,
        ];

        return view('admin.search.rebuild', [
            'stats' => $stats,
        ]);
    }

    /**
     * Rebuild search index.
     */
    public function searchRebuildRun(Request $request): never
    {
        $count = SearchModule::rebuildIndex();

        Response::redirect('/admin/search/rebuild')->with('success', "Search index rebuilt. {$count} items indexed.");
    }
}