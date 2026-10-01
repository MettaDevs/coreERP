<?php

namespace Modules\Apperp\ManagementAset\Http\Controllers\master;

use App\Platform\Modules\Contracts\RowVersion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Modules\Apperp\ManagementAset\Http\Controllers\Controller;
use Modules\Apperp\ManagementAset\Models\master\CounterType;
use Modules\Apperp\ManagementAset\Models\master\MaintenanceJobType;
use Modules\Apperp\ManagementAset\Models\master\MaintenanceJobTypeVariant;
use Modules\Apperp\ManagementAset\Models\master\MaintenancePlan;
use Modules\Apperp\ManagementAset\Models\master\MaintenancePlanLine;
use Modules\Apperp\ManagementAset\Models\master\MaintenancePlanTarget;
use Modules\Apperp\ManagementAset\Models\master\TingkatLayanan;
use Modules\Apperp\ManagementAset\Models\master\TipeWorkOrder;
use Modules\Apperp\ManagementAset\Models\master\Trade;
use Modules\Apperp\ManagementAset\Support\MaintenancePlanBasis;

/**
 * Baris dan objek rencana pemeliharaan, disunting di dalam form rencana.
 *
 * **Diperbarui di tempat, bukan dihapus lalu disisipkan ulang** seperti baris template checklist.
 * Usulan jadwal menunjuk id baris rencana, dan keunikan usulan berkunci id itu: baris yang
 * berganti id setiap kali rencana disimpan akan melahirkan usulan kembar untuk jatuh tempo yang
 * sudah diusulkan. Baris yang dikeluarkan dari kiriman diarsipkan.
 *
 * Keduanya mengklaim versi header rencana, jadi dua orang yang menyunting rencana yang sama tidak
 * saling menimpa tanpa pesan.
 */
final class MaintenancePlanDetailController extends Controller
{
    private const RESOURCE = 'rencana-pemeliharaan';

    public function lines(Request $request, string $id): JsonResponse
    {
        $this->permission($request, 'read');
        $plan = MaintenancePlan::query()->findOrFail($id);

        return response()->json(['data' => $this->presentLines($id), 'version' => $plan->version]);
    }

    public function replaceLines(Request $request, string $id): JsonResponse
    {
        $this->permission($request, 'update');
        $tenant = $this->tenant($request);
        MaintenancePlan::query()->findOrFail($id);
        $data = $request->validate([
            'lines' => ['present', 'array', 'max:50'],
            'lines.*.id' => ['nullable', 'ulid'],
            'lines.*.dasar' => ['required', Rule::in(MaintenancePlanBasis::ALL)],
            'lines.*.interval' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'lines.*.satuan_interval' => ['nullable', Rule::in(MaintenancePlanBasis::UNITS)],
            'lines.*.jenis_counter_id' => ['nullable', 'ulid'],
            'lines.*.interval_counter' => ['nullable', 'numeric', 'gt:0', 'max:9999999999999999'],
            'lines.*.toleransi_counter' => ['nullable', 'numeric', 'min:0', 'max:9999999999999999'],
            'lines.*.maintenance_job_type_id' => ['required', 'ulid'],
            'lines.*.variant_id' => ['nullable', 'ulid'],
            'lines.*.trade_id' => ['nullable', 'ulid'],
            'lines.*.tipe_work_order_id' => ['required', 'ulid'],
            'lines.*.tingkat_layanan_id' => ['nullable', 'ulid'],
            'lines.*.selesai_dalam_hari' => ['nullable', 'integer', 'min:0', 'max:3650'],
            'lines.*.deskripsi' => ['nullable', 'string', 'max:2000'],
            'lines.*.aktif' => ['sometimes', 'boolean'],
        ]);
        $lines = array_values($data['lines']);
        $this->validateLines($lines);
        $expected = RowVersion::expected($request);

        DB::transaction(function () use ($id, $lines, $expected, $tenant): void {
            RowVersion::claim(MaintenancePlan::query()->findOrFail($id), $expected);
            $existing = MaintenancePlanLine::query()->where('rencana_pemeliharaan_id', $id)->get()->keyBy('id');
            $kept = array_values(array_filter(array_column($lines, 'id')));
            $unknown = array_diff($kept, $existing->keys()->all());
            if ($unknown !== []) {
                throw ValidationException::withMessages(['lines' => 'Baris rencana tidak ditemukan pada rencana ini. Muat ulang lalu ulangi.']);
            }

            // Yang dikeluarkan diarsipkan lebih dahulu, supaya nomor barisnya boleh dipakai baris lain.
            MaintenancePlanLine::query()->where('rencana_pemeliharaan_id', $id)->whereNotIn('id', $kept)->delete();
            // Nomor baris disusun ulang dalam dua langkah: nilai sementara yang tidak mungkin bentrok
            // lebih dahulu, karena indeks unik nomor baris memeriksa setiap UPDATE satu per satu.
            MaintenancePlanLine::query()->where('rencana_pemeliharaan_id', $id)->update(['line_number' => DB::raw('line_number + 100000')]);

            foreach ($lines as $index => $line) {
                $values = $this->lineValues($line, $index + 1);
                if (($line['id'] ?? null) !== null) {
                    MaintenancePlanLine::query()->whereKey($line['id'])->update($values);
                } else {
                    MaintenancePlanLine::query()->create([...$values, 'tenant_id' => $tenant, 'rencana_pemeliharaan_id' => $id]);
                }
            }
        });

        return $this->lines($request, $id);
    }

