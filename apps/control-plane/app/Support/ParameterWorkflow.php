<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use UnexpectedValueException;

/**
 * Membaca parameter workflow milik sebuah tenant.
 *
 * Pembacanya generik dan tidak mengenal satu pun parameter secara nama; yang mengenal namanya
 * adalah `DefinisiParameterWorkflow`. Bentuk itu yang membuat penambahan parameter berhenti
 * menyentuh kelas ini.
 *
 * **Seluruh parameter sebuah tenant dibaca sekali per permintaan.** Satu workflow bercabang
 * menanyakan parameter yang sama berkali-kali — sekali per elemen persetujuan, sekali lagi saat
 * keputusan diambil — dan tanpa ingatan ini setiap pertanyaan membayar satu query untuk jawaban
 * yang tidak mungkin berubah di tengah permintaan. Membaca semuanya sekaligus, bukan satu per
 * satu, membuat jumlah query tetap satu berapa pun banyaknya parameter yang ditanyakan.
 */
final class ParameterWorkflow
{
    /** @var array<string, array<string, bool>> */
    private array $ingatan = [];

    /**
     * Nilai sebuah parameter boolean.
     *
     * Kode yang tidak terdaftar melempar, bukan memulangkan `false`. Salah ketik pada kode
     * parameter akan selalu terbaca sebagai "tidak dilarang", dan sebuah penjaga yang mati
     * karena salah ketik adalah kegagalan yang tidak pernah terlihat.
     */
    public function boolean(string $tenantId, string $kode): bool
    {
        if (! DefinisiParameterWorkflow::dikenal($kode)) {
            throw new InvalidArgumentException(sprintf('Parameter workflow "%s" tidak terdaftar.', $kode));
        }

        return $this->semua($tenantId)[$kode];
    }

    /**
     * Seluruh parameter tenant, bawaan yang belum pernah diubah sudah ikut terisi.
     *
     * Dipakai layar settings supaya ia bisa merender dirinya dari daftar definisi, bukan dari
     * field yang ditulis tangan satu per satu.
     *
     * @return array<string, bool>
     */
    public function semua(string $tenantId): array
    {
        if (isset($this->ingatan[$tenantId])) {
            return $this->ingatan[$tenantId];
        }

        $tersimpan = [];

        foreach (DB::table('workflow_parameters')->where('tenant_id', $tenantId)->get(['code', 'value']) as $baris) {
            // Kode yang tidak lagi terdaftar dilewati. Baris yatim boleh tertinggal di database
            // — lihat alasannya pada `DefinisiParameterWorkflow` — tetapi ia tidak boleh ikut
            // menjawab pertanyaan siapa pun.
            if (! DefinisiParameterWorkflow::dikenal((string) $baris->code)) {
                continue;
            }

            $tersimpan[(string) $baris->code] = $this->sesuaiTipe((string) $baris->code, (string) $baris->value);
        }

        return $this->ingatan[$tenantId] = $tersimpan + DefinisiParameterWorkflow::bawaan();
    }

    /**
     * Nilai tersimpan harus benar-benar bertipe seperti yang dijanjikan registry.
     *
     * Tanpa pemeriksaan ini, kalimat "tipenya hidup di registry" cuma komentar. Kolomnya `jsonb`
     * dan database tidak menolak apa pun: `"mungkin"` masuk dengan senang hati, `json_decode`
     * memulangkan string, dan `(bool)` mengubahnya menjadi `true`. Sebuah larangan pemisahan
     * tugas yang menyala karena satu baris rusak — atau mati karena `null` — adalah kegagalan
     * yang tidak pernah terlihat siapa pun.
     *
     * Inilah yang harus dibayar bentuk baris-per-kode: penegakan tipe pindah dari database ke
     * sini. Menaruhnya di satu tempat yang dilewati setiap pembacaan adalah harga yang wajar;
     * membiarkannya tidak ditegakkan sama sekali tidak.
     *
     * Tipe kembalian `bool` method ini sebenarnya sudah menolaknya sendiri. Yang ditambahkan
     * pemeriksaan eksplisit ini **pesannya**, bukan penangkapannya: PHP mengatakan "Return value
     * must be of type bool, string returned" sambil menunjuk sebuah method privat, sedangkan yang
     * dibutuhkan orang yang membaca log adalah parameter mana yang rusak dan apa yang dijanjikan
     * registry untuknya.
     */
    private function sesuaiTipe(string $kode, string $mentah): bool
    {
        $nilai = json_decode($mentah, true, 512, JSON_THROW_ON_ERROR);

        if (! is_bool($nilai)) {
            throw new UnexpectedValueException(sprintf(
                'Parameter workflow "%s" dijanjikan boolean oleh registry, tetapi yang tersimpan %s.',
                $kode,
                get_debug_type($nilai),
            ));
        }

        return $nilai;
    }

