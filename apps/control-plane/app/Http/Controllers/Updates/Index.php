<?php

declare(strict_types=1);

namespace ControlPlane\Http\Controllers\Updates;

use ControlPlane\Environments\EnvironmentRejected;
use ControlPlane\Environments\FleetFromCore;
use ControlPlane\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Satu tempat untuk melihat apakah seluruh armada sudah memakai skema yang berlaku.
 *
 * ## Kenapa layar ini terpisah dari layar Lingkungan
 *
 * Karena pertanyaannya berbeda, dan yang kedua tidak punya tempat di yang pertama. Layar Lingkungan
 * menjawab "siapa punya apa" — ia diurut per pelanggan dan dibaca ketika seseorang mencari satu
 * baris. Layar ini menjawab "apa yang tertinggal" — ia dibaca sesudah rilis, isinya hitungan, dan
 * yang paling penting di dalamnya adalah baris yang **gagal**.
 *
 * AWS menamai kebutuhannya *single pane of glass*: keadaan seluruh tenant dalam satu layar, supaya
 * operator tidak perlu membuka dua ratus halaman rincian untuk menemukan tiga yang bermasalah.
 * Menggabungkannya ke layar Lingkungan berarti menambah dua kolom yang tidak berarti apa-apa pada
 * hari-hari biasa, lalu berharap seseorang memperhatikannya tepat pada hari yang tidak biasa.
 *
 * ## Core mati bukan galat 500
 *
 * Yang paling mungkin membawa operator ke sini adalah kecurigaan bahwa ada yang tidak beres. Kalau
 * jawabannya "ada, dan yang tidak beres adalah Core sendiri", itu justru jawaban yang ia cari —
 * dan ia harus terbaca sebagai kalimat, bukan sebagai halaman galat yang menelan alamat yang
 * dicoba.
 */
class Index extends Controller
{
    public function __invoke(FleetFromCore $fleet): InertiaResponse
    {
        try {
            $armada = $fleet();
        } catch (EnvironmentRejected $rejected) {
            return Inertia::render('updates/index', [
                'platformFingerprint' => null,
                'counts' => ['current' => 0, 'behind' => 0, 'failed' => 0, 'unknown' => 0],
                'environments' => [],
                'unreachable' => $rejected->getMessage(),
            ]);
        }

        return Inertia::render('updates/index', [
            'platformFingerprint' => $armada['platform_fingerprint'],
            'counts' => $armada['counts'],
            'environments' => $armada['environments'],
            'unreachable' => null,
        ]);
    }
}