    public function targets(Request $request, string $id): JsonResponse
    {
        $this->permission($request, 'read');
        $plan = MaintenancePlan::query()->findOrFail($id);

        return response()->json(['data' => $this->presentTargets($id), 'version' => $plan->version]);
    }

    public function replaceTargets(Request $request, string $id): JsonResponse
    {
        $this->permission($request, 'update');
        $tenant = $this->tenant($request);
        MaintenancePlan::query()->findOrFail($id);
        $data = $request->validate([
            'targets' => ['present', 'array', 'max:500'],
            'targets.*.id' => ['nullable', 'ulid'],
            'targets.*.aset_id' => ['nullable', 'ulid', 'distinct',
                Rule::exists('aset_tr_aset', 'id')->where('tenant_id', $tenant)->whereNull('deleted_at')],
            'targets.*.jenis_aset_id' => ['nullable', 'ulid', 'distinct',
                Rule::exists('aset_m_jenis_aset', 'id')->where('tenant_id', $tenant)->whereNull('deleted_at')],
            'targets.*.tanggal_mulai' => ['nullable', 'date'],
            'targets.*.aktif' => ['sometimes', 'boolean'],
        ], [
            'targets.*.aset_id.distinct' => 'Aset yang sama tercantum dua kali.',
            'targets.*.jenis_aset_id.distinct' => 'Jenis aset yang sama tercantum dua kali.',
        ]);
        $targets = array_values($data['targets']);
        foreach ($targets as $index => $target) {
            if ((($target['aset_id'] ?? null) === null) === (($target['jenis_aset_id'] ?? null) === null)) {
                throw ValidationException::withMessages(['targets.'.$index.'.aset_id' => 'Setiap baris berlaku untuk satu aset atau satu jenis aset, bukan keduanya.']);
            }
        }
        $expected = RowVersion::expected($request);

        DB::transaction(function () use ($id, $targets, $expected, $tenant): void {
            RowVersion::claim(MaintenancePlan::query()->findOrFail($id), $expected);
            $existing = MaintenancePlanTarget::query()->where('rencana_pemeliharaan_id', $id)->pluck('id')->all();
            $kept = array_values(array_filter(array_column($targets, 'id')));
            if (array_diff($kept, $existing) !== []) {
                throw ValidationException::withMessages(['targets' => 'Objek rencana tidak ditemukan pada rencana ini. Muat ulang lalu ulangi.']);
            }
            MaintenancePlanTarget::query()->where('rencana_pemeliharaan_id', $id)->whereNotIn('id', $kept)->delete();

            foreach ($targets as $target) {
                $values = [
                    'aset_id' => $target['aset_id'] ?? null,
                    'jenis_aset_id' => $target['jenis_aset_id'] ?? null,
                    'tanggal_mulai' => $target['tanggal_mulai'] ?? null,
                    'aktif' => filter_var($target['aktif'] ?? true, FILTER_VALIDATE_BOOL),
                ];
                if (($target['id'] ?? null) !== null) {
                    MaintenancePlanTarget::query()->whereKey($target['id'])->update($values);
                } else {
                    MaintenancePlanTarget::query()->create([...$values, 'tenant_id' => $tenant, 'rencana_pemeliharaan_id' => $id]);
                }
            }
        });

        return $this->targets($request, $id);
    }

