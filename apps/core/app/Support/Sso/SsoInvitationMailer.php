<?php

declare(strict_types=1);

namespace App\Support\Sso;

use App\Models\InvitationCode;
use App\Support\ControlPlane\EnvironmentAddress;
use Illuminate\Support\Facades\Log;

/**
 * Meminta penyedia SSO mengirim email undangan.
 *
 * Repo ini tidak punya jalur email sama sekali — nol `Mailable`, nol `Mail::` — dan itu sebabnya
 * undangan selama ini berupa kode yang disalin operator lalu dikirimkannya sendiri lewat chat.
 * Penyedia punya jalurnya, jadi yang dikirim dari sini adalah **permintaan mengirim**, bukan
 * emailnya: isi surat, pengirim, dan tampilannya milik penyedia.
 *
 * ## Tautannya mendarat di domain dasar
 *
 * Penyedia menolak `accept_url` yang host-nya di luar alamat balik client, dan alamat balik CoreERP
 * terdaftar satu per penempatan di domain dasar. Alamat tenant karena itu ditolak, dan tautannya
 * mendarat di `/undangan` lalu dialihkan — lihat `InvitationLandingController`.
 *
 * ## Gagal kirim bukan gagal mengundang
 *
 * Undangannya sudah ada di database sebelum method ini dipanggil. Penyedia yang sedang mati tidak
 * boleh menghanguskan pekerjaan operator; yang terjadi hanyalah `sso_notified_at` tetap kosong, dan
 * layar menawarkan kirim ulang.
 */
class SsoInvitationMailer
{
    public function __construct(private readonly SsoApiClient $api) {}

    public function isConfigured(): bool
    {
        return $this->api->isConfigured();
    }

    /**
     * Mengirim undangan ini, dan menandainya bila penyedia menerima permintaannya.
     *
     * @return bool `true` bila penyedia menerima; `false` bila tidak, dengan sebabnya tercatat di log.
     */
    public function send(InvitationCode $invitation, string $code): bool
    {
        if (! $invitation->isSsoBound() || ! $this->isConfigured()) {
            return false;
        }

        $acceptUrl = $this->acceptUrl($code);

        if ($acceptUrl === null) {
            Log::warning('SSO: undangan tidak dapat dikirim karena domain dasar belum disetel.', [
                'invitation_id' => $invitation->id,
            ]);

            return false;
        }

        try {
            $response = $this->api->post('/notifications/send-invitation', [
                'recipient_email' => $invitation->sso_email_at_invite,
                'recipient_name' => $invitation->sso_name_at_invite,
                'app_name' => (string) config('coreerp.sso.invitation_app_name', 'CoreERP'),
                // Penyedia mencetaknya apa adanya di suratnya. Peran sistem sudah cukup menjelaskan,
                // dan nama peran internal tenant bukan urusan penyedia.
                'role_name' => $invitation->system_role === 'admin' ? 'Admin' : 'Pengguna',
                'accept_url' => $acceptUrl,
                'expires_in_days' => $this->days($invitation),
            ]);
        } catch (SsoApiUnavailable $e) {
            Log::warning('SSO: penyedia tidak dapat diminta mengirim undangan.', [
                'invitation_id' => $invitation->id,
                'sebab' => $e->getMessage(),
            ]);

            return false;
        }

        if (! $response->successful()) {
            Log::warning('SSO: penyedia menolak mengirim undangan.', [
                'invitation_id' => $invitation->id,
                'status' => $response->status(),
                // Hanya `message`; jawaban selebihnya dapat memuat apa saja.
                'pesan' => is_string($response->json('message')) ? $response->json('message') : null,
            ]);

            return false;
        }

        $invitation->update(['sso_notified_at' => now()]);

        return true;
    }

    /** Sisa hari sampai undangan ini kedaluwarsa, dalam batas 1–30 yang diterima penyedia. */
    private function days(InvitationCode $invitation): int
    {
        $days = $invitation->expires_at === null
            ? (int) config('coreerp.sso.invitation_days', 7)
            : (int) ceil(now()->diffInDays($invitation->expires_at));

        return max(1, min(30, $days));
    }

    private function acceptUrl(string $code): ?string
    {
        $domain = EnvironmentAddress::baseDomain();

        if ($domain === '') {
            return null;
        }

        $port = request()->getPort();
        $host = in_array($port, [80, 443, null], true) ? $domain : $domain.':'.$port;

        return sprintf('%s://%s/undangan?kode=%s', request()->getScheme(), $host, urlencode($code));
    }
}
