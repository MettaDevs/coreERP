<?php

namespace App\Actions\Access;

use App\Models\ExternalIdentity;
use App\Models\InvitationCode;
use App\Models\Role;
use App\Models\TenantMembership;
use App\Support\ControlPlane\OutboundRefused;
use App\Support\DataPolicyScopeResolver;
use App\Support\Sso\SharedIdentityProvider;
use App\Support\Sso\SsoApiUnavailable;
use App\Support\Sso\SsoDirectory;
use App\Support\Sso\SsoDirectoryUser;
use App\Support\Sso\SsoInvitationMailer;
use App\Support\Sso\TenantSso;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CreateInvitation
{
    public function __construct(
        private readonly DataPolicyScopeResolver $scopeResolver,
        private readonly SsoDirectory $directory,
        private readonly SsoInvitationMailer $mailer,
        private readonly TenantSso $tenantSso,
        private readonly SharedIdentityProvider $provider,
    ) {}

    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    /**
     * @param  array{system_role:string,label:?string,sso_email:?string,assignments:list<array{role_id:string,policy_scopes:list<array{policy_code:string,legal_entity_id:?string,organization_id:?string,hierarchy_id:?string,include_descendants:bool}>}>}  $data
     * @return array{invitation:InvitationCode,code:string}
     */
    public function handle(TenantMembership $actor, array $data): array
    {
        if (! $actor->canManageAccess()) {
            throw new AuthorizationException;
        }
        if ($data['system_role'] === 'owner') {
            throw ValidationException::withMessages(['system_role' => 'Invitations cannot grant owner access.']);
        }

        $roleIds = Role::query()
            ->where('tenant_id', $actor->tenant_id)
            ->where('is_active', true)
            ->whereIn('id', array_column($data['assignments'], 'role_id'))
            ->pluck('id');
        if ($roleIds->count() !== count(array_unique(array_column($data['assignments'], 'role_id')))) {
            throw ValidationException::withMessages(['role_ids' => 'Role harus berasal dari tenant aktif.']);
        }
        $roles = Role::query()->whereIn('id', $roleIds)->get()->keyBy('id');
        $scopes = collect($data['assignments'])->flatMap(function (array $assignment) use ($roles, $actor): array {
            $role = $roles->get($assignment['role_id']);
            $this->scopeResolver->assertNoRedundantGrants($assignment['policy_scopes']);
            $resolved = collect($assignment['policy_scopes'])
                ->map(fn (array $scope): array => $this->scopeResolver->resolve($actor->tenant_id, $role, $scope));

            return $resolved->map(fn (array $scope): array => [
                'role_id' => $role->id,
                ...$scope,
            ])->all();
        });

        $plain = $this->generateCode();

        // Di luar transaksi, dan sebelum apa pun ditulis: ini panggilan jaringan ke pihak ketiga.
        // Menaruhnya di dalam transaksi berarti menahan kunci baris selama penyedia berpikir.
        $invited = $data['sso_email'] === null ? null : $this->assertInvitable($actor, $data['sso_email']);

        $expiresAt = $invited === null ? null : now()->addDays($this->invitationDays());

        try {
            $hasil = DB::transaction(function () use ($actor, $data, $roleIds, $plain, $scopes, $invited, $expiresAt): array {
                $invitation = InvitationCode::create([
                    'tenant_id' => $actor->tenant_id,
                    'code_hash' => self::hash($plain),
                    'code_ciphertext' => Crypt::encryptString($plain),
                    'system_role' => $data['system_role'],
                    'label' => $data['label'] ?? null,
                    'created_by' => $actor->user_id,
                    // Kode anonim tidak pernah kedaluwarsa, seperti sebelumnya. Undangan terikat
                    // punya tenggat karena emailnya menjanjikan tenggat itu kepada penerimanya.
                    'expires_at' => $expiresAt,
                    'sso_issuer' => $invited === null ? null : $this->provider->issuer(),
                    'sso_subject' => $invited?->subject,
                    'sso_email_at_invite' => $invited?->email,
                    'sso_name_at_invite' => $invited?->name,
                    'sso_checked_at' => $invited === null ? null : now(),
                ]);
                $invitation->roles()->sync($roleIds);
                $rows = $scopes->map(fn (array $scope): array => [
                    'id' => (string) Str::ulid(),
                    'invitation_id' => $invitation->id,
                    ...$scope,
                    'created_at' => now(), 'updated_at' => now(),
                ])->all();
                if ($rows !== []) {
                    DB::table('invitation_data_policy_scopes')->insert($rows);
                }

                if ($invited !== null) {
                    self::audit($actor, 'access.invitation.sso.diterbitkan', [
                        'invitation_id' => $invitation->id,
                        'subject' => $invited->subject,
                        'email' => $invited->email,
                        'system_role' => $data['system_role'],
                        'expires_at' => $expiresAt?->toIso8601String(),
                    ]);
                }

                return ['invitation' => $invitation, 'code' => $plain];
            });
        } catch (UniqueConstraintViolationException $e) {
            // Indeks parsial `undangan_sso_satu_yang_terbuka`. Dua operator yang menekan tombol
            // pada saat yang sama sampai di sini; yang kalah harus membaca kalimat, bukan 500.
            if ($invited !== null) {
                throw ValidationException::withMessages(['sso_email' => $this->duplicateSentence()]);
            }

            throw $e;
        }

        // Di luar transaksi, dan sesudahnya. Undangan yang sudah tersimpan tidak boleh hangus hanya
        // karena penyedia sedang tidak dapat mengirim surat; yang tersisa adalah `sso_notified_at`
        // yang kosong, dan layar yang menawarkan kirim ulang.
        if ($invited !== null) {
            $terkirim = $this->mailer->send($hasil['invitation'], $hasil['code']);

            self::audit($actor, 'access.invitation.sso.dikirim', [
                'invitation_id' => $hasil['invitation']->id,
                'email' => $invited->email,
                'terkirim' => $terkirim,
            ]);
        }

        return $hasil;
    }

    /**
     * Memastikan orang yang diundang memang ada di penyedia SSO, dan mengembalikan subjeknya.
     *
     * Seluruh penolakan di sini keluar sebagai galat pada kolom `sso_email`, karena satu-satunya
     * yang dapat diperbaiki operator adalah alamat yang barusan diketiknya — atau keputusan untuk
     * membuat kode anonim saja.
     *
     * @throws ValidationException
     */
    private function assertInvitable(TenantMembership $actor, string $email): SsoDirectoryUser
    {
        if (! $this->tenantSso->availableFor($actor->tenant_id)) {
            throw ValidationException::withMessages(['sso_email' => 'Tenant ini belum memakai SSO, jadi undangan lewat email belum dapat dibuat. Buat kode undangan biasa, atau nyalakan SSO lebih dulu.']);
        }

        if (! $this->directory->isConfigured()) {
            throw ValidationException::withMessages(['sso_email' => 'Alamat API penyedia SSO belum disetel di server ini (COREERP_SSO_API_URL), jadi pencarian pengguna tidak dapat dilakukan.']);
        }

        try {
            $invited = $this->directory->find($email);
        } catch (OutboundRefused) {
            // Lingkungan salinan sengaja tidak boleh menjangkau penyedia sungguhan; menyisir
            // direktori hidup dari salinan database adalah hal yang justru dijaga OutboundGuard.
            throw ValidationException::withMessages(['sso_email' => 'Undangan lewat SSO tidak tersedia di lingkungan salinan. Buat kode undangan biasa.']);
        } catch (SsoApiUnavailable $e) {
            throw ValidationException::withMessages(['sso_email' => $e->getMessage()]);
        }

        if ($invited === null) {
            self::audit($actor, 'access.invitation.sso.ditolak', ['email' => $email, 'sebab' => 'tidak-terdaftar']);

            throw ValidationException::withMessages(['sso_email' => 'Email ini belum terdaftar di SSO. Minta orangnya mendaftar di penyedia SSO lebih dulu, lalu undang lagi.']);
        }

        if (! $invited->isActive) {
            self::audit($actor, 'access.invitation.sso.ditolak', ['email' => $email, 'sebab' => 'nonaktif', 'subject' => $invited->subject]);

            throw ValidationException::withMessages(['sso_email' => 'Akun SSO untuk email ini sedang nonaktif. Minta pengelola SSO mengaktifkannya lebih dulu.']);
        }

        $issuer = $this->provider->issuer();

        $sudahAnggota = ExternalIdentity::query()
            ->where('issuer', $issuer)
            ->where('subject', $invited->subject)
            ->whereIn('user_id', TenantMembership::query()
                ->where('tenant_id', $actor->tenant_id)
                ->where('status', 'active')
                ->select('user_id'))
            ->exists();

        if ($sudahAnggota) {
            self::audit($actor, 'access.invitation.sso.ditolak', ['email' => $email, 'sebab' => 'sudah-anggota', 'subject' => $invited->subject]);

            throw ValidationException::withMessages(['sso_email' => 'Orang ini sudah menjadi anggota tenant ini. Ubah perannya di daftar anggota, bukan lewat undangan baru.']);
        }

        $sudahDiundang = InvitationCode::query()
            ->where('tenant_id', $actor->tenant_id)
            ->where('sso_issuer', $issuer)
            ->where('sso_subject', $invited->subject)
            ->whereNull('revoked_at')
            ->whereNull('sso_redeemed_at')
            ->exists();

        if ($sudahDiundang) {
            throw ValidationException::withMessages(['sso_email' => $this->duplicateSentence()]);
        }

        return $invited;
    }

    private function duplicateSentence(): string
    {
        return 'Sudah ada undangan terbuka untuk email ini. Cabut yang lama lebih dulu, atau kirim ulang tautannya.';
    }

    private function invitationDays(): int
    {
        // Penyedia membatasi 1 sampai 30 hari untuk emailnya; angka di luar itu membuat surat
        // menjanjikan tenggat yang berbeda dari yang berlaku di sini.
        return max(1, min(30, (int) config('coreerp.sso.invitation_days', 7)));
    }

    /** @param  array<string, mixed>  $payload */
    private static function audit(TenantMembership $actor, string $action, array $payload): void
    {
        DB::table('access_audit_events')->insert([
            'id' => (string) Str::ulid(),
            'tenant_id' => $actor->tenant_id,
            'membership_id' => $actor->id,
            'action' => $action,
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public static function hash(string $code): string
    {
        return hash('sha256', strtoupper(preg_replace('/[^A-Z0-9]/i', '', $code) ?? ''));
    }

    private function generateCode(): string
    {
        $characters = '';
        for ($index = 0; $index < 16; $index++) {
            $characters .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        return implode('-', str_split($characters, 4));
    }
}
