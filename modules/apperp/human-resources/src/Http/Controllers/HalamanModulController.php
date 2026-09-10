<?php

declare(strict_types=1);

namespace Modules\Apperp\HumanResources\Http\Controllers;

use App\Support\Modules\Contracts\KonteksPermintaan;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\Yaml\Yaml;

/**
 * Satu layar untuk seluruh menu module ini.
 *
 * Dulu UI module adalah aplikasi React tersendiri di dalam iframe, dan yang memilih layar
 * adalah perutean hash di dalamnya (`#/workers`, `#/jobs`, …). Hash itu ada karena satu image
 * harus bisa disajikan di bawah awalan penempatan mana pun; setelah UI menyatu dengan shell,
 * awalan itu tidak ada lagi dan alamatnya menjadi alamat biasa:
 * `/human-resources/<id menu>/<ruas milik layar>`.
 *
 * **Sumber kebenaran id menu dan izinnya adalah `app.yaml`, dibaca apa adanya.** Menuliskan
 * ulang daftarnya di sini berarti dua daftar yang akan menyimpang, dan penyimpangannya muncul
 * sebagai menu yang mendarat di 404 — atau lebih buruk, sebagai layar yang terbuka tanpa izin
 * yang seharusnya menjaganya. Core menyusun tautan sidebar dengan aturan
 * `/<id module>/<id entri menu>` dari manifest yang sama, jadi keduanya tidak bisa berselisih.
 *
 * Bentuknya meniru `management-aset` sedekat mungkin, sampai ke nama propertinya. Yang
 * dibuktikan module kedua bukan bahwa HR bekerja — ia sudah bekerja sebagai app tersendiri —
 * melainkan bahwa jalur layar module ini satu bentuk, bukan satu bentuk per module.
 */
final class HalamanModulController
{
    /**
     * Entri menu manifest, dipetakan ke izin yang menjaganya.
     *
     * Disimpan sekali per proses. Manifest tidak berubah selama proses hidup, dan membacanya
     * ulang pada setiap permintaan berarti satu pembacaan berkas beserta penguraian YAML untuk
     * jawaban yang selalu sama.
     *
     * @var array<string, ?string>|null
     */
    private static ?array $menu = null;

    public function __invoke(Request $request, KonteksPermintaan $akses, string $view): Response
    {
        $menu = self::menu();

        // 404, bukan 403: id yang tidak ada di manifest bukan layar yang tidak boleh dibuka,
        // melainkan layar yang tidak pernah ada. Membedakannya penting saat menu dan rute
        // sedang tidak sejalan — 403 akan terbaca sebagai masalah hak akses dan menghabiskan
        // waktu orang di tempat yang salah.
        abort_unless(array_key_exists($view, $menu), 404);

        $izin = $menu[$view];

        // Gagal menutup. Entri menu tanpa `permission` pada manifest tidak dianggap terbuka
        // untuk semua orang; ia dianggap salah tulis, dan layarnya ditutup sampai manifestnya
        // dibetulkan.
        abort_unless($izin !== null && $akses->punyaIzin($izin), 403);

        return Inertia::render('human-resources::Modul', [
            'view' => $view,

            // Ruas sesudah id menu, misalnya `['01JQ…', 'ubah']` pada
            // `/human-resources/workers/01JQ…/ubah`. Layar yang membuka satu record pada
            // halaman tersendiri memakainya, sehingga tombol kembali peramban, muat ulang, dan
            // tautan yang disalin semuanya mendarat di record yang sama — persis yang dulu
            // dikerjakan ruas-ruas sesudah hash.
            'segments' => self::ruas($request),

            // Izin dikirim bersama halaman, bukan diambil lewat permintaan kedua. Layar tidak
            // lagi menunggu satu perjalanan jaringan sebelum tahu tombol mana yang boleh
            // tampil.
            'permissions' => $akses->izin(),

            'konteks' => [
                'legal_entity_id' => $request->attributes->get('coreerp.legal_entity_id'),
                'org_unit_id' => $request->attributes->get('coreerp.org_unit_id'),
                'user_id' => $akses->penggunaId(),
            ],
        ]);
    }

    /**
     * Ruas alamat sesudah id menu.
     *
     * @return list<string>
     */
    private static function ruas(Request $request): array
    {
        $sisa = (string) $request->route('sisa', '');

        if ($sisa === '') {
            return [];
        }

        return array_values(array_filter(explode('/', $sisa), static fn (string $ruas): bool => $ruas !== ''));
    }

    /**
     * Id entri menu pada manifest, dipetakan ke kode izinnya.
     *
     * @return array<string, ?string>
     */
    private static function menu(): array
    {
        if (self::$menu !== null) {
            return self::$menu;
        }

        /** @var mixed $manifest */
        $manifest = Yaml::parseFile(dirname(__DIR__, 3).'/app.yaml');
        $menu = [];

        $sidebar = is_array($manifest) ? ($manifest['ui']['navigation']['sidebar'] ?? []) : [];

        if (is_array($sidebar)) {
            foreach ($sidebar as $entri) {
                if (! is_array($entri)) {
                    continue;
                }

                foreach ($entri as $item) {
                    if (! is_array($item) || ! is_string($item['id'] ?? null)) {
                        continue;
                    }

                    $izin = $item['permission'] ?? null;
                    $menu[$item['id']] = is_string($izin) ? $izin : null;
                }
            }
        }

        return self::$menu = $menu;
    }
}
