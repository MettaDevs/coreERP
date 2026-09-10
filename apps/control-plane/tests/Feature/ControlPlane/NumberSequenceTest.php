<?php

namespace Tests\Feature\ControlPlane;

use App\Actions\FiscalCalendar\FiscalCalendarService;
use App\Actions\NumberSequence\EnsureNumberSequenceDrafts;
use App\Actions\NumberSequence\NumberSequenceService;
use App\Models\AppServiceCredential;
use App\Models\FiscalCalendar;
use App\Models\ModuleInstallation;
use App\Models\NumberSequenceReference;
use App\Models\TenantMembership;
use App\Models\TenantNumberSequence;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class NumberSequenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_non_continuous_sequence_uses_a_durable_preallocated_block_and_is_idempotent(): void
    {
        [$sequence, $context] = $this->sequence();
        $service = app(NumberSequenceService::class);

        $first = $service->issue($context, 'sample-app.document', 'transaction-1');
        $retry = $service->issue($context, 'sample-app.document', 'transaction-1');
        $second = app(NumberSequenceService::class)->issue($context, 'sample-app.document', 'transaction-2');

        $this->assertSame('000001', $first['number']);
        $this->assertSame($first, $retry);
        $this->assertSame('000002', $second['number']);
        $this->assertDatabaseHas('number_sequence_allocations', ['sequence_id' => $sequence->id, 'first_number' => 1, 'last_number' => 20, 'next_number' => 3]);
        $this->assertDatabaseHas('number_sequence_counters', ['sequence_id' => $sequence->id, 'next_number' => 21]);
    }

    public function test_owner_can_save_number_sequence_settings_from_the_ui(): void
    {
        [$sequence, $context] = $this->sequence();
        $user = User::factory()->create();
        TenantMembership::query()->create([
            'tenant_id' => $context['tenant_id'],
            'user_id' => $user->id,
            'system_role' => 'owner',
            'status' => 'active',
        ]);

        $this->actingAs($user)
            ->patch("/settings/number-sequences/{$sequence->id}", $this->settings([
                'preallocation_quantity' => 21,
            ]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('tenant_number_sequences', [
            'id' => $sequence->id,
            'preallocation_quantity' => 21,
        ]);
    }

    public function test_scope_has_an_independent_counter_for_each_operating_unit(): void
    {
        [$sequence, $context] = $this->sequence(['scope_type' => 'operating_unit', 'preallocation_enabled' => false]);
        $firstUnit = $this->organization($context['tenant_id'], 'operating_unit', 'ONE');
        $secondUnit = $this->organization($context['tenant_id'], 'operating_unit', 'TWO');
        $service = app(NumberSequenceService::class);

        $first = $service->issue([...$context, 'org_unit_id' => $firstUnit], 'sample-app.document', 'one');
        $second = $service->issue([...$context, 'org_unit_id' => $secondUnit], 'sample-app.document', 'two');

        $this->assertSame('000001', $first['number']);
        $this->assertSame('000001', $second['number']);
        $this->assertDatabaseCount('number_sequence_counters', 2);
    }

    public function test_manual_number_must_match_format_and_does_not_move_counter(): void
    {
        [$sequence, $context] = $this->sequence([
            'allow_manual' => true,
            'segments' => [['type' => 'constant', 'value' => 'DOC-'], ['type' => 'number', 'length' => 6]],
        ]);
        $service = app(NumberSequenceService::class);

        $service->issue($context, 'sample-app.document', 'manual', 'DOC-000500');
        $automatic = $service->issue($context, 'sample-app.document', 'automatic');

        $this->assertSame('DOC-000001', $automatic['number']);
        $this->expectException(ValidationException::class);
        $service->issue($context, 'sample-app.document', 'bad-manual', 'DOC-X');
    }

    public function test_automatic_number_skips_a_manual_number_with_the_same_format(): void
    {
        [$sequence, $context] = $this->sequence([
            'allow_manual' => true,
            'segments' => [['type' => 'constant', 'value' => 'DOC-'], ['type' => 'number', 'length' => 6]],
        ]);
        $service = app(NumberSequenceService::class);

        $service->issue($context, 'sample-app.document', 'manual-first', 'DOC-000001');
        $automatic = $service->issue($context, 'sample-app.document', 'automatic-after-manual');

        $this->assertSame('DOC-000002', $automatic['number']);
        $this->assertSame(1, DB::table('number_sequence_issues')->where('sequence_id', $sequence->id)->where('formatted_value', 'DOC-000001')->count());
    }

    public function test_advance_exhausts_an_existing_preallocated_block(): void
    {
        [$sequence, $context] = $this->sequence();
        $service = app(NumberSequenceService::class);

        $service->issue($context, 'sample-app.document', 'before-advance');
        $service->advance($sequence, $context, 100, null);
        $after = $service->issue($context, 'sample-app.document', 'after-advance');

        $this->assertSame('000100', $after['number']);
        // advance() exhausts the old block, and allocating the replacement prunes it: dead blocks are not kept, or
        // every later issue() would pay to scan past them.
        $this->assertDatabaseMissing('number_sequence_allocations', ['sequence_id' => $sequence->id, 'first_number' => 1]);
        $this->assertDatabaseHas('number_sequence_allocations', ['sequence_id' => $sequence->id, 'first_number' => 100, 'last_number' => 119, 'next_number' => 101]);
    }

    public function test_final_preallocated_block_uses_numbers_up_to_the_maximum(): void
    {
        [, $context] = $this->sequence(['maximum_number' => 25]);
        $service = app(NumberSequenceService::class);
        $last = null;

        foreach (range(1, 25) as $number) {
            $last = $service->issue($context, 'sample-app.document', 'maximum-'.$number);
        }

        $this->assertSame('000025', $last['number']);
        $this->expectException(ValidationException::class);
        $service->issue($context, 'sample-app.document', 'over-maximum');
    }

    public function test_structural_settings_cannot_change_after_a_number_is_used(): void
    {
        [$sequence, $context] = $this->sequence();
        $service = app(NumberSequenceService::class);
        $service->issue($context, 'sample-app.document', 'before-configuration');

        $settings = [
            'profile_code' => 'non-continuous-default',
            'scope_type' => 'tenant',
            'status' => 'stopped',
            'is_continuous' => false,
            'allow_manual' => false,
            'reset_period' => 'never',
            'preallocation_enabled' => true,
            'preallocation_quantity' => 20,
            'minimum_number' => 1,
            'maximum_number' => null,
            'segments' => [['type' => 'number', 'length' => 6]],
        ];
        $updated = $service->configure($sequence, $settings, null);
        $this->assertSame('stopped', $updated->status);

        $this->expectException(ValidationException::class);
        $service->configure($updated, [
            ...$settings,
            'reset_period' => 'calendar_year',
            'segments' => [['type' => 'year'], ['type' => 'number', 'length' => 6]],
        ], null);
    }

    public function test_continuous_sequence_uses_a_durable_pool_and_requires_reconciliation_before_reuse(): void
    {
        [$sequence, $context] = $this->sequence([
            'is_continuous' => true,
            'preallocation_enabled' => true,
            'preallocation_quantity' => 5,
        ]);
        $service = app(NumberSequenceService::class);

        $first = $service->reserve($context, 'sample-app.document', 'first');
        $service->confirm($context, $first['id']);
        $second = $service->reserve($context, 'sample-app.document', 'second');
        $service->cancel($context, $second['id']);
        $reused = $service->reserve($context, 'sample-app.document', 'third');
        $expired = $service->reserve($context, 'sample-app.document', 'expired');
        DB::table('number_sequence_reservations')->where('id', $expired['id'])->update(['expires_at' => now()->subMinute()]);
        $this->assertSame(1, $service->recoverExpired());
        $recovered = $service->reserve($context, 'sample-app.document', 'recovered');

        $this->assertSame('000001', $first['number']);
        $this->assertSame('000002', $second['number']);
        $this->assertSame('000002', $reused['number']);
        $this->assertSame('000004', $recovered['number']);
        $this->assertDatabaseHas('number_sequence_audit_events', ['sequence_id' => $sequence->id, 'event_type' => 'cancelled']);
        $this->assertDatabaseHas('number_sequence_audit_events', ['sequence_id' => $sequence->id, 'event_type' => 'reconciliation_pending']);
        $this->assertDatabaseHas('number_sequence_continuous_pool', ['sequence_id' => $sequence->id, 'numeric_value' => 3, 'status' => 'reconciliation_pending']);
        $service->confirm($context, $expired['id']);
        $this->assertDatabaseHas('number_sequence_continuous_pool', ['sequence_id' => $sequence->id, 'numeric_value' => 3, 'status' => 'confirmed']);
    }

    public function test_stopped_sequence_cannot_issue_a_number(): void
    {
        [, $context] = $this->sequence(['status' => 'stopped']);

        $this->expectException(ValidationException::class);
        app(NumberSequenceService::class)->issue($context, 'sample-app.document', 'stopped');
    }

    public function test_internal_api_requires_ready_app_credential_and_uses_its_tenant_context(): void
    {
        [, $context] = $this->sequence();
        $this->readyAppForTenant($context['tenant_id'], $context['app_id']);
        AppServiceCredential::query()->create([
            'app_id' => $context['app_id'], 'name' => 'test', 'secret_hash' => Hash::make('secret-token'), 'status' => 'active',
        ]);

        $this->withHeaders([
            'X-CoreERP-App-Id' => $context['app_id'],
            'X-CoreERP-Service-Token' => 'secret-token',
            'X-CoreERP-Tenant-Id' => $context['tenant_id'],
        ])->postJson('/api/internal/v1/number-sequences/sample-app.document/issue', ['idempotency_key' => 'api-transaction'])
            ->assertOk()
            ->assertJsonPath('data.number', '000001');

        $this->withHeaders([
            'X-CoreERP-App-Id' => $context['app_id'],
            'X-CoreERP-Service-Token' => 'wrong',
            'X-CoreERP-Tenant-Id' => $context['tenant_id'],
        ])->postJson('/api/internal/v1/number-sequences/sample-app.document/issue', ['idempotency_key' => 'denied'])
            ->assertForbidden();
    }

    public function test_an_issued_token_authenticates_without_a_bcrypt_scan(): void
    {
        [, $context] = $this->sequence();
        $this->readyAppForTenant($context['tenant_id'], $context['app_id']);
        [$credential, $token] = AppServiceCredential::issueToken($context['app_id'], 'fast', $context['tenant_id']);

        $this->assertStringStartsWith($credential->id.'.', $token);
        $this->assertNull($credential->secret_hash);

        $this->withHeaders([
            'X-CoreERP-App-Id' => $context['app_id'],
            'X-CoreERP-Service-Token' => $token,
            'X-CoreERP-Tenant-Id' => $context['tenant_id'],
        ])->postJson('/api/internal/v1/number-sequences/sample-app.document/issue', ['idempotency_key' => 'fast-token'])
            ->assertOk();

        // A tampered secret with a valid credential id must not authenticate.
        $this->withHeaders([
            'X-CoreERP-App-Id' => $context['app_id'],
            'X-CoreERP-Service-Token' => $credential->id.'.wrong',
            'X-CoreERP-Tenant-Id' => $context['tenant_id'],
        ])->postJson('/api/internal/v1/number-sequences/sample-app.document/issue', ['idempotency_key' => 'tampered'])
            ->assertForbidden();
    }

    public function test_a_tenant_scoped_credential_cannot_issue_numbers_for_another_tenant(): void
    {
        [, $context] = $this->sequence();
        $this->readyAppForTenant($context['tenant_id'], $context['app_id']);
        $foreignTenant = $this->foreignTenant();
        AppServiceCredential::query()->create([
            'app_id' => $context['app_id'], 'tenant_id' => $foreignTenant, 'name' => 'scoped',
            'secret_hash' => Hash::make('scoped-token'), 'status' => 'active',
        ]);

        $this->withHeaders([
            'X-CoreERP-App-Id' => $context['app_id'],
            'X-CoreERP-Service-Token' => 'scoped-token',
            'X-CoreERP-Tenant-Id' => $context['tenant_id'],
        ])->postJson('/api/internal/v1/number-sequences/sample-app.document/issue', ['idempotency_key' => 'wrong-tenant'])
            ->assertForbidden();
    }

    public function test_an_unscoped_credential_still_works_for_an_entitled_tenant(): void
    {
        [, $context] = $this->sequence();
        $this->readyAppForTenant($context['tenant_id'], $context['app_id']);
        AppServiceCredential::query()->create([
            'app_id' => $context['app_id'], 'tenant_id' => null, 'name' => 'shared',
            'secret_hash' => Hash::make('shared-token'), 'status' => 'active',
        ]);

        $this->withHeaders([
            'X-CoreERP-App-Id' => $context['app_id'],
            'X-CoreERP-Service-Token' => 'shared-token',
            'X-CoreERP-Tenant-Id' => $context['tenant_id'],
        ])->postJson('/api/internal/v1/number-sequences/sample-app.document/issue', ['idempotency_key' => 'shared-ok'])
            ->assertOk()
            ->assertJsonPath('data.number', '000001');
    }

    public function test_reference_becomes_active_with_a_bounded_prefixed_default_only_after_the_app_is_ready_for_the_tenant(): void
    {
        [$sequence, $context] = $this->sequence();
        $sequence->delete();
        $drafts = app(EnsureNumberSequenceDrafts::class);

        $drafts->forReadyApp($context['app_id']);
        $this->assertDatabaseCount('tenant_number_sequences', 0);

        $this->readyAppForTenant($context['tenant_id'], $context['app_id']);
        $sequence->reference()->update(['default_prefix' => 'DOC']);
        $drafts->forReadyApp($context['app_id']);

        $created = TenantNumberSequence::query()->where('tenant_id', $context['tenant_id'])->firstOrFail();
        $this->assertSame('active', $created->status);
        $this->assertSame('tenant', $created->scope_type);
        $this->assertSame(0, $created->minimum_number);
        $this->assertSame(19999, $created->maximum_number);
        $this->assertSame([
            ['type' => 'constant', 'value' => 'DOC'],
            ['type' => 'number', 'length' => 5],
        ], $created->segments);
    }

    public function test_materialization_falls_back_to_the_only_scope_declared_by_the_manifest(): void
    {
        [$sequence, $context] = $this->sequence();
        $reference = $sequence->reference;
        $sequence->delete();
        $reference->update(['allowed_scopes' => ['legal_entity']]);
        $this->readyAppForTenant($context['tenant_id'], $context['app_id']);

        $drafts = app(EnsureNumberSequenceDrafts::class);
        $drafts->forReadyTenant($context['tenant_id']);
        $created = TenantNumberSequence::query()->where('tenant_id', $context['tenant_id'])->firstOrFail();

        $this->assertSame('legal_entity', $created->scope_type);

        $drafts->forReadyTenant($context['tenant_id']);
        $this->assertDatabaseCount('tenant_number_sequences', 1);
    }

    public function test_internal_issue_materializes_a_ready_tenant_sequence_before_issuing(): void
    {
        [$sequence, $context] = $this->sequence();
        $reference = $sequence->reference;
        $sequence->delete();
        $reference->update(['allowed_scopes' => ['legal_entity']]);
        $this->readyAppForTenant($context['tenant_id'], $context['app_id']);
        AppServiceCredential::query()->create([
            'app_id' => $context['app_id'], 'name' => 'seed', 'secret_hash' => Hash::make('seed-token'), 'status' => 'active',
        ]);
        $legalEntityId = $this->organization($context['tenant_id'], 'legal_entity', 'SEED');
        DB::table('legal_entities')->insert([
            'organization_id' => $legalEntityId, 'company_code' => 'SEED', 'country_code' => 'ID',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->withHeaders([
            'X-CoreERP-App-Id' => $context['app_id'],
            'X-CoreERP-Service-Token' => 'seed-token',
            'X-CoreERP-Tenant-Id' => $context['tenant_id'],
        ])->postJson('/api/internal/v1/number-sequences/sample-app.document/issue', [
            'idempotency_key' => 'seed-before-sequence',
            'legal_entity_id' => $legalEntityId,
        ])->assertOk()->assertJsonPath('data.number', '00000');

        $this->assertDatabaseHas('tenant_number_sequences', [
            'tenant_id' => $context['tenant_id'],
            'reference_id' => $reference->id,
            'scope_type' => 'legal_entity',
        ]);
    }

    public function test_scope_repair_migration_repairs_unused_rows_but_preserves_used_rows(): void
    {
        [$baseSequence, $context] = $this->sequence();
        $unusedReference = NumberSequenceReference::query()->create([
            'app_id' => $context['app_id'], 'code' => 'sample-app.legal-only-unused',
            'name' => 'Nomor legal entity belum dipakai', 'default_prefix' => 'UNUS',
            'allowed_scopes' => ['legal_entity'],
        ]);
        $usedReference = NumberSequenceReference::query()->create([
            'app_id' => $context['app_id'], 'code' => 'sample-app.legal-only-used',
            'name' => 'Nomor legal entity sudah dipakai', 'default_prefix' => 'USED',
            'allowed_scopes' => ['legal_entity'],
        ]);
        $settings = [
            'tenant_id' => $context['tenant_id'],
            'profile_code' => 'non-continuous-default',
            'scope_type' => 'tenant',
            'status' => 'active',
            'is_continuous' => false,
            'allow_manual' => false,
            'reset_period' => 'never',
            'preallocation_enabled' => true,
            'preallocation_quantity' => 20,
            'minimum_number' => 0,
            'maximum_number' => 19999,
            'segments' => [['type' => 'number', 'length' => 5]],
        ];
        $unusedSequence = TenantNumberSequence::query()->create([...$settings, 'reference_id' => $unusedReference->id]);
        $usedSequence = TenantNumberSequence::query()->create([...$settings, 'reference_id' => $usedReference->id]);
        app(NumberSequenceService::class)->issue([
            'tenant_id' => $context['tenant_id'], 'app_id' => $context['app_id'],
            'legal_entity_id' => null, 'org_unit_id' => null,
        ], $usedReference->code, 'used-before-repair');

        $migration = require database_path('migrations/2026_08_12_120000_repair_unused_number_sequence_scopes.php');
        $migration->up();

        $this->assertSame('legal_entity', $unusedSequence->refresh()->scope_type);
        $this->assertSame('tenant', $usedSequence->refresh()->scope_type);
        $this->assertSame('tenant', $baseSequence->refresh()->scope_type);
    }

    public function test_reserve_refuses_to_replay_a_cancelled_idempotency_key(): void
    {
        [, $context] = $this->sequence(['is_continuous' => true, 'preallocation_enabled' => true, 'preallocation_quantity' => 5]);
        $service = app(NumberSequenceService::class);

        $reservation = $service->reserve($context, 'sample-app.document', 'outbox-1');
        $service->cancel($context, $reservation['id']);
        $reused = $service->reserve($context, 'sample-app.document', 'someone-else');

        // The cancelled number went back to the pool and now belongs to another transaction, so replaying the
        // original key must not hand the same number out twice.
        $this->assertSame($reservation['number'], $reused['number']);
        $this->expectException(ValidationException::class);
        $service->reserve($context, 'sample-app.document', 'outbox-1');
    }

    public function test_confirm_is_idempotent_for_a_repeated_outbox_delivery(): void
    {
        [, $context] = $this->sequence(['is_continuous' => true, 'preallocation_enabled' => true, 'preallocation_quantity' => 5]);
        $service = app(NumberSequenceService::class);

        $reservation = $service->reserve($context, 'sample-app.document', 'outbox-double');
        $first = $service->confirm($context, $reservation['id']);
        $second = $service->confirm($context, $reservation['id']);

        $this->assertSame($first, $second);
        $this->assertSame(1, DB::table('number_sequence_issues')->where('formatted_value', $reservation['number'])->count());
    }

    public function test_a_reset_period_without_a_matching_segment_is_rejected(): void
    {
        [$sequence] = $this->sequence();
        $service = app(NumberSequenceService::class);

        // Without a year segment the counter restarts every year and re-issues numbers that were already used,
        // and the unique index cannot catch it because it is scoped by period_key.
        $this->expectException(ValidationException::class);
        $service->configure($sequence, [...$this->settings(), 'reset_period' => 'calendar_year'], null);
    }

    public function test_calendar_year_reset_restarts_the_counter_and_stays_unique(): void
    {
        [, $context] = $this->sequence([
            'reset_period' => 'calendar_year',
            'segments' => [['type' => 'year'], ['type' => 'number', 'length' => 4]],
            'preallocation_enabled' => false,
        ]);
        $service = app(NumberSequenceService::class);

        $this->travelTo('2026-11-02 08:00:00');
        $firstYear = $service->issue($context, 'sample-app.document', 'y1');
        $this->travelTo('2027-01-04 08:00:00');
        $secondYear = $service->issue($context, 'sample-app.document', 'y2');

        $this->assertSame('20260001', $firstYear['number']);
        $this->assertSame('20270001', $secondYear['number']);
        $this->assertDatabaseCount('number_sequence_counters', 2);
    }

    public function test_fiscal_year_reset_follows_the_legal_entity_fiscal_calendar(): void
    {
        $this->travelTo('2026-08-15 08:00:00');
        [, $context] = $this->sequence([
            'scope_type' => 'legal_entity',
            'reset_period' => 'fiscal_year',
            'segments' => [['type' => 'fiscal_year'], ['type' => 'constant', 'value' => '-'], ['type' => 'number', 'length' => 4]],
            'preallocation_enabled' => false,
        ]);
        $legalEntity = $this->legalEntityWithFiscalCalendar($context['tenant_id']);
        $service = app(NumberSequenceService::class);

        // Fiscal year starts in July, so August 2026 and February 2027 are the same fiscal year.
        $first = $service->issue([...$context, 'legal_entity_id' => $legalEntity], 'sample-app.document', 'f1');
        $this->travelTo('2027-02-10 08:00:00');
        $second = $service->issue([...$context, 'legal_entity_id' => $legalEntity], 'sample-app.document', 'f2');
        // Crossing into the next fiscal year restarts the counter.
        $this->travelTo('2027-08-10 08:00:00');
        $third = $service->issue([...$context, 'legal_entity_id' => $legalEntity], 'sample-app.document', 'f3');

        $this->assertSame('FY2027-0001', $first['number']);
        $this->assertSame('FY2027-0002', $second['number']);
        $this->assertSame('FY2028-0001', $third['number']);
    }

    public function test_fiscal_reset_rejects_tenant_scope(): void
    {
        [$sequence] = $this->sequence();

        // Tenant scope names no organization, so the caller's legal entity would be the only thing deciding the
        // counter partition.
        $this->expectException(ValidationException::class);
        app(NumberSequenceService::class)->configure($sequence, [
            ...$this->settings(),
            'reset_period' => 'fiscal_year',
            'scope_type' => 'tenant',
            'segments' => [['type' => 'fiscal_year'], ['type' => 'number', 'length' => 4]],
        ], null);
    }

    public function test_one_operating_unit_under_two_legal_entities_keeps_separate_counters(): void
    {
        $this->travelTo('2026-08-15 08:00:00');
        [, $context] = $this->sequence([
            'scope_type' => 'operating_unit',
            'reset_period' => 'fiscal_year',
            'segments' => [['type' => 'fiscal_year'], ['type' => 'constant', 'value' => '/'], ['type' => 'number', 'length' => 6]],
            'preallocation_enabled' => false,
        ]);
        // Two legal entities on different calendars whose fiscal years share a name, which the schema permits
        // because fiscal year names are unique per calendar, not per tenant.
        $first = $this->legalEntityWithFiscalCalendar($context['tenant_id'], 'LE-A', 7);
        $second = $this->legalEntityWithFiscalCalendar($context['tenant_id'], 'LE-B', 4);
        $branch = $this->organization($context['tenant_id'], 'operating_unit', 'SHARED-BRANCH');
        $service = app(NumberSequenceService::class);

        $a = $service->issue([...$context, 'org_unit_id' => $branch, 'legal_entity_id' => $first], 'sample-app.document', 'a');
        $b = $service->issue([...$context, 'org_unit_id' => $branch, 'legal_entity_id' => $second], 'sample-app.document', 'b');

        // Both render FY2027/000001. That is fine only because they live in different scopes; if the legal entity
        // were left out of the scope key, one declared scope would hold two counters and two identical numbers.
        $this->assertSame($a['number'], $b['number']);
        $scopeKeys = DB::table('number_sequence_counters')->pluck('scope_key');
        $this->assertCount(2, $scopeKeys->unique(), 'The two legal entities must not share one counter scope.');
        foreach ($scopeKeys as $scopeKey) {
            $this->assertStringContainsString('|legal_entity:', $scopeKey);
        }
        $collisions = DB::table('number_sequence_issues')
            ->selectRaw('1')->groupBy('scope_key', 'formatted_value')->havingRaw('count(*) > 1')->get();
        $this->assertCount(0, $collisions, 'Two documents share a number inside one scope.');
    }

    public function test_operating_unit_fiscal_reset_requires_a_legal_entity_in_context(): void
    {
        $this->travelTo('2026-08-15 08:00:00');
        [, $context] = $this->sequence([
            'scope_type' => 'operating_unit',
            'reset_period' => 'fiscal_year',
            'segments' => [['type' => 'fiscal_year'], ['type' => 'number', 'length' => 6]],
            'preallocation_enabled' => false,
        ]);
        $branch = $this->organization($context['tenant_id'], 'operating_unit', 'BRANCH');

        $this->expectException(ValidationException::class);
        app(NumberSequenceService::class)->issue([...$context, 'org_unit_id' => $branch], 'sample-app.document', 'no-entity');
    }

    public function test_operating_unit_fiscal_reset_rejects_a_legal_entity_from_another_tenant(): void
    {
        $this->travelTo('2026-08-15 08:00:00');
        [, $context] = $this->sequence([
            'scope_type' => 'operating_unit',
            'reset_period' => 'fiscal_year',
            'segments' => [['type' => 'fiscal_year'], ['type' => 'number', 'length' => 6]],
            'preallocation_enabled' => false,
        ]);
        $branch = $this->organization($context['tenant_id'], 'operating_unit', 'BRANCH');
        $foreign = $this->legalEntityWithFiscalCalendar($this->foreignTenant(), 'LE-X', 7);

        // The existing guard only validates the scope organization, so the new argument needs its own check.
        $this->expectException(ValidationException::class);
        app(NumberSequenceService::class)->issue(
            [...$context, 'org_unit_id' => $branch, 'legal_entity_id' => $foreign],
            'sample-app.document',
            'cross-tenant-entity',
        );
    }

    public function test_a_fiscal_segment_is_rejected_when_the_reset_period_is_not_fiscal(): void
    {
        [$sequence] = $this->sequence();

        // Without a fiscal reset nothing resolves a fiscal period, so the segment would render as an empty string
        // and silently drop a component from every document number.
        $this->expectException(ValidationException::class);
        app(NumberSequenceService::class)->configure($sequence, [
            ...$this->settings(),
            'scope_type' => 'legal_entity',
            'reset_period' => 'never',
            'segments' => [['type' => 'fiscal_year'], ['type' => 'number', 'length' => 6]],
        ], null);
    }

    public function test_issue_fails_when_the_number_outgrows_its_segment_width(): void
    {
        [, $context] = $this->sequence([
            'minimum_number' => 998,
            'preallocation_enabled' => false,
            'segments' => [['type' => 'number', 'length' => 3]],
        ]);
        $service = app(NumberSequenceService::class);

        $this->assertSame('998', $service->issue($context, 'sample-app.document', 'a')['number']);
        $this->assertSame('999', $service->issue($context, 'sample-app.document', 'b')['number']);

        // 1000 no longer fits three digits; widening it silently would break the format contract.
        $this->expectException(ValidationException::class);
        $service->issue($context, 'sample-app.document', 'c');
    }

    public function test_context_cannot_borrow_an_organization_from_another_tenant(): void
    {
        [, $context] = $this->sequence(['scope_type' => 'operating_unit', 'preallocation_enabled' => false]);
        $foreignUnit = $this->organization($this->foreignTenant(), 'operating_unit', 'FOREIGN');

        $this->expectException(ValidationException::class);
        app(NumberSequenceService::class)->issue([...$context, 'org_unit_id' => $foreignUnit], 'sample-app.document', 'cross-tenant');
    }

    private function foreignTenant(): string
    {
        $clientId = (string) Str::ulid();
        $tenantId = (string) Str::ulid();
        DB::table('clients')->insert(['id' => $clientId, 'legal_name' => 'Other Client', 'slug' => 'other-client-'.Str::lower(Str::random(6)), 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('tenants')->insert(['id' => $tenantId, 'client_id' => $clientId, 'name' => 'Other Tenant', 'slug' => 'other-tenant-'.Str::lower(Str::random(6)), 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);

        return $tenantId;
    }

    public function test_continuous_pool_refills_once_the_preallocated_block_is_used(): void
    {
        [$sequence, $context] = $this->sequence(['is_continuous' => true, 'preallocation_enabled' => true, 'preallocation_quantity' => 3]);
        $service = app(NumberSequenceService::class);

        foreach (range(1, 4) as $index) {
            $reservation = $service->reserve($context, 'sample-app.document', 'pool-'.$index);
            $service->confirm($context, $reservation['id']);
        }

        $this->assertSame(6, DB::table('number_sequence_continuous_pool')->where('sequence_id', $sequence->id)->count());
        $this->assertDatabaseHas('number_sequence_counters', ['sequence_id' => $sequence->id, 'next_number' => 7]);
    }

    public function test_recover_command_marks_expired_reservations_for_reconciliation(): void
    {
        [, $context] = $this->sequence(['is_continuous' => true, 'preallocation_enabled' => true, 'preallocation_quantity' => 5]);
        $service = app(NumberSequenceService::class);
        $reservation = $service->reserve($context, 'sample-app.document', 'expiring');
        DB::table('number_sequence_reservations')->where('id', $reservation['id'])->update(['expires_at' => now()->subMinute()]);

        $this->artisan('number-sequences:recover')->assertSuccessful();

        $this->assertDatabaseHas('number_sequence_reservations', ['id' => $reservation['id'], 'status' => 'reconciliation_pending']);
    }

    public function test_a_late_outbox_can_still_confirm_a_reservation_pending_reconciliation(): void
    {
        [, $context] = $this->sequence(['is_continuous' => true, 'preallocation_enabled' => true, 'preallocation_quantity' => 5]);
        $service = app(NumberSequenceService::class);
        $reservation = $service->reserve($context, 'sample-app.document', 'late-outbox');
        DB::table('number_sequence_reservations')->where('id', $reservation['id'])->update(['expires_at' => now()->subMinute()]);
        $service->recoverExpired();

        $confirmed = $service->confirm($context, $reservation['id']);

        $this->assertSame('confirmed', $confirmed['status']);
        $this->assertSame($reservation['number'], $confirmed['number']);
    }

    /** @return array<string, mixed> */
    private function settings(array $overrides = []): array
    {
        return [
            'profile_code' => 'non-continuous-default',
            'scope_type' => 'tenant',
            'status' => 'active',
            'is_continuous' => false,
            'allow_manual' => false,
            'reset_period' => 'never',
            'preallocation_enabled' => true,
            'preallocation_quantity' => 20,
            'minimum_number' => 1,
            'maximum_number' => null,
            'segments' => [['type' => 'number', 'length' => 6]],
            ...$overrides,
        ];
    }

    /**
     * Creates a legal entity with its own fiscal calendar. The default starts in July so it cannot be confused with
     * the calendar year; the month is a parameter so two entities can carry calendars that differ in shape while
     * naming their fiscal years identically, which the schema allows because year names are unique per calendar.
     */
    private function legalEntityWithFiscalCalendar(string $tenantId, string $code = 'LE1', int $startMonth = 7): string
    {
        $organizationId = $this->organization($tenantId, 'legal_entity', $code);
        DB::table('legal_entities')->insert([
            'organization_id' => $organizationId, 'company_code' => $code, 'country_code' => 'ID',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $calendar = FiscalCalendar::query()->create(['tenant_id' => $tenantId, 'code' => 'CAL-'.$code, 'name' => 'Kalender '.$code]);
        $fiscal = app(FiscalCalendarService::class);
        foreach ([0, 1] as $offset) {
            $start = Carbon::create(2026 + $offset, $startMonth, 1)->startOfDay();
            $fiscal->defineYear(
                $calendar,
                'FY'.$start->copy()->addMonths(11)->year,
                $start,
                $start->copy()->addYear()->subDay(),
                $fiscal->monthlyPeriods($start),
            );
        }
        DB::table('legal_entities')->where('organization_id', $organizationId)->update(['fiscal_calendar_id' => $calendar->id]);

        return $organizationId;
    }

    /** @param array<string, mixed> $overrides @return array{0:TenantNumberSequence,1:array{tenant_id:string,app_id:string}} */
    private function sequence(array $overrides = []): array
    {
        $tenantId = (string) Str::ulid();
        $appId = 'sample-app';
        DB::table('clients')->insert(['id' => (string) Str::ulid(), 'legal_name' => 'Sample Client', 'slug' => 'sample-client-'.Str::lower(Str::random(6)), 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $clientId = DB::table('clients')->latest('created_at')->value('id');
        DB::table('tenants')->insert(['id' => $tenantId, 'client_id' => $clientId, 'name' => 'Sample Tenant', 'slug' => 'sample-tenant-'.Str::lower(Str::random(6)), 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('apps')->insert(['id' => $appId, 'name' => 'Sample app', 'version' => '1.0.0', 'status' => 'available', 'database_name' => 'sample_app', 'created_at' => now(), 'updated_at' => now()]);
        $reference = NumberSequenceReference::query()->create(['app_id' => $appId, 'code' => 'sample-app.document', 'name' => 'Nomor dokumen', 'allowed_scopes' => ['tenant', 'legal_entity', 'operating_unit']]);
        $sequence = TenantNumberSequence::query()->create([
            'tenant_id' => $tenantId,
            'reference_id' => $reference->id,
            'profile_code' => 'non-continuous-default',
            'scope_type' => 'tenant',
            'status' => 'active',
            'is_continuous' => false,
            'allow_manual' => false,
            'reset_period' => 'never',
            'preallocation_enabled' => true,
            'preallocation_quantity' => 20,
            'minimum_number' => 1,
            'segments' => [['type' => 'number', 'length' => 6]],
            ...$overrides,
        ]);

        return [$sequence, ['tenant_id' => $tenantId, 'app_id' => $appId]];
    }

    private function organization(string $tenantId, string $classification, string $code): string
    {
        $id = (string) Str::ulid();
        DB::table('organizations')->insert(['id' => $id, 'tenant_id' => $tenantId, 'name' => $code, 'classification' => $classification, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    /**
     * Tenant yang berhak atas sebuah module **dan** sudah memasangnya.
     *
     * Baris pemasangan module inilah yang menentukan, bukan baris `app_placements`. Sampai
     * 10 September 2026 penentunya adalah penempatan container, dan module tidak pernah punya
     * penempatan container — jadi tidak satu pun urutan nomor dibuat untuk module mana pun,
     * tanpa satu pun kesalahan terlihat. Kegagalannya baru muncul sebagai dokumen pertama yang
     * gagal disimpan di tangan pengguna.
     *
     * Dibuktikan merah: dengan perabot ini apa adanya, query lama memulangkan nol tenant dan
     * kedua test yang memakainya gagal dengan `No query results for model
     * [App\Models\TenantNumberSequence]`.
     */
    private function readyAppForTenant(string $tenantId, string $appId): void
    {
        DB::table('tenant_app_entitlements')->insert(['tenant_id' => $tenantId, 'app_id' => $appId, 'status' => 'active', 'starts_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('tenant_deployments')->insert(['id' => (string) Str::ulid(), 'tenant_id' => $tenantId, 'profile' => 'pooled', 'placement' => 'sample-placement', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('core_module_installations')->insert(['tenant_id' => $tenantId, 'module_id' => $appId, 'version' => '1.0.0', 'status' => ModuleInstallation::STATUS_INSTALLED, 'installed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }
}
