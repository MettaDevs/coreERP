<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Models\IntegrationClient;
use App\Models\TenantMembership;
use App\Support\ControlPlane\ActiveEnvironment;
use App\Support\Integration\PushDestination;
use App\Support\Integration\SignedPush;
use GuzzleHttp\Exception\ConnectException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Klien integrasi tenant (TODO 4.4): terbitkan token, atur scope dan mode pengiriman, cabut.
 *
 * Hanya owner dan admin, termasuk untuk melihat daftarnya: yang tampil di sini adalah siapa yang
 * boleh membaca jurnal keuangan tenant dari luar CoreERP.
 *
 * Token dan signing secret ditampilkan **sekali**, di jawaban yang menerbitkannya. Yang disimpan
 * hanya digest token; signing secret disimpan terenkripsi karena CoreERP sendiri yang memakainya
 * untuk membuat signature kiriman `push`.
 */
final class IntegrationClientController extends Controller
{
    public function index(Request $request): Response
    {
        $membership = $this->admin($request);

        return Inertia::render('settings/integration-clients', [
            'clients' => IntegrationClient::query()
                ->where('tenant_id', $membership->tenant_id)
                ->orderBy('status')
                ->orderBy('name')
                ->get()
                ->map(fn (IntegrationClient $client): array => $this->present($client))
                ->values(),
            'scopes' => IntegrationClient::SCOPES,
            'endpoint' => url('/api/internal/v1'),
        ]);
    }

    public function store(Request $request, PushDestination $tujuan): JsonResponse
    {
        $membership = $this->admin($request);
        $data = $this->validated($request, $membership->tenant_id, $tujuan);
        $rahasia = Str::random(48);
        $penanda = $data['delivery_mode'] === IntegrationClient::PUSH ? Str::random(48) : null;

        $client = IntegrationClient::query()->create([
            'tenant_id' => $membership->tenant_id,
            'name' => $data['name'],
            'token_digest' => IntegrationClient::digest($rahasia),
            'scopes' => $data['scopes'],
            'allowed_ips' => $data['allowed_ips'],
            'posting_type_prefixes' => $data['posting_type_prefixes'],
            'delivery_mode' => $data['delivery_mode'],
            'push_url' => $data['push_url'],
            'signing_secret' => $penanda,
            'status' => IntegrationClient::ACTIVE,
            'created_by_user_id' => (string) $request->user()?->getAuthIdentifier(),
        ]);

        return response()->json([
            'data' => $this->present($client),
            'token' => $client->id.'.'.$rahasia,
            'signing_secret' => $penanda,
        ], 201);
    }

    public function update(Request $request, IntegrationClient $integrationClient, PushDestination $tujuan): JsonResponse
    {
        $membership = $this->admin($request);
        $client = $this->milik($membership, $integrationClient, aktif: true);
        $data = $this->validated($request, $membership->tenant_id, $tujuan, $client);

        // Berpindah ke push menerbitkan signing secret baru; berpindah ke pull membuangnya, supaya
        // klien pull tidak menyimpan secret yang tidak dipakai siapa pun.
        $penandaBaru = $data['delivery_mode'] === IntegrationClient::PUSH && $client->signing_secret === null
            ? Str::random(48)
            : null;

        $client->fill([
            'name' => $data['name'],
            'scopes' => $data['scopes'],
            'allowed_ips' => $data['allowed_ips'],
            'posting_type_prefixes' => $data['posting_type_prefixes'],
            'delivery_mode' => $data['delivery_mode'],
            'push_url' => $data['push_url'],
            'signing_secret' => $data['delivery_mode'] === IntegrationClient::PULL
                ? null
                : ($penandaBaru ?? $client->signing_secret),
        ])->save();

        return response()->json(['data' => $this->present($client), 'signing_secret' => $penandaBaru]);
    }

    /** Mencabut berlaku pada permintaan berikutnya. Klien yang dicabut tidak dapat dihidupkan lagi. */
    public function revoke(Request $request, IntegrationClient $integrationClient): JsonResponse
    {
        $client = $this->milik($this->admin($request), $integrationClient, aktif: true);
        $client->fill(['status' => IntegrationClient::REVOKED, 'revoked_at' => now()])->save();

        return response()->json(['data' => $this->present($client)]);
    }

