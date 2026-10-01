<?php

namespace App\Platform\ChangeLog\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Access\Support\CoreSecurityCatalog;
use App\Platform\ChangeLog\Support\ChangeLogSetup;
use App\Platform\Modules\Contracts\ChangeHistory;
use App\Platform\Modules\Contracts\RowVersion;
use App\Platform\Tenant\Models\TenantMembership;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Setelan log perubahan tenant dan riwayat perubahan satu record untuk admin.
 *
 * Riwayat di sini membaca record apa pun di tenant, jadi izinnya `core.change-log.read` — izin admin, seperti
 * membuka Change Log Entries di BC. Layar module tidak memakai rute ini: module membuka riwayat record
 * miliknya lewat rutenya sendiri, sesudah memeriksa hak dan cakupan organisasi atas record itu.
 */
final class ChangeLogController extends Controller
{
    public function __construct(private readonly ChangeLogSetup $setup) {}

    public function index(Request $request): Response
    {
        $membership = $this->membershipWith($request, CoreSecurityCatalog::CHANGE_LOG_READ);
        $versions = DB::table('change_log_setup_tables')->where('tenant_id', $membership->tenant_id)->pluck('version', 'table_name');

        return Inertia::render('platform/change-log/change-log', [
            'canManage' => $membership->hasCorePermission(CoreSecurityCatalog::CHANGE_LOG_UPDATE),
            // Versi baris setelan milik tenant; 0 selama tabel itu masih memakai bawaan.
            'tables' => array_map(
                fn (array $table): array => [...$table, 'version' => $versions[$table['table_name']] ?? 0],
                $this->setup->forTenant($membership->tenant_id),
            ),
        ]);
    }

    public function update(Request $request, string $table): RedirectResponse
    {
        $membership = $this->membershipWith($request, CoreSecurityCatalog::CHANGE_LOG_UPDATE);
        abort_unless($this->setup->isRegistered($table), 404);

        $fields = $this->setup->registeredFields($table);
        $data = $request->validate([
            'log_insertion' => ['required', 'boolean'],
            'log_modification' => ['required', 'boolean'],
            'log_deletion' => ['required', 'boolean'],
            'fields' => ['array'],
            'fields.*' => ['array:log_insertion,log_modification,log_deletion'],
            'fields.*.*' => ['boolean'],
        ]);
        $unknown = array_diff(array_keys($data['fields'] ?? []), $fields);
        abort_if($unknown !== [], 422, 'Ada field yang tidak dapat dicatat untuk tabel ini.');

        DB::transaction(function () use ($request, $membership, $table, $data): void {
            // Baris setelan tabel milik tenant menjadi induk field-fieldnya; yang diklaim baris itu. Sebelum
            // tenant pernah menyimpan, barisnya belum ada dan halamannya mengirim versi 0.
            RowVersion::claimIfExists(
                DB::table('change_log_setup_tables')->where('tenant_id', $membership->tenant_id)->where('table_name', $table),
                RowVersion::expected($request),
            );

            $this->setup->save(
                $membership->tenant_id,
                $table,
                ['log_insertion' => (bool) $data['log_insertion'], 'log_modification' => (bool) $data['log_modification'], 'log_deletion' => (bool) $data['log_deletion']],
                array_map(fn (array $flags): array => [
                    'log_insertion' => (bool) ($flags['log_insertion'] ?? false),
                    'log_modification' => (bool) ($flags['log_modification'] ?? false),
                    'log_deletion' => (bool) ($flags['log_deletion'] ?? false),
                ], $data['fields'] ?? []),
            );
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Setelan riwayat perubahan disimpan.']);

        return back();
    }

    public function history(Request $request, ChangeHistory $history, string $table, string $record): JsonResponse
    {
        $membership = $this->membershipWith($request, CoreSecurityCatalog::CHANGE_LOG_READ);
        $page = (int) ($request->validate(['page' => ['nullable', 'integer', 'min:1']])['page'] ?? 1);

        return response()->json($history->forRecord($membership->tenant_id, $table, $record, $page));
    }

    private function membershipWith(Request $request, string $permission): TenantMembership
    {
        $membership = $this->currentMembership($request);
        abort_unless($membership->hasCorePermission($permission), 403);

        return $membership;
    }
}
