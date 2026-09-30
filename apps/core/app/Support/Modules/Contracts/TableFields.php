<?php

declare(strict_types=1);

namespace App\Support\Modules\Contracts;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Field sebuah tabel yang boleh difilter pengguna, padanan field tabel beserta Caption-nya di Business
 * Central (K-30). Di BC, "+ Filter" pada request page laporan menawarkan setiap field tabel data item; di
 * sini tabel diwakili modelnya, dan nama tampilan setiap kolom ditulis di model itu sendiri:
 *
 * ```php
 * final class Aset extends Model
 * {
 *     public const FIELD_CAPTIONS = ['kode' => 'Kode aset', 'lokasi_aset_id' => 'Lokasi', ...];
 *     public const FIELD_OPTIONS = ['lifecycle_state' => ['received' => 'Diterima', ...]];
 *     public const FIELD_LOOKUPS = ['lokasi_aset_id' => 'lokasi-aset'];
 *     public const FIELD_HIDDEN = ['metadata' => 'JSON bebas, tidak bermakna sebagai filter'];
 * }
 * ```
 *
 * - **Tipe dibaca dari database**, bukan ditulis ulang: teks, angka, tanggal, waktu, dan ya/tidak. Kolom di
 *   `FIELD_OPTIONS` menjadi pilihan dengan labelnya; kolom di `FIELD_LOOKUPS` menjadi rujukan yang dipilih
 *   dari daftar (nilainya nama resource pemilih milik module), seperti TableRelation di BC.
 * - **Kolom teknis tidak pernah ditawarkan**: kunci, `tenant_id`, versi baris, penanda arsip, kunci
 *   pembuatan, dan jejak pengguna. Kolom berkelas `AccountData` (hash, secret) juga tidak.
 * - **Kolom lain wajib diberi nama tampilan atau disembunyikan dengan alasannya.** `created_at` dan
 *   `updated_at` mendapat nama bawaan. Penjaganya `ReportFieldCatalogBoundaryTest`, supaya "semua kolom"
 *   tetap berarti semua kolom ketika tabelnya bertambah kolom.
 *
 * Kolom dikembalikan berkualifikasi alias tabel di query laporan, siap untuk {@see FieldFilterExpression}.
 */
final class TableFields
{
    /** Kolom yang tidak pernah bermakna sebagai filter pengguna. */
    public const TECHNICAL = ['id', 'tenant_id', 'version', 'deleted_at', 'creation_key', 'created_by_user_id', 'updated_by_user_id'];

    private const DEFAULT_CAPTIONS = ['created_at' => 'Dibuat pada', 'updated_at' => 'Terakhir diubah'];

    /** @var array<string, array<string, string>> Tipe kolom per tabel, dibaca sekali per proses. */
    private static array $columnTypes = [];

    /**
     * @param  class-string<Model>  $model
     * @return list<FilterField>
     */
    public static function for(string $model, string $alias): array
    {
        $fields = [];
        foreach (self::describe($model)['fields'] as $column => [$caption, $type, $options, $lookup]) {
            $fields[] = new FilterField($column, $caption, $type, "{$alias}.{$column}", $options, $lookup);
        }

        return $fields;
    }

    /**
     * Semua kolom tabel model beserta nasibnya: field yang ditawarkan, dan kolom yang belum dinyatakan.
     * Dipakai `for()` dan penjaga katalog.
     *
     * @param  class-string<Model>  $model
     * @return array{fields: array<string, array{string, FieldType, array<string, string>, ?string}>, undeclared: list<string>}
     */
    public static function describe(string $model): array
    {
        /** @var Model $instance */
        $instance = new $model;
        $captions = [...self::DEFAULT_CAPTIONS, ...self::constant($model, 'FIELD_CAPTIONS')];
        $options = self::constant($model, 'FIELD_OPTIONS');
        $lookups = self::constant($model, 'FIELD_LOOKUPS');
        $hidden = self::constant($model, 'FIELD_HIDDEN');
        $classification = self::constant($model, 'COLUMN_CLASSIFICATION');

        $fields = [];
        $undeclared = [];
        foreach (self::columnTypes($instance) as $column => $databaseType) {
            if (in_array($column, self::TECHNICAL, true) || array_key_exists($column, $hidden)
                || ($classification[$column] ?? null) === DataClass::AccountData) {
                continue;
            }
            $caption = $captions[$column] ?? null;
            if (! is_string($caption) || $caption === '') {
                $undeclared[] = $column;

                continue;
            }
            $type = match (true) {
                array_key_exists($column, $options) => FieldType::Option,
                array_key_exists($column, $lookups) => FieldType::Reference,
                default => self::typeOf($databaseType),
            };
            if ($type === null) {
                $undeclared[] = $column;

                continue;
            }
            $fields[$column] = [
                $caption,
                $type,
                $type === FieldType::Option ? array_map('strval', (array) $options[$column]) : [],
                $type === FieldType::Reference ? (string) $lookups[$column] : null,
            ];
        }

        return ['fields' => $fields, 'undeclared' => $undeclared];
    }

    /** Tipe filter dari nama tipe PostgreSQL; `null` untuk tipe yang tidak dapat difilter (JSON, biner). */
    private static function typeOf(string $databaseType): ?FieldType
    {
        return match (true) {
            in_array($databaseType, ['varchar', 'text', 'bpchar', 'char', 'citext'], true) => FieldType::Text,
            in_array($databaseType, ['int2', 'int4', 'int8', 'numeric', 'float4', 'float8'], true) => FieldType::Number,
            $databaseType === 'date' => FieldType::Date,
            in_array($databaseType, ['timestamp', 'timestamptz'], true) => FieldType::DateTime,
            $databaseType === 'bool' => FieldType::Boolean,
            default => null,
        };
    }

    /** @return array<string, string> Nama kolom => nama tipe database, urut seperti di tabel. */
    private static function columnTypes(Model $model): array
    {
        $key = $model->getConnectionName().'|'.$model->getTable();
        if (! isset(self::$columnTypes[$key])) {
            $types = [];
            foreach ($model->getConnection()->getSchemaBuilder()->getColumns($model->getTable()) as $column) {
                $types[(string) $column['name']] = (string) $column['type_name'];
            }
            if ($types === []) {
                throw new LogicException("Tabel `{$model->getTable()}` tidak ditemukan untuk katalog field.");
            }
            self::$columnTypes[$key] = $types;
        }

        return self::$columnTypes[$key];
    }

    /**
     * @param  class-string<Model>  $model
     * @return array<string, mixed>
     */
    private static function constant(string $model, string $name): array
    {
        $constant = $model.'::'.$name;

        return defined($constant) ? (array) constant($constant) : [];
    }
}