    public function rotateToken(Request $request, IntegrationClient $integrationClient): JsonResponse
    {
        $client = $this->milik($this->admin($request), $integrationClient, aktif: true);
        $rahasia = Str::random(48);
        $client->fill(['token_digest' => IntegrationClient::digest($rahasia)])->save();

        return response()->json(['data' => $this->present($client), 'token' => $client->id.'.'.$rahasia]);
    }

    public function rotateSigningSecret(Request $request, IntegrationClient $integrationClient): JsonResponse
    {
        $client = $this->milik($this->admin($request), $integrationClient, aktif: true);
        if ($client->delivery_mode !== IntegrationClient::PUSH) {
            throw ValidationException::withMessages(['delivery_mode' => 'Signing secret hanya dipakai klien mode push.']);
        }
        $penanda = Str::random(48);
        $client->fill(['signing_secret' => $penanda])->save();

        return response()->json(['data' => $this->present($client), 'signing_secret' => $penanda]);
    }

    /**
     * Mengirim satu kiriman uji dengan signature ke URL push, supaya penerima dapat memastikan
     * verifikasi signature-nya benar sebelum posting sungguhan dikirim.
     */
    public function testPush(Request $request, IntegrationClient $integrationClient, SignedPush $push, ActiveEnvironment $lingkungan): JsonResponse
    {
        $client = $this->milik($this->admin($request), $integrationClient, aktif: true);
        if ($client->delivery_mode !== IntegrationClient::PUSH) {
            throw ValidationException::withMessages(['delivery_mode' => 'Kirim uji hanya untuk klien mode push.']);
        }
        if (! $lingkungan->outboundAllowed()) {
            return response()->json(['data' => ['ok' => false, 'status' => null, 'duration_ms' => 0, 'message' => $lingkungan->refusalReason()]]);
        }

        $badan = json_encode([
            'type' => 'coreerp.integration.test',
            'client_id' => $client->id,
            'sent_at' => now()->toIso8601String(),
        ], JSON_THROW_ON_ERROR);
        $mulai = hrtime(true);

        try {
            $jawaban = $push->send($client, $badan);
            $durasi = (int) round((hrtime(true) - $mulai) / 1_000_000);

            return response()->json(['data' => [
                'ok' => $jawaban->successful(),
                'status' => $jawaban->status(),
                'duration_ms' => $durasi,
                'message' => $jawaban->successful()
                    ? 'Penerima menjawab '.$jawaban->status().'.'
                    : 'Penerima menjawab '.$jawaban->status().'. Periksa verifikasi signature di sisi penerima.',
            ]]);
        } catch (ConnectionException|RuntimeException $kegagalan) {
            return response()->json(['data' => [
                'ok' => false,
                'status' => null,
                'duration_ms' => (int) round((hrtime(true) - $mulai) / 1_000_000),
                'message' => $kegagalan instanceof ConnectionException
                    ? 'Tujuan tidak dapat dijangkau: '.$this->connectionCause($kegagalan)
                    : $kegagalan->getMessage(),
            ]]);
        }
    }

    /**
     * Pesan cURL utuh membawa kode galat, tautan dokumentasi cURL, dan URL tujuan yang sudah tampil
     * di layar. Admin hanya butuh sebabnya, misalnya "Could not resolve host: finance.contoh".
     */
    private function connectionCause(ConnectionException $failure): string
    {
        $previous = $failure->getPrevious();
        $cause = $previous instanceof ConnectException ? ($previous->getHandlerContext()['error'] ?? null) : null;

        return is_string($cause) && $cause !== '' ? $cause : $failure->getMessage();
    }

