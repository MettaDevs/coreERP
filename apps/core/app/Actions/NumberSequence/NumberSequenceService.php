<?php

namespace App\Actions\NumberSequence;

use App\Actions\FiscalCalendar\FiscalCalendarService;
use App\Models\LegalEntity;
use App\Models\NumberSequenceAllocation;
use App\Models\NumberSequenceCounter;
use App\Models\NumberSequenceIssue;
use App\Models\NumberSequenceProfile;
use App\Models\NumberSequenceReservation;
use App\Models\NumberSequenceReusableNumber;
use App\Models\Organization;
use App\Models\TenantNumberSequence;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class NumberSequenceService
{
    /** Reset periods a sequence may use. Fiscal periods resolve through the legal entity's fiscal calendar. */
    public const RESET_PERIODS = ['never', 'calendar_year', 'fiscal_year', 'fiscal_period'];

    /** Segment types a format may contain. */
    public const SEGMENT_TYPES = ['constant', 'number', 'year', 'scope', 'fiscal_year', 'fiscal_period'];

    /** The reset period each segment type discriminates, so a reset can never collide across periods. */
    private const PERIOD_DISCRIMINATORS = [
        'calendar_year' => ['year'],
        'fiscal_year' => ['fiscal_year'],
        'fiscal_period' => ['fiscal_year', 'fiscal_period'],
    ];

    public function __construct(private readonly FiscalCalendarService $fiscalCalendar) {}

    /**
     * @param  array{tenant_id:string,app_id:string,legal_entity_id?:?string,org_unit_id?:?string}  $context
     * @return array{id:string,number:string,status:string}
     */
    public function issue(array $context, string $referenceCode, string $idempotencyKey, ?string $manualValue = null): array
    {
        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                return DB::transaction(function () use ($context, $referenceCode, $idempotencyKey, $manualValue): array {
                    [$sequence, $scope] = $this->activeSequence($context, $referenceCode);
                    if ($sequence->is_continuous) {
                        $this->fail('reference', 'Sequence berkelanjutan harus memakai reserve lalu confirm.');
                    }

                    $existing = NumberSequenceIssue::query()
                        ->where('sequence_id', $sequence->id)
                        ->where('app_id', $context['app_id'])
                        ->where('idempotency_key', $idempotencyKey)
                        ->first();
                    if ($existing) {
                        return $this->issueResult($existing);
                    }

                    $isManual = $manualValue !== null;
                    if ($isManual && ! $sequence->allow_manual) {
                        $this->fail('manual_value', 'Nomor manual tidak diizinkan untuk sequence ini.');
                    }

                    $numeric = null;
                    $formatted = $manualValue;
                    if ($isManual) {
                        if (! $this->matchesFormat($sequence, $scope, $manualValue)) {
                            $this->fail('manual_value', 'Nomor manual tidak mengikuti format yang dipilih.');
                        }
                        if ($this->formattedValueExists($sequence, $scope, $manualValue)) {
                            $this->fail('manual_value', 'Nomor tersebut sudah digunakan.');
                        }
                    } else {
                        do {
                            $numeric = $this->nextPreallocatedNumber($sequence, $scope);
                            $formatted = $this->format($sequence, $scope, $numeric);
                        } while ($this->formattedValueExists($sequence, $scope, $formatted));
                    }

                    $issue = NumberSequenceIssue::query()->create([
                        'sequence_id' => $sequence->id,
                        'app_id' => $context['app_id'],
                        'scope_key' => $scope['key'],
                        'period_key' => $scope['period'],
                        'numeric_value' => $numeric,
                        'formatted_value' => $formatted,
                        'idempotency_key' => $idempotencyKey,
                        'is_manual' => $isManual,
                        'issued_at' => now(),
                    ]);
                    $this->audit($sequence->id, $context['app_id'], $isManual ? 'manual_issued' : 'issued', $formatted, ['scope' => $scope['key']]);

                    return $this->issueResult($issue);
                });
            } catch (QueryException $exception) {
                $existing = NumberSequenceIssue::query()
                    ->where('app_id', $context['app_id'])
                    ->where('idempotency_key', $idempotencyKey)
                    ->whereIn('sequence_id', TenantNumberSequence::query()
                        ->select('id')
                        ->where('tenant_id', $context['tenant_id'])
                        ->whereHas('reference', fn ($query) => $query->where('code', $referenceCode)->where('app_id', $context['app_id'])))
                    ->first();
                if ($existing) {
                    return $this->issueResult($existing);
                }
                if (! $this->isUniqueViolation($exception) || $attempt === 2) {
                    throw $exception;
                }
            }
        }

        throw new \LogicException('Penerbitan nomor gagal setelah retry.');
    }

    /** @param array{tenant_id:string,app_id:string,legal_entity_id?:?string,org_unit_id?:?string} $context */
    /**
     * @param  array{tenant_id:string,app_id:string,legal_entity_id?:?string,org_unit_id?:?string}  $context
     * @return array{id:string,number:string,status:string,expires_at:string|null}
     */
    public function reserve(array $context, string $referenceCode, string $idempotencyKey): array
    {
        try {
            return DB::transaction(function () use ($context, $referenceCode, $idempotencyKey): array {
                [$sequence, $scope] = $this->activeSequence($context, $referenceCode);
                if (! $sequence->is_continuous) {
                    $this->fail('reference', 'Sequence ini tidak memakai reservation.');
                }

                $existing = NumberSequenceReservation::query()
                    ->where('sequence_id', $sequence->id)
                    ->where('app_id', $context['app_id'])
                    ->where('idempotency_key', $idempotencyKey)
                    ->first();
                if ($existing) {
                    return $this->replayReservation($existing);
                }

                // An app that reserves and never confirms would drain the pool and leave a continuous sequence unable
                // to issue anything, with no way for an operator to tell why. Refuse early and name the reason.
                $limit = (int) config('coreerp.max_outstanding_reservations', 500);
                $outstanding = NumberSequenceReservation::query()
                    ->where('sequence_id', $sequence->id)
                    ->whereIn('status', ['reserved', 'reconciliation_pending'])
                    ->count();
                if ($outstanding >= $limit) {
                    $this->fail('reference', "Terlalu banyak reservation belum diselesaikan ({$outstanding}). Selesaikan confirm atau cancel sebelum meminta nomor baru.");
                }

                $reservationId = (string) Str::ulid();
                $numeric = $this->nextContinuousNumber($sequence, $scope, $reservationId);
                $reservation = NumberSequenceReservation::query()->create([
                    'id' => $reservationId,
                    'sequence_id' => $sequence->id,
                    'app_id' => $context['app_id'],
                    'scope_key' => $scope['key'],
                    'period_key' => $scope['period'],
                    'numeric_value' => $numeric,
                    'formatted_value' => $this->format($sequence, $scope, $numeric),
                    'idempotency_key' => $idempotencyKey,
                    'status' => 'reserved',
                    'expires_at' => now()->addMinutes(15),
                ]);
                $this->audit($sequence->id, $context['app_id'], 'reserved', $reservation->formatted_value, ['scope' => $scope['key']]);

                return $this->reservationResult($reservation);
            });
        } catch (QueryException $exception) {
            $reservation = NumberSequenceReservation::query()
                ->where('app_id', $context['app_id'])
                ->where('idempotency_key', $idempotencyKey)
                ->whereHas('sequence', fn ($query) => $query->where('tenant_id', $context['tenant_id'])->whereHas('reference', fn ($reference) => $reference->where('code', $referenceCode)->where('app_id', $context['app_id'])))
                ->first();
            if ($reservation) {
                return $this->replayReservation($reservation);
            }

            throw $exception;
        }
    }

    /** @param array{tenant_id:string,app_id:string} $context */
    /**
     * @param  array{tenant_id:string,app_id:string}  $context
     * @return array{id:string,number:string,status:string,expires_at:string|null}
     */
    public function confirm(array $context, string $reservationId): array
    {
        return DB::transaction(function () use ($context, $reservationId): array {
            $reservation = $this->reservation($context, $reservationId);
            if ($reservation->status === 'confirmed') {
                return $this->reservationResult($reservation);
            }
            if (! in_array($reservation->status, ['reserved', 'reconciliation_pending'], true)) {
                $this->fail('reservation', 'Reservation tidak dapat dikonfirmasi.');
            }

            $reservation->update(['status' => 'confirmed', 'confirmed_at' => now()]);
            if ($reservation->sequence->is_continuous) {
                DB::table('number_sequence_continuous_pool')->where([
                    'sequence_id' => $reservation->sequence_id,
                    'scope_key' => $reservation->scope_key,
                    'period_key' => $reservation->period_key,
                    'numeric_value' => $reservation->numeric_value,
                    'reservation_id' => $reservation->id,
                ])->update(['status' => 'confirmed', 'updated_at' => now()]);
            }
            NumberSequenceIssue::query()->create([
                'sequence_id' => $reservation->sequence_id,
                'app_id' => $reservation->app_id,
                'scope_key' => $reservation->scope_key,
                'period_key' => $reservation->period_key,
                'numeric_value' => $reservation->numeric_value,
                'formatted_value' => $reservation->formatted_value,
                'idempotency_key' => $reservation->idempotency_key,
                'is_manual' => false,
                'issued_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->audit($reservation->sequence_id, $context['app_id'], 'confirmed', $reservation->formatted_value, ['reservation_id' => $reservation->id]);

            return $this->reservationResult($reservation->refresh());
        });
    }

    /** @param array{tenant_id:string,app_id:string} $context */
    /**
     * @param  array{tenant_id:string,app_id:string}  $context
     * @return array{id:string,number:string,status:string,expires_at:string|null}
     */
    public function cancel(array $context, string $reservationId, bool $recovered = false): array
    {
        return DB::transaction(function () use ($context, $reservationId, $recovered): array {
            $reservation = $this->reservation($context, $reservationId);
            if ($reservation->status === 'cancelled') {
                return $this->reservationResult($reservation);
            }
            if (! in_array($reservation->status, ['reserved', 'reconciliation_pending'], true)) {
                $this->fail('reservation', 'Reservation yang sudah dikonfirmasi tidak dapat dibatalkan.');
            }

            $reservation->update(['status' => 'cancelled', 'cancelled_at' => now()]);
            $released = 0;
            if ($reservation->sequence->is_continuous) {
                $released = DB::table('number_sequence_continuous_pool')->where([
                    'sequence_id' => $reservation->sequence_id,
                    'scope_key' => $reservation->scope_key,
                    'period_key' => $reservation->period_key,
                    'numeric_value' => $reservation->numeric_value,
                    'reservation_id' => $reservation->id,
                ])->update(['status' => 'available', 'reservation_id' => null, 'updated_at' => now()]);
            }
            if ($released === 0) {
                NumberSequenceReusableNumber::query()->updateOrCreate([
                    'sequence_id' => $reservation->sequence_id,
                    'scope_key' => $reservation->scope_key,
                    'period_key' => $reservation->period_key,
                    'numeric_value' => $reservation->numeric_value,
                ]);
            }
            $this->audit($reservation->sequence_id, $context['app_id'], $recovered ? 'recovered' : 'cancelled', $reservation->formatted_value, ['reservation_id' => $reservation->id]);

            return $this->reservationResult($reservation->refresh());
        });
    }

    public function recoverExpired(): int
    {
        $marked = 0;
        foreach (DB::table('number_sequence_reservations')
            ->select('id')
            ->where('status', 'reserved')
            ->where('expires_at', '<=', now())
            ->lazyById() as $reservation) {
            $marked += $this->markForReconciliation((string) $reservation->id) ? 1 : 0;
        }

        return $marked;
    }

    private function markForReconciliation(string $reservationId): bool
    {
        return DB::transaction(function () use ($reservationId): bool {
            $reservation = NumberSequenceReservation::query()->whereKey($reservationId)->lockForUpdate()->first();
            if (! $reservation || $reservation->status !== 'reserved' || ($reservation->expires_at !== null && now()->lessThan($reservation->expires_at))) {
                return false;
            }

            $reservation->update(['status' => 'reconciliation_pending']);
            if ($reservation->sequence->is_continuous) {
                DB::table('number_sequence_continuous_pool')->where([
                    'sequence_id' => $reservation->sequence_id,
                    'scope_key' => $reservation->scope_key,
                    'period_key' => $reservation->period_key,
                    'numeric_value' => $reservation->numeric_value,
                    'reservation_id' => $reservation->id,
                ])->update(['status' => 'reconciliation_pending', 'updated_at' => now()]);
            }
            $this->audit($reservation->sequence_id, $reservation->app_id, 'reconciliation_pending', $reservation->formatted_value, ['reservation_id' => $reservation->id]);

            return true;
        });
    }

    /** @param array{scope_type:string,status:string,is_continuous:bool,allow_manual:bool,reset_period:string,preallocation_enabled:bool,preallocation_quantity:int,minimum_number:int,maximum_number:?int,segments:list<array<string,mixed>>,profile_code:string} $settings */
    public function configure(TenantNumberSequence $sequence, array $settings, ?int $userId): TenantNumberSequence
    {
        return DB::transaction(function () use ($sequence, $settings, $userId): TenantNumberSequence {
            $sequence = TenantNumberSequence::query()->with('reference')->lockForUpdate()->findOrFail($sequence->id);
            if ($settings['profile_code'] !== $sequence->profile_code) {
                $profile = NumberSequenceProfile::query()->findOrFail($settings['profile_code']);
                $settings = [
                    ...$settings,
                    'is_continuous' => (bool) $profile->is_continuous,
                    'allow_manual' => (bool) $profile->allow_manual,
                    'preallocation_enabled' => (bool) $profile->preallocation_enabled,
                    'preallocation_quantity' => (int) $profile->preallocation_quantity,
                    'segments' => array_values($profile->segments),
                ];
            }
            if (! in_array($settings['scope_type'], $sequence->reference->allowed_scopes, true)) {
                $this->fail('scope_type', 'Scope ini tidak tersedia untuk reference tersebut.');
            }
            if ($settings['is_continuous'] && $settings['allow_manual']) {
                $this->fail('allow_manual', 'Nomor manual tidak dapat digabung dengan nomor berkelanjutan.');
            }
            if ($settings['minimum_number'] < 0 || ($settings['maximum_number'] !== null && $settings['maximum_number'] < $settings['minimum_number'])) {
                $this->fail('maximum_number', 'Batas nomor tidak valid.');
            }
            if (! in_array($settings['reset_period'], self::RESET_PERIODS, true)) {
                $this->fail('reset_period', 'Periode reset tidak dikenal.');
            }
            // Operating unit scope is allowed now: the caller names the legal entity per request, exactly as a D365
            // transaction carries its company. Tenant scope stays rejected because it names no organization, so the
            // counter's identity would be decided by whatever legal entity the caller happened to send.
            if (in_array($settings['reset_period'], ['fiscal_year', 'fiscal_period'], true) && $settings['scope_type'] === 'tenant') {
                $this->fail('reset_period', 'Reset fiskal memerlukan scope legal entity atau operating unit; scope tenant tidak memiliki kalender fiskal.');
            }
            $this->validateSegments($settings['segments'], $settings['scope_type'], $settings['reset_period']);
            if ($this->hasNumberingState($sequence) && $this->structuralSettingsChanged($sequence, $settings)) {
                $this->fail('profile_code', 'Mode, scope, periode, nomor awal, dan format tidak dapat diubah setelah nomor digunakan.');
            }

            $sequence->fill($settings)->save();
            $this->audit($sequence->id, null, 'configured', null, ['status' => $sequence->status], $userId);

            return $sequence->refresh();
        });
    }

    /** @param array{tenant_id:string,legal_entity_id?:?string,org_unit_id?:?string} $context */
    public function advance(TenantNumberSequence $sequence, array $context, int $nextNumber, ?int $userId): void
    {
        DB::transaction(function () use ($sequence, $context, $nextNumber, $userId): void {
            $locked = TenantNumberSequence::query()->lockForUpdate()->findOrFail($sequence->id);
            if ($locked->is_continuous) {
                $this->fail('next_number', 'Nomor berkelanjutan tidak dapat dilompati.');
            }
            $scope = $this->scope($locked, $context);
            $counter = $this->counter($locked, $scope);
            if ($nextNumber <= $counter->next_number) {
                $this->fail('next_number', 'Nomor berikutnya hanya boleh dinaikkan.');
            }
            $this->withinMaximum($locked, $nextNumber);
            // Advancing is irreversible, so a mistyped jump would permanently break the sequence at issue time.
            // Rendering the target now turns that into a validation error the admin can still correct.
            $this->format($locked, $scope, $nextNumber);
            NumberSequenceAllocation::query()
                ->where('sequence_id', $locked->id)
                ->where('scope_key', $scope['key'])
                ->where('period_key', $scope['period'])
                ->whereColumn('next_number', '<=', 'last_number')
                ->lockForUpdate()
                ->get()
                ->each(fn (NumberSequenceAllocation $allocation) => $allocation->update(['next_number' => $allocation->last_number + 1]));
            NumberSequenceReusableNumber::query()
                ->where('sequence_id', $locked->id)
                ->where('scope_key', $scope['key'])
                ->where('period_key', $scope['period'])
                ->where('numeric_value', '<', $nextNumber)
                ->delete();
            DB::table('number_sequence_counters')->where('id', $counter->id)->update(['next_number' => $nextNumber, 'updated_at' => now()]);
            $this->audit($locked->id, null, 'counter_advanced', null, ['scope' => $scope['key'], 'next_number' => $nextNumber], $userId);
        });
    }

    /**
     * @param  array{tenant_id:string,app_id:string,legal_entity_id?:?string,org_unit_id?:?string}  $context
     * @return array{0:TenantNumberSequence,1:array{key:string,period:string,code:?string}}
     */
    private function activeSequence(array $context, string $referenceCode): array
    {
        $sequence = TenantNumberSequence::query()
            ->where('tenant_id', $context['tenant_id'])
            ->where('status', 'active')
            ->whereHas('reference', fn ($query) => $query->where('code', $referenceCode)->where('app_id', $context['app_id']))
            ->with('reference')
            ->sharedLock()
            ->first();
        if (! $sequence) {
            $this->fail('reference', 'Sequence aktif tidak ditemukan untuk aplikasi dan tenant ini.');
        }

        return [$sequence, $this->scope($sequence, $context)];
    }

    /**
     * @param  array{tenant_id:string,legal_entity_id?:?string,org_unit_id?:?string}  $context
     * @return array{key:string,period:string,code:?string}
     */
    private function scope(TenantNumberSequence $sequence, array $context): array
    {
        $organizationId = match ($sequence->scope_type) {
            'tenant' => null,
            'legal_entity' => $context['legal_entity_id'] ?? null,
            'operating_unit' => $context['org_unit_id'] ?? null,
            default => null,
        };
        if ($sequence->scope_type !== 'tenant' && ! $organizationId) {
            $this->fail('scope', 'Context organisasi untuk sequence ini belum tersedia.');
        }

        $organization = null;
        if ($organizationId) {
            $organization = Organization::query()
                ->whereKey($organizationId)
                ->where('tenant_id', $context['tenant_id'])
                ->where('classification', $sequence->scope_type)
                ->where('status', 'active')
                ->with('legalEntity')
                ->first();
            if (! $organization) {
                $this->fail('scope', 'Context organisasi tidak sah untuk sequence ini.');
            }
        }

        $scopeCode = $organization?->id;
        if ($organization && $sequence->scope_type === 'legal_entity') {
            $scopeCode = $organization->legalEntity->company_code;
        }

        $fiscal = null;
        $fiscalLegalEntityId = null;
        if ($sequence->usesFiscalCalendar()) {
            $legalEntity = $this->fiscalLegalEntity($sequence, $context, $organization);
            $fiscalLegalEntityId = $legalEntity->organization_id;
            $fiscal = $this->fiscalCalendar->resolve($legalEntity, now());
        }

        return [
            'key' => $this->scopeKey($sequence, $context, $organization, $fiscalLegalEntityId),
            'period' => $this->periodKey($sequence, $fiscal),
            'code' => $scopeCode,
            'year' => now()->format('Y'),
            'fiscal_year' => $fiscal['year_name'] ?? null,
            'fiscal_period' => $fiscal['period_ordinal'] ?? null,
        ];
    }

    /**
     * Resolve the legal entity whose fiscal calendar dates this number.
     *
     * Dynamics 365 never derives a fiscal calendar from an operating unit: the calendar hangs off the ledger, which
     * exists only for a legal entity, and operating units are deliberately shared across legal entities. D365 gets
     * the period from the ambient company of the transaction and passes it to the scope factory as its own argument.
     * CoreERP has no ambient company, so an operating-unit scoped sequence must be told which legal entity the
     * document belongs to.
     *
     * @param  array{tenant_id:string,legal_entity_id?:?string,org_unit_id?:?string}  $context
     */
    private function fiscalLegalEntity(TenantNumberSequence $sequence, array $context, ?Organization $organization): LegalEntity
    {
        if ($sequence->scope_type === 'legal_entity') {
            return $organization->legalEntity;
        }
        if ($sequence->scope_type !== 'operating_unit') {
            // Tenant scope names no organization at all, so a caller-supplied legal entity would be the only thing
            // deciding the partition. That is a counter whose identity is chosen per request, not a scope.
            $this->fail('scope', 'Reset fiskal memerlukan scope legal entity atau operating unit.');
        }

        $legalEntityId = $context['legal_entity_id'] ?? null;
        if (! $legalEntityId) {
            $this->fail('legal_entity_id', 'Reset fiskal pada scope operating unit memerlukan legal_entity_id pada context.');
        }

        $legalEntityOrganization = Organization::query()
            ->whereKey($legalEntityId)
            ->where('tenant_id', $context['tenant_id'])
            ->where('classification', 'legal_entity')
            ->where('status', 'active')
            ->with('legalEntity')
            ->first();
        if (! $legalEntityOrganization?->legalEntity) {
            $this->fail('legal_entity_id', 'Legal entity pada context tidak sah untuk tenant ini.');
        }
        if ($legalEntityOrganization->legalEntity->fiscal_calendar_id === null) {
            $this->fail('legal_entity_id', 'Legal entity pada context belum memiliki kalender fiskal.');
        }

        return $legalEntityOrganization->legalEntity;
    }

    /**
     * The scope key is the counter's identity, so everything that splits the counter has to be in it.
     *
     * For an operating unit dated by a fiscal calendar, the legal entity is part of that identity: the same operating
     * unit used by two legal entities has two fiscal calendars and therefore two streams. Leaving the legal entity
     * out would let both streams claim one scope, and because the uniqueness index is bounded by period_key the
     * database would happily store the same document number twice.
     *
     * @param  array{tenant_id:string,legal_entity_id?:?string,org_unit_id?:?string}  $context
     */
    private function scopeKey(TenantNumberSequence $sequence, array $context, ?Organization $organization, ?string $fiscalLegalEntityId): string
    {
        if (! $organization) {
            return 'tenant:'.$context['tenant_id'];
        }

        $key = $sequence->scope_type.':'.$organization->id;
        if ($sequence->scope_type === 'operating_unit' && $fiscalLegalEntityId !== null) {
            $key .= '|legal_entity:'.$fiscalLegalEntityId;
        }

        return $key;
    }

    /**
     * The period key partitions counters, pools, and the uniqueness constraints. It uses the fiscal year id rather
     * than its name so a renamed year keeps its counter, and so the key stays inside the column width.
     *
     * @param  array{year_id:string,year_name:string,period_id:string,period_ordinal:int,period_name:string}|null  $fiscal
     */
    private function periodKey(TenantNumberSequence $sequence, ?array $fiscal): string
    {
        return match ($sequence->reset_period) {
            'calendar_year' => now()->format('Y'),
            'fiscal_year' => 'FY:'.$fiscal['year_id'],
            'fiscal_period' => 'FP:'.$fiscal['year_id'].':'.$fiscal['period_ordinal'],
            default => 'all',
        };
    }

    /** @param array{key:string,period:string,code:?string} $scope */
    private function nextPreallocatedNumber(TenantNumberSequence $sequence, array $scope): int
    {
        if (! $sequence->preallocation_enabled) {
            return $this->nextDirectNumber($sequence, $scope);
        }

        $allocation = NumberSequenceAllocation::query()
            ->where('sequence_id', $sequence->id)
            ->where('scope_key', $scope['key'])
            ->where('period_key', $scope['period'])
            ->whereColumn('next_number', '<=', 'last_number')
            ->orderBy('created_at')
            ->lockForUpdate()
            ->first();

        if (! $allocation) {
            $counter = $this->counter($sequence, $scope);
            $allocation = NumberSequenceAllocation::query()
                ->where('sequence_id', $sequence->id)
                ->where('scope_key', $scope['key'])
                ->where('period_key', $scope['period'])
                ->whereColumn('next_number', '<=', 'last_number')
                ->orderBy('created_at')
                ->lockForUpdate()
                ->first();
            if (! $allocation) {
                // Exhausted blocks are dead weight: the hot lookup below still has to walk past every one of them to
                // find the live block, so a long-lived sequence would get linearly slower forever. The counter row is
                // locked here, so nothing can be mid-claim on this scope.
                NumberSequenceAllocation::query()
                    ->where('sequence_id', $sequence->id)
                    ->where('scope_key', $scope['key'])
                    ->where('period_key', $scope['period'])
                    ->whereColumn('next_number', '>', 'last_number')
                    ->delete();

                $first = $this->withinMaximum($sequence, (int) $counter->next_number);
                $last = $this->allocationEnd($sequence, $first);
                $allocation = NumberSequenceAllocation::query()->create([
                    'sequence_id' => $sequence->id, 'scope_key' => $scope['key'], 'period_key' => $scope['period'],
                    'first_number' => $first, 'last_number' => $last, 'next_number' => $first,
                ]);
                $counter->update(['next_number' => $last + 1]);
                $this->audit($sequence->id, null, 'preallocated', null, ['scope' => $scope['key'], 'first' => $first, 'last' => $last]);
            }
        }

        $numeric = $this->withinMaximum($sequence, (int) $allocation->next_number);
        $allocation->update(['next_number' => $numeric + 1]);

        return $numeric;
    }

    /** @param array{key:string,period:string,code:?string} $scope */
    private function nextContinuousNumber(TenantNumberSequence $sequence, array $scope, string $reservationId): int
    {
        if (! $sequence->preallocation_enabled) {
            return $this->nextDirectNumber($sequence, $scope);
        }

        $available = $this->availableContinuousNumber($sequence, $scope);
        if (! $available) {
            $counter = $this->counter($sequence, $scope);
            $available = $this->availableContinuousNumber($sequence, $scope);
            if (! $available) {
                $first = $this->withinMaximum($sequence, (int) $counter->next_number);
                $last = $this->allocationEnd($sequence, $first);
                $now = now();
                DB::table('number_sequence_continuous_pool')->insert(array_map(fn (int $number): array => [
                    'sequence_id' => $sequence->id,
                    'scope_key' => $scope['key'],
                    'period_key' => $scope['period'],
                    'numeric_value' => $number,
                    'status' => 'available',
                    'created_at' => $now,
                    'updated_at' => $now,
                ], range($first, $last)));
                $counter->update(['next_number' => $last + 1]);
                $this->audit($sequence->id, null, 'preallocated', null, ['scope' => $scope['key'], 'first' => $first, 'last' => $last, 'mode' => 'continuous']);
                $available = $this->availableContinuousNumber($sequence, $scope);
            }
        }

        if (! $available) {
            $this->fail('reference', 'Nomor sequence belum tersedia.');
        }

        $numeric = $this->withinMaximum($sequence, $available);
        DB::table('number_sequence_continuous_pool')->where([
            'sequence_id' => $sequence->id,
            'scope_key' => $scope['key'],
            'period_key' => $scope['period'],
            'numeric_value' => $numeric,
            'status' => 'available',
        ])->update(['status' => 'reserved', 'reservation_id' => $reservationId, 'updated_at' => now()]);

        return $numeric;
    }

    /** @param array{key:string,period:string,code:?string} $scope */
    private function availableContinuousNumber(TenantNumberSequence $sequence, array $scope): ?int
    {
        $number = DB::table('number_sequence_continuous_pool')->where([
            'sequence_id' => $sequence->id,
            'scope_key' => $scope['key'],
            'period_key' => $scope['period'],
            'status' => 'available',
        ])->orderBy('numeric_value')->lock('for update skip locked')->value('numeric_value');

        return $number === null ? null : (int) $number;
    }

    /** @param array{key:string,period:string,code:?string} $scope */
    private function nextDirectNumber(TenantNumberSequence $sequence, array $scope): int
    {
        $reusable = NumberSequenceReusableNumber::query()
            ->where('sequence_id', $sequence->id)->where('scope_key', $scope['key'])->where('period_key', $scope['period'])
            ->orderBy('numeric_value')->lockForUpdate()->first();
        if ($reusable) {
            NumberSequenceReusableNumber::query()->where([
                'sequence_id' => $sequence->id,
                'scope_key' => $scope['key'],
                'period_key' => $scope['period'],
                'numeric_value' => $reusable->numeric_value,
            ])->delete();

            return (int) $reusable->numeric_value;
        }

        $counter = $this->counter($sequence, $scope);
        $numeric = $this->withinMaximum($sequence, (int) $counter->next_number);
        $counter->update(['next_number' => $numeric + 1]);

        return $numeric;
    }

    /** @param array{key:string,period:string,code:?string} $scope */
    private function counter(TenantNumberSequence $sequence, array $scope): NumberSequenceCounter
    {
        $counter = NumberSequenceCounter::query()->where([
            'sequence_id' => $sequence->id, 'scope_key' => $scope['key'], 'period_key' => $scope['period'],
        ])->lockForUpdate()->first();
        if ($counter) {
            return $counter;
        }

        NumberSequenceCounter::query()->insertOrIgnore([
            'id' => (string) Str::ulid(),
            'sequence_id' => $sequence->id,
            'scope_key' => $scope['key'],
            'period_key' => $scope['period'],
            'next_number' => $sequence->minimum_number,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return NumberSequenceCounter::query()->where([
            'sequence_id' => $sequence->id, 'scope_key' => $scope['key'], 'period_key' => $scope['period'],
        ])->lockForUpdate()->firstOrFail();
    }

    /** @param array{key:string,period:string,code:?string,year:string,fiscal_year:?string,fiscal_period:?int} $scope */
    private function format(TenantNumberSequence $sequence, array $scope, int $numeric): string
    {
        return collect($sequence->segments)->map(function (array $segment) use ($scope, $numeric): string {
            return match ($segment['type'] ?? null) {
                'constant' => (string) ($segment['value'] ?? ''),
                'number' => $this->padded((string) $numeric, (int) ($segment['length'] ?? 0)),
                'year' => $scope['year'],
                'scope' => (string) $scope['code'],
                'fiscal_year' => (string) $scope['fiscal_year'],
                'fiscal_period' => $this->padded((string) $scope['fiscal_period'], (int) ($segment['length'] ?? 2)),
                default => throw new \LogicException('Tipe segmen tidak dikenal.'),
            };
        })->join('');
    }

    /**
     * A value that no longer fits its declared width would silently widen the document number and break the format
     * contract, so exhausting a segment is an error rather than a wider number.
     */
    private function padded(string $value, int $length): string
    {
        if (strlen($value) > $length) {
            $this->fail('reference', 'Nomor sudah melampaui panjang segmen yang dikonfigurasi.');
        }

        return str_pad($value, $length, '0', STR_PAD_LEFT);
    }

    /** @param array{key:string,period:string,code:?string,year:string,fiscal_year:?string,fiscal_period:?int} $scope */
    private function matchesFormat(TenantNumberSequence $sequence, array $scope, string $value): bool
    {
        $pattern = collect($sequence->segments)->map(function (array $segment) use ($scope): string {
            return match ($segment['type'] ?? null) {
                'constant' => preg_quote((string) ($segment['value'] ?? ''), '/'),
                'number' => '[0-9]{'.(int) ($segment['length'] ?? 0).'}',
                'year' => preg_quote($scope['year'], '/'),
                'scope' => preg_quote((string) $scope['code'], '/'),
                'fiscal_year' => preg_quote((string) $scope['fiscal_year'], '/'),
                'fiscal_period' => '[0-9]{'.(int) ($segment['length'] ?? 2).'}',
                default => throw new \LogicException('Tipe segmen tidak dikenal.'),
            };
        })->join('');

        // \z rather than $, otherwise a trailing newline passes the format check.
        return preg_match('/^'.$pattern.'\z/', $value) === 1;
    }

    /** @param list<array<string, mixed>> $segments */
    private function validateSegments(array $segments, string $scopeType, string $resetPeriod): void
    {
        if ($segments === [] || count($segments) > 10 || count(array_filter($segments, fn (array $segment): bool => ($segment['type'] ?? null) === 'number')) !== 1) {
            $this->fail('segments', 'Format harus memiliki tepat satu segmen nomor.');
        }
        foreach ($segments as $segment) {
            if (! isset($segment['type']) || ! in_array($segment['type'], self::SEGMENT_TYPES, true)) {
                $this->fail('segments', 'Tipe segmen tidak dikenal.');
            }
            if ($segment['type'] === 'constant' && (! is_string($segment['value'] ?? null) || ! preg_match('/^[A-Za-z0-9._\/-]*$/', $segment['value']))) {
                $this->fail('segments', 'Segmen tetap hanya boleh berisi huruf, angka, titik, garis miring, atau strip.');
            }
            if ($segment['type'] === 'number' && (! isset($segment['length']) || ! is_int($segment['length']) || $segment['length'] < 1 || $segment['length'] > 18)) {
                $this->fail('segments', 'Panjang segmen nomor harus 1 sampai 18.');
            }
            if ($segment['type'] === 'fiscal_period' && (! isset($segment['length']) || ! is_int($segment['length']) || $segment['length'] < 1 || $segment['length'] > 4)) {
                $this->fail('segments', 'Panjang segmen periode fiskal harus 1 sampai 4.');
            }
            if ($segment['type'] === 'scope' && $scopeType === 'tenant') {
                $this->fail('segments', 'Segmen scope memerlukan scope legal entity atau operating unit.');
            }
            if (in_array($segment['type'], ['fiscal_year', 'fiscal_period'], true)) {
                if ($scopeType === 'tenant') {
                    $this->fail('segments', 'Segmen fiskal memerlukan scope legal entity atau operating unit.');
                }
                // A fiscal segment only has a value when a fiscal period was resolved, and that only happens for a
                // fiscal reset period. Without this guard the segment renders as an empty string and the document
                // number silently loses a component.
                if (! in_array($resetPeriod, ['fiscal_year', 'fiscal_period'], true)) {
                    $this->fail('segments', 'Segmen fiskal hanya dapat dipakai bila periode reset memakai tahun atau periode fiskal.');
                }
            }
        }

        // Resetting a counter without a segment that varies with the period would re-issue the same document number
        // every period. The unique index is scoped by period_key, so the database cannot catch it either.
        $types = array_column($segments, 'type');
        foreach (self::PERIOD_DISCRIMINATORS[$resetPeriod] ?? [] as $required) {
            if (! in_array($required, $types, true)) {
                $this->fail('segments', 'Format harus memuat segmen '.$required.' agar nomor tidak berulang setiap periode direset.');
            }
        }

        $this->validateRenderedLength($segments);
    }

    /**
     * Guard the rendered width against the formatted_value columns before a configuration can be saved, so a legal
     * looking format cannot fail at issue time instead.
     *
     * @param  list<array<string, mixed>>  $segments
     */
    private function validateRenderedLength(array $segments): void
    {
        $length = 0;
        foreach ($segments as $segment) {
            $length += match ($segment['type']) {
                'constant' => strlen((string) ($segment['value'] ?? '')),
                'number' => (int) ($segment['length'] ?? 0),
                'year' => 4,
                'scope' => 50,
                'fiscal_year' => 40,
                'fiscal_period' => (int) ($segment['length'] ?? 2),
                default => 0,
            };
        }
        if ($length > 200) {
            $this->fail('segments', 'Format yang dihasilkan dapat melebihi batas panjang nomor.');
        }
    }

    private function withinMaximum(TenantNumberSequence $sequence, int $number): int
    {
        if ($sequence->maximum_number !== null && $number > $sequence->maximum_number) {
            $this->fail('reference', 'Nomor sequence sudah mencapai batas maksimum.');
        }

        return $number;
    }

    private function allocationEnd(TenantNumberSequence $sequence, int $first): int
    {
        $last = $first + $sequence->preallocation_quantity - 1;

        return $sequence->maximum_number === null ? $last : min($last, $sequence->maximum_number);
    }

    /** @param array{key:string,period:string,code:?string} $scope */
    private function formattedValueExists(TenantNumberSequence $sequence, array $scope, string $formattedValue): bool
    {
        return NumberSequenceIssue::query()
            ->where('sequence_id', $sequence->id)
            ->where('scope_key', $scope['key'])
            ->where('period_key', $scope['period'])
            ->where('formatted_value', $formattedValue)
            ->exists();
    }

    private function hasNumberingState(TenantNumberSequence $sequence): bool
    {
        return NumberSequenceCounter::query()->where('sequence_id', $sequence->id)->exists()
            || NumberSequenceIssue::query()->where('sequence_id', $sequence->id)->exists()
            || NumberSequenceReservation::query()->where('sequence_id', $sequence->id)->exists();
    }

    /** @param array<string, mixed> $settings */
    private function structuralSettingsChanged(TenantNumberSequence $sequence, array $settings): bool
    {
        return $sequence->profile_code !== $settings['profile_code']
            || $sequence->scope_type !== $settings['scope_type']
            || $sequence->is_continuous !== $settings['is_continuous']
            || $sequence->reset_period !== $settings['reset_period']
            || $sequence->preallocation_enabled !== $settings['preallocation_enabled']
            || (int) $sequence->minimum_number !== $settings['minimum_number']
            || $sequence->segments !== $settings['segments'];
    }

    private function isUniqueViolation(QueryException $exception): bool
    {
        return in_array($exception->errorInfo[0] ?? $exception->getCode(), ['23000', '23505'], true);
    }

    /** @param array{tenant_id:string,app_id:string} $context */
    private function reservation(array $context, string $reservationId): NumberSequenceReservation
    {
        $reservation = NumberSequenceReservation::query()
            ->whereKey($reservationId)->where('app_id', $context['app_id'])
            ->when($context['tenant_id'] !== '', fn ($query) => $query->whereHas('sequence', fn ($sequence) => $sequence->where('tenant_id', $context['tenant_id'])))
            ->lockForUpdate()->first();
        if (! $reservation) {
            $this->fail('reservation', 'Reservation tidak ditemukan.');
        }

        return $reservation;
    }

    /** @param array<string, mixed> $details */
    private function audit(string $sequenceId, ?string $appId, string $eventType, ?string $formattedValue, array $details, ?int $userId = null): void
    {
        DB::table('number_sequence_audit_events')->insert([
            'id' => (string) Str::ulid(), 'sequence_id' => $sequenceId, 'app_id' => $appId, 'user_id' => $userId,
            'event_type' => $eventType, 'formatted_value' => $formattedValue, 'details' => json_encode($details, JSON_THROW_ON_ERROR),
            'occurred_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @return array{id:string,number:string,status:string} */
    private function issueResult(NumberSequenceIssue $issue): array
    {
        return ['id' => $issue->id, 'number' => $issue->formatted_value, 'status' => 'issued'];
    }

    /**
     * Replay an existing reservation for a repeated idempotency key.
     *
     * A cancelled reservation must never replay: cancel() releases the number back to the pool, so by now it may
     * already belong to a different app transaction. Handing it back would put one number on two documents.
     *
     * @return array{id:string,number:string,status:string,expires_at:string|null}
     */
    private function replayReservation(NumberSequenceReservation $reservation): array
    {
        if ($reservation->status === 'cancelled') {
            $this->fail('idempotency_key', 'Reservation untuk idempotency key ini sudah dibatalkan; gunakan idempotency key baru.');
        }

        return $this->reservationResult($reservation);
    }

    /** @return array{id:string,number:string,status:string,expires_at:string|null} */
    private function reservationResult(NumberSequenceReservation $reservation): array
    {
        return ['id' => $reservation->id, 'number' => $reservation->formatted_value, 'status' => $reservation->status, 'expires_at' => $reservation->expires_at?->toISOString()];
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
