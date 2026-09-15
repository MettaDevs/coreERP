<?php

declare(strict_types=1);

namespace ControlPlane\Http\Controllers\Sites;

use ControlPlane\Audit\OperatorAudit;
use ControlPlane\Dns\DnsUnavailable;
use ControlPlane\Http\Controllers\Controller;
use ControlPlane\Models\Site;
use ControlPlane\Models\SiteOperation;
use ControlPlane\Registry\RegistryCredentials;
use ControlPlane\Sites\EnrollmentTokens;
use ControlPlane\Sites\SiteDns;
use ControlPlane\Sites\SiteOperations;
use ControlPlane\Sites\SiteRejected;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Tindakan operator yang mengubah, atau membuka jalan untuk mengubah, server milik klien.
 *
 * ## Konfirmasi tertulis
 *
 * Setiap tindakan di sini meminta nama situs diketik ulang. Satu klik yang salah baris di daftar
 * situs tidak boleh cukup untuk memperbarui server fasilitas kesehatan yang keliru — dan nama yang
 * diketik membuat operator membaca situs mana yang sedang ia perintah.
 *
 * ## Jejak audit
 *
 * Setiap method di sini menulis `operator_audit_events` di transaksi yang sama dengan perubahannya.
 * Token pendaftaran dan isi lisensi tidak pernah masuk jejak itu.
 */
final class SiteActions extends Controller
{
    /**
     * Token pendaftaran tanpa operasi pasang — hanya untuk situs lama yang lahir sebelum 15 September 2026
     * tanpa lingkungan.
     *
     * Situs yang punya lingkungan dipasang dari panel di halaman lingkungannya, yang membuat token **dan**
     * operasi `install` sekaligus. Token saja dari sini akan mendaftarkan agen yang tidak punya apa pun
     * untuk dipasang, dan panel lingkungannya lalu menunjukkan keadaan yang tidak dibuat siapa pun.
     */
    public function issueEnrollment(Request $request, string $site, EnrollmentTokens $tokens): RedirectResponse
    {
        $row = $this->confirmedSite($request, $site);

        if ($row->environment_id !== null) {
            throw ValidationException::withMessages(['operation' => 'Server klien ini dipasang dari halaman lingkungannya, lewat "Buat perintah pasang".']);
        }

        $issued = DB::transaction(function () use ($request, $row, $tokens): array {
            $issued = $tokens->issue($row, $request->user()?->getAuthIdentifier());
            OperatorAudit::record($request, 'site.enrollment_token.issued', 'site', $row->id, [
                'expires_at' => $issued['expires_at']->toIso8601String(),
            ]);

            return $issued;
        });

        // Perintahnya hanya lewat flash session: tampil sekali, tidak pernah di alamat, tidak pernah
        // di log. Token di dalamnya sekali pakai dan kedaluwarsa dalam satu jam.
        return redirect('/situs/'.$row->id)->with('enrollment', [
            'command' => EnrollmentTokens::installCommand($issued['token']),
            'expiresAt' => $issued['expires_at']->toDateTimeString(),
        ]);
    }

    public function requestOperation(Request $request, string $site, SiteOperations $operations): RedirectResponse
    {
        $row = $this->confirmedSite($request, $site);
        $operation = (string) $request->input('operation');

        try {
            $operations->request($request, $row, $operation, $request->only(['release', 'valid_until']));
        } catch (SiteRejected $e) {
            throw ValidationException::withMessages(['operation' => $e->getMessage()]);
        }

        return redirect('/situs/'.$row->id)->with('message', 'Operasi diminta. Agen mengambilnya pada kunjungan berikutnya.');
    }

    public function cancelOperation(Request $request, string $site, string $operation, SiteOperations $operations): RedirectResponse
    {
        $row = Site::query()->whereKey($site)->firstOrFail();
        $target = SiteOperation::query()->whereKey($operation)->where('site_id', $row->id)->firstOrFail();

        try {
            $operations->cancel($request, $row, $target);
        } catch (SiteRejected $e) {
            throw ValidationException::withMessages(['operation' => $e->getMessage()]);
        }

        return redirect('/situs/'.$row->id)->with('message', 'Permintaan operasi dibatalkan.');
    }

