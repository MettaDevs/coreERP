<?php

namespace App\Support;

use App\Models\master\TipeAtribut;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Menegakkan nilai atribut aset terhadap definisi milik jenis asetnya.
 *
 * Definisi atribut dibuat tenant saat berjalan, jadi aturannya tidak dapat dituliskan
 * sebagai rule statis di controller; ia harus dibaca dari database tiap kali.
 */
final class AssetAttributeValidator
{
    /**
     * Definisi atribut satu jenis aset, terurut sesuai tampilan.
     *
     * @return list<array<string, mixed>>
     */
    public function definitions(string $tenantId, string $jenisAsetId): array
    {
        $rows = DB::table('m_jenis_aset_atribut as link')
            ->join('m_tipe_atribut as tipe', function ($join): void {
                $join->on('tipe.id', '=', 'link.tipe_atribut_id')->on('tipe.tenant_id', '=', 'link.tenant_id');
            })
            ->where(['link.tenant_id' => $tenantId, 'link.jenis_aset_id' => $jenisAsetId])
            ->whereNull('link.deleted_at')
            ->whereNull('tipe.deleted_at')
            ->where('tipe.aktif', true)
            ->orderBy('link.urutan')->orderBy('tipe.nama')
            ->select('tipe.id as tipe_atribut_id', 'tipe.kode', 'tipe.nama', 'tipe.data_type', 'tipe.satuan', 'tipe.min_value', 'tipe.max_value', 'link.wajib', 'link.urutan')
            ->get();

        $choices = $this->choices($tenantId, $rows->pluck('tipe_atribut_id')->all());

        return $rows->map(fn (object $row): array => [
            ...(array) $row,
            'wajib' => (bool) $row->wajib,
            'nilai_pilihan' => $choices[$row->tipe_atribut_id] ?? [],
        ])->all();
    }

    /**
     * Memeriksa kiriman atribut dan mengembalikan baris siap simpan.
     *
     * @param  list<array{tipe_atribut_id: string, nilai: mixed}>  $submitted
     * @return list<array<string, mixed>>
     */
    public function rowsFor(string $tenantId, string $jenisAsetId, array $submitted): array
    {
        $definitions = collect($this->definitions($tenantId, $jenisAsetId))->keyBy('tipe_atribut_id');
        $values = collect($submitted)->keyBy('tipe_atribut_id');

        $errors = [];
        // Atribut liar ditolak: mengirim atribut yang tidak terdaftar pada jenis ini
        // hampir selalu berarti salah jenis, bukan niat menambah data.
        foreach ($values->keys() as $index => $id) {
            if (! $definitions->has($id)) {
                $errors['atribut.'.$index.'.tipe_atribut_id'] = ['Atribut ini tidak terdaftar pada jenis aset yang dipilih.'];
            }
        }

        $rows = [];
        foreach ($definitions as $id => $definition) {
            $raw = $values->get($id)['nilai'] ?? null;
            $isEmpty = $raw === null || $raw === '';
            $field = 'atribut.'.$id;

            if ($isEmpty) {
                if ($definition['wajib']) {
                    $errors[$field] = [$definition['nama'].' wajib diisi.'];
                }

                continue;
            }

            $column = $this->column($definition, $raw, $field, $errors);
            if ($column === null) {
                continue;
            }

            $rows[] = [
                'id' => (string) Str::ulid(),
                'tenant_id' => $tenantId,
                'tipe_atribut_id' => $id,
                'nilai_text' => null, 'nilai_number' => null, 'nilai_boolean' => null,
                'nilai_date' => null, 'tipe_atribut_nilai_id' => null,
                ...$column,
                'created_at' => now(), 'updated_at' => now(),
            ];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $definition
     * @param  array<string, list<string>>  $errors
     * @return array<string, mixed>|null
     */
    private function column(array $definition, mixed $raw, string $field, array &$errors): ?array
    {
        switch ($definition['data_type']) {
            case 'number':
            case TipeAtribut::RANGE_TYPE:
                if (! is_numeric($raw)) {
                    $errors[$field] = [$definition['nama'].' harus berupa angka.'];

                    return null;
                }
                $number = (float) $raw;
                $min = $definition['min_value'];
                $max = $definition['max_value'];
                if ($definition['data_type'] === TipeAtribut::RANGE_TYPE
                    && (($min !== null && $number < (float) $min) || ($max !== null && $number > (float) $max))) {
                    $errors[$field] = [$definition['nama'].' harus antara '.$min.' dan '.$max.'.'];

                    return null;
                }

                return ['nilai_number' => $number];

            case 'boolean':
                return ['nilai_boolean' => filter_var($raw, FILTER_VALIDATE_BOOL)];

            case 'date':
                if (Validator::make(['v' => $raw], ['v' => ['date_format:Y-m-d']])->fails()) {
                    $errors[$field] = [$definition['nama'].' harus berupa tanggal (YYYY-MM-DD).'];

                    return null;
                }

                return ['nilai_date' => $raw];

            case TipeAtribut::LIST_TYPE:
                $choice = collect($definition['nilai_pilihan'])->firstWhere('nilai', (string) $raw);
                if (! $choice) {
                    $errors[$field] = [$definition['nama'].' harus dipilih dari daftar yang tersedia.'];

                    return null;
                }

                return ['tipe_atribut_nilai_id' => $choice['id'], 'nilai_text' => $choice['nilai']];

            default:
                return ['nilai_text' => (string) $raw];
        }
    }

    /**
     * @param  list<string>  $typeIds
     * @return array<string, list<array{id: string, nilai: string}>>
     */
    private function choices(string $tenantId, array $typeIds): array
    {
        if ($typeIds === []) {
            return [];
        }

        return DB::table('m_tipe_atribut_nilai')
            ->where('tenant_id', $tenantId)
            ->whereIn('tipe_atribut_id', $typeIds)
            ->whereNull('deleted_at')
            ->orderBy('urutan')->orderBy('nilai')
            ->get(['id', 'tipe_atribut_id', 'nilai'])
            ->groupBy('tipe_atribut_id')
            ->map(fn ($group) => $group->map(fn (object $row): array => ['id' => $row->id, 'nilai' => $row->nilai])->all())
            ->all();
    }
}
