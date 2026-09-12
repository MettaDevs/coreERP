<?php

namespace App\Actions\Onboarding;

use App\Actions\Access\CreateInvitation;
use App\Models\InvitationCode;
use App\Models\RoleAssignment;
use App\Models\TenantMembership;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Menukar kode undangan menjadi keanggotaan pada sebuah tenant.
 *
 * ## Dua jalur, dan yang membedakannya siapa yang bertanya
 *
 * **Orang baru** menukarnya bersama nama, email, dan kata sandi — akunnya lahir di sini.
 *
 * **Orang yang sudah punya akun** menukarnya sambil masuk, dan yang dikirim hanya kodenya.
 * Namanya, emailnya, dan kata sandinya tidak diminta sama sekali, karena akunnya sudah ada dan
 * undangan tidak berhak menyentuh satu pun di antaranya.
 *
 * ## Kenapa jalur kedua tidak boleh sekadar "izinkan email yang sudah terdaftar"
 *
 * Percobaan yang paling jelas adalah membuang pemeriksaan email di jalur pertama. Itu membuka
 * pengambilalihan akun: siapa pun yang memegang satu kode undangan dapat mengetik email orang lain
 * beserta kata sandi pilihannya sendiri, dan keluar sebagai pemilik akun itu. Kode undangan
 * dirancang untuk dibagikan — ia bukan bukti identitas.
 *
 * Karena itu pemeriksaannya **tetap berdiri** di jalur orang baru, dan yang ditambahkan adalah
 * jalur bagi orang yang sudah membuktikan dirinya dengan masuk. Satu orang di banyak tenant
 * akhirnya mungkin — konsultan, akuntan, dan operator vendor — tanpa satu pun akun menjadi dapat
 * direbut.
 *
 * ## Keanggotaan yang sudah ada
 *
 * `tenant_memberships` berkunci unik pada (tenant, user), jadi menukar kode untuk tenant yang sudah
 * dimasuki tidak dapat melahirkan baris kedua. Yang aktif ditolak dengan kalimat yang menyebutnya;
 * yang **tidak** aktif dihidupkan kembali, karena mengundang ulang orang yang pernah dicabut
 * aksesnya adalah alasan undangan itu ada.
 */
class RedeemInvitation
{
    /**
     * Jalur orang baru: akunnya lahir bersama keanggotaannya.
     *
     * @param  array{code:string,name:string,email:string,password:string}  $data
     */
    public function handle(array $data): User
    {
        return DB::transaction(function () use ($data): User {
            $invitation = $this->locate($data['code']);

            if (User::query()->where('email', Str::lower($data['email']))->exists()) {
                /*
                 * Tetap ditolak, dan kalimatnya sekarang menyebut jalan keluarnya.
                 *
                 * Yang salah dari pesan lama bukan penolakannya melainkan bahwa ia berhenti di
                 * "sudah punya akun" — orang yang membacanya menyimpulkan undangannya tidak
                 * berlaku untuk dirinya, padahal ia hanya perlu masuk lebih dulu.
                 */
                throw ValidationException::withMessages([
                    'email' => 'Email ini sudah memiliki akun. Masuk dengan akun itu lebih dulu, '
                        .'lalu tukarkan kodenya dari sana — satu akun boleh berada di banyak tenant.',
                ]);
            }

            $user = User::create([
                'name' => $data['name'],
                'email' => Str::lower($data['email']),
                'password' => $data['password'],
            ]);

            $this->attach($invitation, $user);

            return $user;
        });
    }

    /**
     * Jalur orang yang sudah punya akun: hanya kodenya yang ditukar.
     *
     * Tidak ada nama, email, maupun kata sandi yang masuk ke sini — dan itu bukan kelalaian
     * melainkan seluruh alasan jalur ini terpisah. Identitasnya sudah dibuktikan oleh sesi yang
     * membawanya; undangan hanya menambahkan tempat ia boleh bekerja.
     */
    public function handleForUser(User $user, string $code): TenantMembership
    {
        return DB::transaction(function () use ($user, $code): TenantMembership {
            return $this->attach($this->locate($code), $user);
        });
    }

    /**
     * Menemukan undangan yang masih sah, dan menguncinya.
     *
     * `lockForUpdate()` menahan dua penukaran atas kode yang sama supaya tidak berjalan bersamaan —
     * tanpanya, dua permintaan serentak sama-sama melihat undangan yang belum terpakai.
     */
    private function locate(string $code): InvitationCode
    {
        $invitation = InvitationCode::query()
            ->with('roles')
            ->where('code_hash', CreateInvitation::hash($code))
            ->lockForUpdate()
            ->first();

        if (! $invitation || $invitation->revoked_at || ($invitation->expires_at !== null && $invitation->expires_at->isPast())) {
            throw ValidationException::withMessages(['code' => 'Kode akses tidak valid, sudah dicabut, atau sudah kedaluwarsa.']);
        }

        return $invitation;
    }

