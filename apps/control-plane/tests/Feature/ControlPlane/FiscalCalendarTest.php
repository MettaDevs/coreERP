<?php

namespace Tests\Feature\ControlPlane;

use App\Actions\FiscalCalendar\FiscalCalendarService;
use App\Models\FiscalCalendar;
use App\Models\LegalEntity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class FiscalCalendarTest extends TestCase
{
    use RefreshDatabase;

    public function test_monthly_periods_cover_the_whole_fiscal_year_without_gaps(): void
    {
        $calendar = $this->calendar();
        $service = app(FiscalCalendarService::class);

        $year = $service->defineYear($calendar, 'FY2027', '2026-07-01', '2027-06-30', $service->monthlyPeriods('2026-07-01'));

        $this->assertSame(12, $year->periods()->count());
        $this->assertSame('2026-07-31', $year->periods()->orderBy('ordinal')->first()->ends_on->toDateString());
        $this->assertSame('2027-06-30', $year->periods()->orderBy('ordinal', 'desc')->first()->ends_on->toDateString());
    }

    public function test_overlapping_fiscal_years_are_rejected(): void
    {
        $calendar = $this->calendar();
        $service = app(FiscalCalendarService::class);
        $service->defineYear($calendar, 'FY2027', '2026-07-01', '2027-06-30', $service->monthlyPeriods('2026-07-01'));

        // Two fiscal years covering the same day would make the period of a document ambiguous.
        $this->expectException(ValidationException::class);
        $service->defineYear($calendar, 'FY2027b', '2027-01-01', '2027-12-31', $service->monthlyPeriods('2027-01-01'));
    }

    public function test_periods_with_a_gap_are_rejected(): void
    {
        $calendar = $this->calendar();

        $this->expectException(ValidationException::class);
        app(FiscalCalendarService::class)->defineYear($calendar, 'FY2027', '2026-07-01', '2026-09-30', [
            ['name' => 'Jul', 'starts_on' => '2026-07-01', 'ends_on' => '2026-07-31'],
            // August is missing, so a document dated in August would resolve to no period at all.
            ['name' => 'Sep', 'starts_on' => '2026-09-01', 'ends_on' => '2026-09-30'],
        ]);
    }

    public function test_periods_that_do_not_reach_the_end_of_the_year_are_rejected(): void
    {
        $calendar = $this->calendar();

        $this->expectException(ValidationException::class);
        app(FiscalCalendarService::class)->defineYear($calendar, 'FY2027', '2026-07-01', '2027-06-30', [
            ['name' => 'Jul', 'starts_on' => '2026-07-01', 'ends_on' => '2026-07-31'],
        ]);
    }

    public function test_resolving_a_date_outside_any_defined_year_fails_instead_of_guessing(): void
    {
        $calendar = $this->calendar();
        $service = app(FiscalCalendarService::class);
        $service->defineYear($calendar, 'FY2027', '2026-07-01', '2027-06-30', $service->monthlyPeriods('2026-07-01'));

        $organizationId = (string) Str::ulid();
        DB::table('organizations')->insert(['id' => $organizationId, 'tenant_id' => $calendar->tenant_id, 'name' => 'LE', 'classification' => 'legal_entity', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('legal_entities')->insert(['organization_id' => $organizationId, 'company_code' => 'LE1', 'country_code' => 'ID', 'fiscal_calendar_id' => $calendar->id, 'created_at' => now(), 'updated_at' => now()]);
        $legalEntity = LegalEntity::query()->findOrFail($organizationId);

        $this->assertSame('FY2027', $service->resolve($legalEntity, now()->parse('2026-08-15'))['year_name']);

        // Issuing a number into an undefined period would produce an accounting fact nobody can place.
        $this->expectException(ValidationException::class);
        $service->resolve($legalEntity, now()->parse('2030-01-01'));
    }

    private function calendar(): FiscalCalendar
    {
        $clientId = (string) Str::ulid();
        $tenantId = (string) Str::ulid();
        DB::table('clients')->insert(['id' => $clientId, 'legal_name' => 'C', 'slug' => 'c-'.Str::lower(Str::random(6)), 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('tenants')->insert(['id' => $tenantId, 'client_id' => $clientId, 'name' => 'T', 'slug' => 't-'.Str::lower(Str::random(6)), 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);

        return FiscalCalendar::query()->create(['tenant_id' => $tenantId, 'code' => 'FY', 'name' => 'Fiscal']);
    }
}
