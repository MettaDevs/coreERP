<?php

declare(strict_types=1);

namespace ControlPlane\Lingkungan;

use ControlPlane\Models\Lingkungan;
use ControlPlane\Models\OperasiLingkungan;
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
 */
final class BuatLingkungan
{
    public function __invoke(
        Tenant $tenant,
        string $jenis,
        string $nama,
        ?Carbon $berakhir,
        int $diminta,
    ): Lingkungan {
        return DB::transaction(function () use ($tenant, $jenis, $nama, $berakhir, $diminta): Lingkungan {
            $lingkungan = $this->simpan($tenant, $jenis, $nama, $berakhir, $diminta);

            $sekarang = Carbon::now();

            OperasiLingkungan::query()->create([
                'environment_id' => $lingkungan->id,
                'operation' => 'provision',
                'status' => 'succeeded',
                'requested_by' => $diminta,
                'step' => 'tercatat-di-registry',
                'detail' => [
                    'jenis' => $jenis,
                    'database' => $lingkungan->database(),
                ],
                'started_at' => $sekarang,
                'finished_at' => $sekarang,
            ]);

            return $lingkungan;
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
    private function simpan(
        Tenant $tenant,
        string $jenis,
        string $nama,
        ?Carbon $berakhir,
        int $diminta,
    ): Lingkungan {
        $dasar = Str::slug($nama) !== '' ? Str::slug($nama) : $jenis;

        for ($percobaan = 0; $percobaan < 20; $percobaan++) {
            $slug = $percobaan === 0 ? $dasar : $dasar.'-'.($percobaan + 1);

            try {
                return DB::transaction(fn (): Lingkungan => Lingkungan::query()->create([
                    'tenant_id' => $tenant->id,
                    'kind' => $jenis,
                    'name' => $nama,
                    'slug' => Str::limit($slug, 120, ''),
                    'status' => 'provisioning',
                    'expires_at' => $berakhir,
                    // Ditegakkan juga oleh CHECK `environments_keluar_ikut_jenis`. Ditulis di sini
                    // supaya nilainya tidak pernah datang dari formulir — "beri sandbox ini email
                    // sehari saja" harus menjadi percakapan, bukan satu kotak centang.
                    'outbound_allowed' => $jenis === 'production',
                    'created_by' => $diminta,
                ]));
            } catch (UniqueConstraintViolationException $bentrok) {
                if (str_contains($bentrok->getMessage(), 'environments_satu_produksi')) {
                    throw new LingkunganDitolak(
                        'Tenant '.$tenant->name.' sudah punya lingkungan produksi. Satu tenant hanya boleh punya satu.',
                        previous: $bentrok,
                    );
                }

                if (! str_contains($bentrok->getMessage(), 'environments_slug_per_tenant')) {
                    throw $bentrok;
                }
            }
        }

        throw new LingkunganDitolak(
            'Nama "'.$nama.'" sudah dipakai berkali-kali di tenant ini. Beri nama yang lebih membedakan.',
        );
    }
}
