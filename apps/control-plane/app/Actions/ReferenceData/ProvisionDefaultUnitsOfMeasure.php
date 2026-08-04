<?php

namespace App\Actions\ReferenceData;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ProvisionDefaultUnitsOfMeasure
{
    public function forTenant(string $tenantId): void
    {
        DB::transaction(function () use ($tenantId): void {
            $classes = $this->records($tenantId, 'uom_classes', [
                ['QUANTITY', 'Jumlah'], ['MASS', 'Massa'], ['LENGTH', 'Panjang'], ['AREA', 'Luas'], ['VOLUME', 'Volume'], ['TIME', 'Waktu'], ['ENERGY', 'Energi'],
            ]);
            $systems = $this->records($tenantId, 'uom_systems', [['METRIC', 'Metrik'], ['IMPERIAL', 'Imperial'], ['US_CUSTOMARY', 'Amerika Serikat']]);
            $units = [];
            foreach ([
                ['PCS', 'Pieces', 'pcs', 'QUANTITY', null, 0, 'C62'], ['SET', 'Set', 'set', 'QUANTITY', null, 0, 'SET'], ['PR', 'Pair', 'pasang', 'QUANTITY', null, 0, 'PR'], ['LUSIN', 'Lusin', 'lusin', 'QUANTITY', null, 0, 'DZN'],
                ['KG', 'Kilogram', 'kg', 'MASS', 'METRIC', 3, 'KGM'], ['G', 'Gram', 'g', 'MASS', 'METRIC', 0, 'GRM'], ['TON', 'Metric ton', 't', 'MASS', 'METRIC', 3, 'TNE'],
                ['M', 'Metre', 'm', 'LENGTH', 'METRIC', 3, 'MTR'], ['CM', 'Centimetre', 'cm', 'LENGTH', 'METRIC', 1, 'CMT'], ['KM', 'Kilometre', 'km', 'LENGTH', 'METRIC', 3, 'KMT'],
                ['M2', 'Square metre', 'm²', 'AREA', 'METRIC', 2, 'MTK'], ['HA', 'Hectare', 'ha', 'AREA', 'METRIC', 4, 'HAR'],
                ['L', 'Litre', 'L', 'VOLUME', 'METRIC', 3, 'LTR'], ['ML', 'Millilitre', 'mL', 'VOLUME', 'METRIC', 0, 'MLT'], ['M3', 'Cubic metre', 'm³', 'VOLUME', 'METRIC', 3, 'MTQ'],
                ['HOUR', 'Hour', 'jam', 'TIME', null, 2, 'HUR'], ['MIN', 'Minute', 'menit', 'TIME', null, 0, 'MIN'], ['DAY', 'Day', 'hari', 'TIME', null, 2, 'DAY'],
                ['KWH', 'Kilowatt hour', 'kWh', 'ENERGY', 'METRIC', 3, 'KWH'],
            ] as [$code, $name, $symbol, $class, $system, $decimals, $externalCode]) {
                $unit = DB::table('units_of_measure')->where(['tenant_id' => $tenantId, 'code' => $code])->first();
                if (! $unit) {
                    $id = (string) Str::ulid();
                    DB::table('units_of_measure')->insert(['id' => $id, 'tenant_id' => $tenantId, 'uom_class_id' => $classes[$class], 'uom_system_id' => $system ? $systems[$system] : null, 'code' => $code, 'name' => $name, 'symbol' => $symbol, 'decimal_places' => $decimals, 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
                } else { $id = $unit->id; }
                $units[$code] = $id;
                $external = DB::table('uom_external_codes')->where(['tenant_id' => $tenantId, 'scheme' => 'UN/ECE-REC20', 'code' => $externalCode]);
                if ($external->exists()) $external->update(['unit_id' => $id, 'updated_at' => now()]);
                else $external->insert(['id' => (string) Str::ulid(), 'tenant_id' => $tenantId, 'scheme' => 'UN/ECE-REC20', 'code' => $externalCode, 'unit_id' => $id, 'created_at' => now(), 'updated_at' => now()]);
            }
            foreach ([['LUSIN', 'PCS', 12], ['KG', 'G', 1000], ['KG', 'TON', 0.001], ['M', 'CM', 100], ['KM', 'M', 1000], ['M2', 'HA', 0.0001], ['L', 'ML', 1000], ['M3', 'L', 1000], ['HOUR', 'MIN', 60], ['DAY', 'HOUR', 24]] as [$from, $to, $factor]) {
                $this->conversion($tenantId, $units[$from], $units[$to], $factor);
                $this->conversion($tenantId, $units[$to], $units[$from], 1 / $factor);
            }
        });
    }

    /** @param list<array{0:string,1:string}> $items @return array<string,string> */
    private function records(string $tenantId, string $table, array $items): array
    {
        $result = [];
        foreach ($items as [$code, $name]) {
            $record = DB::table($table)->where(['tenant_id' => $tenantId, 'code' => $code])->first();
            if (! $record) { $id = (string) Str::ulid(); DB::table($table)->insert(['id' => $id, 'tenant_id' => $tenantId, 'code' => $code, 'name' => $name, 'active' => true, 'created_at' => now(), 'updated_at' => now()]); }
            else { $id = $record->id; }
            $result[$code] = $id;
        }
        return $result;
    }

    private function conversion(string $tenantId, string $from, string $to, float $factor): void
    {
        $conversion = DB::table('uom_conversions')->where(['tenant_id' => $tenantId, 'from_unit_id' => $from, 'to_unit_id' => $to]);
        if ($conversion->exists()) $conversion->update(['factor' => $factor, 'offset' => 0, 'rounding_scale' => null, 'updated_at' => now()]);
        else $conversion->insert(['id' => (string) Str::ulid(), 'tenant_id' => $tenantId, 'from_unit_id' => $from, 'to_unit_id' => $to, 'factor' => $factor, 'offset' => 0, 'rounding_scale' => null, 'created_at' => now(), 'updated_at' => now()]);
    }
}
