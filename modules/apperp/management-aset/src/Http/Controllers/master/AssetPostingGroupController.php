<?php

namespace Modules\Apperp\ManagementAset\Http\Controllers\master;

use App\Support\Modules\Contracts\DaftarAkun;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Apperp\ManagementAset\Http\Controllers\Controller;
use Modules\Apperp\ManagementAset\Models\master\AssetPostingGroup;
use Modules\Apperp\ManagementAset\Models\master\GroupAset;

/**
 * Posting group aset (TODO 8.1): matriks group × akun gaya *FA Posting Groups* Business Central,
 * dengan riwayat tanggal berlaku per group.
 *
 * Satu baris diidentifikasi pasangan group dan `effective_from`, jadi penyimpanannya `PUT` ke
 * alamat pasangan itu: mengulang permintaan yang sama tidak menambah baris, tanpa kunci
 * idempotensi. Membuat baris untuk tanggal baru butuh izin `create`, mengubah baris yang sudah ada
 * butuh `update`, dan mengarsipkan butuh `archive`. Ketiganya izin terpisah atas kode
 * `fixed-asset-posting-profiles` yang sudah ada di manifest (keputusan pemilik produk,
 * 22 September 2026), supaya role yang hanya melihat tidak ikut mengubah akun jurnal.
 *
 * Akun dipilih lewat kontrak `DaftarAkun` dan hanya boleh akun yang berlaku untuk semua entitas
 * legal: posting group berlaku untuk seluruh tenant, sedangkan akun khusus satu entitas akan
 * tertahan di Core begitu dipakai entitas lain.
 */
class AssetPostingGroupController extends Controller
{
    private const PERMISSION = 'management-aset.fixed-asset-posting-profiles.';

    public function __construct(private readonly DaftarAkun $daftarAkun) {}

    public function index(Request $request): JsonResponse
    {
        $this->requirePermission($request, 'read');
        $tenantId = $this->tenantId($request);
        $today = now()->toDateString();

        $all = AssetPostingGroup::query()->orderByDesc('effective_from')->get();
        $rows = $all->groupBy('group_aset_id');

        $accountIds = [];
        foreach ($all as $row) {
            foreach (array_keys(AssetPostingGroup::ACCOUNTS) as $column) {
                $accountId = $row->getAttribute($column);
                if (is_string($accountId)) {
                    $accountIds[$accountId] = $accountId;
                }
            }
        }

        $groups = [];
        $incomplete = 0;
        foreach (GroupAset::query()->orderBy('kode')->get(['id', 'kode', 'nama', 'aktif']) as $group) {
            /** @var list<AssetPostingGroup> $history */
            $history = array_values(($rows->get($group->getKey()) ?? collect())->all());
            $current = null;
            foreach ($history as $row) {
                if ($row->effective_from->toDateString() <= $today) {
                    $current = $row;
                    break;
                }
            }
            $missing = $current?->missingRequiredAccounts() ?? AssetPostingGroup::REQUIRED_ACCOUNTS;
            if ($missing !== []) {
                $incomplete++;
            }

            $groups[] = [
                'id' => $group->getKey(),
                'kode' => $group->kode,
                'nama' => $group->nama,
                'aktif' => (bool) $group->aktif,
                'current' => $current ? $this->present($current) : null,
                'rows' => array_map(fn (AssetPostingGroup $row): array => $this->present($row), $history),
                'missing' => $missing,
            ];
        }

        return response()->json(['data' => [
            'today' => $today,
            'accounts' => array_map(
                static fn (string $column, string $label): array => [
                    'column' => $column,
                    'label' => $label,
                    'required' => in_array($column, AssetPostingGroup::REQUIRED_ACCOUNTS, true),
                ],
                array_keys(AssetPostingGroup::ACCOUNTS),
                array_values(AssetPostingGroup::ACCOUNTS),
            ),
            'groups' => $groups,
            'incomplete_groups' => $incomplete,
            'account_details' => (object) $this->daftarAkun->banyak($tenantId, array_values($accountIds)),
        ]]);
    }

    /** Akun aktif yang berlaku untuk semua entitas legal, untuk pemilih akun di layar. */
    public function accounts(Request $request): JsonResponse
    {
        $this->requirePermission($request, 'read');
        $query = $request->validate(['q' => ['sometimes', 'nullable', 'string', 'max:100']])['q'] ?? '';

        return response()->json(['data' => $this->daftarAkun->cari($this->tenantId($request), null, (string) $query)]);
    }