    /**
     * Menyimpan satu parameter, mencatat perubahannya, lalu melupakan jawaban yang diingat.
     *
     * **Aktornya parameter wajib, bukan opsional.** Parameter ini adalah kontrol pemisahan tugas,
     * dan sebuah kontrol kepatuhan yang sakelarnya sendiri bisa diubah tanpa jejak meniadakan
     * dirinya sendiri: pemeriksa yang menemukan dokumen disetujui pengajunya sendiri tidak punya
     * cara mengetahui apakah larangannya memang mati saat itu, atau baru dimatikan sesudahnya.
     * D365 memperlakukan override pemisahan tugas dengan cara yang sama — dicatat permanen
     * sebagai bagian jejak audit.
     *
     * Sebagai parameter opsional, pemanggil yang lupa mengisinya tidak pernah diberi tahu dan
     * jejaknya hilang tanpa suara; sebagai parameter wajib, ia tidak bisa lupa.
     *
     * Penyimpanan, pencatatan, dan pelupaan disatukan di sini dengan sengaja. Ketika terpisah,
     * pemanggil yang lupa melupakan akan membaca nilai lama pada permintaan yang sama — dan itu
     * muncul sebagai layar yang menampilkan setelan lama sesaat setelah pengguna mengubahnya.
     */
    public function simpan(string $tenantId, string $kode, bool $nilai, string $idKeanggotaanAktor): void
    {
        if (! DefinisiParameterWorkflow::dikenal($kode)) {
            throw new InvalidArgumentException(sprintf('Parameter workflow "%s" tidak terdaftar.', $kode));
        }

        $sebelumnya = $this->semua($tenantId)[$kode];

        DB::table('workflow_parameters')->upsert(
            [[
                'id' => (string) Str::ulid(),
                'tenant_id' => $tenantId,
                'code' => $kode,
                'value' => json_encode($nilai, JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ]],
            ['tenant_id', 'code'],
            // `id` dan `created_at` sengaja tidak ikut diperbarui: baris yang sudah ada
            // mempertahankan identitas dan tanggal lahirnya.
            ['value', 'updated_at'],
        );

        unset($this->ingatan[$tenantId]);

        // Hanya perubahan yang dicatat. Sebuah sakelar yang ditekan ke posisi yang sudah
        // ditempatinya bukan peristiwa, dan jejak audit yang penuh baris tanpa peristiwa adalah
        // jejak yang berhenti dibaca orang.
        if ($sebelumnya === $nilai) {
            return;
        }

        DB::table('access_audit_events')->insert([
            'id' => (string) Str::ulid(),
            'tenant_id' => $tenantId,
            // Kolom ini menyebut keanggotaan yang **dikenai** tindakan; parameter berlaku untuk
            // seluruh tenant, jadi tidak ada satu pun yang dikenai. Aktornya ada di payload,
            // sama seperti baris audit akses lain.
            'membership_id' => null,
            'action' => 'workflow.parameter.updated',
            'payload' => json_encode([
                'actor_membership_id' => $idKeanggotaanAktor,
                'code' => $kode,
                'from' => $sebelumnya,
                'to' => $nilai,
            ], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
