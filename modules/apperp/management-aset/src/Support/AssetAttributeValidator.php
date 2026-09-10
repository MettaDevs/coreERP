<?php

namespace Modules\Apperp\ManagementAset\Support;

use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Apperp\ManagementAset\Models\master\JenisAsetAtribut;
use Modules\Apperp\ManagementAset\Models\master\TipeAtribut;
use Modules\Apperp\ManagementAset\Models\master\TipeAtributNilai;

/**
 * Menegakkan nilai atribut aset terhadap definisi milik jenis asetnya.
 *
 * Definisi atribut dibuat tenant saat berjalan, jadi aturannya tidak dapat dituliskan
 * sebagai rule statis di controller; ia harus dibaca dari database tiap kali.
 *
 * Kolom yang datang dari database ditulis `mixed` dengan sengaja. Ia dibaca lewat
 * `getAttributes()`, yaitu nilai mentah apa adanya dari driver — `decimal` dapat berupa
 * string maupun float tergantung driver — sehingga menuliskannya `string` atau `float`
 * akan menjadi anotasi yang meyakinkan sekaligus keliru. Dua kunci yang dilekatkan di
 * sini, `wajib` dan `nilai_pilihan`, tipenya diketahui dan karena itu dituliskan.
 *
 * @phpstan-type DefinisiAtribut array{
 *     tipe_atribut_id: mixed,
 *     kode: mixed,
 *     nama: mixed,
 *     data_type: mixed,
 *     satuan: mixed,
 *     min_value: mixed,
 *     max_value: mixed,
 *     wajib: bool,
 *     urutan: mixed,
 *     nilai_pilihan: list<array{id: string, nilai: string}>,
 * }
 */
final class AssetAttributeValidator
{
    /**
     * Definisi atribut satu jenis aset, terurut sesuai tampilan.
     *
     * @return list<DefinisiAtribut>
     */
    public function definitions(string $tenantId, string $jenisAsetId): array
    {
        // Tabel yang di-`join` tidak ikut tersaring scope tenant, jadi batas tenant dan
        // soft delete tipe atribut ditulis eksplisit di sini. Tabel utamanya tidak boleh
        // dialiaskan: scope menyaring dengan nama tabel yang sebenarnya.
        $rows = JenisAsetAtribut::query()
            ->join('aset_m_tipe_atribut as tipe', function ($join) use ($tenantId): void {
                $join->on('tipe.id', '=', 'aset_m_jenis_aset_atribut.tipe_atribut_id')
                    ->where('tipe.tenant_id', $tenantId);
            })
            ->where('aset_m_jenis_aset_atribut.jenis_aset_id', $jenisAsetId)
            ->whereNull('tipe.deleted_at')
            ->where('tipe.aktif', true)
            ->orderBy('aset_m_jenis_aset_atribut.urutan')->orderBy('tipe.nama')
            ->get([
                'tipe.id as tipe_atribut_id', 'tipe.kode', 'tipe.nama', 'tipe.data_type', 'tipe.satuan',
                'tipe.min_value', 'tipe.max_value', 'aset_m_jenis_aset_atribut.wajib', 'aset_m_jenis_aset_atribut.urutan',
            ]);

        $choices = $this->choices(array_values($rows->map(fn (JenisAsetAtribut $row): string => $row->tipe_atribut_id)->all()));

        // Kunci disebut satu per satu, bukan disebar dari `getAttributes()`, supaya bentuk
        // yang dijanjikan docblock benar-benar terbukti. Urutannya sama dengan urutan kolom
        // pada `get()` di atas, jadi bentuk jawaban endpoint definisi atribut tidak berubah.
        return array_values($rows->map(function (JenisAsetAtribut $row) use ($choices): array {
            $atribut = $row->getAttributes();

            return [
                'tipe_atribut_id' => $atribut['tipe_atribut_id'],
                'kode' => $atribut['kode'],
                'nama' => $atribut['nama'],
                'data_type' => $atribut['data_type'],
                'satuan' => $atribut['satuan'],
                'min_value' => $atribut['min_value'],
                'max_value' => $atribut['max_value'],
                'wajib' => $row->wajib,
                'urutan' => $atribut['urutan'],
                'nilai_pilihan' => $choices[$row->tipe_atribut_id] ?? [],
            ];
        })->all());
    }

    /**
     * Memeriksa kiriman atribut dan mengembalikan baris siap simpan.
     *
     * @param  list<array{tipe_atribut_id: string, nilai: mixed}>  $submitted
     * @return list<array<string, mixed>>
     */
    public function rowsFor(string $tenantId, string $jenisAsetId, array $submitted): array
    {
        $typeIds = JenisAsetAtribut::query()
            ->where('jenis_aset_id', $jenisAsetId)
            ->orderBy('tipe_atribut_id')
            ->pluck('tipe_atribut_id');
        if ($typeIds->isNotEmpty()) {
            // `withTrashed()`: tipe atribut yang sudah diarsipkan tetap dikunci, karena
            // baris nilai yang sedang ditulis merujuk padanya dan `data_type_locked`-nya
            // ikut disetel setelah penyimpanan.
            TipeAtribut::query()->withTrashed()
                ->whereKey($typeIds->all())
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['id']);
        }

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
     * @param  DefinisiAtribut  $definition
     * @param  array<string, list<string>>  $errors
     * @return array<string, mixed>|null
     */
    private function column(array $definition, mixed $raw, string $field, array &$errors): ?array
    {
        switch ($definition['data_type']) {
            case 'decimal':
            case 'integer':
                if (! is_numeric($raw)) {
                    $errors[$field] = [$definition['nama'].' harus berupa angka.'];

                    return null;
                }
                $number = (float) $raw;
                if ($definition['data_type'] === 'integer' && floor($number) !== $number) {
                    $errors[$field] = [$definition['nama'].' harus berupa bilangan bulat.'];

                    return null;
                }
                $min = $definition['min_value'];
                $max = $definition['max_value'];
                if (($min !== null && $number < (float) $min) || ($max !== null && $number > (float) $max)) {
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

            case 'string':
                if ($definition['nilai_pilihan'] !== []) {
                    $choice = collect($definition['nilai_pilihan'])->firstWhere('nilai', (string) $raw);
                    if (! $choice) {
                        $errors[$field] = [$definition['nama'].' harus dipilih dari daftar yang tersedia.'];

                        return null;
                    }

                    return ['tipe_atribut_nilai_id' => $choice['id'], 'nilai_text' => $choice['nilai']];
                }

                return ['nilai_text' => (string) $raw];

            default:
                $errors[$field] = [$definition['nama'].' memakai tipe data yang tidak dikenal.'];

                return null;
        }
    }

    /**
     * @param  list<string>  $typeIds
     * @return array<string, list<array{id: string, nilai: string}>>
     */
    private function choices(array $typeIds): array
    {
        if ($typeIds === []) {
            return [];
        }

        return TipeAtributNilai::query()
            ->whereIn('tipe_atribut_id', $typeIds)
            ->orderBy('urutan')->orderBy('nilai')
            ->get(['id', 'tipe_atribut_id', 'nilai'])
            ->groupBy('tipe_atribut_id')
            ->map(fn ($group) => $group->map(fn (TipeAtributNilai $row): array => ['id' => $row->id, 'nilai' => $row->nilai])->all())
            ->all();
    }
}
