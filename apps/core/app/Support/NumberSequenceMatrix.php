<?php

namespace App\Support;

/**
 * Every number sequence configuration that is legal, expressed once.
 *
 * The service rejects nonsense combinations (continuous plus manual, fiscal reset without a legal entity, a reset
 * period whose format cannot vary with it), so a matrix that just multiplies every option together would be mostly
 * invalid. This is the enumerated set of valid shapes instead, which is what a simulation should actually cover.
 */
class NumberSequenceMatrix
{
    /**
     * @return list<array{key:string,scope_type:string,is_continuous:bool,allow_manual:bool,reset_period:string,preallocation_enabled:bool,preallocation_quantity:int,segments:list<array<string,mixed>>}>
     */
    public static function all(): array
    {
        return [
            // --- tenant scope -------------------------------------------------------------------------------
            self::shape('tenant-auto-block', 'tenant', reset: 'never', prealloc: true, quantity: 20, segments: [
                ['type' => 'constant', 'value' => 'INV-'], ['type' => 'number', 'length' => 8],
            ]),
            self::shape('tenant-auto-direct', 'tenant', reset: 'never', prealloc: false, segments: [
                ['type' => 'number', 'length' => 8],
            ]),
            self::shape('tenant-auto-yearly', 'tenant', reset: 'calendar_year', prealloc: true, quantity: 20, segments: [
                ['type' => 'year'], ['type' => 'constant', 'value' => '-'], ['type' => 'number', 'length' => 6],
            ]),
            self::shape('tenant-continuous-pool', 'tenant', continuous: true, reset: 'never', prealloc: true, quantity: 10, segments: [
                ['type' => 'constant', 'value' => 'JV-'], ['type' => 'number', 'length' => 8],
            ]),
            self::shape('tenant-continuous-direct', 'tenant', continuous: true, reset: 'never', prealloc: false, segments: [
                ['type' => 'constant', 'value' => 'CN-'], ['type' => 'number', 'length' => 8],
            ]),
            self::shape('tenant-manual', 'tenant', manual: true, reset: 'never', prealloc: true, quantity: 20, segments: [
                ['type' => 'constant', 'value' => 'MAN-'], ['type' => 'number', 'length' => 6],
            ]),

            // --- legal entity scope, including everything fiscal --------------------------------------------
            self::shape('entity-auto', 'legal_entity', reset: 'never', prealloc: true, quantity: 20, segments: [
                ['type' => 'scope'], ['type' => 'constant', 'value' => '-'], ['type' => 'number', 'length' => 6],
            ]),
            self::shape('entity-yearly', 'legal_entity', reset: 'calendar_year', prealloc: true, quantity: 20, segments: [
                ['type' => 'scope'], ['type' => 'year'], ['type' => 'number', 'length' => 5],
            ]),
            self::shape('entity-fiscal-year', 'legal_entity', reset: 'fiscal_year', prealloc: true, quantity: 20, segments: [
                ['type' => 'fiscal_year'], ['type' => 'constant', 'value' => '/'], ['type' => 'number', 'length' => 6],
            ]),
            self::shape('entity-fiscal-period', 'legal_entity', reset: 'fiscal_period', prealloc: true, quantity: 20, segments: [
                ['type' => 'fiscal_year'], ['type' => 'constant', 'value' => '.'], ['type' => 'fiscal_period', 'length' => 2],
                ['type' => 'constant', 'value' => '.'], ['type' => 'number', 'length' => 5],
            ]),
            self::shape('entity-continuous-fiscal', 'legal_entity', continuous: true, reset: 'fiscal_year', prealloc: true, quantity: 10, segments: [
                ['type' => 'fiscal_year'], ['type' => 'constant', 'value' => '-GL-'], ['type' => 'number', 'length' => 6],
            ]),

            // --- operating unit scope, i.e. per branch ------------------------------------------------------
            self::shape('branch-auto', 'operating_unit', reset: 'never', prealloc: true, quantity: 20, segments: [
                ['type' => 'constant', 'value' => 'BR-'], ['type' => 'number', 'length' => 7],
            ]),
            self::shape('branch-yearly', 'operating_unit', reset: 'calendar_year', prealloc: false, segments: [
                ['type' => 'constant', 'value' => 'B'], ['type' => 'year'], ['type' => 'number', 'length' => 5],
            ]),
            self::shape('branch-continuous', 'operating_unit', continuous: true, reset: 'never', prealloc: true, quantity: 10, segments: [
                ['type' => 'constant', 'value' => 'BRC-'], ['type' => 'number', 'length' => 7],
            ]),
            // A branch dated by the legal entity's fiscal calendar. The caller supplies the legal entity per request,
            // mirroring the company context a Dynamics 365 transaction always carries, and that legal entity becomes
            // part of the counter's scope key.
            self::shape('branch-fiscal-year', 'operating_unit', reset: 'fiscal_year', prealloc: true, quantity: 20, segments: [
                ['type' => 'constant', 'value' => 'BF-'], ['type' => 'fiscal_year'], ['type' => 'constant', 'value' => '-'], ['type' => 'number', 'length' => 6],
            ]),
            self::shape('branch-fiscal-period', 'operating_unit', reset: 'fiscal_period', prealloc: false, segments: [
                ['type' => 'fiscal_year'], ['type' => 'constant', 'value' => '.'], ['type' => 'fiscal_period', 'length' => 2],
                ['type' => 'constant', 'value' => '.'], ['type' => 'number', 'length' => 5],
            ]),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $segments
     * @return array{key:string,scope_type:string,is_continuous:bool,allow_manual:bool,reset_period:string,preallocation_enabled:bool,preallocation_quantity:int,segments:list<array<string,mixed>>}
     */
    private static function shape(string $key, string $scopeType, array $segments, bool $continuous = false, bool $manual = false, string $reset = 'never', bool $prealloc = true, int $quantity = 20): array
    {
        return [
            'key' => $key,
            'scope_type' => $scopeType,
            'is_continuous' => $continuous,
            'allow_manual' => $manual,
            'reset_period' => $reset,
            'preallocation_enabled' => $prealloc,
            'preallocation_quantity' => $prealloc ? $quantity : 0,
            'segments' => $segments,
        ];
    }
}
