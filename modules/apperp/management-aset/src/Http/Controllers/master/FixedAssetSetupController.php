<?php

namespace Modules\Apperp\ManagementAset\Http\Controllers\master;

use App\Platform\Modules\Contracts\RowVersion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Modules\Apperp\ManagementAset\Http\Controllers\Controller;
use Modules\Apperp\ManagementAset\Models\master\FixedAssetSetup;

/**
 * Pengaturan aset tetap (halaman *Fixed Asset Setup* 5607 Business Central): satu kartu per tenant,
 * dibaca dengan `GET` dan disimpan dengan `PUT` ke alamat yang sama.
 *
 * Seperti BC, barisnya baru lahir saat pertama kali disimpan. Selama belum ada, `GET` memulangkan nilai
 * kosong dengan versi 0, dan `PUT` dengan versi 0 yang membuatnya ({@see RowVersion::claimIfExists()}).
 */
class FixedAssetSetupController extends Controller
{
    private const PERMISSION = 'management-aset.fixed-asset-parameters.';

    public function show(Request $request): JsonResponse
    {
        $this->requirePermission($request, 'read');
        $setup = FixedAssetSetup::current();

        return response()->json(['data' => $this->present($setup)], 200, ['ETag' => RowVersion::etag($setup->version ?? 0)]);
    }

    public function update(Request $request): JsonResponse
    {
        $this->requirePermission($request, 'update');
        $tenantId = (string) $request->attributes->get('coreerp.tenant_id');
        $data = $request->validate($this->rules($tenantId));
        $expected = RowVersion::expected($request);

        DB::transaction(function () use ($data, $tenantId, $expected): void {
            if (RowVersion::claimIfExists(FixedAssetSetup::query(), $expected)) {
                FixedAssetSetup::query()->firstOrFail()->update($data);

                return;
            }
            FixedAssetSetup::query()->create(['tenant_id' => $tenantId, ...$data]);
        });

        return response()->json(['data' => $this->present(FixedAssetSetup::current())]);
    }

    /**
     * Satu aturan per pengaturan, semuanya `sometimes`: penyimpanan yang hanya membawa sebagian field
     * tidak mengosongkan field lain. Pengaturan baru menambah barisnya di sini.
     *
     * @return array<string, list<mixed>>
     */
    private function rules(string $tenantId): array
    {
        return [
            'buku_penyusutan_bawaan_id' => ['sometimes', 'nullable', 'ulid', Rule::exists('aset_m_buku_penyusutan', 'id')
                ->where('tenant_id', $tenantId)->where('aktif', true)->whereNull('deleted_at')],
        ];
    }

    /** @return array<string, mixed> */
    private function present(?FixedAssetSetup $setup): array
    {
        $book = $setup?->bukuPenyusutanBawaan;

        return [
            'version' => $setup->version ?? 0,
            'buku_penyusutan_bawaan_id' => $setup?->buku_penyusutan_bawaan_id,
            'buku_penyusutan_bawaan' => $book === null ? null : ['id' => $book->id, 'kode' => $book->kode, 'nama' => $book->nama],
            'updated_at' => $setup?->updated_at?->toISOString(),
        ];
    }

    private function requirePermission(Request $request, string $action): void
    {
        $permission = self::PERMISSION.$action;
        abort_unless(
            in_array($permission, $request->attributes->get('coreerp.permissions', []), true),
            response()->json(['error' => [
                'code' => 'forbidden',
                'message' => 'Hak '.$permission.' belum dimiliki pengguna pada tenant aktif.',
            ]], 403)
        );
    }
}
