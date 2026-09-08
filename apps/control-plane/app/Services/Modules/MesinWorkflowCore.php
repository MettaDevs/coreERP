<?php

declare(strict_types=1);

namespace App\Services\Modules;

use App\Support\Modules\Contracts\MesinWorkflow;
use App\Support\WorkflowRuntime;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Mencari tipe workflow dan versi yang berlaku, lalu menyerahkannya ke mesin Core.
 *
 * Mesin Core menerima objek tipe dan objek versi. Pencarian keduanya sebelumnya hidup di
 * controller internal — artinya module yang memanggilnya lewat HTTP tidak pernah perlu tahu
 * caranya. Setelah panggilan itu menjadi pemanggilan fungsi, pencariannya harus punya rumah,
 * dan rumahnya di sini; bukan disalin ke setiap module.
 */
final class MesinWorkflowCore implements MesinWorkflow
{
    public function __construct(private readonly WorkflowRuntime $mesin) {}

    /**
     * @param  array<string, mixed>  $data
     * @return array{id: string, status: string}
     */
    public function ajukan(string $tenantId, string $appId, string $kodeTipe, string $kunciIdempoten, array $data): array
    {
        $tipe = DB::table('workflow_types')->where('code', $kodeTipe)->where('app_id', $appId)->first();

        if ($tipe === null) {
            throw new RuntimeException(sprintf('Jenis workflow "%s" tidak terdaftar untuk app %s.', $kodeTipe, $appId));
        }

        $lingkup = $tipe->scope ?? 'legal_entity';

        $versi = DB::table('workflow_configuration_versions as versions')
            ->join('workflow_configurations as configurations', 'configurations.id', '=', 'versions.configuration_id')
            ->where('configurations.tenant_id', $tenantId)
            ->where('configurations.workflow_type_id', $tipe->id)
            ->when($lingkup === 'legal_entity', fn ($query) => $query->where('configurations.legal_entity_id', $data['legal_entity_id'] ?? null))
            ->when($lingkup === 'tenant', fn ($query) => $query->whereNull('configurations.legal_entity_id'))
            ->where('configurations.enabled', true)
            ->where('versions.status', 'published')
            ->where(fn ($query) => $query->whereNull('versions.effective_from')->orWhere('versions.effective_from', '<=', today()))
            ->orderByDesc('versions.effective_from')
            ->first();

        if ($versi === null) {
            throw new RuntimeException('Belum ada workflow aktif untuk dokumen ini.');
        }

        /** @var object{id: string, status: string} $instance */
        $instance = $this->mesin->submit($tenantId, $tipe, $versi, $kunciIdempoten, $data);

        return ['id' => (string) $instance->id, 'status' => (string) $instance->status];
    }
}
