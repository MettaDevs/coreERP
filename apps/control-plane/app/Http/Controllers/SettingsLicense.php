<?php

declare(strict_types=1);

namespace ControlPlane\Http\Controllers;

use ControlPlane\Audit\OperatorAudit;
use ControlPlane\Sites\LicenseTerms;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Masa lisensi bawaan untuk seluruh situs, dari halaman Pengaturan.
 *
 * ## Kenapa dapat diubah dari layar
 *
 * Angka ini menentukan berapa lama admin.erp boleh mati sebelum klinik ikut terkunci: lisensi yang tidak
 * pernah diperpanjang habis dengan sendirinya. Menaikkannya pada saat yang tepat — sebelum pemeliharaan
 * panjang, atau sesudah gangguan — adalah keputusan operasional, dan menunggu deploy untuk mengubah satu
 * angka berarti keputusan itu diambil terlambat.
 *
 * Situs yang punya angkanya sendiri tidak terpengaruh, dan begitu juga situs permanen.
 */
final class SettingsLicense extends Controller
{
    public function update(Request $request, LicenseTerms $terms): RedirectResponse
    {
        $data = $request->validate([
            'valid_days' => ['required', 'integer', 'min:1', 'max:'.LicenseTerms::MAX_VALID_DAYS],
            // Perpanjangan harus mulai sebelum lisensinya habis. Sama dengan `lt` di layar situs, dan
            // dijaga ulang oleh constraint `sites_masa_lisensi_masuk_akal` untuk timpaan per situs.
            'renew_before_days' => ['required', 'integer', 'min:1', 'max:'.LicenseTerms::MAX_RENEW_BEFORE_DAYS, 'lt:valid_days'],
        ]);

        $before = $terms->defaults();
        $terms->storeDefaults((int) $data['valid_days'], (int) $data['renew_before_days'], $request->user()?->getAuthIdentifier());

        OperatorAudit::record($request, 'console.license_terms_changed', 'console', 'settings', [
            'from' => $before,
            'to' => $terms->defaults(),
        ]);

        return redirect('/pengaturan')->with('message', 'Masa lisensi bawaan disimpan. Berlaku pada penerbitan berikutnya.');
    }
}
