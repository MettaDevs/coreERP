<?php

declare(strict_types=1);

namespace ControlPlane\Registry;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Klien API Harbor, dengan kredensial robot sistem dari {@see RegistrySettings}.
 *
 * Hanya tiga hal yang diminta konsol dari Harbor: membuat robot pull-only untuk satu operasi, menghapusnya,
 * dan memastikan robot sistemnya sendiri masih sah. Setiap kegagalan — belum disetel, jaringan, jawaban
 * yang tidak dikenal — menjadi {@see RegistryUnavailable}, dan pesannya tidak pernah memuat rahasia.
 */
final class HarborClient
{
    public function __construct(private readonly RegistrySettings $settings) {}

    /**
     * @return array{id: int, name: string, secret: string, expires_at: CarbonImmutable}
     */
    public function createPullRobot(string $name, int $days, string $description): array
    {
        $response = $this->send(fn (PendingRequest $http): Response => $http->post('/robots', [
            'name' => $name,
            'level' => 'project',
            'duration' => $days,
            'disable' => false,
            'description' => $description,
            'permissions' => [[
                'kind' => 'project',
                'namespace' => $this->settings->project(),
                'access' => [['resource' => 'repository', 'action' => 'pull']],
            ]],
        ]));

        if ($response->status() !== 201) {
            throw new RegistryUnavailable(sprintf('Harbor menolak pembuatan robot (HTTP %d).', $response->status()));
        }

        $id = $response->json('id');
        $fullName = $response->json('name');
        $secret = $response->json('secret');
        $expiresAt = $response->json('expires_at');

        if (! is_int($id) || ! is_string($fullName) || ! is_string($secret) || $secret === '' || ! is_int($expiresAt)) {
            throw new RegistryUnavailable('Jawaban pembuatan robot dari Harbor tidak dikenal.');
        }

        return [
            'id' => $id,
            'name' => $fullName,
            'secret' => $secret,
            'expires_at' => CarbonImmutable::createFromTimestampUTC($expiresAt),
        ];
    }

    /**
     * Robot yang sudah tidak ada dianggap terhapus: penghapusan diulang setiap kali agen menyambung, dan
     * robot yang sudah dihapus tangan operator atau kedaluwarsa tidak boleh membuatnya macet.
     */
    public function deleteRobot(int $id): void
    {
        $response = $this->send(fn (PendingRequest $http): Response => $http->delete('/robots/'.$id));

        if (! in_array($response->status(), [200, 404], true)) {
            throw new RegistryUnavailable(sprintf('Harbor menolak penghapusan robot %d (HTTP %d).', $id, $response->status()));
        }
    }

    /**
     * Robot sistem sah bila Harbor menunjukkan project registry yang privat kepadanya.
     *
     * Status HTTP saja tidak cukup, dan ini diukur, bukan ditebak: Harbor v2.15.2 menjawab `GET /projects`
     * dengan kredensial yang **salah** sebagai `200 []` — permintaan diperlakukan anonim, dan anonim tidak
     * melihat project privat. Yang membuktikan kredensialnya diterima adalah project itu ada di jawabannya.
     */
    public function verifyRobot(): void
    {
        $project = $this->settings->project();
        $response = $this->send(fn (PendingRequest $http): Response => $http->get('/projects', ['name' => $project]));

        if ($response->status() === 401 || $response->status() === 403) {
            throw new RegistryUnavailable('Harbor menolak kredensial robot sistem; putar rahasianya lewat RUNBOOK registry.');
        }

        $names = $response->successful() ? array_column((array) $response->json(), 'name') : null;

        if (! is_array($names)) {
            throw new RegistryUnavailable(sprintf('Harbor menjawab HTTP %d saat project dibaca.', $response->status()));
        }

        if (! in_array($project, $names, true)) {
            throw new RegistryUnavailable(sprintf(
                'Harbor tidak menunjukkan project %s kepada robot sistem: rahasianya salah, robotnya sudah dihapus, atau izinnya tidak mencakup project itu.',
                $project,
            ));
        }
    }

    /** @param  callable(PendingRequest): Response  $call */
    private function send(callable $call): Response
    {
        $robot = $this->settings->robot();

        if ($robot === null) {
            throw new RegistryUnavailable('Robot sistem Harbor belum disetel di konsol ini (php artisan registry:robot-sistem).');
        }

        $http = Http::baseUrl(rtrim((string) config('sites.registry_api_url'), '/').'/api/v2.0')
            ->withBasicAuth($robot['name'], $robot['secret'])
            ->acceptJson()
            ->connectTimeout(3)
            ->timeout(15);

        try {
            return $call($http);
        } catch (ConnectionException) {
            throw new RegistryUnavailable('Harbor tidak dapat dihubungi dari konsol ini.');
        }
    }
}
