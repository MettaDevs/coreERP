<?php

namespace Modules\Apperp\ManagementAset\Http\Controllers\transaksi\InventarisasiAset;

use App\Platform\Modules\Contracts\RowVersion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Apperp\ManagementAset\Http\Controllers\Controller;
use Modules\Apperp\ManagementAset\Http\Controllers\transaksi\PerencanaanAset\PlanningOrganizationScope;
use Modules\Apperp\ManagementAset\Models\master\GroupAset;
use Modules\Apperp\ManagementAset\Models\master\JenisAset;
use Modules\Apperp\ManagementAset\Models\transaksi\InventarisasiAset\Aset;
use Modules\Apperp\ManagementAset\Services\AssetNumberSequenceIssuer;
use Modules\Apperp\ManagementAset\Services\NumberSequenceException;
use Modules\Apperp\ManagementAset\Support\OrganizationScope;
use Modules\Apperp\ManagementAset\Support\StatusAset;

final class BudgetedAssetController extends Controller
{
    public function store(Request $request, AssetNumberSequenceIssuer $numbers): JsonResponse
    {
        $this->authorizeAction($request, 'create');
        $tenant = (string) $request->attributes->get('coreerp.tenant_id');
        $key = validator(['key' => $request->header('Idempotency-Key')], ['key' => ['required', 'string', 'max:154', 'regex:/^[A-Za-z0-9._:-]+$/']])->validate()['key'];
        $existing = Aset::withoutGlobalScope('actual')->withTrashed()->where('creation_key', $key)->first();
        if ($existing !== null) {
            app(PlanningOrganizationScope::class)->require($request, $existing->legal_entity_id, $existing->responsible_org_unit_id);
            abort_unless($existing->is_budgeted, 409, 'Kunci penyimpanan ini sudah dipakai untuk aset aktual. Mulai kartu baru.');

            return response()->json(['data' => $existing], 200, ['Idempotent-Replayed' => 'true']);
        }
        $data = $this->validated($request);
        $created = true;
        try {
            $asset = DB::transaction(function () use ($request, $data, $tenant, $key, $numbers, &$created) {
                DB::select('select pg_advisory_xact_lock(hashtextextended(?, 0))', ['budgeted-asset:'.$tenant.':'.$key]);
                $existing = Aset::withoutGlobalScope('actual')->withTrashed()->where('creation_key', $key)->first();
                if ($existing !== null) {
                    app(PlanningOrganizationScope::class)->require($request, $existing->legal_entity_id, $existing->responsible_org_unit_id);
                    abort_unless($existing->is_budgeted, 409, 'Kunci penyimpanan ini sudah dipakai untuk aset aktual. Mulai kartu baru.');
                    $created = false;

                    return $existing;
                }
                $code = $numbers->issueAsset($tenant, $key, $data['legal_entity_id'], $data['group_aset_id'], null, now()->toDateString());

                return Aset::withoutGlobalScope('actual')->create([
                    ...$data, 'tenant_id' => $tenant, 'creation_key' => $key, 'kode' => $code,
                    'is_budgeted' => true, 'lifecycle_state' => StatusAset::BUDGETED,
                    'acquisition_value' => 0, 'currency_code' => null, 'acquired_on' => null,
                ])->refresh();
            });

        } catch (NumberSequenceException $failure) {
            return response()->json(['error' => ['code' => $failure->errorCode, 'message' => $failure->getMessage()]], NumberSequenceException::HTTP_STATUS);
        }

        return response()->json(['data' => $asset], $created ? 201 : 200, $created ? [] : ['Idempotent-Replayed' => 'true']);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $this->authorizeAction($request, 'update');
        $asset = app(OrganizationScope::class)->asetQuery(Aset::withoutGlobalScope('actual')->where('is_budgeted', true), $request)->findOrFail($id);
        $data = $this->validated($request);
        abort_unless($asset->legal_entity_id === $data['legal_entity_id'], 422, 'Perusahaan aset tersimpan tidak dapat dipindahkan.');
        DB::transaction(function () use ($request, $asset, $data): void {
            RowVersion::claim(Aset::withoutGlobalScope('actual')->whereKey($asset->id), RowVersion::expected($request));
            $asset->fill($data)->save();
        });

        return response()->json(['data' => $asset->refresh()]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'nama' => ['required', 'string', 'max:255'],
            'legal_entity_id' => ['required', 'ulid'], 'responsible_org_unit_id' => ['required', 'ulid'],
            'group_aset_id' => ['required', 'ulid'], 'jenis_aset_id' => ['required', 'ulid'],
            'keterangan' => ['nullable', 'string', 'max:2000'],
            'is_budgeted' => ['required', 'accepted'],
            'acquisition_value' => ['prohibited'], 'acquired_on' => ['prohibited'], 'placed_in_service_on' => ['prohibited'],
        ]);
        app(PlanningOrganizationScope::class)->require($request, $data['legal_entity_id'], $data['responsible_org_unit_id']);
        foreach (['group_aset_id' => GroupAset::class, 'jenis_aset_id' => JenisAset::class] as $field => $model) {
            if (! $model::query()->whereKey($data[$field])->where('aktif', true)->exists()) {
                throw ValidationException::withMessages([$field => 'Pilih data yang masih aktif dan dapat Anda akses.']);
            }
        }
        unset($data['is_budgeted']);

        return $data;
    }

    private function authorizeAction(Request $request, string $action): void
    {
        abort_unless(in_array('management-aset.aset.'.$action, $request->attributes->get('coreerp.permissions', []), true), 403);
    }
}
