<?php

declare(strict_types=1);

namespace ControlPlane\Http\Controllers\Lingkungan;

use ControlPlane\Http\Controllers\Controller;
use ControlPlane\Models\Lingkungan;
use ControlPlane\Models\OperasiLingkungan;
use Inertia\Inertia;
use Inertia\Response as HalamanInertia;

/**
 * Satu lingkungan beserta riwayat operasinya.
 *
 * Riwayatnya dibatasi 50 baris terakhir. Halaman ini dibaca ketika ada yang ingin diketahui
 * sekarang — "kenapa ia begini" — dan jawaban itu hampir selalu ada di baris teratas.
 */
class Rincian extends Controller
{
    public function __invoke(string $lingkungan): HalamanInertia
    {
        $baris = Lingkungan::query()
            ->with('tenant:id,name,slug')
            ->whereKey($lingkungan)
            ->firstOrFail();

        $riwayat = $baris->operasi()
            ->with('pemesan:id,name')
            ->orderByDesc('started_at')
            ->limit(50)
            ->get()
            ->map(fn (OperasiLingkungan $o): array => [
                'id' => $o->id,
                'operasi' => $o->operation,
                'status' => $o->status,
                'langkah' => $o->step,
                'alasan' => $o->failure_message,
                'mulai' => $o->started_at->toDateTimeString(),
                'selesai' => $o->finished_at?->toDateTimeString(),
                // Boleh kosong, dan itu bukan data hilang: sebuah operasi memang dapat dimulai
                // sistem, bukan manusia — penyapuan kedaluwarsa misalnya.
                'oleh' => $o->pemesan->name ?? 'Sistem',
            ])
            ->all();

        return Inertia::render('lingkungan/rincian', [
            'lingkungan' => $baris->untukLayar() + [
                'databaseSendiri' => $baris->database_name !== null,
                'dibuat' => $baris->created_at?->toDateTimeString(),
            ],
            'riwayat' => $riwayat,
        ]);
    }
}
