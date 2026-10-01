<?php

declare(strict_types=1);

namespace App\Platform\Observability\Http\Middleware;

use App\Platform\Modules\Http\Middleware\ResolveModuleContext;
use App\Platform\Modules\Support\ModuleRequestContext;
use App\Platform\Observability\Support\ActiveSpan;
use App\Platform\Observability\Support\ErrorReporter;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Menempelkan konteks CoreERP — tenant, module, batas organisasi, pengguna — ke span
 * yang sedang aktif, supaya sebuah jejak bisa dicari dengan pertanyaan yang benar-benar
 * ditanyakan orang: "tenant mana yang lambat", bukan "rute mana yang lambat".
 *
 * **Kenapa dibaca setelah `$next($request)`, bukan sebelum.**
 *
 * Middleware ini global; yang menghitung konteksnya — `ResolveModuleContext` — tidak.
 * Ia dipasang per grup rute module karena butuh id module sebagai parameter (lihat
 * catatan di kelas itu). Middleware global berjalan **di luar** middleware rute, jadi
 * pada perjalanan masuk atribut yang mau dibaca di sini belum satu pun ditulis. Membaca
 * di sana menghasilkan span tanpa atribut, tanpa error, dan tanpa petunjuk kenapa —
 * kegagalan yang paling mahal untuk ditemukan karena kelihatannya berhasil.
 *
 * Perjalanan keluar tidak punya masalah itu: saat kendali kembali ke sini, seluruh
 * middleware rute sudah selesai menulis. Span permintaan yang dibuat auto-instrumentation
 * membungkus keduanya, jadi ia masih aktif dan masih merekam.
 *
 * Dibungkus `finally`, bukan ditaruh setelah pemanggilan, supaya atribut tetap tertempel
 * ketika permintaan berakhir dengan lemparan. Justru permintaan itu yang paling butuh
 * dikenali tenantnya.
 */
final class AttachTraceContext
{
    /**
     * Nama atribut span untuk id module.
     *
     * Sengaja tidak memakai `ResolveModuleContext::ACTIVE_MODULE` (`module.id`) apa adanya.
     * Nama itu urusan atribut permintaan, dan di sana ia berdiri sendiri; di dalam sebuah
     * span ia berdampingan dengan atribut milik framework, HTTP, dan database, dan
     * `module.id` tanpa awalan akan terbaca sebagai milik salah satu dari mereka.
     */
    private const MODULE_ATTRIBUTE = 'coreerp.module_id';

    public function handle(Request $request, Closure $next): Response
    {
        // Ditandai sebelum apa pun berjalan, supaya penanda ini sudah ada ketika kesalahan
        // terjadi di titik mana pun di hilir. Middleware ini terdaftar global dan karena itu
        // dilewati setiap permintaan HTTP — dan hanya permintaan HTTP; permintaan tiruan yang
        // dibuat pekerja antrean dan perintah artisan tidak pernah melewatinya.
        //
        // Inilah yang membedakan "melayani permintaan HTTP" dari "berjalan di baris perintah".
        // Keduanya sering disamakan lewat `runningInConsole()`, dan itu keliru justru di tempat
        // yang penting: di dalam test, permintaan yang menembus seluruh middleware tetap
        // berjalan pada SAPI `cli`.
        $request->attributes->set(ErrorReporter::HTTP_MARKER, true);

        try {
            return $next($request);
        } finally {
            ActiveSpan::setAttributes([
                'coreerp.tenant_id' => $this->text($request, ModuleRequestContext::TENANT_ID),
                self::MODULE_ATTRIBUTE => $this->text($request, ResolveModuleContext::ACTIVE_MODULE),
                'coreerp.legal_entity_id' => $this->text($request, ModuleRequestContext::LEGAL_ENTITY_ID),
                'coreerp.org_unit_id' => $this->text($request, ModuleRequestContext::ORG_UNIT_ID),
                'coreerp.user_id' => $this->text($request, ModuleRequestContext::USER_ID),
            ]);
        }
    }

    /**
     * Membaca satu atribut permintaan sebagai teks, atau `null` kalau ia bukan skalar.
     *
     * `legal_entity_id` dan `org_unit_id` boleh kosong — pengguna yang belum memilih
     * entitas hukum tetap sah — dan id-nya bisa berupa int maupun string tergantung
     * modelnya. Keduanya ditangani di satu tempat supaya pemanggilnya tetap satu baris.
     */
    private function text(Request $request, string $key): ?string
    {
        $value = $request->attributes->get($key);

        if (is_string($value)) {
            return $value === '' ? null : $value;
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return null;
    }
}
