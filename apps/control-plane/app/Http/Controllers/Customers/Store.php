<?php

declare(strict_types=1);

namespace ControlPlane\Http\Controllers\Customers;

use ControlPlane\Customers\CoreUnreachable;
use ControlPlane\Customers\CreateCustomer;
use ControlPlane\Customers\CustomerRejected;
use ControlPlane\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class Store extends Controller
{
    public function __invoke(Request $request, CreateCustomer $create): RedirectResponse
    {
        $input = $request->validate([
            'legal_name' => ['required', 'string', 'max:150'],
            'admin_name' => ['required', 'string', 'max:150'],
            'admin_email' => ['required', 'string', 'email', 'max:150'],
            // Jenis tempat kerja pertamanya. Core yang menjadi penentu akhirnya; yang di sini
            // menjaga operator mendapat pesan di bawah kolomnya alih-alih penolakan dari jaringan.
            'first_environment' => ['required', 'in:production,demo,none'],
            'first_environment_expires_at' => [
                'exclude_unless:first_environment,demo',
                'required',
                'date',
                'after:today',
            ],
            // Bentuknya diperiksa di sini, **ketersediaannya tidak**. Yang tahu app mana tersedia,
            // apa prerequisite-nya, dan apakah ia ada di edisi ini hanyalah Core — dan ia memang
            // memeriksanya saat permintaannya tiba. Menyalin pemeriksaan itu ke sini berarti dua
            // daftar app yang harus tetap sama, dan yang kedua akan basi lebih dulu.
            'app_ids' => ['required', 'array', 'min:1'],
            'app_ids.*' => ['required', 'string', 'max:80'],
        ], [
            'app_ids.required' => 'Pilih setidaknya satu app yang dibeli.',
            'app_ids.min' => 'Pilih setidaknya satu app yang dibeli.',
            'first_environment_expires_at.required' => 'Lingkungan demo wajib punya tanggal berakhir.',
            'first_environment_expires_at.after' => 'Tanggal berakhir harus sesudah hari ini.',
        ]);

        $apps = [];

        foreach (is_array($input['app_ids']) ? $input['app_ids'] : [] as $item) {
            $apps[] = (string) $item;
        }

        try {
            $result = $create(
                $input['legal_name'],
                $input['admin_name'],
                $input['admin_email'],
                $apps,
                // Dipersempit di sini, bukan dipaksa dengan cast: aturan `in:` di atas sudah
                // menjaminnya, tetapi jaminan itu tidak terbaca analisis statis.
                match ($input['first_environment']) {
                    'demo' => 'demo',
                    'none' => 'none',
                    default => 'production',
                },
                isset($input['first_environment_expires_at']) && is_string($input['first_environment_expires_at'])
                    ? $input['first_environment_expires_at']
                    : null,
            );
        } catch (CustomerRejected $rejected) {
            // Penolakan Core dipulangkan sebagai kesalahan formulir, bukan halaman 500. Keduanya
            // berarti "permintaanmu ditolak", tetapi hanya yang pertama yang memberi tahu sebabnya
            // — dan kalau Core menyebut isian mana yang salah, pesannya mendarat persis di sana.
            throw ValidationException::withMessages(
                $rejected->fieldErrors() !== []
                    ? $rejected->fieldErrors()
                    : ['core' => $rejected->getMessage()],
            );
        } catch (CoreUnreachable $disconnected) {
            // Kuncinya `core`, bukan salah satu nama isian: yang salah memang bukan isian mana pun,
            // melainkan setelan di luar formulir. Dialog merendernya sebagai spanduk di atas,
            // tempat kalimat sepanjang ini masih terbaca.
            throw ValidationException::withMessages(['core' => $disconnected->getMessage()]);
        }

        /*
         * Kata sandi sementara dititipkan ke flash session, lalu ditampilkan sekali di daftar.
         *
         * Ia tidak ikut ke alamat dan tidak ikut ke log. Sekali sebuah rahasia masuk query string,
         * ia tinggal di riwayat peramban, di access log, dan di header Referer setiap permintaan
         * berikutnya — tiga tempat yang tidak pernah dibersihkan siapa pun.
         */
        return redirect('/tenant')->with('credentials', [
            'tenant' => $result['tenant_id'],
            'name' => $input['legal_name'],
            'email' => $result['email'],
            'password' => $result['temporary_password'],
        ]);
    }
}
