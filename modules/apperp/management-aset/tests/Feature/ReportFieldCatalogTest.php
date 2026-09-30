<?php

declare(strict_types=1);

namespace Modules\Apperp\ManagementAset\Tests\Feature;

use App\Support\Modules\Contracts\TableFields;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Modules\Apperp\ManagementAset\Reporting\ReportDataItem;
use Modules\Apperp\ManagementAset\Reporting\ReportRegistry;
use Tests\TestCase;

/**
 * Penjaga katalog field tabel data item laporan (K-30).
 *
 * "+ Tambah filter" menawarkan semua kolom tabel data item, seperti "+ Filter" di BC. Janji itu hanya
 * bertahan kalau kolom yang baru ditambahkan ke tabelnya langsung diberi nama tampilan atau disembunyikan
 * dengan alasan; tanpa penjaga ini kolom baru diam-diam tidak dapat difilter.
 */
class ReportFieldCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_column_of_a_report_data_item_table_is_captioned_or_hidden_with_a_reason(): void
    {
        $checked = 0;
        foreach ($this->dataItems() as $label => $item) {
            $this->assertSame([], TableFields::describe($item->model)['undeclared'], "{$label}: kolom tanpa nama tampilan. Tulis di FIELD_CAPTIONS, atau FIELD_HIDDEN beserta alasannya.");
            $checked++;
        }

        $this->assertGreaterThan(0, $checked, 'Belum ada laporan yang menawarkan filter tambahan; penjaga ini tidak memeriksa apa pun.');
    }

    public function test_field_constants_name_existing_columns_lookups_and_default_fields(): void
    {
        $resources = collect(Route::getRoutes()->getRoutes())->map(fn ($route): string => $route->uri())->all();

        foreach ($this->dataItems() as $label => $item) {
            $model = $item->model;
            $columns = Schema::getColumnListing((new $model)->getTable());
            foreach (['FIELD_CAPTIONS', 'FIELD_OPTIONS', 'FIELD_LOOKUPS', 'FIELD_HIDDEN'] as $constant) {
                $declared = defined("{$model}::{$constant}") ? array_keys((array) constant("{$model}::{$constant}")) : [];
                $this->assertSame([], array_values(array_diff($declared, $columns)), "{$label}: {$constant} menyebut kolom yang tidak ada di tabelnya.");
            }
            foreach (defined("{$model}::FIELD_HIDDEN") ? (array) constant("{$model}::FIELD_HIDDEN") : [] as $column => $reason) {
                $this->assertNotSame('', trim((string) $reason), "{$label}: kolom {$column} disembunyikan tanpa alasan.");
            }
            foreach (defined("{$model}::FIELD_LOOKUPS") ? (array) constant("{$model}::FIELD_LOOKUPS") : [] as $column => $resource) {
                $this->assertTrue(
                    collect($resources)->contains(fn (string $uri): bool => str_ends_with($uri, '/'.$resource)),
                    "{$label}: pemilih `{$resource}` untuk kolom {$column} tidak punya rute.",
                );
            }
            $this->assertSame([], array_values(array_diff($item->defaultFields, array_keys($item->fields()))), "{$label}: kolom bawaan tidak ada di katalog.");
        }
    }

    /** @return array<string, ReportDataItem> Berkunci "laporan/data item". */
    private function dataItems(): array
    {
        $items = [];
        foreach (app(ReportRegistry::class)->all() as $definition) {
            foreach ($definition->dataItems() as $item) {
                $items[$definition->code().'/'.$item->key] = $item;
            }
        }

        return $items;
    }
}
