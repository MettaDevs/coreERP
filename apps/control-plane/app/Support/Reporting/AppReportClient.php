<?php

namespace App\Support\Reporting;

use App\Models\TenantMembership;
use App\Support\AppContextToken;
use App\Support\DataPolicyAccessResolver;
use App\Support\LaunchableAppCatalog;
use App\Support\Reporting\Rendering\RenderException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use stdClass;

/**
 * Klien Core ke endpoint laporan milik app (`internal/v1/laporan/...`).
 *
 * Setiap panggilan membawa token konteks yang diterbitkan untuk membership pengguna
 * yang meminta — bukan token service Core — sehingga app menegakkan permission dan
 * scope organisasi pengguna itu persis seperti pada layar biasa. Core tidak pernah
 * membaca database app; ia hanya menerima dataset yang sudah disaring app.
 */
final class AppReportClient
{
    public function __construct(
        private readonly LaunchableAppCatalog $apps,
        private readonly AppContextToken $tokens,
        private readonly DataPolicyAccessResolver $policies,
    ) {}

    /**
     * Placeholder dan aturan parameter laporan, dari app.
     *
     * @return array{fields: list<array{key:string,label:string,table:?string}>, parameters: list<string>}
     */
    public function definition(stdClass $report, TenantMembership $membership, ?string $legalEntityId, ?string $orgUnitId): array
    {
        $response = $this->request($report, $membership, $legalEntityId, $orgUnitId)
            ->get($this->url($report, ''));
        $data = $this->json($response, $report)['data'] ?? [];

        return [
            'fields' => array_values(array_filter($data['fields'] ?? [], fn (mixed $field): bool => is_array($field) && isset($field['key'], $field['label']))),
            'parameters' => array_values(array_filter($data['parameters'] ?? [], 'is_string')),
        ];
    }

    /** Isi berkas layout bawaan yang ikut release app. */
    public function builtinLayout(stdClass $report, string $key, TenantMembership $membership, ?string $legalEntityId, ?string $orgUnitId): string
    {
        $response = $this->request($report, $membership, $legalEntityId, $orgUnitId)
            ->get($this->url($report, '/layouts/'.rawurlencode($key)));
        if (! $response->successful()) {
            throw new RenderException("Layout bawaan `{$key}` tidak tersedia dari {$report->app_name} (".$response->status().').');
        }

        return $response->body();
    }

    /** @param array<string, mixed> $parameters */
    public function dataset(stdClass $report, TenantMembership $membership, ?string $legalEntityId, ?string $orgUnitId, array $parameters): ReportData
    {
        $response = $this->request($report, $membership, $legalEntityId, $orgUnitId)
            ->post($this->url($report, '/dataset'), ['parameter' => (object) $parameters]);
        $body = $this->json($response, $report);

        return ReportData::fromArray(is_array($body['data'] ?? null) ? $body['data'] : []);
    }

    private function request(stdClass $report, TenantMembership $membership, ?string $legalEntityId, ?string $orgUnitId): PendingRequest
    {
        $token = $this->tokens->issue(
            $membership,
            $report->app_id,
            $this->apps->permissionsFor($membership, $report->app_id),
            $legalEntityId,
            $orgUnitId,
            $this->policies->resolve($membership),
        );

        return Http::acceptJson()
            ->withToken($token)
            ->connectTimeout(5)
            ->timeout((int) config('reporting.app_timeout'));
    }

    private function url(stdClass $report, string $suffix): string
    {
        $base = $this->baseUrl($report);
        $localCode = substr($report->code, strlen($report->app_id) + 1);

        return rtrim($base, '/').'/api/internal/v1/laporan/'.rawurlencode($localCode).$suffix;
    }

    /**
     * Alamat API app: override dari konfigurasi, atau nama service API pada release
     * yang terpasang. Pada Compose nama service dapat di-resolve dari container Core.
     */
    private function baseUrl(stdClass $report): string
    {
        $configured = config('reporting.app_api_endpoints')[$report->app_id] ?? null;
        if (is_string($configured) && $configured !== '') {
            return $configured;
        }
        $service = DB::table('app_placements as placements')
            ->join('app_releases as releases', function ($join): void {
                $join->on('releases.app_id', '=', 'placements.app_id')
                    ->on('releases.version', '=', 'placements.release_version');
            })
            ->where('placements.app_id', $report->app_id)
            ->where('placements.runtime_status', 'ready')
            ->orderByDesc('placements.updated_at')
            ->value('releases.api_service');
        if (! is_string($service) || $service === '') {
            throw new RenderException("Alamat API {$report->app_name} tidak diketahui; app belum terpasang atau belum siap.");
        }

        return 'http://'.$service;
    }

    /** @return array<string, mixed> */
    private function json(Response $response, stdClass $report): array
    {
        if ($response->status() === 403) {
            throw new RenderException("{$report->app_name} menolak permintaan: Anda tidak berhak membaca data laporan ini.");
        }
        if ($response->status() === 422) {
            $message = $response->json('message') ?? $response->json('error.message') ?? 'parameter laporan tidak valid';

            throw new RenderException("{$report->app_name} menolak parameter laporan: {$message}");
        }
        if (! $response->successful()) {
            throw new RenderException("{$report->app_name} tidak dapat menyiapkan data laporan (".$response->status().').');
        }
        $body = $response->json();

        return is_array($body) ? $body : [];
    }

    /** Membungkus kegagalan koneksi menjadi pesan untuk pengguna. */
    public static function guard(callable $call, stdClass $report): mixed
    {
        try {
            return $call();
        } catch (ConnectionException $exception) {
            throw new RenderException("{$report->app_name} tidak dapat dihubungi untuk menyiapkan data laporan.", previous: $exception);
        }
    }
}