    public function revoke(Request $request, string $site, RegistryCredentials $credentials, SiteDns $dns): RedirectResponse
    {
        $row = $this->confirmedSite($request, $site);

        if ($row->revoked()) {
            return redirect('/situs/'.$row->id);
        }

        DB::transaction(function () use ($request, $row): void {
            $row->forceFill(['revoked_at' => now()])->save();

            $cancelled = SiteOperation::query()
                ->where('site_id', $row->id)
                ->where('status', 'requested')
                ->update(SiteOperations::closingColumns('cancelled'));

            OperatorAudit::record($request, 'site.revoked', 'site', $row->id, ['cancelled_operations' => $cancelled]);
        });

        // Sesudah pencabutan tersimpan, di luar transaksinya: Harbor yang tidak menjawab tidak boleh
        // membatalkan pencabutan. Operasi yang masih `running` ikut, karena agennya tidak akan pernah
        // dilayani lagi — situs yang dicabut tidak dapat menarik apa pun lagi (E2E-01).
        $credentials->releaseClosed($row, $request->ip(), includeRunning: true);

        // Record DNS alamat aplikasi dibuang juga, dengan alasan yang sama dengan robot registry: server yang tidak
        // lagi dikelola tidak boleh terus memegang nama di domain kita. Cloudflare yang menolak tidak membatalkan
        // pencabutan; recordnya tetap tercatat sehingga penghapusan dapat diulang dari sini.
        $message = 'Situs dicabut. Aplikasinya di server klien tetap berjalan; pengelolaannya yang berhenti.';

        try {
            $dns->remove($request, $row);
        } catch (DnsUnavailable $e) {
            $message .= ' Record DNS '.$row->dns_name.' belum terhapus: '.$e->getMessage();
        }

        return redirect('/situs/'.$row->id)->with('message', $message);
    }

    /**
     * Menghentikan sewa: lisensi berhenti diperpanjang, dan yang sedang berjalan habis dengan sendirinya.
     *
     * Permintaan `install_license` yang belum diambil agen ikut dibatalkan. Lisensi di dalamnya
     * diterbitkan sebelum sewa dihentikan, dan membiarkannya diambil berarti sewa yang baru saja
     * dihentikan diperpanjang oleh antrean.
     */
    public function suspendLicense(Request $request, string $site): RedirectResponse
    {
        $row = $this->confirmedSite($request, $site);

        if ($row->licenseRenewalSuspended()) {
            return redirect('/situs/'.$row->id);
        }

        DB::transaction(function () use ($request, $row): void {
            $row->forceFill(['license_suspended_at' => now()])->save();

            $cancelled = SiteOperation::query()
                ->where('site_id', $row->id)
                ->where('operation', 'install_license')
                ->where('status', 'requested')
                ->update(SiteOperations::closingColumns('cancelled'));

            OperatorAudit::record($request, 'site.license.renewal_suspended', 'site', $row->id, [
                'license_valid_until' => $row->license_valid_until?->toDateString(),
                'cancelled_operations' => $cancelled,
            ]);
        });

        return redirect('/situs/'.$row->id)->with('message', 'Perpanjangan lisensi dihentikan. Lisensi yang terpasang tetap berlaku sampai tanggal berakhirnya.');
    }

    public function resumeLicense(Request $request, string $site): RedirectResponse
    {
        $row = $this->confirmedSite($request, $site);

        if (! $row->licenseRenewalSuspended()) {
            return redirect('/situs/'.$row->id);
        }

        DB::transaction(function () use ($request, $row): void {
            $suspendedAt = $row->license_suspended_at?->toIso8601String();
            $row->forceFill(['license_suspended_at' => null])->save();

            OperatorAudit::record($request, 'site.license.renewal_resumed', 'site', $row->id, [
                'suspended_at' => $suspendedAt,
            ]);
        });

        return redirect('/situs/'.$row->id)->with('message', 'Perpanjangan lisensi dilanjutkan. Lisensi baru ikut di laporan agen berikutnya bila sudah jatuh tempo.');
    }

    private function confirmedSite(Request $request, string $site): Site
    {
        $row = Site::query()->whereKey($site)->firstOrFail();

        if (! hash_equals($row->name, (string) $request->input('confirm_name'))) {
            throw ValidationException::withMessages(['confirm_name' => 'Ketik nama situs persis seperti tertulis untuk melanjutkan.']);
        }

        return $row;
    }
}