    /**
     * Aturan antar-field yang tidak dapat dinyatakan aturan validasi per field: isian yang wajib
     * menurut dasar hitungnya, dan master yang dirujuk masih aktif.
     *
     * @param  list<array<string, mixed>>  $lines
     */
    private function validateLines(array $lines): void
    {
        foreach ($lines as $index => $line) {
            $field = 'lines.'.$index;
            if (MaintenancePlanBasis::isTimeBased($line['dasar'])) {
                if (($line['interval'] ?? null) === null || ($line['satuan_interval'] ?? null) === null) {
                    throw ValidationException::withMessages([$field.'.interval' => 'Baris berbasis waktu membutuhkan interval dan satuannya, misalnya setiap 6 bulan.']);
                }
            } else {
                if (($line['jenis_counter_id'] ?? null) === null || ($line['interval_counter'] ?? null) === null) {
                    throw ValidationException::withMessages([$field.'.jenis_counter_id' => 'Baris berbasis counter membutuhkan jenis counter dan intervalnya, misalnya setiap 500 jam.']);
                }
                $this->requireActive(CounterType::class, $line['jenis_counter_id'], $field.'.jenis_counter_id', 'Jenis counter tidak ditemukan atau sudah tidak aktif.');
                if ((float) ($line['toleransi_counter'] ?? 0) >= (float) $line['interval_counter']) {
                    throw ValidationException::withMessages([$field.'.toleransi_counter' => 'Toleransi counter harus lebih kecil dari intervalnya.']);
                }
            }

            $this->requireActive(MaintenanceJobType::class, $line['maintenance_job_type_id'], $field.'.maintenance_job_type_id', 'Jenis pekerjaan maintenance tidak ditemukan atau sudah tidak aktif.');
            $this->requireActive(TipeWorkOrder::class, $line['tipe_work_order_id'], $field.'.tipe_work_order_id', 'Tipe work order tidak ditemukan atau sudah tidak aktif.');
            if (($line['trade_id'] ?? null) !== null) {
                $this->requireActive(Trade::class, $line['trade_id'], $field.'.trade_id', 'Bidang keahlian tidak ditemukan atau sudah tidak aktif.');
            }
            if (($line['tingkat_layanan_id'] ?? null) !== null) {
                $this->requireActive(TingkatLayanan::class, $line['tingkat_layanan_id'], $field.'.tingkat_layanan_id', 'Tingkat layanan tidak ditemukan atau sudah tidak aktif.');
            }
            if (($line['variant_id'] ?? null) !== null) {
                $matches = MaintenanceJobTypeVariant::query()->where([
                    'id' => $line['variant_id'],
                    'maintenance_job_type_id' => $line['maintenance_job_type_id'],
                    'aktif' => true,
                ])->exists();
                if (! $matches) {
                    throw ValidationException::withMessages([$field.'.variant_id' => 'Varian pekerjaan harus berasal dari jenis pekerjaan yang dipilih pada baris yang sama.']);
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $line
     * @return array<string, mixed>
     */
    private function lineValues(array $line, int $lineNumber): array
    {
        $timeBased = MaintenancePlanBasis::isTimeBased($line['dasar']);

        // Isian milik dasar hitung lain dikosongkan, bukan disimpan diam-diam: baris waktu yang
        // menyimpan jenis counter akan terbaca sebagai baris counter oleh pembaca berikutnya.
        return [
            'line_number' => $lineNumber,
            'dasar' => $line['dasar'],
            'interval' => $timeBased ? (int) $line['interval'] : null,
            'satuan_interval' => $timeBased ? $line['satuan_interval'] : null,
            'jenis_counter_id' => $timeBased ? null : $line['jenis_counter_id'],
            'interval_counter' => $timeBased ? null : $line['interval_counter'],
            'toleransi_counter' => $timeBased ? 0 : ($line['toleransi_counter'] ?? 0),
            'maintenance_job_type_id' => $line['maintenance_job_type_id'],
            'variant_id' => $line['variant_id'] ?? null,
            'trade_id' => $line['trade_id'] ?? null,
            'tipe_work_order_id' => $line['tipe_work_order_id'],
            'tingkat_layanan_id' => $line['tingkat_layanan_id'] ?? null,
            'selesai_dalam_hari' => $line['selesai_dalam_hari'] ?? null,
            'deskripsi' => ($line['deskripsi'] ?? null) === null ? null : (trim($line['deskripsi']) ?: null),
            'aktif' => filter_var($line['aktif'] ?? true, FILTER_VALIDATE_BOOL),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function presentLines(string $planId): array
    {
        $table = 'aset_m_rencana_pemeliharaan_baris';

        return array_values(MaintenancePlanLine::query()
            ->leftJoin('aset_m_maintenance_job_type as pekerjaan', function ($join) use ($table): void {
                $join->on('pekerjaan.id', '=', $table.'.maintenance_job_type_id')->on('pekerjaan.tenant_id', '=', $table.'.tenant_id');
            })
            ->leftJoin('aset_m_jenis_counter as counter', function ($join) use ($table): void {
                $join->on('counter.id', '=', $table.'.jenis_counter_id')->on('counter.tenant_id', '=', $table.'.tenant_id');
            })
            ->leftJoin('aset_m_tipe_work_order as tipe', function ($join) use ($table): void {
                $join->on('tipe.id', '=', $table.'.tipe_work_order_id')->on('tipe.tenant_id', '=', $table.'.tenant_id');
            })
            ->where($table.'.rencana_pemeliharaan_id', $planId)
            ->orderBy($table.'.line_number')
            ->toBase()
            ->get([
                $table.'.*',
                'pekerjaan.kode as job_type_kode', 'pekerjaan.nama as job_type_nama',
                'counter.nama as jenis_counter_nama', 'counter.satuan as jenis_counter_satuan',
                'tipe.nama as tipe_work_order_nama',
            ])
            ->map(static fn ($row): array => (array) $row)
            ->all());
    }

    /** @return list<array<string, mixed>> */
    private function presentTargets(string $planId): array
    {
        $table = 'aset_m_rencana_pemeliharaan_objek';

        return array_values(MaintenancePlanTarget::query()
            ->leftJoin('aset_tr_aset as aset', function ($join) use ($table): void {
                $join->on('aset.id', '=', $table.'.aset_id')->on('aset.tenant_id', '=', $table.'.tenant_id');
            })
            ->leftJoin('aset_m_jenis_aset as jenis', function ($join) use ($table): void {
                $join->on('jenis.id', '=', $table.'.jenis_aset_id')->on('jenis.tenant_id', '=', $table.'.tenant_id');
            })
            ->where($table.'.rencana_pemeliharaan_id', $planId)
            ->orderByRaw('aset.kode NULLS FIRST, jenis.kode')
            ->toBase()
            ->get([
                $table.'.*',
                'aset.kode as aset_kode', 'aset.nama as aset_nama',
                'jenis.kode as jenis_aset_kode', 'jenis.nama as jenis_aset_nama',
            ])
            ->map(static fn ($row): array => (array) $row)
            ->all());
    }

    /** @param  class-string<Model>  $model */
    private function requireActive(string $model, string $id, string $field, string $message): void
    {
        if (! $model::query()->whereKey($id)->where('aktif', true)->exists()) {
            throw ValidationException::withMessages([$field => $message]);
        }
    }

    private function permission(Request $request, string $action): void
    {
        abort_unless(in_array('management-aset.'.self::RESOURCE.'.'.$action, $request->attributes->get('coreerp.permissions', []), true), 403);
    }

    private function tenant(Request $request): string
    {
        return (string) $request->attributes->get('coreerp.tenant_id');
    }
}
