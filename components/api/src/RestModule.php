<?php

namespace Canyon\Core\Api;

use Canyon\Core\Request;
use Canyon\Core\Response;

/**
 * REST Module - REST API Endpoints
 *
 * Similar to Drupal's REST module.
 * Provides RESTful endpoints for content, users, and custom resources.
 */
class RestModule
{
    /**
     * Register a REST endpoint.
     */
    public static function register(string $resource, string $methods, array $options = []): void
    {
        $path = "/api/{$resource}/{id?}";

        foreach ($methods as $method) {
            $method = strtoupper($method);
            \Canyon\Core\Router::add($method, $path, function (Request $request) use ($method, $resource, $options) {
                return self::handle($method, $request, $resource, $options);
            }, "api.{$resource}.{$method}");
        }
    }

    /**
     * Handle REST request.
     */
    protected static function handle(string $method, Request $request, string $resource, array $options): Response
    {
        $handler = $options['handler'] ?? null;
        if (!$handler) {
            return Response::json(['error' => 'No handler registered'], 500);
        }

        try {
            return match ($method) {
                'GET' => self::get($request, $resource, $handler),
                'POST' => self::post($request, $resource, $handler),
                'PUT' => self::put($request, $resource, $handler),
                'PATCH' => self::patch($request, $resource, $handler),
                'DELETE' => self::delete($request, $resource, $handler),
                default => Response::json(['error' => 'Method not allowed'], 405),
            };
        } catch (\Throwable $e) {
            return Response::json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Handle GET request.
     */
    protected static function get(Request $request, string $resource, callable $handler): Response
    {
        $id = $request->param('id');

        if ($id) {
            $item = $handler->get($id);
            if (!$item) {
                return Response::json(['error' => 'Not found'], 404);
            }
            return Response::json($item);
        }

        $items = $handler->list();
        return Response::json($items);
    }

    /**
     * Handle POST request.
     */
    protected static function post(Request $request, string $resource, callable $handler): Response
    {
        $data = $request->json();
        $item = $handler->create($data);
        return Response::json($item, 201);
    }

    /**
     * Handle PUT request.
     */
    protected static function put(Request $request, string $resource, callable $handler): Response
    {
        $id = $request->param('id');
        $data = $request->json();
        $item = $handler->update($id, $data);

        if (!$item) {
            return Response::json(['error' => 'Not found'], 404);
        }

        return Response::json($item);
    }

    /**
     * Handle PATCH request.
     */
    protected static function patch(Request $request, string $resource, callable $handler): Response
    {
        $id = $request->param('id');
        $data = $request->json();
        return self::put($request, $resource, $handler);
    }

    /**
     * Handle DELETE request.
     */
    protected static function delete(Request $request, string $resource, callable $handler): Response
    {
        $id = $request->param('id');
        $deleted = $handler->delete($id);

        if (!$deleted) {
            return Response::json(['error' => 'Not found'], 404);
        }

        return Response::json(null, 204);
    }
}

/**
 * JSON:API Module - JSON:API Specification
 *
 * Similar to Drupal's JSON:API module.
 * Implements the JSON:API v1.0 specification.
 */
class JsonApiModule
{
    /**
     * Enable JSON:API for a resource type.
     */
    public static function enable(string $type, array $options = []): void
    {
        $options = array_merge([
            'path' => "/jsonapi/{type}",
            'public' => true,
            'fields' => ['*'],
            'filters' => [],
        ], $options);

        \Canyon\Core\Router::get($options['path'] . '/{id}', fn ($req) => self::show($type, $req), "jsonapi.{$type}.show");
        \Canyon\Core\Router::get($options['path'], fn ($req) => self::index($type, $req), "jsonapi.{$type}.index");
    }

    /**
     * Get single item.
     */
    public static function show(string $type, Request $request): Response
    {
        $id = $request->param('id');
        $fields = $request->query('fields', '*');
        $include = $request->query('include', '');

        $data = self::getResource($type, $id, $fields, $include);
        return Response::json(self::formatResponse($data, $type, $id));
    }

    /**
     * List items.
     */
    public static function index(string $type, Request $request): Response
    {
        $filters = $request->query('filter', []);
        $page = $request->query('page', 1);
        $limit = $request->query('limit', 20);
        $fields = $request->query('fields', '*');

        $data = self::listResource($type, $filters, ($page - 1) * $limit, $limit, $fields);
        $total = count($data);

        return Response::json([
            'jsonapi' => ['version' => '1.0'],
            'data' => array_map(fn($item) => self::formatResourceItem($item, $type, $fields), $data),
            'meta' => ['total' => $total],
        ]);
    }

    /**
     * Format as JSON:API resource object.
     */
    protected static function formatResponse(array $data, string $type, string $id): array
    {
        return [
            'jsonapi' => ['version' => '1.0'],
            'data' => [
                'type' => $type,
                'id' => (string)$id,
                'attributes' => $data,
            ],
        ];
    }

    /**
     * Format as JSON:API resource item.
     */
    protected static function formatResourceItem(array $data, string $type, string $fields): array
    {
        return [
            'type' => $type,
            'id' => (string)($data['id'] ?? ''),
            'attributes' => $data,
        ];
    }

    /**
     * Get resource data (stub - implement per resource).
     */
    protected static function getResource(string $type, string $id, string $fields, string $include): array
    {
        // This would be implemented per resource type
        // For now, return empty
        return [];
    }

    /**
     * List resource data.
     */
    protected static function listResource(string $type, array $filters, int $offset, int $limit, string $fields): array
    {
        // This would be implemented per resource type
        // For now, return empty
        return [];
    }
}