<?php

declare(strict_types=1);

namespace ControlPlane\Http\Controllers\Sites;

use ControlPlane\Audit\OperatorAudit;
use ControlPlane\Http\Controllers\Controller;
use ControlPlane\Models\Site;
use ControlPlane\Models\SiteOperation;
use ControlPlane\Sites\EnrollmentTokens;
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
    public function issueEnrollment(Request $request, string $site, EnrollmentTokens $tokens): RedirectResponse
    {
        $row = $this->confirmedSite($request, $site);

        $issued = DB::transaction(function () use ($request, $row, $tokens): array {
            $issued = $tokens->issue($row, $request->user()?->getAuthIdentifier());
            OperatorAudit::record($request, 'site.enrollment_token.issued', 'site', $row->id, [
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
