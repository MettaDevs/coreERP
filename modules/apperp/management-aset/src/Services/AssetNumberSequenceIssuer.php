<?php

namespace Modules\Apperp\ManagementAset\Services;

use App\Platform\Modules\Contracts\NumberSequenceIssuer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Modules\Apperp\ManagementAset\Models\master\GroupAset;
use Modules\Apperp\ManagementAset\Models\master\KelompokHartaFiskal;
use Modules\Apperp\ManagementAset\Models\master\LokasiAset;
use Throwable;
use UnexpectedValueException;

/**
 * Penerbitan nomor lewat kontrak Core, bukan lewat HTTP.
 *
 * Menggantikan klien HTTP yang dulu mengirim permintaan ke Core. Di dalam satu runtime,
 * permintaan itu tidak menambah apa pun selain kegagalan yang bisa terjadi: batas waktu,
 * percobaan ulang, dan 503 yang harus dijelaskan ke pengguna padahal Core berada di proses
 * yang sama.
 *
 * Yang lebih penting daripada kecepatan: penerbitan sekarang berjalan pada koneksi database
 * yang sama dengan dokumen yang sedang disimpan, jadi ia ikut di dalam transaksi dokumen itu.
 * Dokumen gagal, nomornya ikut batal — tidak ada lompatan nomor yang harus dijelaskan ke
 * pemeriksa. Itu alasan terkuat pemindahan ke satu runtime ini ada.
 *
 * Tanda tangan `issue()` sengaja dipertahankan persis seperti milik klien lama supaya
 * pemanggilnya tidak ikut berubah pada pull request yang hanya mengganti jalurnya.
 */
class AssetNumberSequenceIssuer
{
    public function __construct(private readonly NumberSequenceIssuer $issuer) {}

    /**
     * Menerbitkan satu nomor untuk reference milik module ini.
     *
     * @param  array<string,string>  $values
     *
     * @throws NumberSequenceException
     */
    public function issue(string $reference, string $tenantId, string $idempotencyKey, ?string $legalEntityId = null, ?string $manualValue = null, array $values = []): string
    {
        try {
            $result = $this->issuer->issue(
                [
                    'tenant_id' => $tenantId,
                    'app_id' => 'management-aset',
                    'legal_entity_id' => $legalEntityId,
                    'values' => $values,
                ],
                $reference,
                $idempotencyKey,
                $manualValue,
            );
        } catch (ValidationException $failure) {
            throw $this->fail('number_sequence_failed', $failure->getMessage(), $reference, $tenantId, $failure);
        }

        // Kontrak `NumberSequenceIssuer` sudah menjamin kunci `number` ada dan berupa string, jadi
        // yang tersisa untuk diperiksa hanyalah nomor kosong — satu-satunya bentuk jawaban
        // tidak valid yang masih mungkin lolos dari penerbit.
        $number = $result['number'];

        if ($number === '') {
            throw new UnexpectedValueException('Penerbit nomor mengembalikan nomor kosong untuk reference '.$reference.'.');
        }

        return $number;
    }

    public function issueAsset(string $tenantId, string $idempotencyKey, string $legalEntityId, string $groupId, ?string $locationId, string $acquiredOn): string
    {
        return $this->issueAssets($tenantId, $legalEntityId, [[
            'key' => $idempotencyKey, 'group_id' => $groupId, 'location_id' => $locationId, 'acquired_on' => $acquiredOn,
        ]])[$idempotencyKey];
    }

    /**
     * @param  list<array{key:string,group_id:string,location_id:?string,acquired_on:string}>  $requests
     * @return array<string,string>
     */
    public function issueAssets(string $tenantId, string $legalEntityId, array $requests): array
    {
        return DB::transaction(function () use ($tenantId, $legalEntityId, $requests): array {
            $reference = 'management-aset.asset-code';
            $fields = $this->issuer->contextFields($tenantId, 'management-aset', $reference);
            $contexts = [];
            $prepared = [];
            foreach ($requests as $position => $request) {
                $contextKey = json_encode([$request['group_id'], $request['location_id'], $request['acquired_on']], JSON_THROW_ON_ERROR);
                $values = $contexts[$contextKey] ??= $this->assetValues($tenantId, $request['group_id'], $request['location_id'], $request['acquired_on'], $fields);
                ksort($values);
                $prepared[] = ['key' => $request['key'], 'values' => $values, 'rank' => json_encode($values, JSON_THROW_ON_ERROR), 'position' => $position];
            }
            // Dokumen dengan baris terbalik tetap mengunci counter dalam urutan yang sama.
            usort($prepared, static fn (array $first, array $second): int => [$first['rank'], $first['position']] <=> [$second['rank'], $second['position']]);
            $codes = [];
            foreach ($prepared as $request) {
                $code = $this->issue($reference, $tenantId, $request['key'], $legalEntityId, values: $request['values']);
                if (strlen($code) > 50) {
                    throw $this->fail('number_sequence_failed', 'Format kode aset melebihi 50 karakter. Pendekkan bagian kode pada pengaturan nomor.', $reference, $tenantId);
                }
                $codes[$request['key']] = $code;
            }

            return $codes;
        });
    }

    /**
     * @param  list<string>  $fields
     * @return array<string,string>
     */
    private function assetValues(string $tenantId, string $groupId, ?string $locationId, string $acquiredOn, array $fields): array
    {
        $values = [];
        if ($fields !== []) {
            $group = GroupAset::query()->where('tenant_id', $tenantId)->sharedLock()->findOrFail($groupId);
            $classification = KelompokHartaFiskal::query()->where('tenant_id', $tenantId)->whereKey($group->kelompok_harta_fiskal_id)->sharedLock()->first();
            $location = LokasiAset::query()->where('tenant_id', $tenantId)->whereKey($locationId ?? $group->lokasi_aset_id)->sharedLock()->first();
            $values = [
                'asset_classification' => (string) $classification?->kode,
                'asset_group' => (string) $group->kode,
                'asset_location' => (string) $location?->kode,
                'acquisition_year' => substr($acquiredOn, 0, 4),
            ];
            $values = array_intersect_key($values, array_flip($fields));
        }

        return $values;
    }

    private function fail(string $code, string $message, string $reference, string $tenantId, ?Throwable $cause = null): NumberSequenceException
    {
        Log::warning('Penerbitan nomor gagal.', [
            'code' => $code,
            'reference' => $reference,
            'tenant_id' => $tenantId,
        ]);

        // Statusnya tidak lagi dioper dari sini. Core berada di proses yang sama, jadi kegagalan
        // penerbitan nomor tidak pernah lagi berarti "layanan belum dapat dihubungi" — ia selalu
        // berarti permintaannya sendiri tidak bisa dipenuhi, misalnya reference yang belum
        // terdaftar untuk tenant ini. Satu jawaban, jadi satu tempat: NumberSequenceException.
        return new NumberSequenceException($code, $message, $cause);
    }
}
