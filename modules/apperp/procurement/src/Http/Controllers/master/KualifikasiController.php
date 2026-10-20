<?php

declare(strict_types=1);

namespace Modules\Apperp\Procurement\Http\Controllers\master;

use App\Platform\Modules\Contracts\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Apperp\Procurement\Models\master\Prakualifikasi;

final class KualifikasiController extends Controller
{
    /**
     * Menampilkan daftar vendor yang telah lolos kualifikasi (Daftar Kualifikasi Vendor/Supplier).
     */
    public function index(Request $request, TenantContext $context): JsonResponse
    {
        $tenantId = $context->tenantId();

        // Kualifikasi vendor memfilter rekanan yang berstatus lolos evaluasi kualifikasi
        $query = Prakualifikasi::query()
            ->where('tenant_id', $tenantId)
            ->where('status_kualifikasi', 'Lolos')
            ->with(['documents', 'items'])
            ->orderByDesc('tanggal_prakualifikasi')
            ->orderByDesc('created_at');

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search): void {
                $q->where('nomor_prakualifikasi', 'like', "%{$search}%")
                    ->orWhere('kode_vendor', 'like', "%{$search}%")
                    ->orWhere('nama_vendor', 'like', "%{$search}%")
                    ->orWhere('nama_pic', 'like', "%{$search}%")
                    ->orWhere('telepon', 'like', "%{$search}%")
                    ->orWhere('alamat', 'like', "%{$search}%");
            });
        }

        if ($statusVendor = $request->input('status_vendor')) {
            $query->where('status_vendor', $statusVendor);
        }

        $perPage = (int) $request->input('per_page', 15);
        $paginated = $query->paginate($perPage);

        $transformedItems = collect($paginated->items())->map(function (Prakualifikasi $record): array {
            $array = $record->toArray();
            $array['dokumen'] = $array['documents'] ?? [];

            return $array;
        });

        return response()->json([
            'status' => 'success',
            'data' => $transformedItems,
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
            ],
        ]);
    }

    /**
     * Menampilkan detail lengkap rekanan kualifikasi beserta dokumen dan item.
     */
    public function show(string $id, TenantContext $context): JsonResponse
    {
        $tenantId = $context->tenantId();

        $record = Prakualifikasi::where('tenant_id', $tenantId)
            ->where('id', $id)
            ->with(['documents', 'items'])
            ->firstOrFail();

        $array = $record->toArray();
        $array['dokumen'] = $array['documents'] ?? [];

        return response()->json([
            'status' => 'success',
            'data' => $array,
        ]);
    }
}
