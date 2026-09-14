<?php

declare(strict_types=1);

namespace ControlPlane\Sites;

use ControlPlane\Audit\OperatorAudit;
use ControlPlane\Models\Site;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Menerima file laporan yang dibawa pulang dari situs tanpa internet.
 *
 * Bentuk filenya ditulis agen (`deploy/agent/coreerp-agent write-report`):
 *
 *     {"format": "coreerp-site-report-v1", "content": "<base64 JSON>", "signature": "<base64>"}
 *
 * `content` berisi `{"report": {...}, "enrollment": null | {"token": "...", "public_key": "..."}}`.
 * Tanda tangannya atas byte `content` yang sudah didekode.
 *
 * ## File pertama mengantar kunci situs
 *
 * Situs offline tidak pernah dapat menyambung ke konsol ini, jadi kunci publiknya tiba di dalam file
 * laporan pertama bersama token pendaftaran offline. Urutan pemeriksaannya menentukan:
 *
 * 1. tanda tangan diperiksa dengan kunci **yang dibawa file itu** — membuktikan pembawa file memegang
 *    kunci privatnya;
 * 2. token diperiksa dan dipakai — membuktikan kunci itu memang untuk situs ini, bukan kunci siapa
 *    pun yang kebetulan menandatangani file dengan benar.
 *
 * Tanpa langkah kedua, siapa pun dapat membuat pasangan kunci sendiri dan mengikatnya ke situs mana
 * saja. Sesudah terikat, file berikutnya diperiksa dengan kunci yang tersimpan dan bagian `enrollment`
 * diabaikan.
 */
final class OfflineReportFile
{
    public const FORMAT = 'coreerp-site-report-v1';

    public function __construct(
        private readonly EnrollmentTokens $tokens,
        private readonly SiteReports $reports,
    ) {}

    public function accept(Request $request, Site $site, string $raw): void
    {
        $envelope = json_decode($raw, true);

        if (! is_array($envelope)
            || ($envelope['format'] ?? null) !== self::FORMAT
            || ! is_string($envelope['content'] ?? null)
            || ! is_string($envelope['signature'] ?? null)) {
            throw new SiteRejected('report_file_invalid', 'Ini bukan file laporan situs.');
        }

        $content = base64_decode($envelope['content'], true);
        $signature = base64_decode($envelope['signature'], true);
        $decoded = is_string($content) ? json_decode($content, true) : null;

        if (! is_string($content) || ! is_string($signature) || ! is_array($decoded) || ! is_array($decoded['report'] ?? null)) {
            throw new SiteRejected('report_file_invalid', 'Isi file laporan tidak dapat dibaca.');
        }

        if ($site->revoked()) {
            throw new SiteRejected('site_revoked', 'Situs ini sudah dicabut.');
        }

        DB::transaction(function () use ($request, $site, $content, $signature, $decoded): void {
            $enrolledNow = false;

            if (! $site->enrolled()) {
                $enrollment = $decoded['enrollment'] ?? null;

                if (! is_array($enrollment) || ! is_string($enrollment['token'] ?? null) || ! is_string($enrollment['public_key'] ?? null)) {
                    throw new SiteRejected('report_file_unbound', 'Situs ini belum terdaftar, dan file ini tidak membawa bagian pendaftaran.');
                }

                if (! SitePublicKey::acceptable($enrollment['public_key'])
                    || openssl_verify($content, $signature, $enrollment['public_key'], OPENSSL_ALGO_SHA256) !== 1) {
                    throw new SiteRejected('report_file_signature', 'Tanda tangan file laporan tidak sah.');
                }

                $bound = $this->tokens->redeem($enrollment['token'], 'offline', $enrollment['public_key']);

                if ($bound->id !== $site->id) {
                    throw new SiteRejected('report_file_wrong_site', 'Token pendaftaran di file ini milik situs lain.');
                }

                $site->refresh();
                $enrolledNow = true;
            } elseif (openssl_verify($content, $signature, (string) $site->public_key, OPENSSL_ALGO_SHA256) !== 1) {
                throw new SiteRejected('report_file_signature', 'Tanda tangan file laporan tidak sah terhadap kunci situs ini.');
            }

            $report = $this->reports->validate($decoded['report'], $site);
            $this->reports->record($site, $report, 'file');

            OperatorAudit::record($request, 'site.report_file.uploaded', 'site', $site->id, [
                'enrolled_now' => $enrolledNow,
                'reported_at' => $report['created_at'],
            ]);
        });
    }
}
