<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Base tipis untuk tabel penghubung yang disunting di dalam form pemiliknya, misalnya
 * matriks group x buku penyusutan.
 *
 * Ia sengaja bukan MasterDataController: baris penghubung bukan master. Ia tidak punya
 * kode, tidak menerbitkan nomor dari Core, dan tidak berdiri sendiri di navigasi.
 *
 * Saat disimpan, satu `PUT` berisi daftar penuh menggantikan seluruh baris lama.
 * Karena itu permintaan yang sama dapat diulang tanpa efek tambahan, sehingga tidak
 * memerlukan idempotency key per baris. Hak akses memakai permission pemiliknya,
 * sebab baris ini memang bagian dari pengelolaan pemilik.
 */
abstract class MasterLinkController extends Controller
{
    protected const APP_ID = 'management-aset';

    /** Slug pemilik, dipakai untuk permission. Contoh: `group-aset`. */
    abstract protected function ownerResource(): string;

    /** Tabel pemilik, untuk memastikan pemilik ada pada tenant aktif. */
    abstract protected function ownerTable(): string;

    /** Kolom foreign key ke pemilik pada tabel penghubung. */
    abstract protected function ownerColumn(): string;

    abstract protected function table(): string;

    /**
     * Aturan validasi tiap baris, tanpa kolom pemilik.
     *
     * @return array<string, array<int, mixed>>
     */
    abstract protected function rowRules(string $tenantId): array;

    /**
     * Kolom yang disimpan dari satu baris tervalidasi.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    abstract protected function rowPayload(array $row): array;

    /** Kolom yang disajikan pada respons. @return list<string> */
    abstract protected function columns(): array;

    /**
     * Validasi kombinasi seluruh baris sebelum transaksi mengganti himpunan lama.
     * Hook ini sengaja berada setelah validasi per-field agar controller dapat memakai
     * default dari owner/book tanpa menyentuh data yang belum tervalidasi.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    protected function afterRowsValidated(string $tenantId, string $ownerId, array $rows): void {}

    public function index(Request $request, string $ownerId): JsonResponse
    {
        $this->requirePermission($request, 'read');
        $tenantId = $this->tenantId($request);
        $this->findOwner($tenantId, $ownerId);

        return response()->json(['data' => $this->rows($tenantId, $ownerId)]);
    }

    public function replace(Request $request, string $ownerId): JsonResponse
    {
        $this->requirePermission($request, 'update');
        $tenantId = $this->tenantId($request);
        $this->findOwner($tenantId, $ownerId);

        $rules = ['rows' => ['present', 'array']];
        foreach ($this->rowRules($tenantId) as $column => $rule) {
            $rules['rows.*.'.$column] = $rule;
        }
        $data = $request->validate($rules);
        DB::transaction(function () use ($tenantId, $ownerId, $data): void {
            // Mengunci baris pemilik lebih dahulu supaya dua penyuntingan bersamaan pada
            // pemilik yang sama berjalan berurutan.
            //
            // Tanpa kunci ini keduanya sama-sama tidak melihat INSERT lawannya yang belum
            // commit, lalu sama-sama menyisipkan baris dengan identitas yang sama dan yang
            // kalah menabrak unique index sebagai 500. Kunci pada pemilik, bukan pada
            // barisnya, karena baris yang bertabrakan justru yang belum ada.
            DB::table($this->ownerTable())
                ->where(['tenant_id' => $tenantId, 'id' => $ownerId])
                ->lockForUpdate()
                ->first();

            // Aturan yang membaca keadaan pemilik/anak harus diperiksa setelah lock agar
            // hasilnya tetap benar bila ada penulisan lain pada saat yang sama.
            $this->afterRowsValidated($tenantId, $ownerId, $data['rows']);

            // Baris yang hilang dari kiriman diarsipkan, bukan dihapus fisik, supaya
            // buku aset yang sudah terlanjur menyalin aturannya tetap dapat ditelusuri.
            DB::table($this->table())
                ->where(['tenant_id' => $tenantId, $this->ownerColumn() => $ownerId])
                ->whereNull('deleted_at')
                ->update(['deleted_at' => now(), 'updated_at' => now()]);

            foreach ($data['rows'] as $row) {
                $payload = $this->rowPayload($row);
                $keys = ['tenant_id' => $tenantId, $this->ownerColumn() => $ownerId, ...$this->identity($row)];
                $existing = DB::table($this->table())->where($keys)->first();
                if ($existing) {
                    DB::table($this->table())->where('id', $existing->id)
                        ->update([...$payload, 'deleted_at' => null, 'updated_at' => now()]);

                    continue;
                }
                DB::table($this->table())->insert([
                    'id' => (string) Str::ulid(),
                    ...$keys,
                    ...$payload,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });

        return response()->json(['data' => $this->rows($tenantId, $ownerId)]);
    }

    /**
     * Kolom yang membuat satu baris unik di bawah pemiliknya, di luar kolom pemilik.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    abstract protected function identity(array $row): array;

    /** @return list<array<string, mixed>> */
    private function rows(string $tenantId, string $ownerId): array
    {
        return DB::table($this->table())
            ->where(['tenant_id' => $tenantId, $this->ownerColumn() => $ownerId])
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->get($this->columns())
            ->map(fn (object $row): array => (array) $row)
            ->all();
    }

    private function findOwner(string $tenantId, string $ownerId): void
    {
        $exists = DB::table($this->ownerTable())
            ->where(['tenant_id' => $tenantId, 'id' => $ownerId])
            ->whereNull('deleted_at')
            ->exists();

        abort_unless($exists, 404);
    }

    private function requirePermission(Request $request, string $action): void
    {
        $permission = static::APP_ID.'.'.$this->ownerResource().'.'.$action;
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
