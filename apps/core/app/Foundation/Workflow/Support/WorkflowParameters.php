<?php

declare(strict_types=1);

namespace App\Foundation\Workflow\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use UnexpectedValueException;

/**
 * Membaca parameter workflow milik sebuah tenant.
 *
 * Pembacanya generik dan tidak mengenal satu pun parameter secara nama; yang mengenal namanya
 * adalah `WorkflowParameterDefinitions`. Bentuk itu yang membuat penambahan parameter berhenti
 * menyentuh kelas ini.
 *
 * **Seluruh parameter sebuah tenant dibaca sekali per permintaan.** Satu workflow bercabang
 * menanyakan parameter yang sama berkali-kali — sekali per elemen persetujuan, sekali lagi saat
 * keputusan diambil — dan tanpa ingatan ini setiap pertanyaan membayar satu query untuk jawaban
 * yang tidak mungkin berubah di tengah permintaan. Membaca semuanya sekaligus, bukan satu per
 * satu, membuat jumlah query tetap satu berapa pun banyaknya parameter yang ditanyakan.
 */
final class WorkflowParameters
{
    /** @var array<string, array<string, bool>> */
    private array $cache = [];

    /**
     * Nilai sebuah parameter boolean.
     *
     * Kode yang tidak terdaftar melempar, bukan memulangkan `false`. Salah ketik pada kode
     * parameter akan selalu terbaca sebagai "tidak dilarang", dan sebuah penjaga yang mati
     * karena salah ketik adalah kegagalan yang tidak pernah terlihat.
     */
    public function boolean(string $tenantId, string $code): bool
    {
        if (! WorkflowParameterDefinitions::known($code)) {
            throw new InvalidArgumentException(sprintf('Parameter workflow "%s" tidak terdaftar.', $code));
        }

        return $this->all($tenantId)[$code];
    }

    /**
     * Seluruh parameter tenant, bawaan yang belum pernah diubah sudah ikut terisi.
     *
     * Dipakai layar settings supaya ia bisa merender dirinya dari daftar definisi, bukan dari
     * field yang ditulis tangan satu per satu.
     *
     * @return array<string, bool>
     */
    public function all(string $tenantId): array
    {
        if (isset($this->cache[$tenantId])) {
            return $this->cache[$tenantId];
        }

        $stored = [];

        foreach (DB::table('workflow_parameters')->where('tenant_id', $tenantId)->get(['code', 'value']) as $row) {
            // Kode yang tidak lagi terdaftar dilewati. Baris yatim boleh tertinggal di database
            // — lihat alasannya pada `WorkflowParameterDefinitions` — tetapi ia tidak boleh ikut
            // menjawab pertanyaan siapa pun.
            if (! WorkflowParameterDefinitions::known((string) $row->code)) {
                continue;
            }

            $stored[(string) $row->code] = $this->matchesType((string) $row->code, (string) $row->value);
        }

        return $this->cache[$tenantId] = $stored + WorkflowParameterDefinitions::default();
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
    private function matchesType(string $code, string $raw): bool
    {
        $value = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        if (! is_bool($value)) {
            throw new UnexpectedValueException(sprintf(
                'Parameter workflow "%s" dijanjikan boolean oleh registry, tetapi yang tersimpan %s.',
                $code,
                get_debug_type($value),
            ));
        }

        return $value;
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
    public function save(string $tenantId, string $code, bool $value, string $actorMembershipId): void
    {
        if (! WorkflowParameterDefinitions::known($code)) {
            throw new InvalidArgumentException(sprintf('Parameter workflow "%s" tidak terdaftar.', $code));
        }

        $previous = $this->all($tenantId)[$code];

        DB::table('workflow_parameters')->upsert(
            [[
                'id' => (string) Str::ulid(),
                'tenant_id' => $tenantId,
                'code' => $code,
                'value' => json_encode($value, JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ]],
            ['tenant_id', 'code'],
            // `id` dan `created_at` sengaja tidak ikut diperbarui: baris yang sudah ada
            // mempertahankan identitas dan tanggal lahirnya.
            ['value', 'updated_at'],
        );

        unset($this->cache[$tenantId]);

        // Hanya perubahan yang dicatat. Sebuah sakelar yang ditekan ke posisi yang sudah
        // ditempatinya bukan peristiwa, dan jejak audit yang penuh baris tanpa peristiwa adalah
        // jejak yang berhenti dibaca orang.
        if ($previous === $value) {
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
                'actor_membership_id' => $actorMembershipId,
                'code' => $code,
                'from' => $previous,
                'to' => $value,
            ], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
