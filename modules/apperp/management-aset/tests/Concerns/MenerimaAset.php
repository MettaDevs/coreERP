<?php

declare(strict_types=1);

namespace Modules\Apperp\ManagementAset\Tests\Concerns;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Satu-satunya cara test menambah aset ke register: lewat dokumen penerimaan.
 *
 * `POST /aset` dipensiunkan pada 18 September 2026, dan trait ini yang menggantikan
 * perannya di test. Bentuk argumennya sengaja dibuat sama dengan payload layar register
 * yang lama — nama, klasifikasi, tanggal, nilai, unit, orang — supaya test yang sudah ada
 * hanya berganti nama pemanggil, bukan berganti isi.
 *
 * Yang **tidak** dipetakan dan itu disengaja: `serial_number`. Nomor seri tidak lagi
 * diminta saat penerimaan karena kardusnya belum dibuka; test yang memerlukannya
 * mengisinya lewat `isiNomorSeri()` sesudahnya, persis seperti penggunanya.
 */
trait MenerimaAset
{
    /**
     * Menerima satu aset dan memulangkan idnya.
     *
     * @param  array<string, mixed>  $payload  bentuk payload layar register yang lama
     */
    protected function terimaAset(string $tenantId, array $payload): string
    {
        $daftar = $this->terimaBanyakAset($tenantId, $payload, 1);

        return $daftar[0];
    }

    /**
     * Menerima beberapa aset sekaligus dari satu baris dokumen.
     *
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    protected function terimaBanyakAset(string $tenantId, array $payload, int $jumlah): array
    {
        $id = $this->drafPenerimaan($tenantId, $payload, $jumlah)->assertCreated()->json('data.id');
        $this->selesaikanPenerimaan($tenantId, (string) $id)->assertOk();

        return array_values(DB::table('aset_tr_aset')
            ->where('penerimaan_aset_id', $id)
            ->orderBy('kode')
            ->pluck('id')
            ->map(static fn ($nilai): string => (string) $nilai)
            ->all());
    }

    /**
     * Draf penerimaan yang belum diselesaikan, untuk test yang memeriksa penolakan.
     *
     * @param  array<string, mixed>  $payload
     * @return TestResponse<Response>
     */
    protected function drafPenerimaan(string $tenantId, array $payload, int $jumlah = 1): TestResponse
    {
        return $this->sebagaiPengguna($tenantId, [
            'management-aset.penerimaan-aset.read',
            'management-aset.penerimaan-aset.create',
        ])
            ->withHeader('Idempotency-Key', 'pnr-'.Str::ulid())
            ->postJson('/api/modules/management-aset/v1/penerimaan-aset', [
                'legal_entity_id' => $payload['legal_entity_id'],
                'responsible_org_unit_id' => $payload['usage_org_unit_id'],
                'receiving_org_unit_id' => $payload['receiving_org_unit_id'] ?? null,
                'diterima_oleh_user_id' => $payload['received_by_user_id'] ?? null,
                'penanggung_jawab_user_id' => $payload['custodian_user_id'] ?? null,
                'tanggal' => $payload['acquired_on'],
                'tanggal_siap_pakai' => $payload['placed_in_service_on'] ?? null,
                'lokasi_aset_id' => $payload['lokasi_aset_id'] ?? null,
                'currency_code' => $payload['currency_code'] ?? 'IDR',
                'keterangan' => $payload['keterangan'] ?? null,
                'details' => [[
                    'nama' => $payload['nama'],
                    'group_aset_id' => $payload['group_aset_id'],
                    'jenis_aset_id' => $payload['jenis_aset_id'],
                    'kondisi_aset_id' => $payload['kondisi_aset_id'] ?? null,
                    'pabrikan_aset_id' => $payload['pabrikan_aset_id'] ?? null,
                    'model_aset_id' => $payload['model_aset_id'] ?? null,
                    'model_number' => $payload['model_number'] ?? null,
                    'jumlah' => $jumlah,
                    'nilai_per_unit' => $payload['acquisition_value'],
                    'residu_per_unit' => $payload['residual_value'] ?? 0,
                    'atribut' => $payload['atribut'] ?? [],
                ]],
            ]);
    }

    /** @return TestResponse<Response> */
    protected function selesaikanPenerimaan(string $tenantId, string $penerimaanId, int $version = 1): TestResponse
    {
        return $this->sebagaiPengguna($tenantId, [
            'management-aset.penerimaan-aset.read',
            'management-aset.aset.create',
        ])->postJson(
            '/api/modules/management-aset/v1/penerimaan-aset/'.$penerimaanId.'/selesaikan',
            ['version' => $version],
        );
    }
}
