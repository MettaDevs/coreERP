<?php

namespace App\Http\Controllers\MasterData;

use App\Http\Controllers\Controller;
use App\Models\AssetEntity;
use App\Support\CurrentWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

final class AssetEntityController extends Controller
{
    public function index(Request $request): JsonResponse|Response
    {
        $tenantId = $this->tenantId($request);
        $search = $request->query('search');

        $query = AssetEntity::query()
            ->where(function ($q) use ($tenantId) {
                $q->where('tenant_id', $tenantId)->orWhereNull('tenant_id');
            })
            ->orderBy('code');

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('code', 'like', "%{$search}%")
                  ->orWhere('name', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%");
            });
        }

        $entities = $query->get();

        $data = [
            'entities' => $entities,
            'filters' => [
                'search' => $search ?? '',
            ],
            'canManage' => true,
        ];

        if ($request->is('api/*')) {
            return response()->json(['data' => $data]);
        }

        return Inertia::render('master-data/entitas-aset', $data);
    }

    public function store(Request $request): JsonResponse|RedirectResponse
    {
        $tenantId = $this->tenantId($request);

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:50'],
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string'],
            'status' => ['nullable', 'boolean'],
        ]);

        $existing = AssetEntity::query()
            ->where('tenant_id', $tenantId)
            ->where('code', $validated['code'])
            ->exists();

        if ($existing) {
            return back()->withErrors(['code' => 'Kode Entitas Aset sudah digunakan.']);
        }

        AssetEntity::query()->create([
            'id' => (string) Str::ulid(),
            'tenant_id' => $tenantId,
            'code' => strtoupper($validated['code']),
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'status' => $validated['status'] ?? true,
        ]);

        return $this->respond($request, 'Entitas Aset berhasil ditambahkan.');
    }

    public function update(Request $request, AssetEntity $assetEntity): JsonResponse|RedirectResponse
    {
        $tenantId = $this->tenantId($request);
        
        if ($assetEntity->tenant_id && $assetEntity->tenant_id !== $tenantId) {
            abort(404);
        }

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:50'],
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string'],
            'status' => ['nullable', 'boolean'],
        ]);

        $existing = AssetEntity::query()
            ->where('tenant_id', $tenantId)
            ->where('code', $validated['code'])
            ->where('id', '!=', $assetEntity->id)
            ->exists();

        if ($existing) {
            return back()->withErrors(['code' => 'Kode Entitas Aset sudah digunakan.']);
        }

        $assetEntity->update([
            'code' => strtoupper($validated['code']),
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'status' => $validated['status'] ?? true,
        ]);

        return $this->respond($request, 'Entitas Aset berhasil diperbarui.');
    }

    public function toggleStatus(Request $request, AssetEntity $assetEntity): JsonResponse|RedirectResponse
    {
        $tenantId = $this->tenantId($request);

        if ($assetEntity->tenant_id && $assetEntity->tenant_id !== $tenantId) {
            abort(404);
        }

        $assetEntity->update([
            'status' => !$assetEntity->status,
        ]);

        return $this->respond($request, 'Status Entitas Aset berhasil diubah.');
    }

    public function destroy(Request $request, AssetEntity $assetEntity): JsonResponse|RedirectResponse
    {
        $tenantId = $this->tenantId($request);

        if ($assetEntity->tenant_id && $assetEntity->tenant_id !== $tenantId) {
            abort(404);
        }

        $assetEntity->delete();

        return $this->respond($request, 'Entitas Aset berhasil dihapus.');
    }

    private function tenantId(Request $request): string
    {
        return (string) (app(CurrentWorkspace::class)->membership($request)?->tenant_id ?? 'default-tenant');
    }

    private function respond(Request $request, string $message): JsonResponse|RedirectResponse
    {
        return $request->is('api/*')
            ? response()->json(['data' => ['message' => $message]], 200)
            : back()->with('status', $message);
    }
}
