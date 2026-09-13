<?php

declare(strict_types=1);

namespace ControlPlane\Http\Controllers\Updates;

use ControlPlane\Environments\EnvironmentRejected;
use ControlPlane\Environments\QueueUpgradeViaCore;
use ControlPlane\Http\Controllers\Controller;
use ControlPlane\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Tombol "Perbarui" — satu baris, atau semua yang tertinggal.
 *
 * Satu controller untuk keduanya, dan bukan demi menghemat berkas: yang membedakannya hanya ada
 * atau tidaknya satu id di alamatnya. Dua controller yang isinya sama persis kecuali satu argumen
 * adalah dua tempat yang akan menyimpang — dan yang menyimpang di sini adalah siapa yang tercatat
 * menekan tombolnya.
 *
 * ## Ia tidak menunggu pembaruannya selesai
 *
 * Core memulangkan 202 begitu job-nya masuk antrean, dan halaman ini langsung kembali dengan
 * kalimat "sekian diantrekan". Yang membuat pilihan itu tidak menghilangkan pemantauannya: layar
 * Pembaruan memuat ulang dirinya selama masih ada operasi yang berjalan, dan sumbernya
 * `environment_operations` — tabel yang sudah diisi tiap operasi sejak registry berdiri.
 */
class Upgrade extends Controller
{
    public function __invoke(
        Request $request,
        QueueUpgradeViaCore $queue,
        ?string $lingkungan = null,
    ): RedirectResponse {
        $operator = $request->user();

        try {
            $result = $queue(
                $lingkungan,
                $operator instanceof User ? (int) $operator->id : null,
                $request->boolean('force'),
            );
        } catch (EnvironmentRejected $rejected) {
            return redirect('/pembaruan')->withErrors(['upgrade' => $rejected->getMessage()]);
        }

        return redirect('/pembaruan')->with('message', $this->sentence($result['queued_count'], $lingkungan));
    }

    /**
     * Kalimat yang muncul sesudah tombolnya ditekan.
     *
     * Nol disebut apa adanya, dan itu bukan sekadar kesopanan. Tombol yang ditekan lalu diam
     * terbaca seperti tombol yang rusak, dan operator akan menekannya lagi — beberapa kali, sampai
     * ia menyimpulkan layarnya yang bermasalah. Menyebut "tidak ada yang perlu diperbarui"
     * mengakhiri pertanyaannya di kalimat pertama.
     */
    private function sentence(int $queued, ?string $environmentId): string
    {
        if ($queued === 0) {
            return $environmentId === null
                ? 'Tidak ada lingkungan yang perlu diperbarui — seluruh armada sudah memakai skema yang berlaku.'
                : 'Lingkungan itu tidak perlu diperbarui, atau keadaannya belum mengizinkannya.';
        }

        return $queued === 1
            ? 'Satu lingkungan diantrekan. Kemajuannya muncul di kolom "Operasi terakhir" begitu pekerjanya mengambilnya.'
            : $queued.' lingkungan diantrekan. Kemajuannya muncul di kolom "Operasi terakhir" begitu pekerjanya mengambilnya.';
    }
}
