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
 * Setelan server klien dari halaman rinciannya: alamat server dan jendela pembaruan, serta menulis ulang record
 * DNS alamat aplikasinya.
 *
 * Isinya sama dengan "Setelan server" di panel halaman lingkungan, dan keduanya memakai `ServerSettings` serta
 * `ClientServerSetup`. Pintu kedua ini ada karena alamat mesin dicari di daftar server klien, bukan di halaman
 * lingkungan tenant — dan situs lama tanpa lingkungan tidak punya panel sama sekali.
 *
 * Tidak meminta nama situs diketik ulang, berbeda dari `SiteActions`: tidak satu pun tombol ini memerintah
 * server klien. Record DNS memang dapat berpindah, tetapi hanya ke alamat server yang dicatat operator untuk
 * situs itu sendiri.
 */
final class SiteSettings extends Controller
{
    public function update(Request $request, string $site, ClientServerSetup $setup): RedirectResponse
    {
        $row = $this->site($site);
        $settings = ServerSettings::fromRequest($request);

        try {
            $warning = $setup->updateSettings($request, $row, $settings);
        } catch (SiteRejected $e) {
            throw ValidationException::withMessages(['settings' => $e->getMessage()]);
        }

        return redirect('/situs/'.$row->id)->with('message', $warning ?? 'Setelan server klien disimpan.');
    }

    public function syncDns(Request $request, string $site, ClientServerSetup $setup): RedirectResponse
    {
        $row = $this->site($site);

        try {
            $setup->syncDns($request, $row);
        } catch (SiteRejected $e) {
            throw ValidationException::withMessages(['dns' => $e->getMessage()]);
        }

        return back()->with('message', sprintf('Record DNS %s menunjuk %s.', $row->dns_name, $row->dns_target));
    }

    private function site(string $id): Site
    {
        return Site::query()->with('environment.tenant:id,name,slug')->whereKey($id)->firstOrFail();
    }
}
