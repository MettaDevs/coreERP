<?php

declare(strict_types=1);

namespace ControlPlane\Sites;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * App yang aktif untuk satu tenant, dibaca dari API internal Core.
 *
 * ## Kenapa lewat Core, padahal `tenant_app_entitlements` ada di database yang sama
 *
 * Yang dibaca bukan baris tabel melainkan keputusan "app mana yang aktif" — status, tanggal mulai dan
 * berakhir, dan apa pun yang kelak ditambahkan Core pada aturan itu. Konsol yang menyalin aturannya
 * akan menerbitkan lisensi dari tafsiran sendiri, dan lisensi itulah yang mengunci server klien.
 *
 * ## Tidak ada daftar sebagian
 *
 * Satu anggota yang bentuknya salah menggagalkan seluruh daftar. Isian yang dibuang diam-diam berarti
 * lisensi tanpa app yang dibayar klien, dan kunci di server klien menutup app itu sampai lisensi
 * berikutnya — kegagalan yang jauh lebih mahal daripada perpanjangan yang ditunda.
 */
final class EntitlementsFromCore
{
    /**
     * Batas atas menunggu Core, dalam detik.
     *
     * Pemanggil terseringnya jawaban laporan agen: agen memutus sambungannya sesudah 60 detik, dan
     * laporannya sendiri sudah tercatat sebelum panggilan ini dimulai. Membaca daftar app adalah satu
     * query di sisi Core; Core yang tidak menjawab dalam sepuluh detik tidak akan menjawab dalam tiga
     * puluh, dan menunggunya hanya menahan satu pekerja PHP untuk lisensi yang toh dicoba lagi nanti.
     */
    private const MAX_TIMEOUT_SECONDS = 10;

    /**
     * @return list<string> id app, unik dan terurut — bentuk yang sama dengan `apps` di lisensi.
     *
     * @throws EntitlementsUnavailable
     */
    public function appsFor(string $tenantId): array
    {
        $endpoint = rtrim((string) config('core.base_url'), '/')
            .'/api/internal/v1/tenants/'.rawurlencode($tenantId).'/entitlements';

        try {
            $response = Http::withToken((string) config('core.token'))
                ->acceptJson()
                ->timeout(max(1, min((int) config('core.timeout'), self::MAX_TIMEOUT_SECONDS)))
                ->get($endpoint);
        } catch (ConnectionException $disconnected) {
            throw new EntitlementsUnavailable(
                'Core tidak menjawab di '.$endpoint.'. Periksa COREERP_URL di konsol ini. Pesan aslinya: '
                .$disconnected->getMessage(),
                previous: $disconnected,
            );
        }

        // Tepat 200, bukan "berhasil". 204 atau 202 dari sesuatu yang bukan Core tidak membawa daftar,
        // dan menafsirkannya sebagai daftar kosong menerbitkan lisensi "hanya Core".
        if ($response->status() !== 200) {
            throw new EntitlementsUnavailable(match ($response->status()) {
                401, 403 => 'Core menolak kunci konsol ini (HTTP '.$response->status().' dari '.$endpoint.'). '
                    .'Nilai CONTROL_PLANE_TOKEN di sini harus sama persis dengan yang diperiksa Core.',
                default => 'Core menjawab HTTP '.$response->status().' dari '.$endpoint.'.',
            });
        }

        $payload = $response->json();

        // `tenant_id` dicocokkan, bukan hanya diperiksa ada. Jawaban untuk tenant lain — proxy yang
        // menyimpan tembolok, atau rute yang salah membaca parameter — akan menerbitkan lisensi berisi
        // app milik orang lain.
        if (! is_array($payload)
            || ($payload['tenant_id'] ?? null) !== $tenantId
            || ! is_array($payload['apps'] ?? null)
            || ! array_is_list($payload['apps'])) {
            throw new EntitlementsUnavailable('Jawaban '.$endpoint.' tidak sesuai kontrak {"tenant_id", "apps"}.');
        }

        $apps = [];

        foreach ($payload['apps'] as $app) {
            // Pola yang sama dengan yang diperiksa agen (`SignedLicense` di `contracts/openapi-agent.yaml`).
            // Lisensi dengan id di luar pola ditolak agen tanpa suara — yang terlihat di sini hanya
            // tanggal berakhir yang tidak bergerak — jadi penolakannya dipindah ke tempat yang mencatat
            // sebabnya.
            if (! is_string($app) || preg_match('/^[a-z0-9][a-z0-9-]*$/', $app) !== 1) {
                throw new EntitlementsUnavailable('Jawaban '.$endpoint.' memuat id app di luar pola ^[a-z0-9][a-z0-9-]*$.');
            }

            $apps[$app] = true;
        }

        $apps = array_map(strval(...), array_keys($apps));
        sort($apps, SORT_STRING);

        return $apps;
    }
}