    public function upsert(Request $request, string $groupAsetId, string $effectiveFrom): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        $rules = ['effective_from' => ['required', 'date_format:Y-m-d']];
        foreach (array_keys(AssetPostingGroup::ACCOUNTS) as $column) {
            $rules[$column] = ['sometimes', 'nullable', 'ulid'];
        }
        $data = validator(['effective_from' => $effectiveFrom, ...$request->all()], $rules)->validate();
        $accounts = [];
        foreach (array_keys(AssetPostingGroup::ACCOUNTS) as $column) {
            $accounts[$column] = is_string($data[$column] ?? null) ? $data[$column] : null;
        }

        [$row, $created] = DB::transaction(function () use ($request, $tenantId, $groupAsetId, $effectiveFrom, $accounts): array {
            // Kunci pada group, bukan pada baris posting group: dua penyimpanan pertama untuk
            // tanggal yang sama sama-sama belum melihat baris lawannya, dan yang kalah akan
            // menabrak indeks unik sebagai 500. Dengan kunci ini yang kedua menjadi pembaruan.
            abort_unless(GroupAset::query()->whereKey($groupAsetId)->lockForUpdate()->exists(), 404);
            $existing = $this->find($groupAsetId, $effectiveFrom);
            $this->requirePermission($request, $existing ? 'update' : 'create');
            $this->rejectInvalidAccounts($tenantId, $accounts, $existing);

            $row = $existing ?? new AssetPostingGroup(['group_aset_id' => $groupAsetId, 'effective_from' => $effectiveFrom]);
            $row->fill($accounts)->save();

            return [$row, $existing === null];
        });

        return response()->json(['data' => $this->present($row)], $created ? 201 : 200);
    }

    public function archive(Request $request, string $groupAsetId, string $effectiveFrom): Response
    {
        $this->requirePermission($request, 'archive');
        DB::transaction(function () use ($groupAsetId, $effectiveFrom): void {
            abort_unless(GroupAset::query()->whereKey($groupAsetId)->lockForUpdate()->exists(), 404);
            $row = $this->find($groupAsetId, $effectiveFrom);
            abort_if($row === null, 404);
            $row->delete();
        });

        return response()->noContent();
    }

    private function find(string $groupAsetId, string $effectiveFrom): ?AssetPostingGroup
    {
        return AssetPostingGroup::query()
            ->where('group_aset_id', $groupAsetId)
            ->whereDate('effective_from', $effectiveFrom)
            ->first();
    }

    /**
     * Akun harus ada di daftar akun tenant, berlaku untuk semua entitas legal, dan aktif. Akun yang
     * dinonaktifkan sesudah dipetakan tetap boleh tinggal di kolom yang tidak diubah, supaya
     * baris lama masih dapat disimpan untuk memperbaiki kolom lain; posting yang memakainya tetap
     * tertahan di Core sampai akunnya diganti.
     *
     * @param  array<string, ?string>  $accounts
     */
    private function rejectInvalidAccounts(string $tenantId, array $accounts, ?AssetPostingGroup $existing): void
    {
        $known = $this->daftarAkun->banyak($tenantId, array_values(array_unique(array_filter($accounts))));
        $errors = [];
        foreach ($accounts as $column => $accountId) {
            if ($accountId === null) {
                continue;
            }
            $account = $known[$accountId] ?? null;
            $label = AssetPostingGroup::ACCOUNTS[$column];
            if ($account === null) {
                $errors[$column] = sprintf('Akun %s tidak ada di daftar akun.', strtolower($label));
            } elseif ($account['legal_entity_id'] !== null) {
                $errors[$column] = sprintf('Akun %s %s khusus satu entitas legal. Posting group berlaku untuk semua entitas, jadi pilih akun yang berlaku untuk semua entitas.', $account['code'], $account['name']);
            } elseif (! $account['active'] && $existing?->getAttribute($column) !== $accountId) {
                $errors[$column] = sprintf('Akun %s %s nonaktif.', $account['code'], $account['name']);
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /** @return array<string, mixed> */
    private function present(AssetPostingGroup $row): array
    {
        $accounts = [];
        foreach (array_keys(AssetPostingGroup::ACCOUNTS) as $column) {
            $accounts[$column] = $row->getAttribute($column);
        }

        return [
            'group_aset_id' => $row->group_aset_id,
            'effective_from' => $row->effective_from->toDateString(),
            ...$accounts,
            'missing' => $row->missingRequiredAccounts(),
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

    private function tenantId(Request $request): string
    {
        return (string) $request->attributes->get('coreerp.tenant_id');
    }
}
