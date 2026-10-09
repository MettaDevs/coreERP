<?php

declare(strict_types=1);

namespace App\Foundation\Workflow\ModuleServices;

use App\Foundation\Workflow\Support\WorkflowRuntime;
use App\Platform\Modules\Contracts\WorkflowEngine;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use stdClass;

/**
 * Mencari tipe workflow dan versi yang berlaku, lalu menyerahkannya ke mesin Core.
 *
 * Mesin Core menerima objek tipe dan objek versi. Pencarian keduanya sebelumnya hidup di
 * controller internal — artinya module yang memanggilnya lewat HTTP tidak pernah perlu tahu
 * caranya. Setelah panggilan itu menjadi pemanggilan fungsi, pencariannya harus punya rumah,
 * dan rumahnya di sini; bukan disalin ke setiap module.
 *
 * **Yang ditambahkan pada F3-09 adalah pemeriksaan yang dulu dilakukan endpoint, bukan mesin.**
 * Versi pertama kelas ini hanya memindahkan pencarian tipe dan versi, karena itu yang terlihat
 * dari sisi mesin. Membaca endpoint yang digantikannya memperlihatkan empat pemeriksaan lain
 * yang selama ini menjaga pemanggilnya, dan tidak satu pun di antaranya berada di mesin:
 *
 * 1. Pengaju harus keanggotaan aktif pada tenant tersebut.
 * 2. Workflow ber-scope entitas legal wajib menyebut entitasnya.
 * 3. `decision_context` wajib memuat field yang diminta skema tipenya.
 * 4. Kunci idempoten yang berulang memulangkan instance lama, bukan membuat yang kedua.
 *
 * Tanpa keempatnya, module yang berpindah dari HTTP ke kontrak justru **kehilangan** penjaga
 * yang sudah dimilikinya — persis kebalikan dari tujuan pemindahan.
 *
 * **Controller internal `InternalWorkflowInstanceController` belum memanggil kelas ini.** Ia
 * masih menyalin logika yang sama untuk app yang benar-benar berada di luar proses, dan dua
 * salinan pasti menyimpang. Menyatukannya bagian dari F3-20, bersama kasus serupa pada
 * kalender fiskal.
 */
final class WorkflowEngineCore implements WorkflowEngine
{
    public function __construct(private readonly WorkflowRuntime $runtime) {}

