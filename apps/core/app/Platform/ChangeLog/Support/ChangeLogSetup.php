<?php

declare(strict_types=1);

namespace App\Platform\ChangeLog\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Setelan log perubahan satu tenant, padanan halaman Change Log Setup di Business Central.
 *
 * Yang dapat diatur hanya tabel yang didaftarkan pemiliknya lewat `ChangeLogDefaults`, karena hanya tabel itu
 * yang punya nama tabel dan field untuk dibaca orang. Tenant yang belum menyimpan apa pun memakai bawaannya;
 * menyimpan berarti menulis salinan lengkap untuk tabel itu — baris tabel dan semua field terdaftar — yang
 * lalu menggantikan bawaan. Tenant hanya memilih dicatat atau tidak per field (`some`/`none`); mode `all`
 * dipakai untuk tabel yang selalu dicatat.
 */
final class ChangeLogSetup
{
    private const OPERATIONS = ['log_insertion', 'log_modification', 'log_deletion'];

    /**
     * @return list<array{
     *     table_name: string,
     *     table_caption: string,
     *     customized: bool,
     *     log_insertion: bool,
     *     log_modification: bool,
     *     log_deletion: bool,
     *     fields: list<array{field_name: string, field_caption: string, log_insertion: bool, log_modification: bool, log_deletion: bool}>
     * }>
     */
    public function forTenant(string $tenantId): array
    {
        $defaults = DB::table('change_log_setup_tables')->whereNull('tenant_id')->orderBy('table_caption')->get();
        $own = DB::table('change_log_setup_tables')->where('tenant_id', $tenantId)->get()->keyBy('table_name');
        $fields = DB::table('change_log_setup_fields')
            ->where(fn ($query) => $query->whereNull('tenant_id')->orWhere('tenant_id', $tenantId))
            ->orderBy('field_caption')
            ->get()
            ->groupBy('table_name');

        $tables = [];
        foreach ($defaults as $default) {
            $table = (string) $default->table_name;
            $customized = $own->has($table);
            $source = $customized ? $own->get($table) : $default;
            $tableFields = $fields->get($table, collect());
            $ownFields = $tableFields->whereNotNull('tenant_id')->keyBy('field_name');

            $tables[] = [
                'table_name' => $table,
                'table_caption' => (string) ($default->table_caption ?? $table),
                'customized' => $customized,
                ...$this->flags($source),
                'fields' => array_values($tableFields->whereNull('tenant_id')->map(function (object $field) use ($customized, $ownFields): array {
                    $source = $customized ? ($ownFields->get($field->field_name) ?? null) : $field;

                    return [
                        'field_name' => (string) $field->field_name,
                        'field_caption' => (string) ($field->field_caption ?? $field->field_name),
                        'log_insertion' => (bool) ($source->log_insertion ?? false),
                        'log_modification' => (bool) ($source->log_modification ?? false),
                        'log_deletion' => (bool) ($source->log_deletion ?? false),
                    ];
                })->all()),
            ];
        }

        return $tables;
    }

    /** @return list<string> */
    public function registeredFields(string $table): array
    {
        return array_values(DB::table('change_log_setup_fields')->whereNull('tenant_id')->where('table_name', $table)
            ->pluck('field_name')->map(fn ($field): string => (string) $field)->all());
    }

    public function isRegistered(string $table): bool
    {
        return DB::table('change_log_setup_tables')->whereNull('tenant_id')->where('table_name', $table)->exists();
    }

    /**
     * @param  array{log_insertion: bool, log_modification: bool, log_deletion: bool}  $table
     * @param  array<string, array{log_insertion: bool, log_modification: bool, log_deletion: bool}>  $fields
     */
    public function save(string $tenantId, string $tableName, array $table, array $fields): void
    {
        $now = now();

        DB::transaction(function () use ($tenantId, $tableName, $table, $fields, $now): void {
            $this->upsert('change_log_setup_tables', $tenantId, ['table_name' => $tableName], [
                'log_insertion' => $table['log_insertion'] ? 'some' : 'none',
                'log_modification' => $table['log_modification'] ? 'some' : 'none',
                'log_deletion' => $table['log_deletion'] ? 'some' : 'none',
                'updated_at' => $now,
            ]);

            foreach ($this->registeredFields($tableName) as $field) {
                $this->upsert('change_log_setup_fields', $tenantId, ['table_name' => $tableName, 'field_name' => $field], [
                    'log_insertion' => (bool) ($fields[$field]['log_insertion'] ?? false),
                    'log_modification' => (bool) ($fields[$field]['log_modification'] ?? false),
                    'log_deletion' => (bool) ($fields[$field]['log_deletion'] ?? false),
                    'updated_at' => $now,
                ]);
            }
        });
    }

    /** @return array{log_insertion: bool, log_modification: bool, log_deletion: bool} */
    private function flags(object $row): array
    {
        $flags = [];
        foreach (self::OPERATIONS as $operation) {
            $flags[$operation] = ($row->{$operation} ?? 'none') !== 'none';
        }

        /** @var array{log_insertion: bool, log_modification: bool, log_deletion: bool} $flags */
        return $flags;
    }

    /**
     * @param  array<string, string>  $key
     * @param  array<string, mixed>  $values
     */
    private function upsert(string $table, string $tenantId, array $key, array $values): void
    {
        $updated = DB::table($table)->where('tenant_id', $tenantId)->where($key)->update($values);

        if ($updated === 0) {
            DB::table($table)->insert([
                'id' => (string) Str::ulid(), 'tenant_id' => $tenantId, ...$key, ...$values, 'created_at' => $values['updated_at'],
            ]);
        }
    }
}
