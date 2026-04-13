<?php

namespace App\Http\Controllers;

use App\Models\Collection;
use App\Models\SavedRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SavedRequestController extends Controller
{
    public function index(Request $request)
    {
        $query = SavedRequest::query()
            ->where('user_id', $request->user()->getAuthIdentifier())
            ->with('collection:id,name');

        if ($request->filled('collection_id')) {
            $query->where('collection_id', $request->integer('collection_id'));
        }

        $requests = $query
            ->orderBy('collection_id')
            ->orderBy('position')
            ->orderByDesc('updated_at')
            ->get();

        return response()->json(['data' => $requests]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'collection_id' => ['nullable', 'integer', 'exists:collections,id'],
            'name' => ['nullable', 'string', 'max:100'],
            'method' => ['required', 'string', 'in:GET,POST,PUT,PATCH,DELETE'],
            'url' => ['required', 'string', 'max:2048'],
            'query' => ['sometimes', 'nullable', 'array'],
            'headers' => ['nullable', 'array', 'max:50'],
            'body_type' => ['sometimes', 'string', 'in:none,json,raw,form-data,x-www-form-urlencoded,binary'],
            'body' => ['sometimes', 'nullable', 'array'],
            'body_text' => ['sometimes', 'nullable', 'string', 'max:2000000'],
            'body_form' => ['sometimes', 'nullable', 'array'],
            'auth_type' => ['sometimes', 'nullable', 'string', 'in:inherit,none,bearer,basic,api_key'],
            'auth_config' => ['sometimes', 'nullable', 'array'],
        ]);

        $collectionId = $data['collection_id'] ?? null;
        $userId = $request->user()->getAuthIdentifier();

        if ($collectionId !== null) {
            $owns = Collection::query()
                ->whereKey($collectionId)
                ->where('user_id', $userId)
                ->exists();

            if (! $owns) {
                throw ValidationException::withMessages([
                    'collection_id' => ['Collection not found.'],
                ]);
            }
        }

        $max = SavedRequest::query()
            ->where('user_id', $userId)
            ->when($collectionId === null, fn ($q) => $q->whereNull('collection_id'), fn ($q) => $q->where('collection_id', $collectionId))
            ->max('position');

        $saved = SavedRequest::create([
            'user_id' => $userId,
            'collection_id' => $collectionId,
            'position' => $max === null ? 0 : $max + 1,
            'name' => $data['name'] ?? null,
            'method' => strtoupper($data['method']),
            'url' => $data['url'],
            'query' => $data['query'] ?? null,
            'headers' => $data['headers'] ?? null,
            'body_type' => $data['body_type'] ?? 'json',
            'body' => $data['body'] ?? null,
            'body_text' => $data['body_text'] ?? null,
            'body_form' => $data['body_form'] ?? null,
            'auth_type' => $data['auth_type'] ?? 'inherit',
            'auth_config' => $data['auth_config'] ?? null,
        ]);

        $saved->load('collection:id,name');

        return response()->json(['data' => $saved], 201);
    }

    public function update(Request $request, SavedRequest $savedRequest)
    {
        if ($savedRequest->user_id !== $request->user()->getAuthIdentifier()) {
            abort(404);
        }

        $data = $request->validate([
            'collection_id' => ['nullable', 'integer', 'exists:collections,id'],
            'name' => ['sometimes', 'nullable', 'string', 'max:100'],
            'method' => ['sometimes', 'required', 'string', 'in:GET,POST,PUT,PATCH,DELETE'],
            'url' => ['sometimes', 'required', 'string', 'max:2048'],
            'query' => ['sometimes', 'nullable', 'array'],
            'headers' => ['sometimes', 'nullable', 'array', 'max:50'],
            'body_type' => ['sometimes', 'string', 'in:none,json,raw,form-data,x-www-form-urlencoded,binary'],
            'body' => ['sometimes', 'nullable', 'array'],
            'body_text' => ['sometimes', 'nullable', 'string', 'max:2000000'],
            'body_form' => ['sometimes', 'nullable', 'array'],
            'auth_type' => ['sometimes', 'nullable', 'string', 'in:inherit,none,bearer,basic,api_key'],
            'auth_config' => ['sometimes', 'nullable', 'array'],
        ]);

        $userId = $request->user()->getAuthIdentifier();

        $hasCollectionId = array_key_exists('collection_id', $data);
        $collectionId = $hasCollectionId ? $data['collection_id'] : $savedRequest->collection_id;

        if ($hasCollectionId && $collectionId !== null) {
            $owns = Collection::query()
                ->whereKey($collectionId)
                ->where('user_id', $userId)
                ->exists();

            if (! $owns) {
                throw ValidationException::withMessages([
                    'collection_id' => ['Collection not found.'],
                ]);
            }
        }

        $updates = [];

        if ($hasCollectionId) {
            $updates['collection_id'] = $collectionId;
        }

        if (array_key_exists('name', $data)) {
            $updates['name'] = $data['name'];
        }

        if (array_key_exists('method', $data)) {
            $updates['method'] = strtoupper($data['method']);
        }

        if (array_key_exists('url', $data)) {
            $updates['url'] = $data['url'];
        }

        if (array_key_exists('query', $data)) {
            $updates['query'] = $data['query'];
        }

        if (array_key_exists('headers', $data)) {
            $updates['headers'] = $data['headers'];
        }

        if (array_key_exists('body_type', $data)) {
            $updates['body_type'] = $data['body_type'];
        }

        if (array_key_exists('body', $data)) {
            $updates['body'] = $data['body'];
        }

        if (array_key_exists('body_text', $data)) {
            $updates['body_text'] = $data['body_text'];
        }

        if (array_key_exists('body_form', $data)) {
            $updates['body_form'] = $data['body_form'];
        }

        if (array_key_exists('auth_type', $data)) {
            $updates['auth_type'] = $data['auth_type'];
        }

        if (array_key_exists('auth_config', $data)) {
            $updates['auth_config'] = $data['auth_config'];
        }

        if ($updates !== []) {
            $savedRequest->update($updates);
        }

        $savedRequest->load('collection:id,name');

        return response()->json(['data' => $savedRequest]);
    }

    public function destroy(Request $request, SavedRequest $savedRequest)
    {
        if ($savedRequest->user_id !== $request->user()->getAuthIdentifier()) {
            abort(404);
        }

        $savedRequest->delete();

        return response()->json(['ok' => true]);
    }

    public function reorder(Request $request)
    {
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:500'],
            'items.*.id' => ['required', 'integer'],
            'items.*.collection_id' => ['nullable', 'integer'],
            'items.*.position' => ['required', 'integer', 'min:0'],
        ]);

        $userId = $request->user()->getAuthIdentifier();

        $items = $data['items'];
        $requestIds = array_values(array_unique(array_map(fn ($i) => (int) $i['id'], $items)));

        $ownedRequestIds = SavedRequest::query()
            ->where('user_id', $userId)
            ->whereIn('id', $requestIds)
            ->pluck('id')
            ->all();

        if (count($ownedRequestIds) !== count($requestIds)) {
            abort(404);
        }

        $collectionIds = array_values(array_unique(array_filter(array_map(
            fn ($i) => array_key_exists('collection_id', $i) ? $i['collection_id'] : null,
            $items
        ), fn ($v) => $v !== null)));

        if ($collectionIds !== []) {
            $ownedCollectionCount = Collection::query()
                ->where('user_id', $userId)
                ->whereIn('id', $collectionIds)
                ->count();

            if ($ownedCollectionCount !== count($collectionIds)) {
                throw ValidationException::withMessages([
                    'items' => ['One or more collections were not found.'],
                ]);
            }
        }

        DB::transaction(function () use ($items, $userId) {
            foreach ($items as $item) {
                $collectionId = $item['collection_id'] ?? null;

                SavedRequest::query()
                    ->where('user_id', $userId)
                    ->whereKey((int) $item['id'])
                    ->update([
                        'collection_id' => $collectionId,
                        'position' => (int) $item['position'],
                    ]);
            }
        });

        return response()->json(['ok' => true]);
    }
}
