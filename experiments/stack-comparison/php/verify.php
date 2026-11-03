<?php

declare(strict_types=1);

use App\Platform\Modules\Contracts\TenantRunner;
use App\Platform\Organization\Support\BusinessUnitResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Apperp\ManagementAset\Services\AcquisitionPosting;

require __DIR__.'/bootstrap.php';

comparisonGuard();
$fixture = comparisonLoad();
comparisonOwnsDatabase($fixture);
$checks = 0;
$completedReceipts = 0;

// Oracle ini membaca tabel langsung; tidak mempercayai respons API atau model scope.
assertFixture('tenant_count', (int) DB::table('tenants')->count() === count($fixture['tenants']));
assertFixture('positive_account_count', count($fixture['accounts']) === count($fixture['tenants']) * $fixture['users_per_tenant']);
foreach ($fixture['accounts'] as $account) {
    $row = DB::selectOne(<<<'SQL'
select count(*) as amount
from users u join tenant_memberships m on m.user_id = u.id
join environments e on e.tenant_id = m.tenant_id and e.kind = 'production'
join environment_members em on em.environment_id = e.id and em.user_id = u.id
where u.id = ? and u.email = ? and u.account_type = 'person'
  and m.id = ? and m.tenant_id = ? and m.status = 'active'
  and em.status = 'active' and e.status = 'active' and e.hosting = 'provider'
  and e.database_name is null
SQL, [$account['user_id'], $account['email'], $account['membership_id'], $account['tenant_id']]);
    assertFixture('positive_account_membership', (int) $row->amount === 1);
}

