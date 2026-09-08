<?php

namespace Modules\Apperp\ManagementAset\Http\Controllers\master;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Modules\Apperp\ManagementAset\Http\Controllers\Controller;
use Modules\Apperp\ManagementAset\Support\WorkOrderValidation;

/**
 * Aturan validasi perpindahan status work order.
 *
 * Bukan master biasa: barisnya adalah matriks tetap status x aturan yang disemai saat
 * tenant disiapkan, jadi tidak ada tambah, tidak ada hapus, dan tidak ada nomor. Yang dapat
 * diubah hanya keaktifan dan tingkat keparahannya.
 */
class ValidasiStatusWorkOrderController extends Controller
{
    private const RESOURCE = 'validasi-status-work-order';

    public function index(Request $request): JsonResponse
    {
        $this->guard($request, 'read');

        return response()->json(['data' => $this->aturan($this->tenant($request))]);
    }

    public function replace(Request $request): JsonResponse
    {
        $this->guard($request, 'update');
        $tenant = $this->tenant($request);
        $data = $request->validate([
            'aturan' => ['present', 'array', 'max:100'],
            'aturan.*.status' => ['required', Rule::in(WorkOrderValidation::statusTervalidasi())],
            'aturan.*.aturan' => ['required', Rule::in(WorkOrderValidation::ATURAN)],
            'aturan.*.aktif' => ['required', 'boolean'],
            'aturan.*.keparahan' => ['required', Rule::in(WorkOrderValidation::KEPARAHAN)],
        ]);

        DB::transaction(function () use ($tenant, $data): void {
            foreach ($data['aturan'] as $baris) {
                // Baris yang belum ada tetap dibuat: tenant lama dapat saja disemai sebelum
                // satu aturan diperkenalkan, dan layar tidak boleh menolak menyimpannya.
                DB::table('m_validasi_status_work_order')->updateOrInsert(
                    ['tenant_id' => $tenant, 'status' => $baris['status'], 'aturan' => $baris['aturan']],
                    [
                        'id' => (string) Str::ulid(),
                        'aktif' => filter_var($baris['aktif'], FILTER_VALIDATE_BOOL),
                        'keparahan' => $baris['keparahan'],
                        'created_at' => now(),
                        'updated_at' => now(),
                    ],
                );
            }
        });

        return $this->index($request);
    }

    /** @return Collection<int, object> */
    private function aturan(string $tenant): mixed
    {
        return DB::table('m_validasi_status_work_order')
            ->where('tenant_id', $tenant)
            ->orderBy('status')->orderBy('aturan')
            ->get(['id', 'status', 'aturan', 'aktif', 'keparahan']);
    }

    private function guard(Request $request, string $action): void
    {
        abort_unless(
            in_array('management-aset.'.self::RESOURCE.'.'.$action, $request->attributes->get('coreerp.permissions', []), true),
            403,
        );
    }

    private function tenant(Request $request): string
    {
        return (string) $request->attributes->get('coreerp.tenant_id');
    }
}