    /**
     * @return array{name: string, delivery_mode: string, push_url: ?string, scopes: list<string>, allowed_ips: ?list<string>, posting_type_prefixes: ?list<string>}
     */
    private function validated(Request $request, string $tenantId, PushDestination $tujuan, ?IntegrationClient $client = null): array
    {
        $data = $request->validate([
            'name' => [
                'required', 'string', 'max:120',
                Rule::unique('integration_clients', 'name')->where('tenant_id', $tenantId)->ignore($client?->id),
            ],
            'delivery_mode' => ['required', Rule::in([IntegrationClient::PULL, IntegrationClient::PUSH])],
            'push_url' => ['nullable', 'required_if:delivery_mode,push', 'string', 'max:500', 'url:https'],
            'scopes' => ['required', 'array', 'min:1'],
            'scopes.*' => ['string', Rule::in(array_keys(IntegrationClient::SCOPES))],
            'posting_type_prefixes' => ['nullable', 'array', 'max:20'],
            'posting_type_prefixes.*' => ['string', 'max:60', 'regex:/^[a-z0-9_-]+(\.[a-z0-9_-]+)*\.?\*?$/'],
            'allowed_ips' => ['nullable', 'array', 'max:50'],
            'allowed_ips.*' => ['string', 'max:64', function (string $attribute, mixed $value, \Closure $fail): void {
                if (! self::ipAtauCidr((string) $value)) {
                    $fail('Alamat IP atau rentang CIDR tidak valid.');
                }
            }],
        ], [
            'name.unique' => 'Nama klien integrasi sudah dipakai.',
            'push_url.required_if' => 'Mode push membutuhkan URL tujuan.',
            'push_url.url' => 'URL tujuan harus alamat https:// yang lengkap.',
        ]);

        $mode = (string) $data['delivery_mode'];
        $url = $mode === IntegrationClient::PUSH ? (string) $data['push_url'] : null;
        if ($url !== null && ($tolak = $tujuan->reject($url)) !== null) {
            throw ValidationException::withMessages(['push_url' => $tolak]);
        }

        return [
            'name' => trim((string) $data['name']),
            'delivery_mode' => $mode,
            'push_url' => $url,
            'scopes' => array_values(array_unique($data['scopes'])),
            // `asset.*` diterima sebagai ejaan yang lazim untuk awalan `asset.`.
            'allowed_ips' => self::daftar($data['allowed_ips'] ?? null),
            'posting_type_prefixes' => self::daftar(array_map(
                static fn (string $awalan): string => rtrim($awalan, '*'),
                $data['posting_type_prefixes'] ?? [],
            )),
        ];
    }

    /**
     * @param  array<int, string>|null  $nilai
     * @return list<string>|null
     */
    private static function daftar(?array $nilai): ?array
    {
        $bersih = array_values(array_unique(array_filter(array_map('trim', $nilai ?? []), static fn (string $v): bool => $v !== '')));

        return $bersih === [] ? null : $bersih;
    }

    private static function ipAtauCidr(string $nilai): bool
    {
        if (filter_var($nilai, FILTER_VALIDATE_IP) !== false) {
            return true;
        }
        if (! str_contains($nilai, '/')) {
            return false;
        }
        [$alamat, $panjang] = explode('/', $nilai, 2);
        $maksimum = filter_var($alamat, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false ? 128 : 32;

        return filter_var($alamat, FILTER_VALIDATE_IP) !== false
            && ctype_digit($panjang)
            && (int) $panjang <= $maksimum
            && IpUtils::checkIp($alamat, $nilai);
    }

    private function admin(Request $request): TenantMembership
    {
        $membership = $this->currentMembership($request);
        abort_unless($membership->canManageAccess(), 403);

        return $membership;
    }

    private function milik(TenantMembership $membership, IntegrationClient $client, bool $aktif = false): IntegrationClient
    {
        abort_unless($client->tenant_id === $membership->tenant_id, 404);
        if ($aktif && $client->status !== IntegrationClient::ACTIVE) {
            throw ValidationException::withMessages(['status' => 'Klien integrasi ini sudah dicabut. Buat klien baru bila masih dibutuhkan.']);
        }

        return $client;
    }

    /** @return array<string, mixed> */
    private function present(IntegrationClient $client): array
    {
        return [
            'id' => $client->id,
            'name' => $client->name,
            'delivery_mode' => $client->delivery_mode,
            'push_url' => $client->push_url,
            'scopes' => $client->scopes,
            'allowed_ips' => $client->allowed_ips ?? [],
            'posting_type_prefixes' => $client->posting_type_prefixes ?? [],
            'status' => $client->status,
            'has_signing_secret' => $client->signing_secret !== null,
            'last_used_at' => $client->last_used_at?->toIso8601String(),
            'last_pulled_at' => $client->last_pulled_at?->toIso8601String(),
            'revoked_at' => $client->revoked_at?->toIso8601String(),
            'created_at' => $client->created_at?->toIso8601String(),
        ];
    }
}
