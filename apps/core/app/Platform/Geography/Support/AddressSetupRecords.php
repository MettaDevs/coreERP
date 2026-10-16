<?php

namespace App\Platform\Geography\Support;

use App\Platform\Modules\Contracts\IncrementalList;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Daftar master alamat bersama: satu query berjoin, satu batch, tanpa baris milik tenant. */
final class AddressSetupRecords
{
    public const TABLES = [
        'countries' => 'country_regions', 'provinces' => 'ref_provinces',
        'regencies' => 'ref_regencies', 'cities' => 'ref_regencies',
        'districts' => 'ref_districts', 'villages' => 'ref_villages',
        'streets' => 'ref_streets', 'groupOfHouses' => 'ref_group_of_houses',
        'landPlots' => 'ref_land_plots', 'buildings' => 'ref_buildings',
        'postalCodes' => 'ref_postal_codes',
    ];

    /** @return array{data: Collection<int, \stdClass>, next_cursor: ?string} */
    public static function batch(Request $request): array
    {
        $input = $request->validate([
            'section' => ['required', Rule::in(array_keys(self::TABLES))],
            'country' => ['nullable', 'string', 'max:3'],
            'province_id' => ['nullable', 'ulid'], 'regency_id' => ['nullable', 'ulid'],
            'district_id' => ['nullable', 'ulid'], 'village_id' => ['nullable', 'ulid'],
            'include_cities' => ['sometimes', 'boolean'],
        ]);
        $section = $input['section'];
        $query = DB::table(self::TABLES[$section].' as records')->select('records.*');
        $country = 'records';
        $province = $regency = $district = $village = null;

        if ($section !== 'countries') {
            if ($section === 'postalCodes') {
                $query->leftJoin('ref_villages as village', 'village.id', '=', 'records.village_id')
                    ->leftJoin('ref_districts as district', 'district.id', '=', DB::raw('COALESCE(records.district_id, village.district_id)'))
                    ->leftJoin('ref_regencies as regency', 'regency.id', '=', DB::raw('COALESCE(records.regency_id, district.regency_id)'))
                    ->leftJoin('ref_provinces as province', 'province.id', '=', DB::raw('COALESCE(records.province_id, regency.province_id)'))
                    ->leftJoin('country_regions as country', 'country.code', '=', 'records.country_code');
                $village = 'village';
                $district = 'district';
                $regency = 'regency';
                $province = 'province';
            } else {
                if (in_array($section, ['streets', 'groupOfHouses', 'landPlots', 'buildings'], true)) {
                    $query->leftJoin('ref_villages as village', 'village.id', '=', 'records.village_id');
                    $village = 'village';
                } elseif ($section === 'villages') {
                    $village = 'records';
                }
                if ($village !== null) {
                    $query->leftJoin('ref_districts as district', 'district.id', '=', $village.'.district_id');
                    $district = 'district';
                } elseif ($section === 'districts') {
                    $district = 'records';
                }
                if ($district !== null) {
                    $query->leftJoin('ref_regencies as regency', 'regency.id', '=', $district.'.regency_id');
                    $regency = 'regency';
                } elseif (in_array($section, ['regencies', 'cities'], true)) {
                    $regency = 'records';
                }
                if ($regency !== null) {
                    $query->leftJoin('ref_provinces as province', 'province.id', '=', $regency.'.province_id');
                    $province = 'province';
                } else {
                    $province = 'records';
                }
                $query->leftJoin('country_regions as country', 'country.code', '=', $province.'.country_code');
            }
            $country = 'country';
        } else {
            $query->selectRaw('records.code as id')->where('records.active', true);
        }

        foreach (['country' => [$country, 'code'], 'province_id' => [$province, 'id'],
            'regency_id' => [$regency, 'id'], 'district_id' => [$district, 'id'], 'village_id' => [$village, 'id']] as $filter => [$alias, $column]) {
            if (! empty($input[$filter]) && $alias !== null) {
                $query->where($alias.'.'.$column, $input[$filter]);
            }
        }
        if (in_array($section, ['streets', 'groupOfHouses', 'landPlots', 'buildings', 'postalCodes'], true)) {
            $query->whereNull('records.tenant_id');
        }
        if (in_array($section, ['regencies', 'cities'], true) && ! $request->boolean('include_cities')) {
            $types = ['kota', 'city', 'capital_city', 'thanh_pho', 'planning_area', 'huc', 'municipality', 'town', 'khet', 'krong', 'quan', 'special_ward'];
            $expression = "(LOWER(COALESCE(records.type, '')) IN (".implode(',', array_fill(0, count($types), '?')).") OR LOWER(records.name) LIKE 'kota %')";
            $query->whereRaw(($section === 'cities' ? '' : 'NOT ').$expression, $types);
        }

        $code = match ($section) {
            'postalCodes' => 'records.postal_code', 'landPlots' => 'records.plot_number',
            'streets' => "COALESCE(records.rt, records.rw, '')",
            'groupOfHouses' => 'records.name', 'buildings' => 'COALESCE(records.block, records.name)',
            default => 'records.code',
        };
        $name = $section === 'postalCodes' ? 'records.area_name' : 'records.name';
        $query->selectRaw($code.' as list_code')->selectRaw($name.' as list_name');
        foreach (['country' => $country, 'province' => $province, 'regency' => $regency, 'district' => $district, 'village' => $village] as $label => $alias) {
            $query->selectRaw(($alias === null ? 'NULL' : $alias.'.name').' as '.$label.'_name');
        }
        $query->selectRaw('COALESCE('.($province === null ? '' : $province.'.timezone, ').$country.'.timezone) as resolved_timezone');

        return IncrementalList::query($request, $query, ['code' => 'list_code', 'name' => 'list_name'],
            ['list_code', 'list_name', 'country_name', 'province_name', 'regency_name', 'district_name', 'village_name'], 'name');
    }
}
