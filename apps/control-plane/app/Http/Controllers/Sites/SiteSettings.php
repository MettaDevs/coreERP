<?php

declare(strict_types=1);

namespace ControlPlane\Http\Controllers\Sites;

use ControlPlane\Http\Controllers\Controller;
use ControlPlane\Models\Site;
use ControlPlane\Sites\ClientServerSetup;
use ControlPlane\Sites\ServerSettings;
use ControlPlane\Sites\SiteRejected;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Setelan server klien dari halaman rinciannya: alamat server, alamat aplikasi, dan jendela pembaruan.
 *
 * Isinya sama dengan "Lanjutan" di panel halaman lingkungan, dan keduanya memakai `ServerSettings` serta
 * `ClientServerSetup::updateSettings`. Pintu kedua ini ada karena alamat mesin dicari di daftar server klien,
 * bukan di halaman lingkungan tenant — dan situs lama tanpa lingkungan tidak punya panel sama sekali.
 *
 * Tidak meminta nama situs diketik ulang, berbeda dari `SiteActions`: tidak satu pun isian ini memerintah
 * server klien. Jendela pembaruan baru berlaku pada pembaruan berikutnya yang diminta operator.
 */
final class SiteSettings extends Controller
{
    public function update(Request $request, string $site, ClientServerSetup $setup): RedirectResponse
    {
        $row = Site::query()->whereKey($site)->firstOrFail();
        $settings = ServerSettings::fromRequest($request);

        try {
            $setup->updateSettings($request, $row, $settings);
        } catch (SiteRejected $e) {
            throw ValidationException::withMessages(['settings' => $e->getMessage()]);
        }

        return redirect('/situs/'.$row->id)->with('message', 'Setelan server klien disimpan.');
    }
}
