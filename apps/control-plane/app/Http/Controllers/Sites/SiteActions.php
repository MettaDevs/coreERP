<?php

declare(strict_types=1);

namespace ControlPlane\Http\Controllers\Sites;

use ControlPlane\Audit\OperatorAudit;
use ControlPlane\Http\Controllers\Controller;
use ControlPlane\Models\Site;
use ControlPlane\Models\SiteOperation;
use ControlPlane\Sites\EnrollmentTokens;
use ControlPlane\Sites\LicenseIssuer;
use ControlPlane\Sites\OfflineReportFile;
use ControlPlane\Sites\SiteOperations;
use ControlPlane\Sites\SiteRejected;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

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
    public function issueOnlineEnrollment(Request $request, string $site, EnrollmentTokens $tokens): RedirectResponse
    {
        $row = $this->confirmedSite($request, $site);

        if ($row->connectivity !== 'online') {
            throw ValidationException::withMessages(['confirm_name' => 'Situs offline didaftarkan lewat paket pendaftaran, bukan perintah pasang.']);
        }

        $issued = DB::transaction(function () use ($request, $row, $tokens): array {
            $issued = $tokens->issue($row, 'online', $request->user()?->getAuthIdentifier());
            OperatorAudit::record($request, 'site.enrollment_token.issued', 'site', $row->id, [
                'channel' => 'online',
                'expires_at' => $issued['expires_at']->toIso8601String(),
            ]);

            return $issued;
        });

        $source = rtrim((string) config('sites.agent_source'), '/');
        $ref = (string) config('sites.agent_source_ref');

        // Perintahnya hanya lewat flash session: tampil sekali, tidak pernah di alamat, tidak pernah
        // di log. Token di dalamnya sekali pakai dan kedaluwarsa dalam satu jam.
        return redirect('/situs/'.$row->id)->with('enrollment', [
            'command' => sprintf(
                'curl -fsSL %s/%s/deploy/agent/pasang.sh | sudo bash -s -- --admin-url %s --token %s --ref %s',
                $source,
                $ref,
                rtrim((string) config('app.url'), '/'),
                $issued['token'],
                $ref,
            ),
            'expiresAt' => $issued['expires_at']->toDateTimeString(),
        ]);
    }

    public function downloadOfflinePackage(Request $request, string $site, EnrollmentTokens $tokens, LicenseIssuer $licenses): Response
    {
        $row = $this->confirmedSite($request, $site);

        if ($row->connectivity !== 'offline') {
            throw ValidationException::withMessages(['confirm_name' => 'Situs online didaftarkan lewat perintah pasang.']);
        }

        if ($row->enrolled()) {
            throw ValidationException::withMessages(['confirm_name' => 'Situs ini sudah terdaftar. Paket pendaftaran baru akan menimpa kunci situsnya.']);
        }

        $package = DB::transaction(function () use ($request, $row, $tokens, $licenses): array {
            $issued = $tokens->issue($row, 'offline', $request->user()?->getAuthIdentifier());

            OperatorAudit::record($request, 'site.enrollment_token.issued', 'site', $row->id, [
                'channel' => 'offline',
                'expires_at' => $issued['expires_at']->toIso8601String(),
            ]);

            return [
                'site_id' => $row->id,
                'tenant_id' => $row->tenant_id,
                'tenant_name' => (string) $row->tenant()->value('name'),
                'admin_url' => rtrim((string) config('app.url'), '/'),
                'channel' => 'offline',
                'enrollment_token' => $issued['token'],
                'update_window' => $row->updateWindow(),
                'license_public_key' => $licenses->publicKey(),
                'license' => null,
            ];
        });

        return response()->streamDownload(
            static function () use ($package): void {
                echo json_encode($package, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            },
            'site.json',
            ['Content-Type' => 'application/json', 'Cache-Control' => 'no-store'],
        );
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

    public function downloadOfflineLicense(Request $request, string $site, LicenseIssuer $licenses): Response
    {
        $row = $this->confirmedSite($request, $site);
        $validUntil = (string) $request->input('valid_until');

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $validUntil) !== 1) {
            throw ValidationException::withMessages(['valid_until' => 'Tanggal berakhir lisensi harus berbentuk tahun-bulan-tanggal.']);
        }

        try {
            $license = DB::transaction(function () use ($request, $row, $licenses, $validUntil): array {
                $license = $licenses->issue($row, $validUntil);
                OperatorAudit::record($request, 'site.license.issued_offline', 'site', $row->id, ['valid_until' => $validUntil]);

                return $license;
            });
        } catch (SiteRejected $e) {
            throw ValidationException::withMessages(['valid_until' => $e->getMessage()]);
        }

        return response()->streamDownload(
            static function () use ($license): void {
                echo json_encode($license, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            },
            'lisensi-'.$row->id.'.json',
            ['Content-Type' => 'application/json', 'Cache-Control' => 'no-store'],
        );
    }

    public function uploadReportFile(Request $request, string $site, OfflineReportFile $files): RedirectResponse
    {
        $row = Site::query()->whereKey($site)->firstOrFail();
        $upload = $request->file('report');

        if (! $upload instanceof UploadedFile || ! $upload->isValid() || $upload->getSize() > 512 * 1024) {
            throw ValidationException::withMessages(['report' => 'Pilih file laporan situs, paling besar 512 KB.']);
        }

        try {
            $files->accept($request, $row, (string) file_get_contents($upload->getRealPath()));
        } catch (SiteRejected $e) {
            throw ValidationException::withMessages(['report' => $e->getMessage()]);
        }

        return redirect('/situs/'.$row->id)->with('message', 'File laporan diterima.');
    }

    public function revoke(Request $request, string $site): RedirectResponse
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
                ->update(['status' => 'cancelled', 'finished_at' => now(), 'updated_at' => now()]);

            OperatorAudit::record($request, 'site.revoked', 'site', $row->id, ['cancelled_operations' => $cancelled]);
        });

        return redirect('/situs/'.$row->id)->with('message', 'Situs dicabut. Aplikasinya di server klien tetap berjalan; pengelolaannya yang berhenti.');
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
