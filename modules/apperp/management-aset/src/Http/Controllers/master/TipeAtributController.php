<?php

namespace Modules\Apperp\ManagementAset\Http\Controllers\master;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Modules\Apperp\ManagementAset\Http\Controllers\MasterDataController;
use Modules\Apperp\ManagementAset\Models\master\TipeAtribut;
use Modules\Apperp\ManagementAset\Models\MasterData;
use Modules\Apperp\ManagementAset\Services\UnitOfMeasureClient;
use Modules\Apperp\ManagementAset\Support\MasterChild;
use RuntimeException;

class TipeAtributController extends MasterDataController
{
    /**
     * Tenant permintaan yang sedang berjalan, ditangkap dari `extraRules()`.
     *
     * `extraPayload()` tidak menerima tenant, padahal ia yang perlu menanyakan kode satuan
     * ke Core. `extraRules()` dipanggil lebih dulu pada kedua jalur tulis, jadi di situlah
     * tenant paling awal dapat diketahui.
     */
    private string $tenantId = '';

    /**
     * Kode satuan yang sudah ditanyakan ke Core, disimpan per id.
     *
     * `extraPayload()` dapat terpanggil lebih dari sekali dalam satu permintaan, dan satuan
     * dimiliki layanan luar — tanpa ingatan ini, satu penyimpanan berarti beberapa panggilan
     * jaringan yang jawabannya sama.
     *
     * @var array<string, string>
     */
    private array $unitCodes = [];

    private ?TipeAtribut $currentRecord = null;

    protected function resource(): string
    {
        return 'tipe-atribut';
    }

    protected function model(): string
    {
        return TipeAtribut::class;
    }

    protected function childMasters(): array
    {
        return [
            new MasterChild(table: 'aset_m_tipe_atribut_nilai', column: 'tipe_atribut_id', label: 'pilihan nilai'),
            new MasterChild(table: 'aset_m_jenis_aset_atribut', column: 'tipe_atribut_id', label: 'atribut pada jenis aset'),
        ];
    }

    protected function extraRules(string $tenantId, bool $creating): array
    {
        $this->tenantId = $tenantId;
        $required = $creating ? ['required'] : ['sometimes', 'required'];

        return [
            'data_type' => [...$required, Rule::in(TipeAtribut::DATA_TYPES)],
            // Satuan dirujuk lewat id milik Core. Kodenya tidak diterima dari klien supaya
            // layar tidak dapat menampilkan satuan yang tidak dikenal Core.
            'satuan_id' => ['sometimes', 'nullable', 'ulid'],
            'min_value' => ['sometimes', 'nullable', 'numeric'],
            'max_value' => ['sometimes', 'nullable', 'numeric'],
        ];
    }

    /**
     * Menolak satuan pada tipe data yang tidak mengenalnya.
     *
     * Hanya pemeriksaan ini yang berada di sini, karena hanya ia yang butuh record lama:
     * saat mengubah, `data_type` boleh tidak ikut dikirim, sehingga tipe yang berlaku
     * harus dibaca dari record. Pemeriksaan satuan ke Core justru tidak dapat menumpang
     * hook ini — pada `store()` ia berjalan setelah payload disusun, sedangkan pada
     * `update()` sebelum, jadi hasilnya tidak dapat diandalkan oleh `extraPayload()`.
     */
    protected function afterWriteValidation(array $data, string $tenantId, bool $creating, ?MasterData $record = null): void
    {
        $this->currentRecord = $record instanceof TipeAtribut ? $record : null;
        $dataType = (string) ($data['data_type'] ?? $record?->data_type);

        if ($record instanceof TipeAtribut
            && array_key_exists('data_type', $data)
            && $dataType !== $record->data_type) {
            if ($record->data_type_locked) {
                abort(response()->json(['error' => [
                    'code' => 'data_type_locked',
                    'message' => 'Tipe data tidak dapat diubah karena atribut ini sudah pernah diisi pada aset. Buat tipe atribut baru bila bentuk datanya berbeda.',
                ]], 409));
            }
            if ($record->data_type === 'string' && DB::table('aset_m_tipe_atribut_nilai')
                ->where(['tenant_id' => $tenantId, 'tipe_atribut_id' => $record->id])
                ->whereNull('deleted_at')->exists()) {
                abort(response()->json(['error' => [
                    'code' => 'attribute_type_has_values',
                    'message' => 'Kosongkan pilihan nilai lebih dahulu sebelum mengganti tipe data.',
                ]], 409));
            }
        }

        $min = array_key_exists('min_value', $data) ? $data['min_value'] : $record?->min_value;
        $max = array_key_exists('max_value', $data) ? $data['max_value'] : $record?->max_value;
        if (in_array($dataType, TipeAtribut::NUMERIC_TYPES, true)) {
            if (($min === null) !== ($max === null)) {
                throw ValidationException::withMessages([
                    'min_value' => 'Nilai minimum dan maksimum harus diisi bersama atau dikosongkan bersama.',
                    'max_value' => 'Nilai minimum dan maksimum harus diisi bersama atau dikosongkan bersama.',
                ]);
            }
            if ($min !== null && (float) $max < (float) $min) {
                throw ValidationException::withMessages(['max_value' => 'Nilai maksimum harus sama dengan atau lebih besar dari nilai minimum.']);
            }
            if ($dataType === 'integer' && (($min !== null && ! $this->isWholeNumber($min)) || ($max !== null && ! $this->isWholeNumber($max)))) {
                throw ValidationException::withMessages([
                    'min_value' => 'Batas untuk bilangan bulat tidak boleh memiliki angka di belakang koma.',
                    'max_value' => 'Batas untuk bilangan bulat tidak boleh memiliki angka di belakang koma.',
                ]);
            }
        }

        if (! array_key_exists('satuan_id', $data) || $data['satuan_id'] === null) {
            return;
        }
        if (! in_array($dataType, TipeAtribut::NUMERIC_TYPES, true)) {
            throw ValidationException::withMessages([
                'satuan_id' => 'Satuan hanya berlaku untuk tipe data desimal atau bilangan bulat.',
            ]);
        }
    }

