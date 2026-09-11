<?php

namespace Modules\Apperp\ManagementAset\Http\Controllers;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
 *
 * `TModel` adalah model baris penghubung milik satu controller turunan; anak
 * menyebutkannya lewat `@extends MasterLinkController<GroupBukuPenyusutan>`.
 *
 * @template TModel of Model
 */
abstract class MasterLinkController extends Controller
{
    protected const APP_ID = 'management-aset';

    /** Slug pemilik, dipakai untuk permission. Contoh: `group-aset`. */
    abstract protected function ownerResource(): string;

    /**
     * Model pemilik, untuk memastikan pemilik ada pada tenant aktif.
     *
     * @return class-string<Model>
     */
    abstract protected function ownerModel(): string;

    /** Kolom foreign key ke pemilik pada tabel penghubung. */
    abstract protected function ownerColumn(): string;

    /**
     * Query baris penghubung; `$termasukArsip` membuka baris yang sudah diarsipkan.
     *
     * Model baris penghubung **wajib** memakai soft delete, sebab penggantian himpunan di
     * `replace()` menghidupkan kembali baris yang identitasnya sama alih-alih menyisipkan
     * yang kedua di bawah unique index yang sama.
     *
     * Syarat itu ditegakkan di sini, bukan sekadar ditulis: satu-satunya cara anak
     * memenuhi kontrak ini adalah menyebut `withTrashed()` pada model konkretnya, dan
     * `withTrashed()` hanya ada pada model yang memakai soft delete. Model yang melepas
     * `SoftDeletes` membuat analisa tipe gagal di controller-nya sendiri.
     *
     * @return Builder<TModel>
     */
    abstract protected function query(bool $termasukArsip = false): Builder;

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

    /**
     * Kolom yang disajikan pada respons.
     *
     * @return list<string>
     */
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
        $this->findOwner($ownerId);

        return response()->json(['data' => $this->rows($ownerId)]);
    }

    public function replace(Request $request, string $ownerId): JsonResponse
    {
        $this->requirePermission($request, 'update');
        $tenantId = $this->tenantId($request);
        $this->findOwner($ownerId);

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
            $ownerModel = $this->ownerModel();
            $ownerModel::query()->whereKey($ownerId)->lockForUpdate()->first();

            // Aturan yang membaca keadaan pemilik/anak harus diperiksa setelah lock agar
            // hasilnya tetap benar bila ada penulisan lain pada saat yang sama.
            $this->afterRowsValidated($tenantId, $ownerId, $data['rows']);

            // Baris yang hilang dari kiriman diarsipkan, bukan dihapus fisik, supaya
            // buku aset yang sudah terlanjur menyalin aturannya tetap dapat ditelusuri.
            $this->query()->where($this->ownerColumn(), $ownerId)->delete();

            foreach ($data['rows'] as $row) {
                $payload = $this->rowPayload($row);
                // Baris terarsip ikut dicari: baris yang identitasnya sama boleh saja baru
                // diarsipkan beberapa baris di atas, atau pada penyimpanan sebelumnya. Ia
                // dihidupkan kembali, bukan disisipkan kedua kalinya di bawah unique index
                // yang sama.
                $existing = $this->query(termasukArsip: true)
                    ->where($this->ownerColumn(), $ownerId)
                    ->where($this->identity($row))
                    ->first();
                if ($existing) {
                    $existing->fill($payload);
                    // Jalur yang sama dengan `$existing->deleted_at = null`, hanya disebut
                    // langsung: kolom arsip berada di luar `$fillable`, jadi `fill()` di atas
                    // tidak menyentuhnya, dan ia bukan kolom yang dikenal `Model`.
                    $existing->setAttribute('deleted_at', null);
                    $existing->save();

                    continue;
                }
                $this->query()->create([
                    $this->ownerColumn() => $ownerId,
                    ...$this->identity($row),
                    ...$payload,
                ]);
            }
        });

        return response()->json(['data' => $this->rows($ownerId)]);
    }

    /**
     * Kolom yang membuat satu baris unik di bawah pemiliknya, di luar kolom pemilik.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    abstract protected function identity(array $row): array;

    /** @return list<array<string, mixed>> */
    private function rows(string $ownerId): array
    {
        // `array_values()` tidak mengubah isi maupun urutannya: kunci hasil `get()` memang
        // sudah 0..n. Ia yang membuat bentuk list itu terbaca, dan bentuk list yang menjaga
        // respons tetap terbit sebagai array JSON, bukan object.
        return array_values($this->query()
            ->where($this->ownerColumn(), $ownerId)
            ->orderBy('id')
            ->get($this->columns())
            ->map(fn (Model $row): array => $row->only($this->columns()))
            ->all());
    }

    private function findOwner(string $ownerId): void
    {
        $ownerModel = $this->ownerModel();

        abort_unless($ownerModel::query()->whereKey($ownerId)->exists(), 404);
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
