<?php

declare(strict_types=1);

namespace App\Http\Controllers\Onboarding;

use App\Actions\Access\CreateInvitation;
use App\Http\Controllers\Controller;
use App\Models\Environment;
use App\Models\InvitationCode;
use App\Models\Tenant;
use App\Support\ControlPlane\EnvironmentAddress;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Pendaratan tautan undangan di domain dasar, yang tugasnya hanya mengalihkan ke alamat tenant.
 *
 * Satu langkah tambahan ini ada karena batas di sisi penyedia, bukan karena kerapian. Penyedia
 * menolak mengirim email undangan yang tautannya menuju host yang bukan host alamat balik client —
 * dan alamat balik CoreERP terdaftar **satu per penempatan, di domain dasar**, bukan satu per
 * tenant. Alamat `klinik.erp.contoh` karena itu ditolak, sementara `erp.contoh` diterima.
 *
 * Upacara SSO-nya sendiri tetap harus dimulai di alamat tenant: di sanalah cookie rahasia peramban
 * dipasang, dan ke sanalah token serah kembali.
 *
 * Kode di alamat ini bukan rahasia yang membuka apa pun. Untuk undangan terikat, yang membukanya
 * klaim `sub` dari penyedia; kode hanya memilih baris mana yang sedang ditukar.
 */
class InvitationLandingController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $code = $request->query('kode');

        $invitation = is_string($code) && $code !== ''
            ? InvitationCode::query()->where('code_hash', CreateInvitation::hash($code))->first()
            : null;

        // Tautan yang tidak dikenal atau sudah habis dijawab halaman tukar kode biasa, dengan
        // kalimatnya. Menjawab 404 hanya memberi tahu penebak bahwa tebakannya salah.
        if (! $invitation instanceof InvitationCode || ! $invitation->isSsoBound() || ! $invitation->isOpen()) {
            return redirect()->to('/join?sso_error=undangan-tidak-berlaku');
        }

        $address = $this->tenantAddress($invitation);

        if ($address === null) {
            return redirect()->to('/join?sso_error=undangan-tidak-berlaku');
        }

        return redirect()->away(sprintf('%s://%s/join?kode=%s', $request->getScheme(), $address, urlencode((string) $code)));
    }

    /** Alamat produksi tenant pemilik undangan, lengkap dengan porta permintaan ini. */
    private function tenantAddress(InvitationCode $invitation): ?string
    {
        $slug = Tenant::query()->whereKey($invitation->tenant_id)->value('slug');

        if (! is_string($slug) || $slug === '') {
            return null;
        }

        $kind = Environment::query()
            ->where('tenant_id', $invitation->tenant_id)
            ->whereNull('deleted_at')
            ->where('kind', 'production')
            ->exists() ? 'production' : null;

        if ($kind === null) {
            return null;
        }

        $host = EnvironmentAddress::forEnvironment($slug, $kind);

        if ($host === null) {
            return null;
        }

        $port = request()->getPort();

        return in_array($port, [80, 443, null], true) ? $host : $host.':'.$port;
    }
}
