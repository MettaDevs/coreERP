<?php

declare(strict_types=1);

namespace ControlPlane\Http\Controllers\Environments;

use ControlPlane\Environments\EnvironmentRejected;
use ControlPlane\Environments\ProvisionViaCore;
use ControlPlane\Http\Controllers\Controller;
use ControlPlane\Models\Environment;
use ControlPlane\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Tombol "Siapkan" pada layar rincian lingkungan.
 *
 * Sampai ini ada, layar itu hanya menampilkan perintah `environment:provision` untuk disalin ke
 * terminal — dan itu memutus alurnya tepat di tengah: operator membuat lingkungan dari layar, lalu
 * harus membuka SSH untuk menyalakannya. Alasan yang dulu ditulis di sana sudah tidak berlaku;
 * jalur konsol → Core sudah berdiri dan sudah dipakai pembuatan pelanggan.
 */
class Provision extends Controller
{
    public function __invoke(Request $request, string $environment, ProvisionViaCore $provision): RedirectResponse
    {
        $row = Environment::query()->whereKey($environment)->firstOrFail();
        $operator = $request->user();

        try {
            // Operatornya ikut disebut supaya kolom "Oleh" pada riwayat operasi menyebut orangnya,
            // bukan "Sistem". Riwayat yang menamai penjadwal dan manusia dengan kata yang sama
            // menghapus satu-satunya keterangan yang membedakan keduanya.
            $result = $provision($row, $operator instanceof User ? (int) $operator->id : null);
        } catch (EnvironmentRejected $rejected) {
            /*
             * Dipulangkan sebagai galat di halaman yang sama, bukan halaman 500. Keduanya berarti
             * "tidak jadi", tetapi hanya yang pertama yang menyebut apa yang harus diperbaiki — dan
             * riwayat operasi di halaman itu sering sudah memuat sebab yang lebih rinci.
             *
             * Lewat `withErrors`, bukan lewat kunci flash baru. `errors` sudah dibagikan middleware
             * Inertia bawaan, jadi jalur ini tidak menuntut satu pun prop bersama tambahan — dan
             * prop bersama yang ditambah untuk satu layar adalah prop yang harus diingat setiap
             * layar lain.
             */
            return redirect('/lingkungan/'.$row->id)
                ->withErrors(['provision' => $rejected->getMessage()]);
        }

        $modules = array_map(static fn (array $item): string => $item['id'], $result['modules']);

        return redirect('/lingkungan/'.$row->id)->with('message', sprintf(
            'Lingkungan "%s" disiapkan di database "%s". %s',
            $row->name,
            $result['database'] ?? '—',
            $modules === []
                ? 'Tidak ada module yang dibeli tenant ini, jadi hanya skema Core yang dipasang.'
                : 'Module terpasang: '.implode(', ', $modules).'.',
        ));
    }
}