foreach ($fixture['tenants'] as $tenant) {
    $tenantId = $tenant['tenant_id'];
    $row = DB::selectOne(<<<'SQL'
select count(*) as amount
from core_module_installations
where tenant_id = ? and module_id = ? and status = 'installed' and seeded_at is not null
SQL, [$tenantId, $fixture['module_id']]);
    assertFixture('module_installed_seeded', (int) $row->amount === 1);

    $row = DB::selectOne(<<<'SQL'
select count(*) as amount
from aset_m_group_aset g
join aset_m_kelompok_harta_fiskal c on c.tenant_id = g.tenant_id and c.id = g.kelompok_harta_fiskal_id
join aset_m_lokasi_aset l on l.tenant_id = g.tenant_id and l.id = g.lokasi_aset_id
join aset_m_group_buku_penyusutan gb on gb.tenant_id = g.tenant_id and gb.group_aset_id = g.id
join aset_m_buku_penyusutan b on b.tenant_id = gb.tenant_id and b.id = gb.buku_id
join aset_m_profil_penyusutan p on p.tenant_id = b.tenant_id and p.id = b.depreciation_profile_id
where g.tenant_id = ? and g.id = ? and l.id = ? and b.id = ?
  and g.deleted_at is null and g.aktif and c.deleted_at is null and c.aktif and c.kode <> ''
  and l.deleted_at is null and l.aktif and l.kode <> ''
  and l.org_unit_id = ? and l.departemen_bawaan_id = ?
  and b.deleted_at is null and b.aktif and b.posting_layer = 'current'
  and gb.deleted_at is null and gb.depreciate and gb.useful_life_periods = 48 and gb.convention = 'full_month'
  and p.deleted_at is null and p.aktif and p.method = 'straight_line'
  and p.frequency = 'monthly' and p.year_basis = 'calendar' and p.useful_life_periods = 48
SQL, [$tenantId, $tenant['group_id'], $tenant['location_id'], $tenant['book_id'], $tenant['department_id'], $tenant['department_id']]);
    assertFixture('depreciating_book_profile', (int) $row->amount === 1);

    $row = DB::selectOne(<<<'SQL'
select count(*) as amount
from aset_m_jenis_aset_atribut a
join aset_m_tipe_atribut t on t.tenant_id = a.tenant_id and t.id = a.tipe_atribut_id
where a.tenant_id = ? and a.jenis_aset_id = ? and t.id = ?
  and a.wajib and a.deleted_at is null and t.deleted_at is null and t.aktif and t.data_type = 'decimal'
  and t.min_value <= cast(? as numeric) and t.max_value >= cast(? as numeric)
SQL, [$tenantId, $tenant['asset_type_id'], $tenant['attribute_id'], $tenant['attribute_value'], $tenant['attribute_value']]);
    assertFixture('required_typed_attribute', (int) $row->amount === 1);

    $row = DB::selectOne(<<<'SQL'
select count(*) as amount
from finance_posting_settings s
join currency_precisions c on c.tenant_id = s.tenant_id
join vendors v on v.tenant_id = s.tenant_id and v.legal_entity_id = s.legal_entity_id
join parties p on p.tenant_id = v.tenant_id and p.id = v.party_id
where s.tenant_id = ? and s.legal_entity_id = ? and s.enabled and s.cutover_date <= cast(? as date)
  and c.currency_code = ? and c.amount_decimals = 2 and c.unit_amount_decimals = 3
  and v.id = ? and v.status = 'active' and p.status = 'active'
SQL, [$tenantId, $tenant['legal_entity_id'], $fixture['receipt_date'], $fixture['currency_code'], $tenant['vendor_id']]);
    assertFixture('feed_currency_vendor_party', (int) $row->amount === 1);

    $bu = app(BusinessUnitResolver::class)->resolve($tenantId, [$tenant['department_id']], $fixture['receipt_date']);
    assertFixture('published_management_dimension', ($bu[$tenant['department_id']]['id'] ?? null) === $tenant['business_unit_id']);

    // Ini preflight layanan produksi, bukan autentikasi atau pengukuran HTTP.
    // Jurnal pratinjau tidak disimpan dan tidak mengalokasikan nomor.
    $preview = app(TenantRunner::class)->runFor($tenantId, function () use ($tenant): array {
        $body = $tenant['receipt_body'];
        $lines = collect($body['details'])->map(static fn (array $line): stdClass => (object) $line);
        $receipt = (object) [
            ...array_diff_key($body, ['details' => true]), 'id' => (string) Str::ulid(),
            'tenant_id' => $tenant['tenant_id'], 'kode' => 'PREFLIGHT-'.$tenant['fixture_index'],
        ];

        return app(AcquisitionPosting::class)->preview($receipt, $lines);
    });
    assertFixture('production_preview_no_blockers', $preview['blockers'] === []);
    assertFixture('production_preview_pending', ($preview['posting']['status'] ?? null) === 'pending');
    assertFixture('production_preview_no_problems', ($preview['posting']['problems'] ?? null) === []);

    $orphans = DB::selectOne(<<<'SQL'
select count(*) as amount from aset_tr_aset a
join aset_tr_penerimaan_aset r on r.id = a.penerimaan_aset_id
where a.tenant_id = ? and (r.tenant_id <> a.tenant_id or r.legal_entity_id <> a.legal_entity_id)
SQL, [$tenantId]);
    assertFixture('no_receipt_scope_mismatch', (int) $orphans->amount === 0);
    foreach (DB::table('aset_tr_penerimaan_aset')->where('tenant_id', $tenantId)->whereNull('deleted_at')->get() as $receipt) {
        verifyReceipt($receipt, $tenant);
        $completedReceipts += $receipt->status === 'selesai' ? 1 : 0;
    }
    comparisonReport(['tenant_index' => $tenant['fixture_index'], 'profile_pending_eligible' => true]);
}

if (getenv('COMPARE_ORACLE_SELFTEST') === '1') {
    assertFixture('deliberate_selftest_failure', false);
}
comparisonReport(['status' => 'verified', 'checks' => $checks, 'tenants' => count($fixture['tenants']), 'positive_accounts' => count($fixture['accounts']), 'completed_receipts_checked' => $completedReceipts, 'http_auth_verified_by_this_script' => false]);

function assertFixture(string $label, bool $condition): void
{
    global $checks;
    $checks++;
    if (! $condition) {
        throw new RuntimeException('Oracle gagal: '.$label);
    }
}