    protected function extraPayload(array $data): array
    {
        $payload = [];
        foreach (['data_type', 'min_value', 'max_value'] as $column) {
            if (array_key_exists($column, $data)) {
                $payload[$column] = $data[$column];
            }
        }
        if (array_key_exists('satuan_id', $data)) {
            // `satuan` adalah salinan tampilan dari satuan yang dirujuk, bukan masukan
            // tersendiri. Keduanya selalu ditulis bersama supaya tidak pernah ada baris
            // yang menampilkan satu satuan sambil merujuk satuan lain.
            $payload['satuan_id'] = $data['satuan_id'];
            $payload['satuan'] = $data['satuan_id'] === null ? null : $this->unitCode($data['satuan_id']);
        }

        $dataType = (string) ($data['data_type'] ?? $this->currentRecord?->data_type);
        if (! in_array($dataType, TipeAtribut::NUMERIC_TYPES, true)) {
            $payload['satuan_id'] = null;
            $payload['satuan'] = null;
            $payload['min_value'] = null;
            $payload['max_value'] = null;
        }

        return $payload;
    }

    /**
     * Kode satuan menurut Core. Satuan yang tidak dikenalnya ditolak di sini, bukan
     * disimpan sebagai rujukan menggantung yang baru ketahuan salah saat ditampilkan.
     */
    private function unitCode(string $satuanId): string
    {
        if (array_key_exists($satuanId, $this->unitCodes)) {
            return $this->unitCodes[$satuanId];
        }
        try {
            $units = app(UnitOfMeasureClient::class)->resolve($this->tenantId, [$satuanId]);
        } catch (RuntimeException) {
            throw ValidationException::withMessages([
                'satuan_id' => 'Satuan tidak ditemukan, tidak aktif, atau belum dapat diperiksa.',
            ]);
        }

        return $this->unitCodes[$satuanId] = (string) ($units[$satuanId]['code'] ?? '');
    }

    protected function extraPresent(MasterData $record): array
    {
        return [
            ...$record->only(['data_type', 'satuan_id', 'satuan', 'min_value', 'max_value']),
            'values_count' => (int) ($record->values_count ?? 0),
            'asset_types_count' => (int) ($record->asset_types_count ?? 0),
            'data_type_locked' => (bool) $record->data_type_locked,
        ];
    }

    protected function prepareQuery(Builder $query, ?Request $request = null): Builder
    {
        return $query->addSelect([
            'values_count' => DB::table('aset_m_tipe_atribut_nilai as nilai')
                ->selectRaw('count(*)')
                ->whereColumn('nilai.tenant_id', 'aset_m_tipe_atribut.tenant_id')
                ->whereColumn('nilai.tipe_atribut_id', 'aset_m_tipe_atribut.id')
                ->whereNull('nilai.deleted_at'),
            'asset_types_count' => DB::table('aset_m_jenis_aset_atribut as link')
                ->selectRaw('count(*)')
                ->whereColumn('link.tenant_id', 'aset_m_tipe_atribut.tenant_id')
                ->whereColumn('link.tipe_atribut_id', 'aset_m_tipe_atribut.id')
                ->whereNull('link.deleted_at'),
        ]);
    }

    protected function updateUnderLock(): bool
    {
        return true;
    }

    private function isWholeNumber(mixed $value): bool
    {
        return is_numeric($value) && floor((float) $value) === (float) $value;
    }
}
