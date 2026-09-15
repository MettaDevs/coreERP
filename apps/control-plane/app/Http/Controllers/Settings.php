<?php

declare(strict_types=1);

namespace ControlPlane\Http\Controllers;

use ControlPlane\Registry\HarborClient;
use ControlPlane\Registry\RegistrySettings;
use ControlPlane\Registry\RegistryUnavailable;
use ControlPlane\Sites\KeyInspection;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Halaman Pengaturan: keadaan kunci yang menentukan apakah server klien dapat dipasang dan dikunci
 * lisensinya (PS-08).
 *
 * ## Kenapa ada halaman untuk berkas yang disetel lewat `.env`
 *
 * Kunci yang hilang tidak membuat konsol mati. Ia membuat `/agen/kunci-rilis.pub` menjawab 503 di
 * terminal teknisi yang sedang berada di lokasi klien, atau membuat lisensi tidak pernah diperpanjang
 * sampai klinik terkunci tiga puluh hari kemudian. Keduanya ditemukan paling akhir oleh orang yang paling
 * jauh dari server ini. Halaman ini memindahkan penemuannya ke operator, sebelum perintah pasang dibuat.
 *
 * Isinya hanya sidik jari dan kalimat penyebab — tidak pernah isi kunci, termasuk yang publik.
 *
 * ## Bagian Harbor
 *
 * Status registry (CP-06 di `docs/todo/registry-harbor`): alamat yang diberikan ke agen, nama robot
 * sistem, dan apakah Harbor menerimanya. Pemeriksaannya satu permintaan ke Harbor setiap halaman dibuka,
 * dibatasi beberapa detik — halaman ini dibuka operator sesekali, dan jawaban basi dari cache justru
 * menyembunyikan robot yang baru saja dicabut. Pemakaian disk menunggu metrik Harbor dikumpulkan (REG-09).
 */
final class Settings extends Controller
{
    public function __invoke(RegistrySettings $registry, HarborClient $harbor): InertiaResponse
    {
        $robot = $registry->robotName();
        $check = ['ok' => true];

        if ($robot !== null) {
            try {
                $harbor->verifyRobot();
            } catch (RegistryUnavailable $e) {
                $check = ['ok' => false, 'error' => $e->getMessage()];
            }
        }

        $licensePrivate = KeyInspection::privateKey(config('sites.license_private_key_path'), 'CONSOLE_LICENSE_PRIVATE_KEY_PATH');
        $licensePublic = KeyInspection::publicKey(config('sites.license_public_key_path'), 'CONSOLE_LICENSE_PUBLIC_KEY_PATH');

        return Inertia::render('settings', [
            'releaseKey' => KeyInspection::publicKey(config('sites.release_public_key_path'), 'CONSOLE_RELEASE_PUBLIC_KEY_PATH'),
            'licenseKey' => [
                'private' => $licensePrivate['ok']
                    ? ['ok' => true]
                    : ['ok' => false, 'error' => $licensePrivate['error']],
                'public' => $licensePublic,
                /*
                 * Kunci privat dan publik yang tidak berpasangan adalah kesalahan yang paling sunyi: lisensi
                 * tetap ditandatangani, agen menerima kunci publik yang salah saat mendaftar, lalu setiap
                 * lisensi ditolak di server klien dan klinik terkunci. Kosong bila salah satunya belum sah —
                 * yang tidak dapat dibandingkan tidak dinyatakan cocok maupun tidak.
                 */
                'pairMatches' => $licensePrivate['ok'] && $licensePublic['ok']
                    ? hash_equals($licensePrivate['fingerprint'], $licensePublic['fingerprint'])
                    : null,
            ],
            'registry' => [
                'host' => $registry->host(),
                'robot' => $robot,
                'check' => $check,
            ],
        ]);
    }
}
