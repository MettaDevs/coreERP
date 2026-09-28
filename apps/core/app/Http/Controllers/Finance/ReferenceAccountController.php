<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Models\FinanceReferenceAccount;
use App\Models\FinanceReferenceAccountImport;
use App\Models\Organization;
use App\Support\Finance\ReferenceAccountImporter;
use Illuminate\Contracts\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Daftar akun referensi (K-05): dilihat semua anggota tenant, diimpor dan diaktifkan owner/admin.
 *
 * Akun tidak dibuat atau diubah satu per satu di layar ini. Sumber kebenarannya aplikasi finance,
 * dan satu-satunya jalan masuk adalah berkas ekspornya — akun yang diketik tangan di sini adalah
 * akun yang tidak dikenal pembacanya. Yang boleh diputuskan di sini hanya aktif atau tidaknya.
 */
final class ReferenceAccountController extends Controller
{
    private const PER_PAGE = 50;

    public function index(Request $request): Response
    {
        $membership = $this->currentMembership($request);
        $tenant = $membership->tenant_id;
        $filter = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'scope' => ['nullable', 'string', 'max:26'],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
        ]);
        $kata = trim((string) ($filter['q'] ?? ''));
        $scope = $filter['scope'] ?? null;

        $accounts = FinanceReferenceAccount::query()
            ->where('tenant_id', $tenant)
            ->when($scope === 'all', fn ($query) => $query->whereNull('legal_entity_id'))
            ->when($scope !== null && $scope !== 'all', fn ($query) => $query->where('legal_entity_id', $scope))
            ->when(($filter['status'] ?? null) !== null, fn ($query) => $query->where('active', $filter['status'] === 'active'))
            ->when($kata !== '', fn ($query) => $query->where(fn (QueryBuilder $inner) => $inner
                ->where('code', 'ilike', '%'.addcslashes($kata, '\\%_').'%')
                ->orWhere('name', 'ilike', '%'.addcslashes($kata, '\\%_').'%')
                ->orWhere('external_id', $kata)))
            ->orderBy('code')
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (FinanceReferenceAccount $akun): array => $this->present($akun));

        $imports = FinanceReferenceAccountImport::query()
            ->where('tenant_id', $tenant)
            ->latest()
            ->limit(10)
            ->get();
        $namaPengguna = DB::table('users')
            ->whereIn('id', $imports->pluck('imported_by_user_id')->filter()->unique()->values())
            ->pluck('name', 'id');

        return Inertia::render('settings/finance-accounts', [
            'canManage' => $membership->canManageAccess(),
            'filters' => ['q' => $kata, 'scope' => $scope, 'status' => $filter['status'] ?? null],
            'legalEntities' => $this->legalEntities($tenant),
            'accounts' => $accounts,
            'imports' => $imports->map(fn (FinanceReferenceAccountImport $import): array => [
                'id' => $import->id,
                'file_name' => $import->file_name,
                'legal_entity_id' => $import->legal_entity_id,
                'status' => $import->status,
                'created_count' => $import->created_count,
                'updated_count' => $import->updated_count,
                'unchanged_count' => $import->unchanged_count,
                'missing_count' => $import->missing_count,
                'rejected_count' => $import->rejected_count,
                'rejected_rows' => $import->rejected_rows ?? [],
                'imported_by' => $namaPengguna[$import->imported_by_user_id] ?? null,
                'created_at' => $import->created_at?->toIso8601String(),
            ])->values(),
            'header' => ReferenceAccountImporter::HEADER,
        ]);
    }

    /**
     * Pratinjau (`apply` kosong) atau terapkan satu berkas impor.
     *
     * Jawabannya selalu 200 dengan laporan lengkap, termasuk ketika berkas ditolak: laporan itulah
     * yang harus dibaca pengguna, dan status galat akan membuat klien kehilangan isinya.
     */
    public function import(Request $request, ReferenceAccountImporter $importer): JsonResponse
    {
        $membership = $this->currentMembership($request);
        abort_unless($membership->canManageAccess(), 403);
        $data = $request->validate([
            'file' => ['required', 'file', 'max:2048', 'extensions:csv,txt'],
            'scope' => ['required', 'string', 'max:26'],
            'apply' => ['sometimes', 'boolean'],
        ]);
        $legalEntityId = $this->scope($membership->tenant_id, $data['scope']);
        $file = $request->file('file');
        abort_if($file === null || is_array($file), 422);

        $report = $importer->run(
            $membership->tenant_id,
            $legalEntityId,
            $file->getClientOriginalName(),
            (string) $file->get(),
            filter_var($data['apply'] ?? false, FILTER_VALIDATE_BOOL),
            (string) $request->user()?->getAuthIdentifier(),
        );

        return response()->json(['data' => $report]);
    }

    public function update(Request $request, FinanceReferenceAccount $account): JsonResponse
    {
        $membership = $this->currentMembership($request);
        abort_unless($account->tenant_id === $membership->tenant_id, 404);
        abort_unless($membership->canManageAccess(), 403);
        $data = $request->validate(['active' => ['required', 'boolean']]);

        $account->update(['active' => (bool) $data['active']]);

        return response()->json(['data' => $this->present($account)]);
    }

    public function template(): HttpResponse
    {
        return response(implode(',', ReferenceAccountImporter::HEADER)."\r\n", 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="templat-daftar-akun.csv"',
        ]);
    }

    /** `all` berarti berlaku untuk semua entitas legal; selain itu id entitas legal milik tenant. */
    private function scope(string $tenantId, string $scope): ?string
    {
        if ($scope === 'all') {
            return null;
        }

        $ada = Organization::query()
            ->where('tenant_id', $tenantId)
            ->where('classification', 'legal_entity')
            ->whereKey($scope)
            ->exists();
        if (! $ada) {
            throw ValidationException::withMessages(['scope' => 'Pilih entitas legal milik tenant ini, atau semua entitas.']);
        }

        return $scope;
    }

    /** @return list<array{id: string, name: string, company_code: ?string}> */
    private function legalEntities(string $tenantId): array
    {
        return array_values(Organization::query()
            ->where('tenant_id', $tenantId)
            ->where('classification', 'legal_entity')
            ->with('legalEntity:organization_id,company_code')
            ->orderBy('name')
            ->get()
            ->map(static fn (Organization $organisasi): array => [
                'id' => $organisasi->id,
                'name' => (string) $organisasi->name,
                'company_code' => $organisasi->legalEntity?->company_code,
            ])
            ->all());
    }

    /** @return array<string, mixed> */
    private function present(FinanceReferenceAccount $akun): array
    {
        return [
            'id' => $akun->id,
            'external_id' => $akun->external_id,
            'code' => $akun->code,
            'name' => $akun->name,
            'type' => $akun->type,
            'active' => $akun->active,
            'legal_entity_id' => $akun->legal_entity_id,
            'synced_at' => $akun->synced_at?->toIso8601String(),
        ];
    }
}
