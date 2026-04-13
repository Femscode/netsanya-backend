<?php

namespace App\Http\Controllers;

use App\Models\Collection;
use App\Models\SavedRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CollectionController extends Controller
{
    public function index(Request $request)
    {
        $collections = Collection::query()
            ->where('user_id', $request->user()->getAuthIdentifier())
            ->withCount('requests')
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $collections]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'auth_type' => ['sometimes', 'nullable', 'string', 'in:inherit,none,bearer,basic,api_key'],
            'auth_config' => ['sometimes', 'nullable', 'array'],
            'variables' => ['sometimes', 'nullable', 'array', 'max:200'],
        ]);

        $collection = Collection::create([
            'user_id' => $request->user()->getAuthIdentifier(),
            'name' => $data['name'],
            'auth_type' => $data['auth_type'] ?? null,
            'auth_config' => $data['auth_config'] ?? null,
            'variables' => $data['variables'] ?? null,
        ]);

        return response()->json(['data' => $collection], 201);
    }

    public function update(Request $request, Collection $collection)
    {
        if ($collection->user_id !== $request->user()->getAuthIdentifier()) {
            abort(404);
        }

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:100'],
            'auth_type' => ['sometimes', 'nullable', 'string', 'in:inherit,none,bearer,basic,api_key'],
            'auth_config' => ['sometimes', 'nullable', 'array'],
            'variables' => ['sometimes', 'nullable', 'array', 'max:200'],
        ]);

        $collection->update($data);

        return response()->json(['data' => $collection]);
    }

    public function destroy(Request $request, Collection $collection)
    {
        if ($collection->user_id !== $request->user()->getAuthIdentifier()) {
            abort(404);
        }

        $collection->delete();

        return response()->json(['ok' => true]);
    }

    public function export(Request $request)
    {
        $userId = $request->user()->getAuthIdentifier();

        $collections = Collection::query()
            ->where('user_id', $userId)
            ->with(['requests' => function ($query) use ($userId) {
                $query
                    ->where('user_id', $userId)
                    ->orderBy('position')
                    ->orderBy('id');
            }])
            ->orderBy('name')
            ->get();

        $ungrouped = SavedRequest::query()
            ->where('user_id', $userId)
            ->whereNull('collection_id')
            ->orderBy('position')
            ->orderBy('id')
            ->get();

        if ($request->query('format') === 'postman') {
            return response()->json($this->toPostmanCollection($collections, $ungrouped));
        }

        return response()->json($this->toNetSanyaExport($collections, $ungrouped));
    }

    public function import(Request $request)
    {
        $maxRequests = (int) env('IMPORT_MAX_REQUESTS', 2000);

        $payload = $request->all();

        if (is_array($payload) && array_is_list($payload)) {
            $collections = [];
            $ungrouped = [];

            foreach ($payload as $entry) {
                if (! is_array($entry) || ! isset($entry['info']) || ! isset($entry['item'])) {
                    continue;
                }
                [$c, $u] = $this->parsePostmanImport($entry);
                $collections = array_merge($collections, $c);
                $ungrouped = array_merge($ungrouped, $u);
            }
        } elseif (is_array($payload) && isset($payload['collections']) && is_array($payload['collections'])) {
            $collections = [];
            $ungrouped = [];

            foreach ($payload['collections'] as $entry) {
                if (! is_array($entry) || ! isset($entry['info']) || ! isset($entry['item'])) {
                    continue;
                }
                [$c, $u] = $this->parsePostmanImport($entry);
                $collections = array_merge($collections, $c);
                $ungrouped = array_merge($ungrouped, $u);
            }
        } elseif (is_array($payload) && isset($payload['info']) && isset($payload['item'])) {
            [$collections, $ungrouped] = $this->parsePostmanImport($payload);
        } else {
            $data = validator($payload, [
                'collections' => ['sometimes', 'array', 'max:100'],
                'collections.*.name' => ['required', 'string', 'max:100'],
                'collections.*.auth_type' => ['sometimes', 'nullable', 'string', 'in:inherit,none,bearer,basic,api_key'],
                'collections.*.auth_config' => ['sometimes', 'nullable', 'array'],
                'collections.*.variables' => ['sometimes', 'nullable', 'array', 'max:200'],
                'collections.*.requests' => ['sometimes', 'array', 'max:'.$maxRequests],
                'collections.*.requests.*.name' => ['nullable', 'string', 'max:100'],
                'collections.*.requests.*.method' => ['required', 'string', 'in:GET,POST,PUT,PATCH,DELETE'],
                'collections.*.requests.*.url' => ['required', 'string', 'max:2048'],
                'collections.*.requests.*.query' => ['sometimes', 'nullable', 'array'],
                'collections.*.requests.*.headers' => ['nullable', 'array', 'max:50'],
                'collections.*.requests.*.body_type' => ['sometimes', 'string', 'in:none,json,raw,form-data,x-www-form-urlencoded,binary'],
                'collections.*.requests.*.body' => ['sometimes', 'nullable', 'array'],
                'collections.*.requests.*.body_text' => ['sometimes', 'nullable', 'string', 'max:2000000'],
                'collections.*.requests.*.body_form' => ['sometimes', 'nullable', 'array'],
                'collections.*.requests.*.auth_type' => ['sometimes', 'nullable', 'string', 'in:inherit,none,bearer,basic,api_key'],
                'collections.*.requests.*.auth_config' => ['sometimes', 'nullable', 'array'],
                'ungrouped_requests' => ['sometimes', 'array', 'max:'.$maxRequests],
                'ungrouped_requests.*.name' => ['nullable', 'string', 'max:100'],
                'ungrouped_requests.*.method' => ['required', 'string', 'in:GET,POST,PUT,PATCH,DELETE'],
                'ungrouped_requests.*.url' => ['required', 'string', 'max:2048'],
                'ungrouped_requests.*.query' => ['sometimes', 'nullable', 'array'],
                'ungrouped_requests.*.headers' => ['nullable', 'array', 'max:50'],
                'ungrouped_requests.*.body_type' => ['sometimes', 'string', 'in:none,json,raw,form-data,x-www-form-urlencoded,binary'],
                'ungrouped_requests.*.body' => ['sometimes', 'nullable', 'array'],
                'ungrouped_requests.*.body_text' => ['sometimes', 'nullable', 'string', 'max:2000000'],
                'ungrouped_requests.*.body_form' => ['sometimes', 'nullable', 'array'],
                'ungrouped_requests.*.auth_type' => ['sometimes', 'nullable', 'string', 'in:inherit,none,bearer,basic,api_key'],
                'ungrouped_requests.*.auth_config' => ['sometimes', 'nullable', 'array'],
            ])->validate();

            $collections = $data['collections'] ?? [];
            $ungrouped = $data['ungrouped_requests'] ?? [];
        }

        $totalRequests = count($ungrouped);
        foreach ($collections as $collection) {
            $totalRequests += count($collection['requests'] ?? []);
        }

        if ($totalRequests > $maxRequests) {
            throw ValidationException::withMessages([
                'collections' => ['Import exceeds maximum allowed requests ('.$maxRequests.').'],
            ]);
        }

        $userId = $request->user()->getAuthIdentifier();

        $result = DB::transaction(function () use ($collections, $ungrouped, $userId) {
            $createdCollections = 0;
            $createdRequests = 0;

            foreach ($collections as $collectionData) {
                $collection = Collection::create([
                    'user_id' => $userId,
                    'name' => $collectionData['name'],
                    'auth_type' => $collectionData['auth_type'] ?? null,
                    'auth_config' => $collectionData['auth_config'] ?? null,
                    'variables' => $collectionData['variables'] ?? null,
                ]);
                $createdCollections++;

                $requests = $collectionData['requests'] ?? [];
                foreach (array_values($requests) as $index => $req) {
                    SavedRequest::create([
                        'user_id' => $userId,
                        'collection_id' => $collection->id,
                        'position' => $index,
                        'name' => $req['name'] ?? null,
                        'method' => strtoupper($req['method']),
                        'url' => $req['url'],
                        'query' => $req['query'] ?? null,
                        'headers' => $req['headers'] ?? null,
                        'body_type' => $req['body_type'] ?? 'json',
                        'body' => $req['body'] ?? null,
                        'body_text' => $req['body_text'] ?? null,
                        'body_form' => $req['body_form'] ?? null,
                        'auth_type' => $req['auth_type'] ?? 'inherit',
                        'auth_config' => $req['auth_config'] ?? null,
                    ]);
                    $createdRequests++;
                }
            }

            foreach (array_values($ungrouped) as $index => $req) {
                SavedRequest::create([
                    'user_id' => $userId,
                    'collection_id' => null,
                    'position' => $index,
                    'name' => $req['name'] ?? null,
                    'method' => strtoupper($req['method']),
                    'url' => $req['url'],
                    'query' => $req['query'] ?? null,
                    'headers' => $req['headers'] ?? null,
                    'body_type' => $req['body_type'] ?? 'json',
                    'body' => $req['body'] ?? null,
                    'body_text' => $req['body_text'] ?? null,
                    'body_form' => $req['body_form'] ?? null,
                    'auth_type' => $req['auth_type'] ?? 'inherit',
                    'auth_config' => $req['auth_config'] ?? null,
                ]);
                $createdRequests++;
            }

            return [$createdCollections, $createdRequests];
        });

        return response()->json([
            'ok' => true,
            'created_collections' => $result[0],
            'created_requests' => $result[1],
        ], 201);
    }

    private function toNetSanyaExport($collections, $ungrouped): array
    {
        return [
            'version' => 1,
            'exported_at' => now()->toIso8601String(),
            'collections' => $collections->map(function (Collection $collection) {
                return [
                    'name' => $collection->name,
                    'auth_type' => $collection->auth_type,
                    'auth_config' => $collection->auth_config,
                    'variables' => $collection->variables,
                    'requests' => $collection->requests->map(function (SavedRequest $savedRequest) {
                        return [
                            'name' => $savedRequest->name,
                            'method' => $savedRequest->method,
                            'url' => $savedRequest->url,
                            'query' => $savedRequest->query,
                            'headers' => $savedRequest->headers,
                            'body_type' => $savedRequest->body_type,
                            'body' => $savedRequest->body,
                            'body_text' => $savedRequest->body_text,
                            'body_form' => $savedRequest->body_form,
                            'auth_type' => $savedRequest->auth_type,
                            'auth_config' => $savedRequest->auth_config,
                            'position' => $savedRequest->position,
                        ];
                    })->values(),
                ];
            })->values(),
            'ungrouped_requests' => $ungrouped->map(function (SavedRequest $savedRequest) {
                return [
                    'name' => $savedRequest->name,
                    'method' => $savedRequest->method,
                    'url' => $savedRequest->url,
                    'query' => $savedRequest->query,
                    'headers' => $savedRequest->headers,
                    'body_type' => $savedRequest->body_type,
                    'body' => $savedRequest->body,
                    'body_text' => $savedRequest->body_text,
                    'body_form' => $savedRequest->body_form,
                    'auth_type' => $savedRequest->auth_type,
                    'auth_config' => $savedRequest->auth_config,
                    'position' => $savedRequest->position,
                ];
            })->values(),
        ];
    }

    private function toPostmanCollection($collections, $ungrouped): array
    {
        $items = [];

        foreach ($ungrouped as $savedRequest) {
            $items[] = $this->postmanItemFromSavedRequest($savedRequest);
        }

        foreach ($collections as $collection) {
            $folderItems = [];
            foreach ($collection->requests as $savedRequest) {
                $folderItems[] = $this->postmanItemFromSavedRequest($savedRequest);
            }

            $items[] = [
                'name' => $collection->name,
                'item' => $folderItems,
            ];
        }

        return [
            'info' => [
                'name' => 'NetSanya Export',
                'schema' => 'https://schema.getpostman.com/json/collection/v2.1.0/collection.json',
            ],
            'item' => $items,
        ];
    }

    private function postmanItemFromSavedRequest(SavedRequest $savedRequest): array
    {
        $headers = [];
        foreach (($savedRequest->headers ?? []) as $key => $value) {
            $headers[] = [
                'key' => $key,
                'value' => is_array($value) ? implode(',', array_map('strval', $value)) : (string) $value,
            ];
        }

        $query = [];
        foreach (($savedRequest->query ?? []) as $key => $value) {
            $query[] = [
                'key' => $key,
                'value' => (string) $value,
            ];
        }

        $body = null;
        $bodyType = (string) ($savedRequest->body_type ?? 'json');

        if ($bodyType === 'json') {
            $raw = $savedRequest->body === null ? '' : json_encode($savedRequest->body, JSON_UNESCAPED_SLASHES);
            $body = [
                'mode' => 'raw',
                'raw' => $raw === false ? '' : $raw,
            ];
        } elseif ($bodyType === 'raw') {
            $body = [
                'mode' => 'raw',
                'raw' => (string) ($savedRequest->body_text ?? ''),
            ];
        } elseif ($bodyType === 'x-www-form-urlencoded') {
            $urlencoded = [];
            foreach (($savedRequest->body_form ?? []) as $row) {
                if (! is_array($row)) continue;
                if (($row['type'] ?? null) !== 'text') continue;
                $urlencoded[] = [
                    'key' => (string) ($row['key'] ?? ''),
                    'value' => (string) ($row['value'] ?? ''),
                ];
            }
            $body = [
                'mode' => 'urlencoded',
                'urlencoded' => $urlencoded,
            ];
        } elseif ($bodyType === 'form-data') {
            $formdata = [];
            foreach (($savedRequest->body_form ?? []) as $row) {
                if (! is_array($row)) continue;
                $type = ($row['type'] ?? null) === 'file' ? 'file' : 'text';
                $formdata[] = [
                    'key' => (string) ($row['key'] ?? ''),
                    'type' => $type,
                    'value' => (string) ($row['value'] ?? ''),
                    'src' => ($type === 'file') ? (string) ($row['filename'] ?? '') : null,
                ];
            }
            $body = [
                'mode' => 'formdata',
                'formdata' => $formdata,
            ];
        } elseif ($bodyType === 'binary') {
            $body = [
                'mode' => 'file',
                'file' => [
                    'src' => '',
                ],
            ];
        }

        $auth = $this->postmanAuthFromSavedRequest($savedRequest);

        return [
            'name' => $savedRequest->name ?? $savedRequest->url,
            'request' => array_filter([
                'method' => $savedRequest->method,
                'header' => $headers,
                'url' => [
                    'raw' => $savedRequest->url,
                    'query' => $query,
                ],
                'body' => $body,
                'auth' => $auth,
            ], fn ($v) => $v !== null),
        ];
    }

    private function postmanAuthFromSavedRequest(SavedRequest $savedRequest): ?array
    {
        $type = strtolower((string) ($savedRequest->auth_type ?? 'inherit'));
        $config = is_array($savedRequest->auth_config) ? $savedRequest->auth_config : [];

        if ($type === 'bearer') {
            $token = $config['bearer'] ?? null;
            if (! is_string($token) || $token === '') return null;
            return [
                'type' => 'bearer',
                'bearer' => [
                    ['key' => 'token', 'value' => $token, 'type' => 'string'],
                ],
            ];
        }

        if ($type === 'basic') {
            $username = $config['username'] ?? null;
            $password = $config['password'] ?? null;
            if (! is_string($username) || ! is_string($password)) return null;
            return [
                'type' => 'basic',
                'basic' => [
                    ['key' => 'username', 'value' => $username, 'type' => 'string'],
                    ['key' => 'password', 'value' => $password, 'type' => 'string'],
                ],
            ];
        }

        if ($type === 'api_key') {
            $in = $config['in'] ?? 'header';
            $key = $config['key'] ?? null;
            $value = $config['value'] ?? null;
            if (! is_string($key) || $key === '' || ! is_string($value)) return null;
            return [
                'type' => 'apikey',
                'apikey' => [
                    ['key' => 'in', 'value' => $in === 'query' ? 'query' : 'header', 'type' => 'string'],
                    ['key' => 'key', 'value' => $key, 'type' => 'string'],
                    ['key' => 'value', 'value' => $value, 'type' => 'string'],
                ],
            ];
        }

        return null;
    }

    private function parsePostmanImport(array $payload): array
    {
        $info = $payload['info'] ?? [];
        $items = $payload['item'] ?? [];

        if (! is_array($items)) {
            throw ValidationException::withMessages([
                'item' => ['Invalid Postman collection format.'],
            ]);
        }

        $rootAuth = is_array($payload['auth'] ?? null) ? $payload['auth'] : null;
        $rootVariables = $this->postmanVariablesToAssoc($payload['variable'] ?? null);

        $collections = [];
        $ungrouped = [];
        $folderIndex = [];

        foreach ($this->flattenPostmanItems($items, []) as $entry) {
            $folderPath = $entry['folder'];
            $item = $entry['item'];

            $request = $item['request'] ?? null;
            if (! is_array($request)) {
                continue;
            }

            $normalized = $this->normalizePostmanRequest($item, $rootAuth);
            if ($normalized === null) {
                continue;
            }

            if ($folderPath === []) {
                $ungrouped[] = $normalized;
                continue;
            }

            $folderName = implode(' / ', $folderPath);
            if (! array_key_exists($folderName, $folderIndex)) {
                $folderIndex[$folderName] = count($collections);
                $collections[] = [
                    'name' => mb_substr($folderName, 0, 100),
                    'variables' => $rootVariables ?: null,
                    'requests' => [],
                ];
            }

            $collections[$folderIndex[$folderName]]['requests'][] = $normalized;
        }

        return [$collections, $ungrouped];
    }

    private function postmanVariablesToAssoc($variables): array
    {
        if (! is_array($variables)) {
            return [];
        }

        $out = [];

        foreach ($variables as $var) {
            if (! is_array($var)) {
                continue;
            }
            $key = $var['key'] ?? null;
            if (! is_string($key) || $key === '') {
                continue;
            }
            $value = $var['value'] ?? '';
            $out[$key] = is_string($value) ? $value : (string) $value;
        }

        return $out;
    }

    private function flattenPostmanItems(array $items, array $path): array
    {
        $out = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $hasChildren = array_key_exists('item', $item) && is_array($item['item']);

            if ($hasChildren) {
                $name = is_string($item['name'] ?? null) ? $item['name'] : 'Folder';
                $out = array_merge($out, $this->flattenPostmanItems($item['item'], [...$path, $name]));
                continue;
            }

            $out[] = ['folder' => $path, 'item' => $item];
        }

        return $out;
    }

    private function normalizePostmanRequest(array $item, ?array $rootAuth): ?array
    {
        $request = $item['request'] ?? null;
        if (! is_array($request)) {
            return null;
        }

        $method = strtoupper((string) ($request['method'] ?? 'GET'));
        if (! in_array($method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'], true)) {
            $method = 'GET';
        }

        [$url, $query] = $this->postmanUrlToUrlAndQuery($request['url'] ?? null);

        $headers = $this->postmanHeadersToAssoc($request['header'] ?? []);

        [$bodyType, $body, $bodyText, $bodyForm] = $this->postmanBodyToInternal($request['body'] ?? null);

        [$authType, $authConfig] = $this->postmanAuthToInternal(
            is_array($request['auth'] ?? null) ? $request['auth'] : $rootAuth
        );

        $name = is_string($item['name'] ?? null) ? $item['name'] : null;

        return [
            'name' => $name,
            'method' => $method,
            'url' => $url,
            'query' => $query ?: null,
            'headers' => $headers ?: null,
            'body_type' => $bodyType,
            'body' => $body,
            'body_text' => $bodyText,
            'body_form' => $bodyForm,
            'auth_type' => $authType,
            'auth_config' => $authConfig,
        ];
    }

    private function postmanUrlToUrlAndQuery($url): array
    {
        $raw = '';
        $query = [];

        if (is_string($url)) {
            $raw = $url;
        } elseif (is_array($url)) {
            if (is_string($url['raw'] ?? null)) {
                $raw = $url['raw'];
            } else {
                $scheme = is_string($url['protocol'] ?? null) ? $url['protocol'].'://' : '';
                $host = '';
                if (is_array($url['host'] ?? null)) {
                    $host = implode('.', array_map('strval', $url['host']));
                } elseif (is_string($url['host'] ?? null)) {
                    $host = $url['host'];
                }
                $path = '';
                if (is_array($url['path'] ?? null)) {
                    $path = '/'.implode('/', array_map('strval', $url['path']));
                } elseif (is_string($url['path'] ?? null)) {
                    $path = $url['path'];
                }
                $raw = $scheme.$host.$path;
            }

            if (is_array($url['query'] ?? null)) {
                foreach ($url['query'] as $q) {
                    if (! is_array($q)) continue;
                    if (($q['disabled'] ?? false) === true) continue;
                    $k = $q['key'] ?? null;
                    if (! is_string($k) || $k === '') continue;
                    $v = $q['value'] ?? '';
                    $query[$k] = is_string($v) ? $v : (string) $v;
                }
            }
        }

        return [$raw, $query];
    }

    private function postmanHeadersToAssoc($headers): array
    {
        $out = [];
        if (! is_array($headers)) return $out;

        foreach ($headers as $h) {
            if (! is_array($h)) continue;
            if (($h['disabled'] ?? false) === true) continue;
            $key = $h['key'] ?? null;
            if (! is_string($key) || $key === '') continue;
            $value = $h['value'] ?? '';
            $out[$key] = is_string($value) ? $value : (string) $value;
        }

        return $out;
    }

    private function postmanBodyToInternal($body): array
    {
        if (! is_array($body)) {
            return ['none', null, null, null];
        }

        $mode = strtolower((string) ($body['mode'] ?? 'none'));

        if ($mode === 'raw') {
            return ['raw', null, (string) ($body['raw'] ?? ''), null];
        }

        if ($mode === 'urlencoded') {
            $rows = [];
            if (is_array($body['urlencoded'] ?? null)) {
                foreach ($body['urlencoded'] as $row) {
                    if (! is_array($row)) continue;
                    if (($row['disabled'] ?? false) === true) continue;
                    $key = $row['key'] ?? null;
                    if (! is_string($key) || $key === '') continue;
                    $rows[] = [
                        'key' => $key,
                        'type' => 'text',
                        'value' => is_string($row['value'] ?? null) ? $row['value'] : (string) ($row['value'] ?? ''),
                    ];
                }
            }
            return ['x-www-form-urlencoded', null, null, $rows ?: null];
        }

        if ($mode === 'formdata') {
            $rows = [];
            if (is_array($body['formdata'] ?? null)) {
                foreach ($body['formdata'] as $row) {
                    if (! is_array($row)) continue;
                    if (($row['disabled'] ?? false) === true) continue;
                    $key = $row['key'] ?? null;
                    if (! is_string($key) || $key === '') continue;
                    $type = strtolower((string) ($row['type'] ?? 'text')) === 'file' ? 'file' : 'text';
                    $rows[] = $type === 'file'
                        ? ['key' => $key, 'type' => 'file', 'filename' => is_string($row['src'] ?? null) ? $row['src'] : null]
                        : ['key' => $key, 'type' => 'text', 'value' => is_string($row['value'] ?? null) ? $row['value'] : (string) ($row['value'] ?? '')];
                }
            }
            return ['form-data', null, null, $rows ?: null];
        }

        if ($mode === 'file') {
            $src = null;
            if (is_array($body['file'] ?? null) && is_string($body['file']['src'] ?? null)) {
                $src = $body['file']['src'];
            }
            return ['binary', null, null, $src ? [['key' => 'file', 'type' => 'file', 'filename' => $src]] : null];
        }

        return ['none', null, null, null];
    }

    private function postmanAuthToInternal(?array $auth): array
    {
        if (! is_array($auth)) {
            return ['inherit', null];
        }

        $type = strtolower((string) ($auth['type'] ?? 'inherit'));

        if ($type === 'bearer') {
            $token = null;
            foreach (($auth['bearer'] ?? []) as $row) {
                if (! is_array($row)) continue;
                if (($row['key'] ?? null) === 'token' && is_string($row['value'] ?? null)) {
                    $token = $row['value'];
                    break;
                }
            }
            return ['bearer', $token ? ['bearer' => $token] : null];
        }

        if ($type === 'basic') {
            $username = null;
            $password = null;
            foreach (($auth['basic'] ?? []) as $row) {
                if (! is_array($row)) continue;
                if (($row['key'] ?? null) === 'username' && is_string($row['value'] ?? null)) {
                    $username = $row['value'];
                }
                if (($row['key'] ?? null) === 'password' && is_string($row['value'] ?? null)) {
                    $password = $row['value'];
                }
            }
            return ['basic', ($username !== null || $password !== null) ? ['username' => $username, 'password' => $password] : null];
        }

        if ($type === 'apikey') {
            $in = 'header';
            $key = null;
            $value = null;
            foreach (($auth['apikey'] ?? []) as $row) {
                if (! is_array($row)) continue;
                if (($row['key'] ?? null) === 'in' && is_string($row['value'] ?? null)) {
                    $in = strtolower($row['value']) === 'query' ? 'query' : 'header';
                }
                if (($row['key'] ?? null) === 'key' && is_string($row['value'] ?? null)) {
                    $key = $row['value'];
                }
                if (($row['key'] ?? null) === 'value' && is_string($row['value'] ?? null)) {
                    $value = $row['value'];
                }
            }
            return ['api_key', ($key !== null || $value !== null) ? ['in' => $in, 'key' => $key, 'value' => $value] : null];
        }

        if ($type === 'noauth' || $type === 'none') {
            return ['none', null];
        }

        return ['inherit', null];
    }
}
