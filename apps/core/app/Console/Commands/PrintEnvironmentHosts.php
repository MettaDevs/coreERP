<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Environment;
use App\Support\ControlPlane\EnvironmentAddress;
use Illuminate\Console\Command;

/**
 * Mencetak blok `hosts` untuk seluruh alamat lingkungan yang dikenal registry.
 *
 * ## Kenapa perintah ini ada, dan kenapa ia HANYA untuk mesin pengembang
 *
 * Di server tidak ada berkas `hosts`. Satu record `*.erp.contoh.co.id` menjawab setiap tenant dan
 * setiap lingkungan, selamanya, tanpa satu pun pekerjaan per pelanggan — itu justru alasan utama
 * memilih wildcard alih-alih sertifikat per tenant.
 *
 * Laptop tidak punya DNS wildcard. `hosts` tidak mengenal pola; tiap nama harus disebut satu per
 * satu, dan daftarnya bertambah tiap kali sebuah lingkungan lahir. Menyalinnya tangan berarti
 * lingkungan baru yang "tidak bisa dibuka" karena satu baris yang terlupa — kegagalan yang terbaca
 * seperti cacat routing padahal bukan.
 *
 * ## Ia mencetak, bukan menulis
 *
 * `C:\Windows\System32\drivers\etc\hosts` menuntut hak Administrator, dan sebuah perintah artisan
 * yang meminta elevasi adalah perintah yang dijalankan orang tanpa membaca apa yang akan ditulisnya.
 * Yang menempelkan hasilnya `deploy/traefik/update-hosts.ps1`, dan skrip itu meminta elevasi secara
 * terbuka.
 *
 * ## Semua yang belum diarsipkan ikut, bukan hanya yang aktif
 *
 * Lingkungan yang sedang disiapkan atau bermasalah memang tidak dapat dilayani — `ResolveEnvironment`
 * menjawabnya 503 atau 404. Tetapi membuktikan jawaban itu benar menuntut alamatnya dapat dibuka,
 * dan alamat yang tidak ada di `hosts` gagal jauh lebih awal, dengan pesan dari peramban alih-alih
 * dari aplikasi.
 */
final class PrintEnvironmentHosts extends Command
{
    protected $signature = 'environment:hosts
        {--ip=127.0.0.1 : Alamat yang dituju tiap nama}
        {--bare : Tanpa penanda #ERP, untuk disalurkan ke perkakas lain}';

    protected $description = 'Mencetak blok berkas hosts untuk seluruh alamat lingkungan';

    /** Penanda yang dicari `update-hosts.ps1` untuk menemukan blok yang harus diganti. */
    public const BEGIN = '#ERP';

    public const END = '#END ERP';

    public function handle(): int
    {
        $domain = EnvironmentAddress::baseDomain();

        if ($domain === '') {
            $this->error(
                'COREERP_BASE_DOMAIN belum disetel, jadi tidak ada satu pun alamat lingkungan yang '
                .'dapat dihitung. Selama ia kosong, seluruh aplikasi memang dilayani dari satu '
                .'alamat dan berkas hosts tidak dibutuhkan sama sekali.'
            );

            return self::FAILURE;
        }

        $ip = (string) $this->option('ip');
        $lines = [];

        // Alamat pangkal, lalu label yang memang bukan lingkungan — konsol operator dan alamat
        // pemasaran. Keduanya berbentuk persis seperti alamat produksi, dan tanpa barisnya konsol
        // operator tidak dapat dibuka di mesin ini sama sekali.
        $lines[] = $ip.' '.$domain;

        foreach (EnvironmentAddress::reservedLabels() as $label) {
            $lines[] = $ip.' '.$label.'.'.$domain;
        }

        $environments = Environment::query()
            ->with('tenant:id,slug')
            ->whereNull('deleted_at')
            ->orderBy('tenant_id')
            ->orderBy('kind')
            ->get();

        foreach ($environments as $environment) {
            $tenant = $environment->tenant->slug ?? null;

            if (! is_string($tenant) || $tenant === '') {
                continue;
            }

            $host = EnvironmentAddress::forEnvironment($tenant, $environment->kind);

            if ($host === null) {
                continue;
            }

            $lines[] = $ip.' '.$host;
        }

        // Dua lingkungan produksi milik tenant yang sama menghasilkan nama yang sama, dan `hosts`
        // yang memuat baris kembar tidak salah — hanya membingungkan orang yang membacanya.
        $lines = array_values(array_unique($lines));

        if ($this->option('bare') === true) {
            $this->line(implode(PHP_EOL, $lines));

            return self::SUCCESS;
        }

        $this->line(self::BEGIN);
        $this->line(implode(PHP_EOL, $lines));
        $this->line(self::END);

        return self::SUCCESS;
    }
}
