<?php

declare(strict_types=1);

namespace App\Foundation\NumberSequence\Support;

use App\Foundation\NumberSequence\Models\NumberSequenceReference;
use App\Foundation\NumberSequence\Models\TenantNumberSequence;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Urutan nomor untuk referensi yang dimiliki Core sendiri, bukan module.
 *
 * Referensi module mendapat urutan tenant saat module dipasang (`EnsureNumberSequenceDrafts`).
 * Referensi Core tidak punya peristiwa pemasangan, jadi urutannya dibuat saat pertama kali dipakai —
 * di luar transaksi dokumen, supaya bentrokan dua permintaan pertama yang bersamaan tidak membatalkan
 * transaksi pemanggil.
 *
 * Setelan awal per referensi ditulis di sini. Admin tenant tetap bebas mengubahnya di layar Nomor
 * dokumen sesudahnya; yang di sini hanya bawaan saat urutan lahir.
 */
final class CoreNumberSequences
{
    public const APP_ID = 'core';

    /**
     * @var array<string, array{name: string, default_prefix: string, allowed_scopes: list<string>, profile: string, scope: string, segments: list<array<string, mixed>>, maximum: int}>
     */
    private const DEFAULTS = [
        // Boleh diketik manual supaya nomor pemasok lama dapat dipindahkan apa adanya.
        'core.vendor' => [
            'name' => 'Nomor vendor',
            'default_prefix' => 'VND',
            'allowed_scopes' => ['legal_entity'],
            'profile' => 'manual-compatible',
            'scope' => 'legal_entity',
            'segments' => [['type' => 'constant', 'value' => 'VND-'], ['type' => 'number', 'length' => 6]],
            'maximum' => 999999,
        ],
    ];

    /**
     * Semua urutan Core untuk satu tenant. Dipanggil layar Nomor dokumen, supaya admin dapat
     * mengubah formatnya — misalnya menyesuaikan dengan nomor pemasok lama yang akan diketik
     * manual — sebelum nomor pertama terbit dan format itu terkunci.
     */
    public function ensureAll(string $tenantId): void
    {
        foreach (array_keys(self::DEFAULTS) as $referenceCode) {
            $this->ensure($tenantId, $referenceCode);
        }
    }

    public function ensure(string $tenantId, string $referenceCode): void
    {
        $default = self::DEFAULTS[$referenceCode] ?? throw new RuntimeException('Referensi nomor Core tidak dikenal: '.$referenceCode);
        $reference = $this->references($referenceCode, $default);

        if (TenantNumberSequence::query()->where('tenant_id', $tenantId)->where('reference_id', $reference->id)->exists()) {
            return;
        }

        $profile = DB::table('number_sequence_profiles')->where('code', $default['profile'])->first();
        if ($profile === null) {
            throw new RuntimeException('Profil nomor '.$default['profile'].' belum tersedia.');
        }

        try {
            DB::transaction(fn () => TenantNumberSequence::query()->create([
                'tenant_id' => $tenantId,
                'reference_id' => $reference->id,
                'profile_code' => $profile->code,
                'scope_type' => $default['scope'],
                'status' => 'active',
                'is_continuous' => (bool) $profile->is_continuous,
                'allow_manual' => (bool) $profile->allow_manual,
                'preallocation_enabled' => (bool) $profile->preallocation_enabled,
                'preallocation_quantity' => (int) $profile->preallocation_quantity,
                'minimum_number' => 1,
                'maximum_number' => $default['maximum'],
                'segments' => $default['segments'],
            ]));
        } catch (UniqueConstraintViolationException) {
            // Permintaan lain membuatnya lebih dulu; itulah yang dipakai.
        }
    }

    /**
     * Baris referensi, dipasang ulang bila hilang.
     *
     * Migration `create_vendors_table` sudah menulis baris app `core` dan referensinya. Tetapi
     * keduanya data, bukan skema: apa pun yang mengosongkan tabel `apps` — test yang memakai
     * `DatabaseTruncation` melakukannya dengan `TRUNCATE apps CASCADE` — ikut menghapusnya, dan
     * sesudah itu vendor tidak dapat dibuat sama sekali. Karena bentuk referensinya memang milik
     * kelas ini, kelas ini pula yang memasangnya kembali. `insertOrIgnore` tidak membatalkan
     * transaksi pemanggil bila permintaan lain memasangnya lebih dulu.
     *
     * @param  array{name: string, default_prefix: string, allowed_scopes: list<string>}  $default
     */
    private function references(string $referenceCode, array $default): NumberSequenceReference
    {
        $exists = NumberSequenceReference::query()->where('app_id', self::APP_ID)->where('code', $referenceCode)->first();
        if ($exists !== null) {
            return $exists;
        }

        $now = now();
        DB::table('apps')->insertOrIgnore([
            'id' => self::APP_ID,
            'name' => 'CoreERP',
            'version' => '1.0.0',
            'status' => 'internal',
            'database_name' => null,
            'description' => 'Pemilik referensi nomor milik Core sendiri, misalnya nomor vendor. Bukan produk yang dipasang.',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('app_number_sequence_references')->insertOrIgnore([
            'id' => strtolower((string) Str::ulid()),
            'app_id' => self::APP_ID,
            'code' => $referenceCode,
            'name' => $default['name'],
            'default_prefix' => $default['default_prefix'],
            'allowed_scopes' => json_encode($default['allowed_scopes'], JSON_THROW_ON_ERROR),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return NumberSequenceReference::query()->where('app_id', self::APP_ID)->where('code', $referenceCode)->firstOrFail();
    }
}
