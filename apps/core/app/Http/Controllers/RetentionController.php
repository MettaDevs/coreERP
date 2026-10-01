<?php

namespace App\Http\Controllers;

use App\Platform\Access\Support\CoreSecurityCatalog;
use App\Platform\Tenant\Models\TenantMembership;
use App\Support\Modules\Contracts\RowVersion;
use App\Support\Retention\RetentionPolicies;
use App\Support\Retention\RetentionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Pengaturan → Retensi data: masa simpan per kebijakan untuk tenant dan hasil penerapan terakhir (K-15, K-16).
 * Kebijakan yang tampil hanya yang didaftarkan kode di `RetentionPolicies`; masa simpan tidak boleh di bawah
 * minimum kebijakan itu.
 */
final class RetentionController extends Controller
{
    private const LATEST_ENTRIES = 30;

    public function __construct(private readonly RetentionService $retention) {}

    public function index(Request $request): Response
    {
        $membership = $this->membershipWith($request, CoreSecurityCatalog::RETENTION_READ);
        $settings = $this->retention->settingsFor($membership->tenant_id);

        $policies = array_map(fn ($policy): array => [
            'code' => $policy->code,
            'caption' => $policy->caption,
            'minimum_days' => $policy->minimumDays,
            'default_days' => $policy->defaultDays(),
            ...$settings[$policy->code],
        ], array_values(array_filter(RetentionPolicies::all(), fn ($policy): bool => $policy->tenantConfigurable)));

        $captions = array_column($policies, 'caption', 'code');
        $entries = DB::table('retention_policy_log_entries')
            ->where('tenant_id', $membership->tenant_id)
            ->whereIn('policy_code', array_keys($captions))
            ->orderByDesc('created_at')->orderByDesc('id')
            ->limit(self::LATEST_ENTRIES)
            ->get()
            ->map(fn (object $entry): array => [
                'id' => $entry->id,
                'policy' => $captions[$entry->policy_code] ?? $entry->policy_code,
                'deleted_count' => (int) $entry->deleted_count,
                'cutoff_at' => $entry->cutoff_at,
                'status' => $entry->status,
                'message' => $entry->message,
                'created_at' => $entry->created_at,
            ])->all();

        return Inertia::render('settings/retention', [
            'canManage' => $membership->hasCorePermission(CoreSecurityCatalog::RETENTION_UPDATE),
            'policies' => $policies,
            'entries' => $entries,
        ]);
    }

    public function update(Request $request, string $policy): RedirectResponse
    {
        $membership = $this->membershipWith($request, CoreSecurityCatalog::RETENTION_UPDATE);
        $registered = collect(RetentionPolicies::all())->firstWhere('code', $policy);
        abort_if($registered === null || ! $registered->tenantConfigurable, 404);

        $optional = $registered->defaultDays() === null;
        $data = $request->validate([
            'enabled' => [$optional ? 'required' : 'nullable', 'boolean'],
            'retention_days' => [
                $optional && ! $request->boolean('enabled') ? 'nullable' : 'required',
                'integer', "min:{$registered->minimumDays}", 'max:36500',
            ],
        ], [
            'retention_days.required' => 'Isi berapa hari data disimpan.',
            'retention_days.integer' => 'Masa simpan harus berupa angka hari.',
            'retention_days.min' => "Masa simpan {$registered->caption} tidak boleh kurang dari {$registered->minimumDays} hari.",
            'retention_days.max' => 'Masa simpan terlalu lama. Isi paling banyak 36.500 hari.',
        ]);

        DB::transaction(function () use ($request, $membership, $registered, $optional, $data): void {
            // Setelan tenant baru lahir saat pertama disimpan; layar mengirim versi 0 selama masih bawaan.
            RowVersion::claimIfExists(
                DB::table('retention_policy_setups')->where(['tenant_id' => $membership->tenant_id, 'policy_code' => $registered->code]),
                RowVersion::expected($request),
            );
            $this->retention->save(
                $membership->tenant_id,
                $registered,
                $optional ? (bool) $data['enabled'] : true,
                isset($data['retention_days']) ? (int) $data['retention_days'] : null,
            );
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Masa simpan data disimpan.']);

        return back();
    }

    private function membershipWith(Request $request, string $permission): TenantMembership
    {
        $membership = $this->currentMembership($request);
        abort_unless($membership->hasCorePermission($permission), 403);

        return $membership;
    }
}
