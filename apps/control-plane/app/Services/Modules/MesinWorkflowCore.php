<?php

declare(strict_types=1);

namespace App\Services\Modules;

use App\Support\Modules\Contracts\MesinWorkflow;
use App\Support\WorkflowRuntime;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
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
final class MesinWorkflowCore implements MesinWorkflow
{
    public function __construct(private readonly WorkflowRuntime $mesin) {}

    /**
     * @param  array{legal_entity_id?: ?string, source_document_type: string, source_document_id: string, decision_context: array<string, mixed>}  $data
     * @return array{id: string, status: string, terulang: bool}
     */
    public function ajukan(
        string $tenantId,
        string $appId,
        string $kodeTipe,
        string $idPenggunaPengaju,
        string $idKorelasi,
        string $kunciIdempoten,
        array $data,
    ): array {
        $tipe = DB::table('workflow_types')->where('code', $kodeTipe)->where('app_id', $appId)->first();

        if ($tipe === null) {
            throw new RuntimeException(sprintf('Jenis workflow "%s" tidak terdaftar untuk app %s.', $kodeTipe, $appId));
        }

        $lingkup = $tipe->scope ?? 'legal_entity';
        $legalEntityId = $data['legal_entity_id'] ?? null;

        if ($lingkup === 'legal_entity' && ($legalEntityId === null || $legalEntityId === '')) {
            // Tanpa pemeriksaan ini, pencarian versi di bawah mencari konfigurasi dengan
            // `legal_entity_id` null, tidak menemukannya, lalu gagal dengan "belum ada
            // workflow aktif" — pesan yang menyuruh admin membuat workflow yang sebenarnya
            // sudah ada.
            throw ValidationException::withMessages([
                'legal_entity_id' => 'Workflow ini berlaku per entitas legal, jadi entitasnya harus disebut.',
            ]);
        }

        $idKeanggotaan = DB::table('tenant_memberships')
            ->where('tenant_id', $tenantId)
            ->where('user_id', $idPenggunaPengaju)
            ->where('status', 'active')
            ->value('id');

        if (! is_string($idKeanggotaan) || $idKeanggotaan === '') {
            throw ValidationException::withMessages(['pengaju' => 'Pengaju workflow tidak valid.']);
        }

        $this->periksaKonteksKeputusan($tipe, $data['decision_context']);

        $terdahulu = $this->instance($tenantId, (string) $tipe->id, $kunciIdempoten);

        if ($terdahulu !== null) {
            return ['id' => (string) $terdahulu->id, 'status' => (string) $terdahulu->status, 'terulang' => true];
        }

        $versi = $this->versiBerlaku($tenantId, $tipe, $lingkup, $legalEntityId);

        $isi = $data + [
            'initiator_membership_id' => $idKeanggotaan,
            'correlation_id' => $idKorelasi,
        ];

        try {
            /** @var object{id: string, status: string} $instance */
            $instance = $this->mesin->submit($tenantId, $tipe, $versi, $kunciIdempoten, $isi);
        } catch (QueryException $kegagalan) {
            // Dua permintaan dengan kunci idempoten yang sama bisa lolos pemeriksaan di atas
            // bersama-sama; yang kalah dijawab indeks unik. Membacanya kembali di sini
            // membuat keduanya memulangkan instance yang sama, bukan satu jawaban dan satu
            // kegagalan yang tidak bisa dijelaskan ke pemanggil.
            if ($kegagalan->getCode() !== '23505') {
                throw $kegagalan;
            }

            $instance = $this->instance($tenantId, (string) $tipe->id, $kunciIdempoten)
                ?? throw $kegagalan;

            return ['id' => (string) $instance->id, 'status' => (string) $instance->status, 'terulang' => true];
        }

        return ['id' => (string) $instance->id, 'status' => (string) $instance->status, 'terulang' => false];
    }

    private function instance(string $tenantId, string $tipeId, string $kunciIdempoten): ?stdClass
    {
        return DB::table('workflow_instances')
            ->where(['tenant_id' => $tenantId, 'workflow_type_id' => $tipeId, 'idempotency_key' => $kunciIdempoten])
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
     * @param  array<string, mixed>  $konteks
     */
    private function periksaKonteksKeputusan(stdClass $tipe, array $konteks): void
    {
        /** @var array<mixed> $skema */
        $skema = json_decode((string) $tipe->decision_context_schema, true, 512, JSON_THROW_ON_ERROR);
        $wajib = $skema['required'] ?? [];

        foreach (is_array($wajib) ? $wajib : [] as $field) {
            if (! is_string($field)) {
                continue;
            }

            if (! array_key_exists($field, $konteks) || $konteks[$field] === null) {
                throw ValidationException::withMessages([
                    'decision_context' => sprintf('Data %s wajib dikirim untuk workflow ini.', $field),
                ]);
            }
        }
    }

    private function versiBerlaku(string $tenantId, stdClass $tipe, string $lingkup, ?string $legalEntityId): stdClass
    {
        $versi = DB::table('workflow_configuration_versions as versions')
            ->join('workflow_configurations as configurations', 'configurations.id', '=', 'versions.configuration_id')
            ->where('configurations.tenant_id', $tenantId)
            ->where('configurations.workflow_type_id', $tipe->id)
            ->when($lingkup === 'legal_entity', fn ($query) => $query->where('configurations.legal_entity_id', $legalEntityId))
            ->when($lingkup === 'tenant', fn ($query) => $query->whereNull('configurations.legal_entity_id'))
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

        if ($versi === null) {
            throw new RuntimeException('Belum ada workflow aktif untuk dokumen ini.');
        }

        return $versi;
    }
}
