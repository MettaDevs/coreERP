<?php

declare(strict_types=1);

namespace ControlPlane\Environments;

use ControlPlane\Models\Environment;
use ControlPlane\Models\EnvironmentOperation;
use ControlPlane\Models\Tenant;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Mendaftarkan satu lingkungan baru milik sebuah tenant.
 *
 * Sejauh irisan ini, "membuat lingkungan" berarti **mencatatnya di registry** — belum membuat
 * database, belum menjalankan migration, belum menyemai apa pun. Karena itu hasilnya berstatus
 * `provisioning` dan bukan `active`: hanya `active` yang boleh dirutekan, sehingga lingkungan yang
 * baru tercatat adalah lingkungan yang tidak bisa dimasuki siapa pun — bukan lingkungan yang bisa
 * dimasuki lalu ternyata kosong.
 *
 * Operasinya sendiri dicatat `succeeded`, dan itu bukan kelonggaran: yang dikerjakannya memang
 * selesai seluruhnya. Langkah membuat database adalah operasi tersendiri yang lahir di irisan
 * berikutnya, bukan bagian dari operasi ini yang menggantung.
 *
 * ## Produksi di server klien
 *
 * `hosting = client_server` hanya sah untuk produksi. Constraint `environments_server_klien_hanya_produksi`
 * menolak selainnya; pemanggil yang sampai ke sini dengan kombinasi itu ditolak lebih dulu dengan
 * kalimat, bukan dengan pelanggaran constraint. Lingkungannya tetap lahir `provisioning` dan tidak pernah
 * disiapkan di server kita: isinya dipasang agen di server klien, lewat panel di halaman rinciannya.
 */
final class CreateEnvironment
{
    public function __invoke(
        Tenant $tenant,
        string $kind,
        string $name,
        ?Carbon $expiresAt,
        int $requestedBy,
        string $hosting = 'provider',
    ): Environment {
        if ($hosting === 'client_server' && $kind !== 'production') {
            throw new EnvironmentRejected('Server klien hanya untuk lingkungan produksi. Demo dan sandbox selalu berjalan di server kita.');
        }

        return DB::transaction(function () use ($tenant, $kind, $name, $expiresAt, $requestedBy, $hosting): Environment {
            $environment = $this->insert($tenant, $kind, $name, $expiresAt, $requestedBy, $hosting);

            $now = Carbon::now();

            EnvironmentOperation::query()->create([
                'environment_id' => $environment->id,
                'operation' => 'provision',
                'status' => 'succeeded',
                'requested_by' => $requestedBy,
                'step' => 'tercatat-di-registry',
                'detail' => [
                    'kind' => $kind,
                    'hosting' => $hosting,
                    'database' => $environment->database(),
                ],
                'started_at' => $now,
                'finished_at' => $now,
            ]);

            return $environment;
        });
    }

    /**
     * Sisip dulu, tangkap bentrokannya — bukan periksa dulu lalu sisip.
     *
     * Memeriksa lebih dulu menyisakan jendela antara pemeriksaan dan penyisipan, dan dua operator
     * yang menekan tombolnya pada detik yang sama akan sama-sama lolos pemeriksaan. Yang benar-benar
     * memutuskan adalah partial unique index di PostgreSQL, jadi ia yang ditanya.
     *
     * Dua index dapat menolak di sini dan keduanya berarti hal yang sangat berbeda bagi operator,
     * sehingga pesannya dibedakan dari nama constraint-nya — bukan ditebak dari urutan percobaan.
     *
     * **Tiap percobaan dibungkus transaksi bersarang, dan itu bukan kerapian.** Di PostgreSQL,
     * sebuah pernyataan yang gagal membatalkan seluruh blok transaksi: pernyataan berikutnya
     * ditolak dengan `25P02 current transaction is aborted` sampai blok itu ditutup. Menangkap
     * exception-nya di PHP tidak memulihkan apa pun — percobaan kedua gagal karena percobaan
     * pertama, bukan karena slugnya. Transaksi bersarang Laravel diterjemahkan menjadi SAVEPOINT,
     * dan rollback ke savepoint itulah yang mengembalikan blok luarnya ke keadaan dapat dipakai.
     *
     * Ini ditemukan test, bukan saat review: versi pertama melewati seluruh test kecuali yang
     * memang membuat dua lingkungan bernama sama di satu tenant.
     */
    private function insert(
        Tenant $tenant,
        string $kind,
        string $name,
        ?Carbon $expiresAt,
        int $requestedBy,
        string $hosting,
    ): Environment {
        $base = Str::slug($name) !== '' ? Str::slug($name) : $kind;

        for ($attempt = 0; $attempt < 20; $attempt++) {
            $slug = $attempt === 0 ? $base : $base.'-'.($attempt + 1);

            try {
                return DB::transaction(fn (): Environment => Environment::query()->create([
                    'tenant_id' => $tenant->id,
                    'kind' => $kind,
                    'name' => $name,
                    'slug' => Str::limit($slug, 120, ''),
                    'status' => 'provisioning',
                    'expires_at' => $expiresAt,
                    'hosting' => $hosting,
                    // Ditegakkan juga oleh CHECK `environments_keluar_ikut_jenis`. Ditulis di sini
                    // supaya nilainya tidak pernah datang dari formulir — "beri sandbox ini email
                    // sehari saja" harus menjadi percakapan, bukan satu kotak centang.
                    'outbound_allowed' => $kind === 'production',
                    'created_by' => $requestedBy,
                ]));
            } catch (UniqueConstraintViolationException $conflict) {
                if (str_contains($conflict->getMessage(), 'environments_satu_produksi')) {
                    throw new EnvironmentRejected(
                        'Tenant '.$tenant->name.' sudah punya lingkungan produksi. Satu tenant hanya boleh punya satu.',
                        previous: $conflict,
                    );
                }

                if (str_contains($conflict->getMessage(), 'environments_satu_per_jenis')) {
                    throw new EnvironmentRejected(
                        'Tenant '.$tenant->name.' sudah punya lingkungan '.$kind.'. Satu tenant hanya boleh punya satu demo dan satu sandbox, karena alamatnya hanya memuat tenant dan jenis.',
                        previous: $conflict,
                    );
                }

                if (! str_contains($conflict->getMessage(), 'environments_slug_per_tenant')) {
                    throw $conflict;
                }
            }
        }

        throw new EnvironmentRejected(
            'Nama "'.$name.'" sudah dipakai berkali-kali di tenant ini. Beri nama yang lebih membedakan.',
        );
    }
}
