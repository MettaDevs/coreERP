<?php

declare(strict_types=1);

namespace App\Services\Modules;

use App\Models\User;
use App\Support\Modules\Contracts\ChangeHistory;
use App\Support\Modules\Contracts\ChangeLogValueResolvers;
use App\Support\Modules\Contracts\PelaksanaUntukTenant;
use Illuminate\Support\Facades\DB;

/**
 * Membaca riwayat satu record dari `change_log_entries` dan melengkapinya untuk layar: nama pelaku, nama
 * field dari setelan bawaan, dan nilai tampilan dari penerjemah milik pemilik tabel.
 */
final class ChangeHistoryCore implements ChangeHistory
{
    public function __construct(
        private readonly ChangeLogValueResolvers $resolvers,
        private readonly PelaksanaUntukTenant $pelaksana,
    ) {}

    public function forRecord(string $tenantId, string $table, string $recordId, int $page = 1): array
    {
        $page = max(1, $page);
        $rows = DB::table('change_log_entries')
            ->where(['tenant_id' => $tenantId, 'table_name' => $table, 'record_id' => $recordId])
            ->orderByDesc('id')
            ->offset(($page - 1) * self::PER_PAGE)
            ->limit(self::PER_PAGE + 1)
            ->get(['id', 'changed_at', 'created_by_user_id', 'field_name', 'change_type', 'old_value', 'new_value']);

        $hasMore = $rows->count() > self::PER_PAGE;
        $rows = $rows->take(self::PER_PAGE)->values();

        // Nama dibaca lewat model User: tabel `users` bisa berada di database pusat, bukan database tenant.
        $names = User::query()
            ->whereIn('id', $rows->pluck('created_by_user_id')->filter()->unique()->values())
            ->pluck('name', 'id');

        $captions = DB::table('change_log_setup_fields')
            ->whereNull('tenant_id')
            ->where('table_name', $table)
            ->whereNotNull('field_caption')
            ->pluck('field_caption', 'field_name');

        $display = $this->display($tenantId, $table, $rows->all());

        $data = [];
        foreach ($rows as $row) {
            $field = (string) $row->field_name;
            $userId = $row->created_by_user_id === null ? null : (int) $row->created_by_user_id;
            $old = $row->old_value === null ? null : (string) $row->old_value;
            $new = $row->new_value === null ? null : (string) $row->new_value;

            $data[] = [
                'id' => (int) $row->id,
                'changed_at' => (string) $row->changed_at,
                'user_id' => $userId,
                'user_name' => $userId === null ? null : ($names[$userId] ?? null),
                'field_name' => $field,
                'field_caption' => isset($captions[$field]) ? (string) $captions[$field] : null,
                'change_type' => (string) $row->change_type,
                'old_value' => $old,
                'new_value' => $new,
                'old_display' => $old === null ? null : ($display[$field][$old] ?? null),
                'new_display' => $new === null ? null : ($display[$field][$new] ?? null),
            ];
        }

        return ['data' => $data, 'next_page' => $hasMore ? $page + 1 : null];
    }

    /**
     * Nilai tampilan per field, satu panggilan penerjemah per field.
     *
     * @param  array<int, \stdClass>  $rows
     * @return array<string, array<string, string>>
     */
    private function display(string $tenantId, string $table, array $rows): array
    {
        $resolver = $this->resolvers->for($table);
        if ($resolver === null) {
            return [];
        }

        $values = [];
        foreach ($rows as $row) {
            foreach ([$row->old_value, $row->new_value] as $value) {
                if ($value !== null) {
                    $values[(string) $row->field_name][(string) $value] = true;
                }
            }
        }

        // Model module menyaring lewat tenant aktif; riwayat juga dibaca dari rute admin Core yang tidak
        // melewati middleware konteks module, jadi tenant-nya disebut di sini.
        return $this->pelaksana->jalankanUntuk($tenantId, function () use ($resolver, $tenantId, $values): array {
            $display = [];
            foreach ($values as $field => $set) {
                $display[$field] = $resolver->display($tenantId, $field, array_map('strval', array_keys($set)));
            }

            return $display;
        });
    }
}
