<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Satu tenant, satu demo hidup dan satu sandbox hidup.
 *
 * Alamat lingkungan kini `<tenant>.<jenis>.<domain>` — slug lingkungan tidak lagi ada di dalamnya
 * (lihat `EnvironmentAddress`). Dua demo hidup milik tenant yang sama akan menunjuk alamat yang
 * sama, dan yang terpilih ditentukan urutan baris. Produksi sudah dijaga `environments_satu_produksi`
 * sejak awal; indeks ini pasangannya untuk dua jenis yang lain.
 *
 * Yang dihapus lunak tidak dihitung, sama seperti indeks produksi: demo yang sedang dalam masa
 * tenggang tidak menghalangi demo baru. Memulihkan yang lama sesudah itu yang ditolak.
 *
 * ## Hanya di database pusat
 *
 * Registry yang berwenang hanya satu, di database pusat. Tabel `environments` juga ada di setiap
 * database lingkungan karena migration-nya dibagi, tetapi isinya tidak dibaca siapa pun — dan di
 * database salinan sandbox isinya justru sengaja dirusak: `CopyEnvironment::disarmCopiedRegistry`
 * menurunkan **setiap** baris menjadi `sandbox` supaya tidak ada yang terbaca sebagai produksi.
 * Indeks ini akan menolak penurunan itu, atau gagal dibuat di salinan yang sudah diturunkan.
 *
 * Koneksi database lingkungan selalu bernama `environment_*` (`ProvisionEnvironment`,
 * `UpgradeEnvironments`, `CopyEnvironment`), dan Laravel menjadikan koneksi migrate itu koneksi
 * bawaan selama `up()` berjalan. Nama itu yang dibaca di sini. Database lingkungan yang pernah
 * dimigrasi lewat koneksi bernama lain tetap dapat membawanya ke sebuah salinan; untuk itu
 * `disarmCopiedRegistry` membuangnya lebih dulu.
 */
return new class extends Migration
{
    public function up(): void
    {
        if ($this->onEnvironmentDatabase()) {
            return;
        }

        $this->refuseExistingDuplicates();

        DB::statement("CREATE UNIQUE INDEX environments_satu_per_jenis ON environments (tenant_id, kind) WHERE kind IN ('demo', 'sandbox') AND deleted_at IS NULL");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS environments_satu_per_jenis');
    }

    /**
     * Berhenti dengan daftar yang terbaca bila registry sudah memuat lebih dari satu per jenis.
     *
     * Terukur di database pengembangan pada hari indeks ini ditulis: satu tenant dengan lima demo
     * hidup. Tanpa pemeriksaan ini migration-nya jatuh dengan `23505` yang hanya menyebut satu
     * pasangan kunci. Barisnya sengaja tidak dihapus di sini — memilih demo mana yang dibuang
     * adalah keputusan orang, bukan migration.
     */
    private function refuseExistingDuplicates(): void
    {
        $duplicates = DB::table('environments')
            ->join('tenants', 'tenants.id', '=', 'environments.tenant_id')
            ->whereIn('environments.kind', ['demo', 'sandbox'])
            ->whereNull('environments.deleted_at')
            ->groupBy('tenants.slug', 'environments.kind')
            ->havingRaw('count(*) > 1')
            ->selectRaw("tenants.slug as tenant, environments.kind, string_agg(environments.slug || ' (' || environments.id || ')', ', ' ORDER BY environments.created_at) as lingkungan")
            ->get();

        if ($duplicates->isEmpty()) {
            return;
        }

        $lines = $duplicates->map(fn (object $row): string => sprintf('  - %s, %s: %s', $row->tenant, $row->kind, $row->lingkungan));

        throw new RuntimeException(
            "Satu tenant kini hanya boleh punya satu demo hidup dan satu sandbox hidup, karena alamatnya\n"
            ."hanya memuat tenant dan jenis. Registry ini masih memuat lebih dari satu:\n"
            .$lines->implode("\n")."\n"
            ."Hapus lunak yang tidak dipakai lagi, sisakan satu per jenis, lalu jalankan migrate lagi.\n"
            ."Untuk demo: mundurkan masa berlakunya ke kemarin, lalu sapu —\n"
            ."  UPDATE environments SET expires_at = now() - interval '1 day' WHERE id IN (...);\n"
            .'  php artisan environment:sweep-expired'
        );
    }

    private function onEnvironmentDatabase(): bool
    {
        return str_starts_with(DB::connection()->getName() ?? '', 'environment_');
    }
};