    public function withdraw(string $tenantId, string $appId, string $instanceId, string $actorUserId): void
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('Penutupan workflow harus berada dalam transaksi dokumen sumber.');
        }
        $membershipId = DB::table('tenant_memberships')->where('tenant_id', $tenantId)->where('user_id', $actorUserId)->where('status', 'active')->value('id');
        if ($membershipId === null) {
            throw ValidationException::withMessages(['workflow' => 'Pengguna pembatalan tidak aktif pada tenant ini.']);
        }
        $instance = DB::table('workflow_instances')->where('tenant_id', $tenantId)->where('id', $instanceId)
            ->whereIn('workflow_type_id', DB::table('workflow_types')->where('app_id', $appId)->select('id'))->lockForUpdate()->first();
        if ($instance === null || $instance->status !== 'pending') {
            return;
        }
        DB::table('workflow_instances')->where('id', $instanceId)->update(['status' => 'cancelled', 'updated_at' => now()]);
        DB::table('workflow_work_items')->where('instance_id', $instanceId)->where('status', 'pending')->update(['status' => 'cancelled', 'completed_at' => now(), 'updated_at' => now()]);
        DB::table('workflow_history')->insert(['id' => (string) Str::ulid(), 'tenant_id' => $tenantId,
            'instance_id' => $instanceId, 'actor_membership_id' => $membershipId, 'event_type' => 'cancelled',
            'details' => json_encode(['reason' => 'Transaksi sumber ditangani langsung oleh pengguna berwenang.']), 'occurred_at' => now()]);
    }

    /**
     * @param  array{legal_entity_id?: ?string, source_document_type: string, source_document_id: string, decision_context: array<string, mixed>}  $data
     * @return array{id: string, status: string, terulang: bool}
     */
    public function submit(
        string $tenantId,
        string $appId,
        string $typeCode,
        string $submitterUserId,
        string $correlationId,
        string $idempotencyKey,
        array $data,
    ): array {
        $type = DB::table('workflow_types')->where('code', $typeCode)->where('app_id', $appId)->first();

        if ($type === null) {
            throw new RuntimeException(sprintf('Jenis workflow "%s" tidak terdaftar untuk app %s.', $typeCode, $appId));
        }

        $scope = $type->scope ?? 'legal_entity';
        $legalEntityId = $data['legal_entity_id'] ?? null;

        if ($scope === 'legal_entity' && ($legalEntityId === null || $legalEntityId === '')) {
            // Tanpa pemeriksaan ini, pencarian versi di bawah mencari konfigurasi dengan
            // `legal_entity_id` null, tidak menemukannya, lalu gagal dengan "belum ada
            // workflow aktif" — pesan yang menyuruh admin membuat workflow yang sebenarnya
            // sudah ada.
            throw ValidationException::withMessages([
                'legal_entity_id' => 'Workflow ini berlaku per entitas legal, jadi entitasnya harus disebut.',
            ]);
        }

        $membershipId = DB::table('tenant_memberships')
            ->where('tenant_id', $tenantId)
            ->where('user_id', $submitterUserId)
            ->where('status', 'active')
            ->value('id');

        if (! is_string($membershipId) || $membershipId === '') {
            throw ValidationException::withMessages(['pengaju' => 'Pengaju workflow tidak valid.']);
        }

        $this->checkDecisionContext($type, $data['decision_context']);

        $previous = $this->instance($tenantId, (string) $type->id, $idempotencyKey);

        if ($previous !== null) {
            return ['id' => (string) $previous->id, 'status' => (string) $previous->status, 'terulang' => true];
        }

        $version = $this->effectiveVersion($tenantId, $type, $scope, $legalEntityId);

        $payload = $data + [
            'initiator_membership_id' => $membershipId,
            'correlation_id' => $correlationId,
        ];

        try {
            /** @var object{id: string, status: string} $instance */
            $instance = $this->runtime->submit($tenantId, $type, $version, $idempotencyKey, $payload);
        } catch (QueryException $exception) {
            // Dua permintaan dengan kunci idempoten yang sama bisa lolos pemeriksaan di atas
            // bersama-sama; yang kalah dijawab indeks unik. Membacanya kembali di sini
            // membuat keduanya memulangkan instance yang sama, bukan satu jawaban dan satu
            // kegagalan yang tidak bisa dijelaskan ke pemanggil.
            if ($exception->getCode() !== '23505') {
                throw $exception;
            }

            $instance = $this->instance($tenantId, (string) $type->id, $idempotencyKey)
                ?? throw $exception;

            return ['id' => (string) $instance->id, 'status' => (string) $instance->status, 'terulang' => true];
        }

        return ['id' => (string) $instance->id, 'status' => (string) $instance->status, 'terulang' => false];
    }

    private function instance(string $tenantId, string $typeId, string $idempotencyKey): ?stdClass
    {
        return DB::table('workflow_instances')
            ->where(['tenant_id' => $tenantId, 'workflow_type_id' => $typeId, 'idempotency_key' => $idempotencyKey])
            ->first();
    }

    /**
     * Field yang diwajibkan skema tipenya harus benar-benar ada dan terisi.
     *
     * Skema inilah yang dipakai admin saat menyusun langkah kondisi di perancang workflow:
     * sebuah kondisi menunjuk salah satu field di dalamnya. Field yang tidak dikirim membuat
     * kondisi itu dievaluasi terhadap `null`, dan cabangnya diambil karena kebetulan, bukan
     * karena datanya.
     *
     * @param  array<string, mixed>  $context
     */
    private function checkDecisionContext(stdClass $type, array $context): void
    {
        /** @var array<mixed> $schema */
        $schema = json_decode((string) $type->decision_context_schema, true, 512, JSON_THROW_ON_ERROR);
        $required = $schema['required'] ?? [];

        foreach (is_array($required) ? $required : [] as $field) {
            if (! is_string($field)) {
                continue;
            }

            if (! array_key_exists($field, $context) || $context[$field] === null) {
                throw ValidationException::withMessages([
                    'decision_context' => sprintf('Data %s wajib dikirim untuk workflow ini.', $field),
                ]);
            }
        }
    }

    private function effectiveVersion(string $tenantId, stdClass $type, string $scope, ?string $legalEntityId): stdClass
    {
        $version = DB::table('workflow_configuration_versions as versions')
            ->join('workflow_configurations as configurations', 'configurations.id', '=', 'versions.configuration_id')
            ->where('configurations.tenant_id', $tenantId)
            ->where('configurations.workflow_type_id', $type->id)
            ->when($scope === 'legal_entity', fn ($query) => $query->where('configurations.legal_entity_id', $legalEntityId))
            ->when($scope === 'tenant', fn ($query) => $query->whereNull('configurations.legal_entity_id'))
            ->where('configurations.enabled', true)
            ->where('versions.status', 'published')
            ->where(fn ($query) => $query->whereNull('versions.effective_from')->orWhere('versions.effective_from', '<=', today()))
            ->orderByDesc('versions.effective_from')
            // Kolomnya disebut satu per satu, dan itu bukan gaya. `select *` pada join ini
            // memulangkan `id` milik `workflow_configurations` — namanya sama, dan yang
            // belakangan menimpa yang duluan. Instance lalu dibuat menunjuk id konfigurasi
            // sebagai versinya, dan database menolaknya sebagai pelanggaran kunci asing.
            //
            // Salinan query ini di `InternalWorkflowInstanceController` membawa cacat yang
            // sama sejak awal, dan tidak ada yang melihatnya: satu-satunya test yang menyentuh
            // jalur itu memalsukan jawaban Core.
            ->first(['versions.*']);

        if ($version === null) {
            throw new RuntimeException('Belum ada workflow aktif untuk dokumen ini.');
        }

        return $version;
    }
}
