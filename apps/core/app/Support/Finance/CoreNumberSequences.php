<?php

declare(strict_types=1);

namespace App\Support\Finance;

use App\Models\NumberSequenceReference;
use App\Models\TenantNumberSequence;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
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
     * @var array<string, array{profile: string, scope: string, segments: list<array<string, mixed>>, maximum: int}>
     */
    private const BAWAAN = [
        // Boleh diketik manual supaya nomor pemasok lama dapat dipindahkan apa adanya.
        'core.vendor' => [
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
        foreach (array_keys(self::BAWAAN) as $referenceCode) {
            $this->ensure($tenantId, $referenceCode);
        }
    }

    public function ensure(string $tenantId, string $referenceCode): void
    {
        $bawaan = self::BAWAAN[$referenceCode] ?? throw new RuntimeException('Referensi nomor Core tidak dikenal: '.$referenceCode);
        $referensi = NumberSequenceReference::query()
            ->where('app_id', self::APP_ID)
            ->where('code', $referenceCode)
            ->first();
        if ($referensi === null) {
            throw new RuntimeException('Referensi nomor '.$referenceCode.' belum terdaftar. Jalankan migration.');
        }

        if (TenantNumberSequence::query()->where('tenant_id', $tenantId)->where('reference_id', $referensi->id)->exists()) {
            return;
        }

        $profil = DB::table('number_sequence_profiles')->where('code', $bawaan['profile'])->first();
        if ($profil === null) {
            throw new RuntimeException('Profil nomor '.$bawaan['profile'].' belum tersedia.');
        }

        try {
            DB::transaction(fn () => TenantNumberSequence::query()->create([
                'tenant_id' => $tenantId,
                'reference_id' => $referensi->id,
                'profile_code' => $profil->code,
                'scope_type' => $bawaan['scope'],
                'status' => 'active',
                'is_continuous' => (bool) $profil->is_continuous,
                'allow_manual' => (bool) $profil->allow_manual,
                'preallocation_enabled' => (bool) $profil->preallocation_enabled,
                'preallocation_quantity' => (int) $profil->preallocation_quantity,
                'minimum_number' => 1,
                'maximum_number' => $bawaan['maximum'],
                'segments' => $bawaan['segments'],
            ]));
        } catch (UniqueConstraintViolationException) {
            // Permintaan lain membuatnya lebih dulu; itulah yang dipakai.
        }
    }
}