    /**
     * Memasang keanggotaan, peran, dan cakupan datanya — bagian yang sama bagi kedua jalur.
     *
     * Diangkat ke satu tempat karena ia **protokol izin**. Dua salinan protokol izin adalah dua
     * kesempatan untuk menyimpang pada hal yang justru tidak terlihat ketika ia salah: jalur yang
     * satu memberi cakupan data, jalur yang lain lupa, dan yang terlihat hanya orang yang "tidak
     * bisa melihat apa-apa" tanpa seorang pun tahu kenapa.
     */
    private function attach(InvitationCode $invitation, User $user): TenantMembership
    {
        $membership = TenantMembership::query()
            ->where('tenant_id', $invitation->tenant_id)
            ->where('user_id', $user->id)
            ->lockForUpdate()
            ->first();

        if ($membership instanceof TenantMembership && $membership->status === 'active') {
            throw ValidationException::withMessages([
                'code' => 'Akun ini sudah menjadi anggota tenant tersebut. Tidak ada yang perlu ditukar.',
            ]);
        }

        if ($membership instanceof TenantMembership) {
            // Pernah menjadi anggota lalu dicabut. Menghidupkannya kembali persis yang dimaksud
            // orang yang mengundangnya lagi — dan barisnya tetap satu, seperti yang dituntut
            // kunci unik (tenant, user).
            $membership->update([
                'system_role' => $invitation->system_role,
                'status' => 'active',
            ]);
        } else {
            $membership = TenantMembership::create([
                'tenant_id' => $invitation->tenant_id,
                'user_id' => $user->id,
                'system_role' => $invitation->system_role,
                'status' => 'active',
            ]);
        }

        $assignments = $this->assignRoles($invitation, $membership);
        $this->applyDataPolicyScopes($invitation, $membership, $assignments);

        return $membership;
    }

    /**
     * @return Collection<string, RoleAssignment> Ditandai id peran, dan HANYA yang baru lahir.
     */
    private function assignRoles(InvitationCode $invitation, TenantMembership $membership): Collection
    {
        /** @var Collection<string, RoleAssignment> $fresh */
        $fresh = collect();

        foreach ($invitation->roles as $role) {
            $existing = RoleAssignment::query()
                ->where('membership_id', $membership->id)
                ->where('role_id', $role->id)
                ->where('source', 'manual')
                ->first();

            if ($existing instanceof RoleAssignment) {
                // Kunci unik (membership, role, source) menolak baris kedua, dan memaksanya hanya
                // akan menjatuhkan seluruh penukaran demi peran yang sudah dipegang orangnya.
                continue;
            }

            $fresh->put($role->id, RoleAssignment::create([
                'membership_id' => $membership->id,
                'role_id' => $role->id,
                'source' => 'manual',
                'source_reference' => 'invitation:'.$invitation->id,
                'status' => 'active',
                'valid_from' => now(),
            ]));
        }

        return $fresh;
    }

    /**
     * @param  Collection<string, RoleAssignment>  $assignments
     */
    private function applyDataPolicyScopes(InvitationCode $invitation, TenantMembership $membership, Collection $assignments): void
    {
        if ($assignments->isEmpty()) {
            return;
        }

        $scopes = DB::table('invitation_data_policy_scopes')->where('invitation_id', $invitation->id)->get();

        foreach ($scopes as $scope) {
            $assignment = $assignments->get($scope->role_id);

            // Cakupan hanya dipasang untuk penugasan yang baru lahir. Penugasan yang sudah ada
            // membawa cakupannya sendiri — yang mungkin sudah dipersempit sesudahnya — dan
            // menimpanya dari undangan lama akan mengembalikan akses yang sengaja dicabut.
            if (! $assignment instanceof RoleAssignment) {
                continue;
            }

            $assignment->dataPolicyScopes()->create([
                'tenant_id' => $membership->tenant_id,
                'policy_code' => $scope->policy_code,
                'legal_entity_id' => $scope->legal_entity_id,
                'organization_id' => $scope->organization_id,
                'hierarchy_id' => $scope->hierarchy_id,
                'hierarchy_version_id' => $scope->hierarchy_version_id,
                'include_descendants' => $scope->include_descendants,
                'valid_from' => now(),
            ]);
        }
    }
}
