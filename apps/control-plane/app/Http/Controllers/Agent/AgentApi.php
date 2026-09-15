<?php

declare(strict_types=1);

namespace ControlPlane\Http\Controllers\Agent;

use ControlPlane\Http\Controllers\Controller;
use ControlPlane\Http\Middleware\VerifyAgentSignature;
use ControlPlane\Models\Site;
use ControlPlane\Models\SiteOperation;
use ControlPlane\Models\SiteRelease;
use ControlPlane\Sites\EnrollmentTokens;
use ControlPlane\Sites\LicenseIssuer;
use ControlPlane\Sites\LicenseRenewal;
use ControlPlane\Sites\SiteOperations;
use ControlPlane\Sites\SitePublicKey;
use ControlPlane\Sites\SiteRejected;
use ControlPlane\Sites\SiteReports;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Seluruh endpoint agen situs, satu method per operasi di `contracts/openapi-agent.yaml`.
 *
 * Satu kelas, bukan enam, karena keenamnya satu percakapan dengan pihak yang sama: agen yang sudah
 * lolos `VerifyAgentSignature`, kecuali `enroll`. Membacanya berurutan di satu berkas lebih mudah
 * dicocokkan dengan kontraknya daripada enam berkas yang masing-masing memuat separuh cerita.
 *
 * Jawaban galat selalu `{"error": "<kode>"}`. Kalimat penolakan untuk operator tidak dikirim ke agen:
 * agen tidak menampilkannya kepada siapa pun, dan kalimatnya dapat berubah tanpa kontrak berubah.
 */
final class AgentApi extends Controller
{
    public function enroll(Request $request, EnrollmentTokens $tokens, LicenseIssuer $licenses): JsonResponse
    {
        $body = $request->json()->all();

        if (array_diff(array_keys($body), ['token', 'public_key', 'agent_version']) !== []
            || ! is_string($body['token'] ?? null) || strlen($body['token']) < 32
            || ! is_string($body['public_key'] ?? null)
            || ! is_string($body['agent_version'] ?? null) || strlen($body['agent_version']) > 40) {
            return response()->json(['error' => 'invalid_request'], 422);
        }

        try {
            $site = $tokens->redeem($body['token'], $body['public_key']);
        } catch (SiteRejected $e) {
            Log::info('Agen situs: pendaftaran ditolak.', ['sebab' => $e->reason]);

            return $e->reason === 'public_key_invalid'
                ? response()->json(['error' => 'public_key_invalid'], 422)
                : response()->json(['error' => 'enrollment_rejected'], 401);
        }

        Log::info('Agen situs: terdaftar.', ['situs' => $site->id, 'versi_agen' => $body['agent_version']]);

        return response()->json([
            'site_id' => $site->id,
            'tenant_id' => $site->tenant_id,
            'tenant_name' => (string) $site->tenant()->value('name'),
            'interval_seconds' => (int) config('sites.interval_seconds'),
            'update_window' => $site->updateWindow(),
            'license_public_key' => $licenses->publicKey(),
        ], 201);
    }

    public function report(Request $request, SiteReports $reports, LicenseRenewal $renewal): JsonResponse
    {
        $site = $this->site($request);

        try {
            $report = $reports->validate($request->json()->all(), $site);
        } catch (SiteRejected $e) {
            Log::warning('Agen situs: laporan ditolak.', ['situs' => $site->id, 'sebab' => $e->getMessage()]);

            return response()->json(['error' => 'report_invalid'], 422);
        }

        $reports->record($site, $report);

        $answer = ['interval_seconds' => (int) config('sites.interval_seconds')];

        // Sesudah laporan tercatat, dan tanpa pernah melempar: lisensi yang gagal diterbitkan hanya
        // berarti jawaban tanpa `license`, bukan laporan yang hilang. Lihat `LicenseRenewal`.
        $license = $renewal->licenseFor($site, $report, $request->ip());

        if ($license !== null) {
            $answer['license'] = $license;
        }

        return response()->json($answer);
    }

    public function claim(Request $request, SiteOperations $operations): Response
    {
        $site = $this->site($request);

        if ($request->json()->all() !== []) {
            return response()->json(['error' => 'invalid_request'], 422);
        }

        $operation = $operations->claim($site);

        if (! $operation instanceof SiteOperation) {
            return response()->noContent();
        }

        return response()->json([
            'id' => $operation->id,
            'operation' => $operation->operation,
            'parameters' => (object) $operation->parameters,
            'lease_until' => $operation->lease_until?->toIso8601String(),
        ]);
    }

    public function step(Request $request, SiteOperations $operations, string $operation): JsonResponse
    {
        $site = $this->site($request);
        $body = $request->json()->all();

        if (array_diff(array_keys($body), ['status', 'step', 'failure_message']) !== []
            || ! in_array($body['status'] ?? null, ['running', 'succeeded', 'failed'], true)
            || ! is_string($body['step'] ?? null) || $body['step'] === '' || mb_strlen($body['step']) > 120
            || (array_key_exists('failure_message', $body) && (! is_string($body['failure_message']) || mb_strlen($body['failure_message']) > 4000))) {
            return response()->json(['error' => 'invalid_request'], 422);
        }

        try {
            $recorded = $operations->recordStep($site, $operation, $body['status'], $body['step'], $body['failure_message'] ?? null);
        } catch (SiteRejected $e) {
            return response()->json(['error' => $e->reason], 409);
        }

        return response()->json([
            'lease_until' => $recorded->status === 'running' ? $recorded->lease_until?->toIso8601String() : null,
        ]);
    }

    public function releaseFile(Request $request, string $edition, string $release, string $file): Response
    {
        $site = $this->site($request);

        // Hanya edisi situs ini. Rilis edisi lain memuat modul yang tidak dibeli klien ini.
        if ($edition !== $site->edition || ! array_key_exists($file, SiteRelease::FILES)) {
            return response()->json(['error' => 'not_found'], 404);
        }

        $registered = SiteRelease::query()->where('edition', $edition)->where('release', $release)->first();
        $contents = $registered?->fileContents($file);

        if ($contents === null) {
            return response()->json(['error' => 'not_found'], 404);
        }

        return response($contents, 200, [
            'Content-Type' => 'application/octet-stream',
            'Cache-Control' => 'no-store',
        ]);
    }

    public function rotateKey(Request $request): Response
    {
        $site = $this->site($request);
        $body = $request->json()->all();

        if (array_keys($body) !== ['public_key'] || ! SitePublicKey::acceptable($body['public_key'])) {
            return response()->json(['error' => 'public_key_invalid'], 422);
        }

        $site->forceFill(['public_key' => $body['public_key']])->save();

        Log::info('Agen situs: kunci diganti.', ['situs' => $site->id]);

        return response()->noContent(200);
    }

    private function site(Request $request): Site
    {
        $site = $request->attributes->get(VerifyAgentSignature::SITE);

        abort_unless($site instanceof Site, 401);

        return $site;
    }
}