/** @param array<string, mixed> $tenant */
function verifyReceipt(stdClass $receipt, array $tenant): void
{
    $assets = DB::selectOne(<<<'SQL'
select count(*) as amount, coalesce(sum(acquisition_value),0)::text as value
from aset_tr_aset where tenant_id = ? and penerimaan_aset_id = ? and deleted_at is null
SQL, [$receipt->tenant_id, $receipt->id]);
    $postings = DB::table('finance_postings')->where('tenant_id', $receipt->tenant_id)->where('posting_id', 'AST-ACQ-'.$receipt->id)->get();
    if ($receipt->status === 'draft') {
        assertFixture('draft_no_completion_effect', (int) $assets->amount === 0 && $postings->isEmpty());

        return;
    }
    if ($receipt->status !== 'selesai') {
        return; // Pembatalan adalah profil lain dan tidak diklaim oleh fixture ini.
    }
    $expected = DB::selectOne(<<<'SQL'
select coalesce(sum(jumlah),0) as quantity,
       coalesce(sum(round(nilai_per_unit * jumlah,2)),0)::text as value,
       coalesce(sum(round(nilai_per_unit * jumlah,2) + round(ppn_per_unit * jumlah,2)),0)::text as total
from aset_tr_penerimaan_aset_details where tenant_id = ? and penerimaan_aset_id = ?
SQL, [$receipt->tenant_id, $receipt->id]);
    assertFixture('completed_asset_count', (int) $assets->amount === (int) $expected->quantity);
    assertFixture('completed_asset_value', bccomp($assets->value, $expected->value, 2) === 0);
    assertFixture('one_pending_posting', $postings->count() === 1 && $postings[0]->status === 'pending');
    $posting = $postings[0];
    assertFixture('posting_scope', $posting->legal_entity_id === $receipt->legal_entity_id && $posting->vendor_id === $receipt->vendor_id && $posting->settlement_mode === 'direct_payable');
    $journal = DB::selectOne(<<<'SQL'
select count(*) as amount, sum(debit)::text as debit, sum(credit)::text as credit,
       count(*) filter (where (debit > 0) = (credit > 0)) as invalid_sides,
       count(*) filter (where business_unit_code is distinct from ?) as wrong_business_unit
from finance_posting_lines where tenant_id = ? and finance_posting_id = ?
SQL, [$tenant['business_unit_code'], $receipt->tenant_id, $posting->id]);
    assertFixture('balanced_journal', (int) $journal->amount >= 2 && bccomp($journal->debit, $journal->credit, 2) === 0 && bccomp($journal->debit, $expected->total, 2) === 0);
    assertFixture('journal_sides_dimensions', (int) $journal->invalid_sides === 0 && (int) $journal->wrong_business_unit === 0);
    $effects = DB::selectOne(<<<'SQL'
select count(*) as amount,
       count(*) filter (where b.id is not null and b.depreciate and b.useful_life_periods = 48 and b.depreciation_profile_id = ?) as books,
       count(*) filter (where l.id is not null and l.acquisition_delta = a.acquisition_value and l.source_id = ?) as ledgers,
       count(*) filter (where t.id is not null and t.nilai_number = cast(? as numeric)) as attributes,
       count(*) filter (where p.id is not null) as placements
from aset_tr_aset a
left join aset_tr_buku_aset b on b.tenant_id = a.tenant_id and b.aset_id = a.id and b.buku_id = ?
left join aset_tr_ledger_aset l on l.tenant_id = a.tenant_id and l.aset_id = a.id and l.buku_aset_id = b.id and l.posting_type = 'acquisition'
left join aset_tr_aset_atribut t on t.tenant_id = a.tenant_id and t.aset_id = a.id and t.tipe_atribut_id = ?
left join aset_tr_penempatan_aset p on p.tenant_id = a.tenant_id and p.aset_id = a.id
where a.tenant_id = ? and a.penerimaan_aset_id = ? and a.deleted_at is null
SQL, [$tenant['profile_id'], $receipt->id, $tenant['attribute_value'], $tenant['book_id'], $tenant['attribute_id'], $receipt->tenant_id, $receipt->id]);
    $quantity = (int) $expected->quantity;
    assertFixture('no_duplicate_completion_effect', (int) $effects->amount === $quantity);
    foreach (['books', 'ledgers', 'attributes', 'placements'] as $effect) {
        assertFixture('completed_'.$effect, (int) $effects->{$effect} === $quantity);
    }
    assertFixture('typed_attribute_locked', (bool) DB::table('aset_m_tipe_atribut')->where('tenant_id', $receipt->tenant_id)->where('id', $tenant['attribute_id'])->value('data_type_locked'));
    assertFixture('posting_published_event', DB::table('finance_posting_events')->where('tenant_id', $receipt->tenant_id)->where('finance_posting_id', $posting->id)->where('event', 'published')->count() === 1);
}
