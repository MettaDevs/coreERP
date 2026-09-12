<?php

declare(strict_types=1);

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\Environment;
use App\Models\EnvironmentOperation;
use App\Models\User;
use App\Support\Pusat\KoneksiLingkungan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Menjalankan penyiapan sebuah lingkungan atas perintah konsol operator.
 *
 * ## Kenapa tombolnya baru ada sekarang
 *
 * Layar rincian lingkungan dulu hanya **menampilkan** perintah `environment:siapkan` untuk disalin
 * ke terminal, dan alasannya ditulis apa adanya di sana: arah konsol → Core belum punya jalur
 * autentikasi. Alasan itu sudah tidak berlaku. `HanyaPusatAdmin` lahir bersama pembuatan pelanggan,
 * sudah dipakai sungguhan, dan token yang sama persis yang menjaga pintu ini.
 *
 * ## Kenapa sinkron, padahal ia menjalankan migration
 *
 * Karena antrean Core bukan tempat yang lebih aman untuk pekerjaan ini, hanya tempat yang lebih
 * jauh dari mata operator. Pekerja antrean yang mati di tengah meninggalkan lingkungan `degraded`
 * yang tidak dilihat siapa pun sampai ada yang membuka layarnya; permintaan HTTP yang mati di
 * tengah meninggalkan keadaan yang sama, tetapi orangnya sedang menatap layar ketika itu terjadi.
 *
 * Yang membuat pilihan ini sah bukan optimisme melainkan dua sifat yang sudah ada:
 *
 * 1. **Kunci operasi punya masa berlaku.** Permintaan kedua yang datang selagi yang pertama masih
 *    berjalan ditolak oleh kuncinya, bukan dilayani sebagai penyiapan kedua di atas yang pertama.
 * 2. **Perintahnya aman diulang.** Sambungan yang putus sesudah permintaan terkirim tidak
 *    membatalkan apa pun di sisi sini, dan menekan tombolnya lagi meneruskan dari tempat ia
 *    berhenti — bukan mengulang dari nol.
 *
 * Kalau kelak satu penyiapan benar-benar melampaui batas waktu yang wajar bagi peramban, yang
 * berubah adalah tempat perintah ini dijalankan, bukan bentuk jawabannya.
 */
final class PenyiapanLingkunganController extends Controller
{
    public function store(Request $permintaan, string $lingkungan, KoneksiLingkungan $koneksi): JsonResponse
    {
        $baris = Environment::query()->whereKey($lingkungan)->whereNull('deleted_at')->first();

        if (! $baris instanceof Environment) {
            return response()->json(['message' => 'Lingkungan itu tidak ada di registry.'], 404);
        }

        if (! in_array($baris->status, ['provisioning', 'degraded'], true)) {
            // 409, bukan 422. Permintaannya tidak salah bentuk — ia datang ke keadaan yang salah,
            // dan itu keadaan yang bisa berubah sendiri sebelum orangnya menekan tombol.
            return response()->json([
                'message' => sprintf(
                    'Lingkungan "%s" berstatus %s. Yang boleh disiapkan hanya yang berstatus '
                    .'provisioning atau degraded — menyiapkan ulang lingkungan yang sudah hidup '
                    .'akan menimpa isinya.',
                    $baris->name,
                    $baris->status,
                ),
            ], 409);
        }

        $keluar = Artisan::call('environment:siapkan', array_filter([
            'environment' => $baris->id,
            '--diminta-oleh' => $this->pemintanya($permintaan),
        ]));
        $catatan = trim(Artisan::output());

        $baris->refresh();

        if ($keluar !== 0) {
            return response()->json([
                'message' => $this->alasanGagal($baris) ?? 'Penyiapan gagal tanpa menyebut alasan.',
                'status' => $baris->status,
                'catatan' => $catatan,
            ], 422);
        }

        return response()->json([
            'status' => $baris->status,
            'database' => $baris->database_name,
            'modul' => $this->modul($baris, $koneksi),
            'catatan' => $catatan,
        ]);
    }

    /**
     * Id operator yang menekan tombolnya, bila ia benar-benar ada sebagai user di sini.
     *
     * Diperiksa keberadaannya lebih dulu, dan itu bukan kehati-hatian berlebih: kolomnya punya
     * foreign key ke `users`, jadi id yang tidak ada akan menjatuhkan **penyiapannya** — menukar
     * pekerjaan yang berhasil dengan kegagalan demi sebuah nama di kolom riwayat.
     *
     * Kosong berarti riwayatnya berbunyi "Sistem", persis seperti perintah yang diketik di terminal.
     */
    private function pemintanya(Request $permintaan): ?int
    {
        $id = $permintaan->input('diminta_oleh');

        if (! is_int($id) && ! (is_string($id) && $id !== '' && ctype_digit($id))) {
            return null;
        }

        $id = (int) $id;

        return User::query()->whereKey($id)->exists() ? $id : null;
    }

    /**
     * Alasan yang tercatat pada operasi terakhir, bukan keluaran perintahnya.
     *
     * Keluaran perintah ikut dikirim sebagai `catatan`, tetapi ia tidak boleh menjadi pesan
     * utamanya: yang tercatat di `environment_operations` itulah yang masih dapat dibaca besok
     * ketika tidak ada lagi yang ingat layar mana yang terbuka hari ini.
     */
    private function alasanGagal(Environment $lingkungan): ?string
    {
        $operasi = EnvironmentOperation::query()
            ->where('environment_id', $lingkungan->id)
            ->orderByDesc('started_at')
            ->first();

        if (! $operasi instanceof EnvironmentOperation) {
            return null;
        }

        $alasan = $operasi->failure_message;

        return is_string($alasan) && $alasan !== '' ? $alasan : null;
    }

    /**
     * Module yang benar-benar terpasang **di database lingkungan itu**.
     *
     * Dibaca dari sana, bukan disimpulkan dari entitlement tenantnya. Entitlement menjawab apa yang
     * boleh ada; hanya tabel di dalam databasenya yang menjawab apa yang benar-benar ada, dan
     * perbedaan keduanya persis yang ingin dilihat operator sesudah penyiapan berjalan.
     *
     * Kegagalan membacanya tidak menggagalkan jawaban. Penyiapannya sudah berhasil pada titik ini;
     * menukar keberhasilan itu dengan 500 karena satu daftar tambahan tidak terbaca berarti operator
     * mengira penyiapannya gagal, lalu menjalankannya lagi.
     *
     * @return list<array{id: string, versi: string, status: string, disemai: bool}>
     */
    private function modul(Environment $lingkungan, KoneksiLingkungan $koneksi): array
    {
        try {
            $nama = $koneksi->untuk($lingkungan);

            $baris = DB::connection($nama)
                ->table('core_module_installations')
                ->where('tenant_id', $lingkungan->tenant_id)
                ->orderBy('module_id')
                ->get(['module_id', 'version', 'status', 'seeded_at']);
        } catch (Throwable) {
            return [];
        }

        $hasil = [];

        foreach ($baris as $satu) {
            $hasil[] = [
                'id' => (string) $satu->module_id,
                'versi' => (string) $satu->version,
                'status' => (string) $satu->status,
                'disemai' => $satu->seeded_at !== null,
            ];
        }

        return $hasil;
    }
}
