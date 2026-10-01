<?php

declare(strict_types=1);

namespace Modules\Apperp\ManagementAset\Reporting;

use App\Platform\Modules\Contracts\FilterField;
use App\Platform\Modules\Contracts\TableFields;
use Illuminate\Database\Eloquent\Model;

/**
 * Satu data item laporan, padanan `dataitem` di report Business Central (K-30): tabel yang barisnya dibaca
 * laporan, dengan bagian filternya sendiri di layar. Laporan dokumen biasanya punya dua, dokumen dan
 * barisnya, seperti FA Register lalu FA Ledger Entry di laporan Fixed Asset Register BC.
 *
 * Pengguna boleh menambah filter pada kolom mana pun di tabel itu, lewat katalog {@see TableFields}.
 * `$defaultFields` padanan `RequestFilterFields`: kolom yang langsung tampil sebagai baris filter tanpa
 * pengguna menambahkannya. Filter tetap laporan (periode, buku, group, dan sejenisnya) tetap milik
 * `parameterRules()` laporannya, dan batasan yang tidak boleh dilepas pengguna tetap di query laporannya,
 * padanan `DataItemTableView`.
 */
final readonly class ReportDataItem
{
    /**
     * @param  class-string<Model>  $model  Model tabel data item.
     * @param  string  $alias  Nama atau alias tabel itu di query laporan.
     * @param  list<string>  $defaultFields
     */
    public function __construct(
        public string $key,
        public string $caption,
        public string $model,
        public string $alias,
        public array $defaultFields = [],
    ) {}

    /** @return array<string, FilterField> Berkunci nama kolom. */
    public function fields(): array
    {
        $fields = [];
        foreach (TableFields::for($this->model, $this->alias) as $field) {
            $fields[$field->key] = $field;
        }

        return $fields;
    }
}
