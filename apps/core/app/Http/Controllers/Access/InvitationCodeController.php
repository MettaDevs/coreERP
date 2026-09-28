<?php

namespace App\Http\Controllers\Access;

use App\Actions\Access\CreateInvitation;
use App\Actions\Access\UpdateInvitation;
use App\Http\Controllers\Controller;
use App\Http\Requests\Access\InvitationRequest;
use App\Models\InvitationCode;
use App\Support\Sso\SsoInvitationMailer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class InvitationCodeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $membership = $this->currentMembership($request);

        return response()->json(['data' => InvitationCode::query()
            ->where('tenant_id', $membership->tenant_id)
            ->with('roles:id,name')
            ->latest()
            ->get()]);
    }

    public function store(InvitationRequest $request, CreateInvitation $action): JsonResponse|RedirectResponse
    {
        $membership = $this->currentMembership($request);
        $results = array_map(
            fn (array $payload): array => $action->handle($membership, $payload),
            $request->payloads(),
        );

        if ($request->is('api/*')) {
            $data = array_map(fn (array $result): array => [
                'id' => $result['invitation']->id,
                'code' => $result['code'],
                'expires_at' => $result['invitation']->expires_at,
            ], $results);

            // Bentuk tunggal tetap menjawab objek tunggal demi kompatibilitas.
            return response()->json(['data' => $request->has('codes') ? $data : $data[0]], 201);
        }

        return back()->with('new_invitation_codes', array_column($results, 'code'));
    }

    /**
     * Mengubah kode yang sudah terbit. Kodenya tetap sama; yang berubah hanya
     * role dan batas data yang akan diterima penukar berikutnya.
     */
    public function update(InvitationRequest $request, InvitationCode $invitationCode, UpdateInvitation $action): JsonResponse|RedirectResponse
    {
        $membership = $this->currentMembership($request);
        $invitation = $action->handle($membership, $invitationCode, $request->payload());

        if ($request->is('api/*')) {
            return response()->json(['data' => [
                'id' => $invitation->id,
                'system_role' => $invitation->system_role,
                'label' => $invitation->label,
                'roles' => $invitation->roles->pluck('name')->values(),
            ]]);
        }

        return back();
    }

    /**
     * Mengirim ulang email undangan terikat SSO.
     *
     * Ada karena pengiriman pertamanya boleh gagal tanpa menghanguskan undangannya: penyedia yang
     * sedang mati meninggalkan baris yang sah dengan `sso_notified_at` kosong. Kodenya sendiri
     * dibaca kembali dari simpanan terenkripsi — ia memang harus masuk ke tautan di surat.
     */
    public function resend(Request $request, InvitationCode $invitationCode, SsoInvitationMailer $mailer): JsonResponse|RedirectResponse
    {
        $membership = $this->currentMembership($request);
        abort_unless($membership->canManageAccess() && $invitationCode->tenant_id === $membership->tenant_id, 403);

        if (! $invitationCode->isSsoBound() || ! $invitationCode->isOpen()) {
            throw ValidationException::withMessages(['invitation' => 'Undangan ini tidak dapat dikirim ulang.']);
        }

        $code = $invitationCode->accessibleCode();

        if ($code === null) {
            throw ValidationException::withMessages(['invitation' => 'Kode undangan ini tidak dapat dibaca lagi dari simpanan, jadi tautannya tidak dapat dikirim ulang. Cabut undangan ini dan buat yang baru.']);
        }

        if (! $mailer->send($invitationCode, $code)) {
            throw ValidationException::withMessages(['invitation' => 'Penyedia SSO tidak dapat diminta mengirim undangan ini sekarang. Coba lagi sebentar lagi.']);
        }

        return $request->is('api/*') ? response()->json(null, 204) : back();
    }

    public function destroy(Request $request, InvitationCode $invitationCode): JsonResponse|RedirectResponse
    {
        $membership = $this->currentMembership($request);
        abort_unless($membership->canManageAccess() && $invitationCode->tenant_id === $membership->tenant_id, 403);
        $invitationCode->update(['revoked_at' => now()]);

        return $request->is('api/*') ? response()->json(null, 204) : back();
    }
}
