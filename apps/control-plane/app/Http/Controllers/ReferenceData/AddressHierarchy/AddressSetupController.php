<?php

namespace App\Http\Controllers\ReferenceData\AddressHierarchy;

use App\Http\Controllers\Controller;
use App\Models\ReferenceData\AddressHierarchy\AdministrativeDivision;
use App\Models\ReferenceData\AddressHierarchy\AdministrativeDivisionExternalCode;
use App\Models\ReferenceData\AddressHierarchy\AdministrativeDivisionTranslation;
use App\Models\ReferenceData\AddressHierarchy\Building;
use App\Models\ReferenceData\AddressHierarchy\Country;
use App\Models\ReferenceData\AddressHierarchy\CountryHierarchyLevel;
use App\Models\ReferenceData\AddressHierarchy\District;
use App\Models\ReferenceData\AddressHierarchy\GroupOfHouses;
use App\Models\ReferenceData\AddressHierarchy\LandPlot;
use App\Models\ReferenceData\AddressHierarchy\PostalCode;
use App\Models\ReferenceData\AddressHierarchy\Province;
use App\Models\ReferenceData\AddressHierarchy\Regency;
use App\Models\ReferenceData\AddressHierarchy\Street;
use App\Models\ReferenceData\AddressHierarchy\Village;
use App\Services\AddressHierarchy\TimezoneResolverService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

final class AddressSetupController extends Controller
{
    public function __construct(
        protected TimezoneResolverService $timezoneResolver
    ) {}

    public function index(Request $request): JsonResponse|Response
    {
        $section  = $request->query('section', 'countries');
        $country  = $request->query('country', 'IDN');
        if ($country === 'ID') {
            $country = 'IDN';
        }
        $province = $request->query('province_id', '');
        $regency  = $request->query('regency_id', '');
        $district = $request->query('district_id', '');
        $village  = $request->query('village_id', '');

        $selectedId = session('saved_id') ?: $request->query('selected_id', '');
        $savedSection = session('saved_section') ?: $section;

        if (!empty($selectedId)) {
            if ($savedSection === 'provinces') {
                $p = Province::find($selectedId);
                if ($p) {
                    $country = $p->country_code ?: $country;
                    $province = $p->id;
                }
            } elseif ($savedSection === 'regencies') {
                $r = Regency::with('province')->find($selectedId);
                if ($r) {
                    $regency = $r->id;
                    $province = $r->province_id ?: $province;
                    $country = $r->province?->country_code ?: $country;
                }
            } elseif ($savedSection === 'districts') {
                $d = District::with('regency.province')->find($selectedId);
                if ($d) {
                    $district = $d->id;
                    $regency = $d->regency_id ?: $regency;
                    $province = $d->regency?->province_id ?: $province;
                    $country = $d->regency?->province?->country_code ?: $country;
                }
            } elseif ($savedSection === 'villages') {
                $v = Village::with('district.regency.province')->find($selectedId);
                if ($v) {
                    $village = $v->id;
                    $district = $v->district_id ?: $district;
                    $regency = $v->district?->regency_id ?: $regency;
                    $province = $v->district?->regency?->province_id ?: $province;
                    $country = $v->district?->regency?->province?->country_code ?: $country;
                }
            }
        }

        // Bottom-up parent resolution: if child is supplied, automatically discover and sync parents
        if (!empty($village)) {
            $v = Village::with('district.regency.province')->find($village);
            if ($v && $v->district) {
                if (!empty($district) && $v->district_id !== $district) {
                    $village = '';
                } else {
                    $district = $v->district_id;
                    $regency  = $v->district->regency_id ?: $regency;
                    $province = $v->district->regency?->province_id ?: $province;
                    $country  = $v->district->regency?->province?->country_code ?: $country;
                }
            }
        } elseif (!empty($district)) {
            $d = District::with('regency.province')->find($district);
            if ($d && $d->regency) {
                if (!empty($regency) && $d->regency_id !== $regency) {
                    $district = '';
                } else {
                    $regency  = $d->regency_id;
                    $province = $d->regency->province_id ?: $province;
                    $country  = $d->regency->province?->country_code ?: $country;
                }
            }
        } elseif (!empty($regency)) {
            $r = Regency::with('province')->find($regency);
            if ($r && $r->province) {
                if (!empty($province) && $r->province_id !== $province) {
                    $regency = '';
                } else {
                    $province = $r->province_id;
                    $country  = $r->province->country_code ?: $country;
                }
            }
        } elseif (!empty($province)) {
            $p = Province::find($province);
            if ($p) {
                if (!empty($country) && $p->country_code !== $country) {
                    $province = '';
                } else {
                    $country = $p->country_code ?: $country;
                }
            }
        }

        // Top-down consistency validation: if a child is incompatible with parent, reset child
        if (!empty($province)) {
            $p = Province::find($province);
            if (!$p || ($country && $p->country_code !== $country)) {
                $province = '';
                $regency  = '';
                $district = '';
                $village  = '';
            }
        }
        if (!empty($regency)) {
            $r = Regency::find($regency);
            if (!$r || ($province && $r->province_id !== $province)) {
                $regency  = '';
                $district = '';
                $village  = '';
            }
        }
        if (!empty($district)) {
            $d = District::find($district);
            if (!$d || ($regency && $d->regency_id !== $regency)) {
                $district = '';
                $village  = '';
            }
        }
        if (!empty($village)) {
            $v = Village::find($village);
            if (!$v || ($district && $v->district_id !== $district)) {
                $village = '';
            }
        }

        $countries = Country::where('active', true)->orderBy('name')->get();
        if ($countries->isEmpty()) {
            $countries = Country::orderBy('name')->get();
        }

        $provinces = Province::when($country, fn ($q) => $q->where('country_code', $country))
            ->orderBy('name')
            ->get();

        $regencies = $province
            ? Regency::with('province')->where('province_id', $province)->orderBy('name')->get()
            : ($country ? Regency::with('province')->whereHas('province', fn ($q) => $q->where('country_code', $country))->orderBy('name')->get() : collect());

        $districts = $regency
            ? District::with('regency.province')->where('regency_id', $regency)->orderBy('name')->get()
            : ($province ? District::with('regency.province')->whereHas('regency', fn ($q) => $q->where('province_id', $province))->orderBy('name')->get()
                : ($country ? District::with('regency.province')->whereHas('regency.province', fn ($q) => $q->where('country_code', $country))->orderBy('name')->limit(500)->get() : collect()));

        $villages = $district
            ? Village::with('district.regency.province')->where('district_id', $district)->orderBy('name')->get()
            : ($regency
                ? Village::with('district.regency.province')->whereHas('district', fn ($q) => $q->where('regency_id', $regency))->orderBy('name')->limit(300)->get()
                : ($province
                    ? Village::with('district.regency.province')->whereHas('district.regency', fn ($q) => $q->where('province_id', $province))->orderBy('name')->limit(300)->get()
                    : ($country ? Village::with('district.regency.province')->whereHas('district.regency.province', fn ($q) => $q->where('country_code', $country))->orderBy('name')->limit(300)->get() : collect())));

        $streets = $village
            ? Street::where('village_id', $village)->orderBy('rt')->orderBy('rw')->get()
            : ($district
                ? Street::whereHas('village', fn ($q) => $q->where('district_id', $district))->orderBy('name')->limit(300)->get()
                : ($regency
                    ? Street::whereHas('village.district', fn ($q) => $q->where('regency_id', $regency))->orderBy('name')->limit(300)->get()
                    : ($province
                        ? Street::whereHas('village.district.regency', fn ($q) => $q->where('province_id', $province))->orderBy('name')->limit(300)->get()
                        : Street::orderBy('name')->limit(300)->get())));

        $groupOfHouses = $village
            ? GroupOfHouses::where('village_id', $village)->orderBy('name')->get()
            : ($district
                ? GroupOfHouses::whereHas('village', fn ($q) => $q->where('district_id', $district))->orderBy('name')->limit(300)->get()
                : ($regency
                    ? GroupOfHouses::whereHas('village.district', fn ($q) => $q->where('regency_id', $regency))->orderBy('name')->limit(300)->get()
                    : ($province
                        ? GroupOfHouses::whereHas('village.district.regency', fn ($q) => $q->where('province_id', $province))->orderBy('name')->limit(300)->get()
                        : GroupOfHouses::orderBy('name')->limit(300)->get())));

        $landPlots = $village
            ? LandPlot::where('village_id', $village)->orderBy('plot_number')->get()
            : ($district
                ? LandPlot::whereHas('village', fn ($q) => $q->where('district_id', $district))->orderBy('plot_number')->limit(300)->get()
                : ($regency
                    ? LandPlot::whereHas('village.district', fn ($q) => $q->where('regency_id', $regency))->orderBy('plot_number')->limit(300)->get()
                    : ($province
                        ? LandPlot::whereHas('village.district.regency', fn ($q) => $q->where('province_id', $province))->orderBy('plot_number')->limit(300)->get()
                        : LandPlot::orderBy('plot_number')->limit(300)->get())));

        $buildings = $village
            ? Building::where('village_id', $village)->orderBy('name')->get()
            : ($district
                ? Building::whereHas('village', fn ($q) => $q->where('district_id', $district))->orderBy('name')->limit(300)->get()
                : ($regency
                    ? Building::whereHas('village.district', fn ($q) => $q->where('regency_id', $regency))->orderBy('name')->limit(300)->get()
                    : ($province
                        ? Building::whereHas('village.district.regency', fn ($q) => $q->where('province_id', $province))->orderBy('name')->limit(300)->get()
                        : Building::orderBy('name')->limit(300)->get())));

        $postalCodes = PostalCode::with(['country', 'province', 'regency', 'district', 'village'])
            ->when($country, fn ($q) => $q->where('country_code', $country))
            ->when($province, fn ($q) => $q->where('province_id', $province))
            ->when($regency,  fn ($q) => $q->where('regency_id', $regency))
            ->when($district, fn ($q) => $q->where('district_id', $district))
            ->when($village,  fn ($q) => $q->where('village_id', $village))
            ->orderBy('postal_code')
            ->limit(150)
            ->get();
        $parameters = DB::table('ref_address_parameters')->get();

        $dropdownProvinces = $country ? Province::where('country_code', $country)->orderBy('name')->get() : Province::orderBy('name')->get();
        $dropdownRegencies = $province ? Regency::where('province_id', $province)->orderBy('name')->get() : ($country ? Regency::whereHas('province', fn ($q) => $q->where('country_code', $country))->orderBy('name')->get() : collect());
        $dropdownDistricts = $regency ? District::where('regency_id', $regency)->orderBy('name')->get() : ($province ? District::whereHas('regency', fn ($q) => $q->where('province_id', $province))->orderBy('name')->get() : collect());
        $dropdownVillages  = $district ? Village::where('district_id', $district)->orderBy('name')->get() : ($regency ? Village::whereHas('district', fn ($q) => $q->where('regency_id', $regency))->orderBy('name')->limit(500)->get() : ($province ? Village::whereHas('district.regency', fn ($q) => $q->where('province_id', $province))->orderBy('name')->limit(500)->get() : collect()));

        $currentCountry  = Country::where('code', $country)->first();
        $currentProvince = $province ? Province::find($province) : null;
        $currentRegency  = $regency ? Regency::find($regency) : null;
        $currentDistrict = $district ? District::find($district) : null;

        $activeDivisionType = $village ? 'village' : ($district ? 'district' : ($regency ? 'regency' : ($province ? 'province' : 'country')));
        $activeDivisionId   = $village ?: ($district ?: ($regency ?: ($province ?: $country)));
        $activeTimezone     = $activeDivisionId ? $this->timezoneResolver->resolve($activeDivisionType, $activeDivisionId) : null;

        $hierarchyLevels = CountryHierarchyLevel::where('country_code', $country)->orderBy('level')->get();

        return Inertia::render('settings/address-hierarchy/address-setup', [
            'section'         => $section,
            'countries'       => $countries,
            'provinces'       => $provinces,
            'regencies'       => $regencies,
            'districts'       => $districts,
            'villages'        => $villages,
            'streets'         => $streets,
            'groupOfHouses'   => $groupOfHouses,
            'landPlots'       => $landPlots,
            'buildings'       => $buildings,
            'postalCodes'     => $postalCodes,
            'parameters'      => $parameters,
            'hierarchyLevels' => $hierarchyLevels,
            'activeTimezone'  => $activeTimezone,
            'dropdowns'   => [
                'provinces' => $dropdownProvinces,
                'regencies' => $dropdownRegencies,
                'districts' => $dropdownDistricts,
                'villages'  => $dropdownVillages,
            ],
            'context' => [
                'country'  => $currentCountry ? ['code' => $currentCountry->code, 'name' => $currentCountry->name] : null,
                'province' => $currentProvince ? ['id' => $currentProvince->id, 'name' => $currentProvince->name, 'code' => $currentProvince->code] : null,
                'regency'  => $currentRegency ? ['id' => $currentRegency->id, 'name' => $currentRegency->name, 'code' => $currentRegency->code] : null,
                'district' => $currentDistrict ? ['id' => $currentDistrict->id, 'name' => $currentDistrict->name, 'code' => $currentDistrict->code] : null,
            ],
            'selectedId'      => $selectedId ?: null,
            'filters' => compact('country', 'province', 'regency', 'district', 'village'),
        ]);
    }

    public function storeCountry(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'code'       => 'required|string|max:3|uppercase',
            'iso3'       => 'nullable|string|max:3|uppercase',
            'name'       => 'required|string|max:100',
            'phone_code' => 'nullable|string|max:10',
            'timezone'   => 'nullable|string|max:50',
            'active'     => 'boolean',
        ]);

        if (empty($data['timezone'])) {
            $data['timezone'] = $this->timezoneResolver->inferCountryTimezone($data['code']) ?? 'UTC';
        }

        DB::table('ref_countries')->updateOrInsert(['code' => $data['code']], array_merge($data, [
            'created_at' => now(), 'updated_at' => now(),
        ]));

        DB::table('ref_administrative_division_timezones')->updateOrInsert(
            ['division_type' => 'country', 'division_id' => $data['code']],
            [
                'id'         => (string) Str::ulid(),
                'timezone'   => $data['timezone'],
                'is_default' => true,
                'status'     => 'active',
                'updated_at' => now(),
            ]
        );
        \Illuminate\Support\Facades\Cache::forget("timezone:division:country:{$data['code']}");

        return back()->with([
            'saved_id'      => $data['code'],
            'saved_section' => 'countries',
            'status'        => 'Record saved successfully.',
        ]);
    }

    public function destroyCountry(string $code): RedirectResponse
    {
        if (Province::where('country_code', $code)->exists()) {
            return back()->withErrors(['error' => 'Data negara tidak dapat dihapus karena masih memiliki child data provinsi.']);
        }
        DB::table('ref_countries')->where('code', $code)->delete();
        DB::table('ref_administrative_division_timezones')->where('division_type', 'country')->where('division_id', $code)->delete();
        \Illuminate\Support\Facades\Cache::forget("timezone:division:country:{$code}");
        return back();
    }

    public function storeProvince(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'id'              => 'nullable|string|exists:ref_provinces,id',
            'country_code'    => 'required|string|max:3|exists:ref_countries,code',
            'code'            => 'required|string|max:20',
            'name'            => 'required|string|max:150',
            'description'     => 'nullable|string|max:500',
            'timezone'        => 'nullable|string|max:50',
            'intrastat'       => 'nullable|string|max:50',
            'it_state_code'   => 'nullable|string|max:50',
            'state_code'      => 'nullable|string|max:50',
            'default_state'   => 'boolean',
            'union_territory' => 'boolean',
            'active'          => 'boolean',
        ]);

        if (empty($data['timezone'])) {
            $data['timezone'] = $this->timezoneResolver->inferProvinceTimezone($data['country_code'], $data['code'], $data['name']) ?? 'UTC';
        }

        $existing = (!empty($data['id']) ? Province::find($data['id']) : null)
            ?: Province::where('country_code', $data['country_code'])->where('code', $data['code'])->first();

        if ($existing) {
            if (Province::where('country_code', $data['country_code'])
                ->where('id', '!=', $existing->id)
                ->whereRaw('LOWER(name) = ?', [strtolower($data['name'])])
                ->exists()) {
                return back()->withErrors(['name' => 'State/province name already exists in this country.']);
            }
            $existing->update($data);
            $savedId = $existing->id;
        } else {
            if (Province::where('country_code', $data['country_code'])->whereRaw('LOWER(name) = ?', [strtolower($data['name'])])->exists()) {
                return back()->withErrors(['name' => 'State/province name already exists in this country.']);
            }
            $saved = Province::create(array_merge($data, ['id' => (string) Str::ulid()]));
            $savedId = $saved->id;
        }

        DB::table('ref_administrative_division_timezones')->updateOrInsert(
            ['division_type' => 'province', 'division_id' => $savedId],
            [
                'id'         => (string) Str::ulid(),
                'timezone'   => $data['timezone'],
                'is_default' => true,
                'status'     => 'active',
                'updated_at' => now(),
            ]
        );
        \Illuminate\Support\Facades\Cache::forget("timezone:division:province:{$savedId}");

        return back()->with([
            'saved_id'      => $savedId,
            'saved_section' => 'provinces',
            'status'        => 'Record saved successfully.',
        ]);
    }

    public function destroyProvince(string $id): RedirectResponse
    {
        if (Regency::where('province_id', $id)->exists()) {
            return back()->withErrors(['error' => 'Cannot delete record because it contains child records.']);
        }
        Province::findOrFail($id)->delete();
        return back()->with('status', 'Record deleted successfully.');
    }

    /** Regencies / Counties */
    public function storeRegency(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'id'             => 'nullable|string|exists:ref_regencies,id',
            'province_id'    => 'required|string|exists:ref_provinces,id',
            'code'           => 'required|string|max:20',
            'name'           => 'required|string|max:150',
            'description'    => 'nullable|string|max:500',
            'type'           => 'nullable|string|max:50',
            'it_county_code' => 'nullable|string|max:50',
            'es_county_code' => 'nullable|string|max:50',
            'active'         => 'boolean',
        ]);

        if (empty($data['type'])) {
            $data['type'] = 'county';
        }

        $cleanCode = str_replace('.', '', $data['code']);
        $data['code'] = $cleanCode;

        $existing = (!empty($data['id']) ? Regency::find($data['id']) : null)
            ?: Regency::where('province_id', $data['province_id'])->where('code', $cleanCode)->first();

        if ($existing) {
            if (Regency::where('province_id', $data['province_id'])
                ->where('id', '!=', $existing->id)
                ->whereRaw('LOWER(name) = ?', [strtolower($data['name'])])
                ->exists()) {
                return back()->withErrors(['name' => 'County/city name already exists in this state/province.']);
            }
            $existing->update($data);
            $savedId = $existing->id;
        } else {
            if (Regency::where('province_id', $data['province_id'])->whereRaw('LOWER(name) = ?', [strtolower($data['name'])])->exists()) {
                return back()->withErrors(['name' => 'County/city name already exists in this state/province.']);
            }
            $saved = Regency::create(array_merge($data, ['id' => (string) Str::ulid()]));
            $savedId = $saved->id;
        }
        return back()->with([
            'saved_id'      => $savedId,
            'saved_section' => 'regencies',
            'status'        => 'Record saved successfully.',
        ]);
    }

    public function destroyRegency(string $id): RedirectResponse
    {
        if (District::where('regency_id', $id)->exists()) {
            return back()->withErrors(['error' => 'Cannot delete record because it contains child records.']);
        }
        Regency::findOrFail($id)->delete();
        return back()->with('status', 'Record deleted successfully.');
    }

    /** Districts */
    public function storeDistrict(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'id'         => 'nullable|string|exists:ref_districts,id',
            'regency_id' => 'required|string|exists:ref_regencies,id',
            'code'       => 'required|string|max:20',
            'name'       => 'required|string|max:150',
            'active'     => 'boolean',
        ]);

        // Validate parent chain: regency must belong to a valid province
        $regency = Regency::with('province')->find($data['regency_id']);
        if (! $regency || ! $regency->province) {
            return back()->withErrors(['regency_id' => 'Invalid county/city parent reference.']);
        }

        $cleanCode = str_replace('.', '', $data['code']);
        $data['code'] = $cleanCode;

        $existing = (!empty($data['id']) ? District::find($data['id']) : null)
            ?: District::where('regency_id', $data['regency_id'])->where('code', $cleanCode)->first();

        if ($existing) {
            if (District::where('regency_id', $data['regency_id'])
                ->where('id', '!=', $existing->id)
                ->whereRaw('LOWER(name) = ?', [strtolower($data['name'])])
                ->exists()) {
                return back()->withErrors(['name' => 'District name already exists in this county/city.']);
            }
            $existing->update($data);
            $savedId = $existing->id;
        } else {
            if (District::where('regency_id', $data['regency_id'])->whereRaw('LOWER(name) = ?', [strtolower($data['name'])])->exists()) {
                return back()->withErrors(['name' => 'District name already exists in this county/city.']);
            }
            $saved = District::create(array_merge($data, ['id' => (string) Str::ulid()]));
            $savedId = $saved->id;
        }
        return back()->with([
            'saved_id'      => $savedId,
            'saved_section' => 'districts',
            'status'        => 'Record saved successfully.',
        ]);
    }

    public function destroyDistrict(string $id): RedirectResponse
    {
        if (Village::where('district_id', $id)->exists()) {
            return back()->withErrors(['error' => 'Cannot delete record because it contains child records.']);
        }
        District::findOrFail($id)->delete();
        return back()->with('status', 'Record deleted successfully.');
    }

    /** Villages */
    public function storeVillage(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'id'          => 'nullable|string|exists:ref_villages,id',
            'district_id' => 'required|string|exists:ref_districts,id',
            'code'        => 'required|string|max:30',
            'name'        => 'required|string|max:150',
            'type'        => 'nullable|string|max:50',
            'postal_code' => 'nullable|string|max:20',
            'active'      => 'boolean',
        ]);

        if (empty($data['type'])) {
            $data['type'] = 'village';
        }

        // Validate parent chain: district must belong to a valid regency and province
        $district = District::with('regency.province')->find($data['district_id']);
        if (! $district || ! $district->regency || ! $district->regency->province) {
            return back()->withErrors(['district_id' => 'Invalid district parent reference.']);
        }
        $countryCode = $district->regency->province->country_code ?? 'ID';

        $cleanCode = str_replace('.', '', $data['code']);
        $displayCode = 'V-' . $cleanCode;
        $data['display_code'] = $displayCode;

        if (! empty($data['postal_code']) && ! preg_match('/^[A-Za-z0-9\s\-]{3,10}$/', $data['postal_code'])) {
            return back()->withErrors(['postal_code' => 'Please enter a valid postal code format.']);
        }

        $existing = (!empty($data['id']) ? Village::find($data['id']) : null)
            ?: Village::where('district_id', $data['district_id'])->where('code', $cleanCode)->first();

        if ($existing) {
            if (Village::where('district_id', $data['district_id'])
                ->where('id', '!=', $existing->id)
                ->whereRaw('LOWER(name) = ?', [strtolower($data['name'])])
                ->exists()) {
                return back()->withErrors(['name' => 'Village name already exists in this district.']);
            }
            $existing->update($data);
            $id = $existing->id;
        } else {
            if (Village::where('district_id', $data['district_id'])->whereRaw('LOWER(name) = ?', [strtolower($data['name'])])->exists()) {
                return back()->withErrors(['name' => 'Village name already exists in this district.']);
            }
            $created = Village::create(array_merge($data, ['code' => $cleanCode]));
            $id = $created->id;
        }

        // Sync with ref_administrative_divisions
        AdministrativeDivision::updateOrCreate(
            ['country_id' => $countryCode, 'level' => 4, 'official_code' => $cleanCode],
            [
                'id'           => $id,
                'parent_id'    => $data['district_id'],
                'type'         => $data['type'] === 'kelurahan' ? 'urban_village' : 'village',
                'display_code' => $displayCode,
                'name'         => $data['name'],
                'status'       => 'active',
            ]
        );

        // Sync with ref_postal_codes if postal code is supplied
        if (! empty($data['postal_code'])) {
            PostalCode::updateOrCreate(
                [
                    'country_code' => $countryCode,
                    'postal_code'  => $data['postal_code'],
                    'village_id'   => $id,
                ],
                [
                    'province_id' => $district?->regency?->province_id,
                    'regency_id'  => $district?->regency_id,
                    'district_id' => $data['district_id'],
                    'area_name'   => $data['name'],
                    'source'      => 'POS_INDONESIA',
                    'status'      => 'active',
                    'active'      => true,
                ]
            );
        }

        return back()->with([
            'saved_id'      => $id,
            'saved_section' => 'villages',
            'status'        => 'Data berhasil disimpan.',
        ]);
    }

    public function destroyVillage(string $id): RedirectResponse
    {
        if (Street::where('village_id', $id)->exists() || Building::where('village_id', $id)->exists() || GroupOfHouses::where('village_id', $id)->exists() || LandPlot::where('village_id', $id)->exists()) {
            return back()->withErrors(['error' => 'Data tidak dapat dihapus karena masih memiliki data turunan.']);
        }
        Village::where('id', $id)->delete();
        AdministrativeDivision::where('id', $id)->delete();
        return back()->with('status', 'Data berhasil dihapus.');
    }

    /** Streets */
    public function storeStreet(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'id'                   => 'nullable|string|exists:ref_streets,id',
            'village_id'           => 'required|string|exists:ref_villages,id',
            'rt'                   => 'nullable|string|max:5',
            'rw'                   => 'nullable|string|max:5',
            'name'                 => 'nullable|string|max:200',
            'postal_code'          => 'nullable|string|max:10',
            'override_postal_code' => 'boolean',
            'active'               => 'boolean',
        ]);

        // Auto-inherit postal code from parent village if not overridden
        $parentVillage = Village::find($data['village_id']);
        if ($request->boolean('override_postal_code') && $request->filled('postal_code')) {
            $data['postal_code'] = $request->input('postal_code');
            $data['override_postal_code'] = true;
        } else {
            $data['postal_code'] = $parentVillage?->postal_code;
            $data['override_postal_code'] = false;
        }

        $id = $data['id'] ?? $request->input('id');
        $existing = ($id ? Street::find($id) : null)
            ?: Street::where('village_id', $data['village_id'])
                ->where('name', $data['name'] ?? null)
                ->where('rt', $data['rt'] ?? null)
                ->where('rw', $data['rw'] ?? null)
                ->first();

        if ($existing) {
            $existing->update($data);
            $savedId = $existing->id;
        } else {
            $created = Street::create(array_merge($data, ['id' => (string) Str::ulid()]));
            $savedId = $created->id;
        }

        return back()->with([
            'saved_id'      => $savedId,
            'saved_section' => 'streets',
            'status'        => 'Record saved successfully.',
        ]);
    }

    public function destroyStreet(string $id): RedirectResponse
    {
        Street::findOrFail($id)->delete();
        return back();
    }

    /** Buildings */
    public function storeBuilding(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'id'                   => 'nullable|string|exists:ref_buildings,id',
            'village_id'           => 'required|string|exists:ref_villages,id',
            'street_id'            => 'nullable|string|exists:ref_streets,id',
            'name'                 => 'required|string|max:200',
            'block'                => 'nullable|string|max:20',
            'unit'                 => 'nullable|string|max:50',
            'floor'                => 'nullable|string|max:20',
            'postal_code'          => 'nullable|string|max:10',
            'override_postal_code' => 'boolean',
            'active'               => 'boolean',
        ]);

        // Auto-inherit postal code from parent village if not overridden
        $parentVillage = Village::find($data['village_id']);
        if ($request->boolean('override_postal_code') && $request->filled('postal_code')) {
            $data['postal_code'] = $request->input('postal_code');
            $data['override_postal_code'] = true;
        } else {
            $data['postal_code'] = $parentVillage?->postal_code;
            $data['override_postal_code'] = false;
        }

        $id = $data['id'] ?? $request->input('id');
        if ($id && $existing = Building::find($id)) {
            $existing->update($data);
            $savedId = $existing->id;
        } else {
            $created = Building::create(array_merge($data, ['id' => (string) Str::ulid()]));
            $savedId = $created->id;
        }

        return back()->with([
            'saved_id'      => $savedId,
            'saved_section' => 'buildings',
            'status'        => 'Record saved successfully.',
        ]);
    }

    public function destroyBuilding(string $id): RedirectResponse
    {
        Building::findOrFail($id)->delete();
        return back();
    }

    /** Postal Codes */
    public function storePostalCode(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'id'               => 'nullable|string|exists:ref_postal_codes,id',
            'country_code'     => 'required|string|max:3|exists:ref_countries,code',
            'postal_code'      => 'required|string|max:10',
            'province_id'      => 'nullable|string|exists:ref_provinces,id',
            'regency_id'       => 'nullable|string|exists:ref_regencies,id',
            'district_id'      => 'nullable|string|exists:ref_districts,id',
            'village_id'       => 'nullable|string|exists:ref_villages,id',
            'area_name'        => 'nullable|string|max:200',
            'source'           => 'nullable|string|max:100',
            'source_reference' => 'nullable|string|max:255',
            'status'           => 'nullable|string|max:20',
            'active'           => 'boolean',
        ]);

        if ($data['country_code'] === 'ID' && ! preg_match('/^[0-9]{5}$/', $data['postal_code'])) {
            return back()->withErrors(['postal_code' => 'Kode pos Indonesia harus berupa 5 digit angka.']);
        }

        // Auto-resolve ancestors if village_id is set
        $villageId = ! empty($data['village_id']) ? $data['village_id'] : null;
        $data['village_id'] = $villageId;
        if ($villageId) {
            $village = Village::with('district.regency.province')->find($villageId);
            if ($village) {
                $data['district_id'] = $village->district_id;
                $data['regency_id']  = $village->district?->regency_id;
                $data['province_id'] = $village->district?->regency?->province_id;
                if (empty($data['area_name'])) {
                    $data['area_name'] = $village->name;
                }
                // Sync village primary postal code
                $village->update(['postal_code' => $data['postal_code']]);
            }
        }

        $id = $data['id'] ?? $request->input('id');
        if ($id && $existing = PostalCode::find($id)) {
            $existing->update($data);
            $savedId = $existing->id;
        } else {
            $query = PostalCode::where('country_code', $data['country_code'])
                ->where('postal_code', $data['postal_code']);

            if ($villageId) {
                $query->where('village_id', $villageId);
            } else {
                $query->whereNull('village_id');
                if (! empty($data['area_name'])) {
                    $query->where('area_name', $data['area_name']);
                }
                if (! empty($data['district_id'])) {
                    $query->where('district_id', $data['district_id']);
                }
            }
            $existing = $query->first();
            if ($existing) {
                $existing->update($data);
                $savedId = $existing->id;
            } else {
                $created = PostalCode::create(array_merge($data, [
                    'id'     => (string) Str::ulid(),
                    'source' => $data['source'] ?? 'POS_INDONESIA',
                    'status' => $data['status'] ?? 'active',
                ]));
                $savedId = $created->id;
            }
        }

        return back()->with([
            'saved_id'      => $savedId,
            'saved_section' => 'postalCodes',
            'status'        => 'Record saved successfully.',
        ]);
    }

    public function destroyPostalCode(string $id): RedirectResponse
    {
        $pc = PostalCode::findOrFail($id);
        if (
            Street::where('postal_code', $pc->postal_code)->where('village_id', $pc->village_id)->exists() ||
            Building::where('postal_code', $pc->postal_code)->where('village_id', $pc->village_id)->exists() ||
            GroupOfHouses::where('postal_code', $pc->postal_code)->where('village_id', $pc->village_id)->exists() ||
            LandPlot::where('postal_code', $pc->postal_code)->where('village_id', $pc->village_id)->exists()
        ) {
            return back()->withErrors(['error' => 'Postal code is in use by address records and cannot be deleted.']);
        }
        $pc->delete();
        return back();
    }

    /** Group of Houses */
    public function storeGroupOfHouses(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'id'                   => 'nullable|string|exists:ref_group_of_houses,id',
            'village_id'           => 'required|string|exists:ref_villages,id',
            'code'                 => 'nullable|string|max:30',
            'name'                 => 'required|string|max:200',
            'postal_code'          => 'nullable|string|max:10',
            'override_postal_code' => 'boolean',
            'status'               => 'nullable|string|max:20',
            'active'               => 'boolean',
        ]);

        // Auto-inherit postal code from parent village if not overridden
        $parentVillage = Village::find($data['village_id']);
        if ($request->boolean('override_postal_code') && $request->filled('postal_code')) {
            $data['postal_code'] = $request->input('postal_code');
            $data['override_postal_code'] = true;
        } else {
            $data['postal_code'] = $parentVillage?->postal_code;
            $data['override_postal_code'] = false;
        }

        $id = $data['id'] ?? $request->input('id');
        if ($id && $existing = GroupOfHouses::find($id)) {
            $existing->update($data);
            $savedId = $existing->id;
        } else {
            if (GroupOfHouses::where('village_id', $data['village_id'])->whereRaw('LOWER(name) = ?', [strtolower($data['name'])])->exists()) {
                return back()->withErrors(['name' => 'Group of houses name already exists in this village.']);
            }
            $created = GroupOfHouses::create(array_merge($data, ['id' => (string) Str::ulid()]));
            $savedId = $created->id;
        }

        return back()->with([
            'saved_id'      => $savedId,
            'saved_section' => 'groupOfHouses',
            'status'        => 'Record saved successfully.',
        ]);
    }

    public function destroyGroupOfHouses(string $id): RedirectResponse
    {
        if (LandPlot::where('group_of_houses_id', $id)->exists()) {
            return back()->withErrors(['error' => 'Cannot delete group of houses because it contains land plots.']);
        }
        GroupOfHouses::findOrFail($id)->delete();
        return back();
    }

    /** Land Plots */
    public function storeLandPlot(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'id'                   => 'nullable|string|exists:ref_land_plots,id',
            'village_id'           => 'required|string|exists:ref_villages,id',
            'street_id'            => 'nullable|string|exists:ref_streets,id',
            'group_of_houses_id'   => 'nullable|string|exists:ref_group_of_houses,id',
            'plot_number'          => 'required|string|max:50',
            'name'                 => 'nullable|string|max:200',
            'postal_code'          => 'nullable|string|max:10',
            'override_postal_code' => 'boolean',
            'status'               => 'nullable|string|max:20',
            'active'               => 'boolean',
        ]);

        // Auto-inherit postal code from parent village if not overridden
        $parentVillage = Village::find($data['village_id']);
        if ($request->boolean('override_postal_code') && $request->filled('postal_code')) {
            $data['postal_code'] = $request->input('postal_code');
            $data['override_postal_code'] = true;
        } else {
            $data['postal_code'] = $parentVillage?->postal_code;
            $data['override_postal_code'] = false;
        }

        $id = $data['id'] ?? $request->input('id');
        if ($id && $existing = LandPlot::find($id)) {
            $existing->update($data);
            $savedId = $existing->id;
        } else {
            if (LandPlot::where('village_id', $data['village_id'])->where('plot_number', $data['plot_number'])->exists()) {
                return back()->withErrors(['plot_number' => 'Plot number already exists in this village.']);
            }
            $created = LandPlot::create(array_merge($data, ['id' => (string) Str::ulid()]));
            $savedId = $created->id;
        }

        return back()->with([
            'saved_id'      => $savedId,
            'saved_section' => 'landPlots',
            'status'        => 'Record saved successfully.',
        ]);
    }

    public function destroyLandPlot(string $id): RedirectResponse
    {
        LandPlot::findOrFail($id)->delete();
        return back();
    }

    /** Address Parameters */
    public function storeParameters(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'country_code'   => 'required|string|max:3|exists:ref_countries,code',
            'use_province'   => 'boolean',
            'use_regency'    => 'boolean',
            'use_district'   => 'boolean',
            'use_village'    => 'boolean',
            'use_rt_rw'      => 'boolean',
            'use_postal_code'=> 'boolean',
            'use_building'   => 'boolean',
            'address_format' => 'nullable|string|max:500',
        ]);

        if (! empty($data['address_format'])) {
            $allowedVariables = ['street', 'rt', 'rw', 'village', 'district', 'regency', 'province', 'postal_code', 'country', 'building', 'block', 'unit', 'floor'];
            preg_match_all('/\{([^}]+)\}/', $data['address_format'], $matches);
            if (! empty($matches[1])) {
                foreach ($matches[1] as $var) {
                    if (! in_array($var, $allowedVariables, true)) {
                        return back()->withErrors(['address_format' => "Invalid template variable: {{$var}}"]);
                    }
                }
            }
        }

        DB::table('ref_address_parameters')->updateOrInsert(
            ['country_code' => $data['country_code']],
            array_merge($data, ['updated_at' => now()])
        );
        return back();
    }

    /** Hierarchy Lookups */
    public function lookupBottomUp(Request $request, TimezoneResolverService $timezoneResolver): JsonResponse
    {
        $id = $request->query('id');
        $type = $request->query('type', 'village');

        if ($type === 'village' || $type === 'villages') {
            $village = Village::with('district.regency.province.country')->find($id);
            if (! $village) {
                return response()->json(['message' => 'Village not found'], 404);
            }
            $tzData = $timezoneResolver->resolve('village', $village->id);

            return response()->json([
                'village'  => ['id' => $village->id, 'code' => $village->code, 'name' => $village->name, 'type' => $village->type, 'postal_code' => $village->postal_code],
                'district' => $village->district ? ['id' => $village->district->id, 'code' => $village->district->code, 'name' => $village->district->name] : null,
                'regency'  => $village->district?->regency ? ['id' => $village->district->regency->id, 'code' => $village->district->regency->code, 'name' => $village->district->regency->name, 'type' => $village->district->regency->type] : null,
                'province' => $village->district?->regency?->province ? ['id' => $village->district->regency->province->id, 'code' => $village->district->regency->province->code, 'name' => $village->district->regency->province->name] : null,
                'country'  => $village->district?->regency?->province?->country ? ['code' => $village->district->regency->province->country->code, 'name' => $village->district->regency->province->country->name] : null,
                'timezone' => $tzData,
                'lineage'  => [
                    'country'  => $village->district?->regency?->province?->country?->name,
                    'province' => $village->district?->regency?->province?->name,
                    'regency'  => $village->district?->regency?->name,
                    'district' => $village->district?->name,
                    'village'  => $village->name,
                ],
                'formatted' => sprintf('%s > %s > %s > %s > %s',
                    $village->district?->regency?->province?->country?->name ?? 'Country',
                    $village->district?->regency?->province?->name ?? '-',
                    $village->district?->regency?->name ?? '-',
                    $village->district?->name ?? '-',
                    $village->name
                ),
            ]);
        }

        if ($type === 'district' || $type === 'districts') {
            $district = District::with('regency.province.country')->find($id);
            if (! $district) {
                return response()->json(['message' => 'District not found'], 404);
            }
            $tzData = $timezoneResolver->resolve('district', $district->id);

            return response()->json([
                'district' => ['id' => $district->id, 'code' => $district->code, 'name' => $district->name],
                'regency'  => $district->regency ? ['id' => $district->regency->id, 'code' => $district->regency->code, 'name' => $district->regency->name, 'type' => $district->regency->type] : null,
                'province' => $district->regency?->province ? ['id' => $district->regency->province->id, 'code' => $district->regency->province->code, 'name' => $district->regency->province->name] : null,
                'country'  => $district->regency?->province?->country ? ['code' => $district->regency->province->country->code, 'name' => $district->regency->province->country->name] : null,
                'timezone' => $tzData,
                'lineage'  => [
                    'country'  => $district->regency?->province?->country?->name,
                    'province' => $district->regency?->province?->name,
                    'regency'  => $district->regency?->name,
                    'district' => $district->name,
                ],
                'formatted' => sprintf('%s > %s > %s > %s',
                    $district->regency?->province?->country?->name ?? 'Country',
                    $district->regency?->province?->name ?? '-',
                    $district->regency?->name ?? '-',
                    $district->name
                ),
            ]);
        }

        if ($type === 'regency' || $type === 'regencies' || $type === 'regencie') {
            $regency = Regency::with('province.country')->find($id);
            if (! $regency) {
                return response()->json(['message' => 'County/City not found'], 404);
            }
            $tzData = $timezoneResolver->resolve('regency', $regency->id);

            return response()->json([
                'regency'  => ['id' => $regency->id, 'code' => $regency->code, 'name' => $regency->name, 'type' => $regency->type],
                'province' => $regency->province ? ['id' => $regency->province->id, 'code' => $regency->province->code, 'name' => $regency->province->name] : null,
                'country'  => $regency->province?->country ? ['code' => $regency->province->country->code, 'name' => $regency->province->country->name] : null,
                'timezone' => $tzData,
                'lineage'  => [
                    'country'  => $regency->province?->country?->name,
                    'province' => $regency->province?->name,
                    'regency'  => $regency->name,
                ],
                'formatted' => sprintf('%s > %s > %s',
                    $regency->province?->country?->name ?? 'Indonesia',
                    $regency->province?->name ?? '-',
                    $regency->name
                ),
            ]);
        }

        if ($type === 'province' || $type === 'provinces') {
            $province = Province::with('country')->find($id);
            if (! $province) {
                return response()->json(['message' => 'Provinsi tidak ditemukan'], 404);
            }
            $tzData = $timezoneResolver->resolve('province', $province->id);

            return response()->json([
                'province' => ['id' => $province->id, 'code' => $province->code, 'name' => $province->name],
                'country'  => $province->country ? ['code' => $province->country->code, 'name' => $province->country->name] : null,
                'timezone' => $tzData,
                'lineage'  => [
                    'country'  => $province->country?->name,
                    'province' => $province->name,
                ],
                'formatted' => sprintf('%s > %s',
                    $province->country?->name ?? 'Indonesia',
                    $province->name
                ),
            ]);
        }

        if ($type === 'street') {
            $street = Street::with('village.district.regency.province.country')->find($id);
            if (! $street) return response()->json(['message' => 'Street tidak ditemukan'], 404);
            $village = $street->village;
            $tzData = $village ? $timezoneResolver->resolve('village', $village->id) : null;
            return response()->json([
                'street'   => ['id' => $street->id, 'name' => $street->name, 'rt' => $street->rt, 'rw' => $street->rw, 'postal_code' => $street->postal_code],
                'village'  => $village ? ['id' => $village->id, 'code' => $village->code, 'name' => $village->name, 'type' => $village->type, 'postal_code' => $village->postal_code] : null,
                'district' => $village?->district ? ['id' => $village->district->id, 'code' => $village->district->code, 'name' => $village->district->name] : null,
                'regency'  => $village?->district?->regency ? ['id' => $village->district->regency->id, 'code' => $village->district->regency->code, 'name' => $village->district->regency->name, 'type' => $village->district->regency->type] : null,
                'province' => $village?->district?->regency?->province ? ['id' => $village->district->regency->province->id, 'code' => $village->district->regency->province->code, 'name' => $village->district->regency->province->name] : null,
                'country'  => $village?->district?->regency?->province?->country ? ['code' => $village->district->regency->province->country->code, 'name' => $village->district->regency->province->country->name] : null,
                'timezone' => $tzData,
                'lineage'  => [
                    'country'  => $village?->district?->regency?->province?->country?->name,
                    'province' => $village?->district?->regency?->province?->name,
                    'regency'  => $village?->district?->regency?->name,
                    'district' => $village?->district?->name,
                    'village'  => $village?->name,
                    'street'   => $street->name ?: "RT {$street->rt} / RW {$street->rw}",
                ],
                'formatted' => sprintf('%s, RT %s/RW %s, %s, Kec. %s, %s, %s %s, %s',
                    $street->name ?: 'Alamat Lokal',
                    $street->rt ?? '-',
                    $street->rw ?? '-',
                    $village?->name ?? '-',
                    $village?->district?->name ?? '-',
                    $village?->district?->regency?->name ?? '-',
                    $village?->district?->regency?->province?->name ?? '-',
                    $street->postal_code ?? ($village?->postal_code ?? ''),
                    $village?->district?->regency?->province?->country?->name ?? '-'
                ),
            ]);
        }

        if ($type === 'building') {
            $bldg = Building::with('village.district.regency.province.country')->find($id);
            if (! $bldg) return response()->json(['message' => 'Gedung tidak ditemukan'], 404);
            $village = $bldg->village;
            $tzData = $village ? $timezoneResolver->resolve('village', $village->id) : null;
            return response()->json([
                'building' => ['id' => $bldg->id, 'name' => $bldg->name, 'block' => $bldg->block, 'unit' => $bldg->unit, 'floor' => $bldg->floor, 'postal_code' => $bldg->postal_code],
                'village'  => $village ? ['id' => $village->id, 'code' => $village->code, 'name' => $village->name, 'type' => $village->type, 'postal_code' => $village->postal_code] : null,
                'district' => $village?->district ? ['id' => $village->district->id, 'code' => $village->district->code, 'name' => $village->district->name] : null,
                'regency'  => $village?->district?->regency ? ['id' => $village->district->regency->id, 'code' => $village->district->regency->code, 'name' => $village->district->regency->name, 'type' => $village->district->regency->type] : null,
                'province' => $village?->district?->regency?->province ? ['id' => $village->district->regency->province->id, 'code' => $village->district->regency->province->code, 'name' => $village->district->regency->province->name] : null,
                'country'  => $village?->district?->regency?->province?->country ? ['code' => $village->district->regency->province->country->code, 'name' => $village->district->regency->province->country->name] : null,
                'timezone' => $tzData,
                'lineage'  => [
                    'country'  => $village?->district?->regency?->province?->country?->name,
                    'province' => $village?->district?->regency?->province?->name,
                    'regency'  => $village?->district?->regency?->name,
                    'district' => $village?->district?->name,
                    'village'  => $village?->name,
                    'building' => $bldg->name,
                ],
                'formatted' => sprintf('%s (Blok %s, Unit %s, Lt %s), %s, Kec. %s, %s, %s %s, %s',
                    $bldg->name,
                    $bldg->block ?? '-',
                    $bldg->unit ?? '-',
                    $bldg->floor ?? '-',
                    $village?->name ?? '-',
                    $village?->district?->name ?? '-',
                    $village?->district?->regency?->name ?? '-',
                    $village?->district?->regency?->province?->name ?? '-',
                    $bldg->postal_code ?? ($village?->postal_code ?? ''),
                    $village?->district?->regency?->province?->country?->name ?? '-'
                ),
            ]);
        }

        if ($type === 'postalCode' || $type === 'postal_code') {
            $pc = PostalCode::with(['country', 'province', 'regency', 'district', 'village'])->find($id);
            if (! $pc) return response()->json(['message' => 'Kode pos tidak ditemukan'], 404);
            $tzData = $pc->village_id ? $timezoneResolver->resolve('village', $pc->village_id) : ($pc->province_id ? $timezoneResolver->resolve('province', $pc->province_id) : null);
            return response()->json([
                'postal_code' => ['id' => $pc->id, 'postal_code' => $pc->postal_code, 'area_name' => $pc->area_name, 'source' => $pc->source, 'status' => $pc->status],
                'village'     => $pc->village ? ['id' => $pc->village->id, 'code' => $pc->village->code, 'name' => $pc->village->name, 'type' => $pc->village->type] : null,
                'district'    => $pc->district ? ['id' => $pc->district->id, 'code' => $pc->district->code, 'name' => $pc->district->name] : null,
                'regency'     => $pc->regency ? ['id' => $pc->regency->id, 'code' => $pc->regency->code, 'name' => $pc->regency->name, 'type' => $pc->regency->type] : null,
                'province'    => $pc->province ? ['id' => $pc->province->id, 'code' => $pc->province->code, 'name' => $pc->province->name] : null,
                'country'     => $pc->country ? ['code' => $pc->country->code, 'name' => $pc->country->name] : null,
                'timezone'    => $tzData,
                'lineage'     => [
                    'country'     => $pc->country?->name,
                    'province'    => $pc->province?->name,
                    'regency'     => $pc->regency?->name,
                    'district'    => $pc->district?->name,
                    'village'     => $pc->village?->name,
                    'postal_code' => $pc->postal_code,
                ],
                'formatted' => sprintf('Kode Pos %s (%s, %s, %s, %s %s)',
                    $pc->postal_code,
                    $pc->village?->name ?? ($pc->area_name ?? '-'),
                    $pc->district?->name ?? '-',
                    $pc->regency?->name ?? '-',
                    $pc->province?->name ?? '-',
                    $pc->country?->name ?? '-'
                ),
            ]);
        }

        return response()->json(['message' => 'Tipe hierarchy tidak valid'], 400);
    }

    public function lookupTopDown(Request $request): JsonResponse
    {
        $parentType = $request->query('parent_type');
        $parentId   = $request->query('parent_id');
        $provinceId = $request->query('province_id');
        $regencyId  = $request->query('regency_id');
        $districtId = $request->query('district_id');

        if ($provinceId || $regencyId || $districtId) {
            return response()->json([
                'provinces' => Province::where('country_code', 'ID')->orderBy('name')->get(),
                'regencies' => $provinceId ? Regency::where('province_id', $provinceId)->orderBy('name')->get() : collect(),
                'districts' => $regencyId ? District::where('regency_id', $regencyId)->orderBy('name')->get() : collect(),
                'villages'  => $districtId ? Village::where('district_id', $districtId)->orderBy('name')->get() : collect(),
            ]);
        }

        if ($parentType === 'country') {
            return response()->json(Province::where('country_code', $parentId ?: 'ID')->orderBy('name')->get());
        }
        if ($parentType === 'province') {
            return response()->json(Regency::where('province_id', $parentId)->orderBy('name')->get());
        }
        if ($parentType === 'regency') {
            return response()->json(District::where('regency_id', $parentId)->orderBy('name')->get());
        }
        if ($parentType === 'district') {
            return response()->json(Village::where('district_id', $parentId)->orderBy('name')->get());
        }

        return response()->json([
            'provinces' => Province::where('country_code', 'ID')->orderBy('name')->get(),
        ]);
    }

    /** Timezone Resolution */
    public function resolveTimezone(Request $request, TimezoneResolverService $timezoneResolver): JsonResponse
    {
        $divisionType = $request->query('division_type');
        $divisionId   = $request->query('division_id');

        $countryCode = $request->query('country_code') ?: $request->query('country_id');
        $provinceId  = $request->query('province_id');
        $regencyId   = $request->query('regency_id');
        $districtId  = $request->query('district_id');
        $villageId   = $request->query('village_id');

        if (! $divisionType || ! $divisionId) {
            if ($villageId) {
                $divisionType = 'village';
                $divisionId   = $villageId;
            } elseif ($districtId) {
                $divisionType = 'district';
                $divisionId   = $districtId;
            } elseif ($regencyId) {
                $divisionType = 'regency';
                $divisionId   = $regencyId;
            } elseif ($provinceId) {
                $divisionType = 'province';
                $divisionId   = $provinceId;
            } elseif ($countryCode) {
                $divisionType = 'country';
                $divisionId   = $countryCode;
            }
        }

        if (! $divisionId) {
            return response()->json(['message' => 'division_id atau country_code wajib diisi'], 400);
        }

        $result = $timezoneResolver->resolve($divisionType ?: 'country', $divisionId);
        
        // Fetch available timezones for the country
        $targetCountry = $countryCode;
        if (! $targetCountry && $divisionType === 'country') {
            $targetCountry = $divisionId;
        } elseif (! $targetCountry && $divisionType === 'province') {
            $targetCountry = Province::where('id', $divisionId)->value('country_code');
        }

        $availableTzs = [];
        if ($targetCountry) {
            $availableTzs = \App\Models\ReferenceData\AddressHierarchy\TimeZone::where('country_code', $targetCountry)
                ->where('active', true)
                ->get(['iana_name', 'display_name', 'utc_offset', 'is_default']);
        }

        if (! $result) {
            return response()->json([
                'timezone'             => null,
                'offset'               => null,
                'label'                => null,
                'display_name'         => 'Timezone belum dipetakan',
                'source_division_id'   => null,
                'source_division_type' => null,
                'source_division_name' => null,
                'available_timezones'  => $availableTzs,
            ], 200);
        }

        $result['available_timezones'] = $availableTzs;

        return response()->json($result);
    }

    /** Administrative Divisions */
    public function getDivisions(Request $request): JsonResponse
    {
        $countryId = $request->query('country_id', 'ID');
        $parentId  = $request->query('parent_id');
        $level     = (int) $request->query('level', 1);
        $search    = $request->query('search');

        $query = match ($level) {
            1 => Province::where('country_code', $countryId),
            2 => $parentId ? Regency::where('province_id', $parentId) : Regency::whereHas('province', fn ($q) => $q->where('country_code', $countryId)),
            3 => $parentId ? District::where('regency_id', $parentId) : District::whereHas('regency.province', fn ($q) => $q->where('country_code', $countryId)),
            4 => $parentId ? Village::where('district_id', $parentId) : Village::whereHas('district.regency.province', fn ($q) => $q->where('country_code', $countryId)),
            default => Province::where('country_code', $countryId),
        };

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ilike', "%{$search}%")
                  ->orWhere('code', 'ilike', "%{$search}%")
                  ->orWhere('display_code', 'ilike', "%{$search}%");
            });
        }

        return response()->json($query->orderBy('code')->get());
    }

    /** Paginated Villages */
    public function getVillagesPaginated(Request $request): JsonResponse
    {
        $districtId = $request->query('district_id');
        $regencyId  = $request->query('regency_id');
        $provinceId = $request->query('province_id');
        $country    = $request->query('country', 'ID');
        $search     = $request->query('search');
        $page       = (int) $request->query('page', 1);
        $perPage    = (int) $request->query('per_page', 50);
        $sort       = $request->query('sort', 'code');
        $direction  = $request->query('direction', 'asc');

        $query = Village::with('district.regency.province');

        if ($districtId) {
            $query->where('district_id', $districtId);
        } elseif ($regencyId) {
            $query->whereHas('district', fn ($q) => $q->where('regency_id', $regencyId));
        } elseif ($provinceId) {
            $query->whereHas('district.regency', fn ($q) => $q->where('province_id', $provinceId));
        } elseif ($country) {
            $query->whereHas('district.regency.province', fn ($q) => $q->where('country_code', $country));
        }

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ilike', "%{$search}%")
                  ->orWhere('code', 'ilike', "%{$search}%")
                  ->orWhere('display_code', 'ilike', "%{$search}%");
            });
        }

        $paginated = $query->orderBy($sort, $direction)->paginate($perPage, ['*'], 'page', $page);

        return response()->json($paginated);
    }

    /** External Codes */
    public function getExternalCodes(Request $request): JsonResponse
    {
        $divisionId = $request->query('division_id');
        if (! $divisionId) {
            return response()->json([]);
        }

        $codes = AdministrativeDivisionExternalCode::where('division_id', $divisionId)->orderBy('system')->get();
        return response()->json($codes);
    }

    public function storeExternalCode(Request $request): JsonResponse
    {
        $data = $request->validate([
            'division_id'   => 'required|string|max:50',
            'system'        => 'required|string|max:50',
            'external_code' => 'required|string|max:100',
            'description'   => 'nullable|string|max:255',
            'status'        => 'nullable|string|max:20',
        ]);

        $record = AdministrativeDivisionExternalCode::updateOrCreate(
            ['division_id' => $data['division_id'], 'system' => $data['system'], 'external_code' => $data['external_code']],
            [
                'id'          => (string) Str::ulid(),
                'description' => $data['description'] ?? null,
                'status'      => $data['status'] ?? 'active',
            ]
        );

        return response()->json(['message' => 'External code saved', 'data' => $record]);
    }

    public function destroyExternalCode(string $id): JsonResponse
    {
        AdministrativeDivisionExternalCode::where('id', $id)->delete();
        return response()->json(['message' => 'External code deleted']);
    }

    /** Translations */
    public function getTranslations(Request $request): JsonResponse
    {
        $divisionId = $request->query('division_id');
        if (! $divisionId) {
            return response()->json([]);
        }

        $translations = AdministrativeDivisionTranslation::where('division_id', $divisionId)->orderBy('locale')->get();
        return response()->json($translations);
    }

    public function storeTranslation(Request $request): JsonResponse
    {
        $data = $request->validate([
            'division_id' => 'required|string|max:50',
            'locale'      => 'required|string|max:10',
            'name'        => 'required|string|max:255',
            'description' => 'nullable|string',
        ]);

        $record = AdministrativeDivisionTranslation::updateOrCreate(
            ['division_id' => $data['division_id'], 'locale' => $data['locale']],
            [
                'id'          => (string) Str::ulid(),
                'name'        => $data['name'],
                'description' => $data['description'] ?? null,
            ]
        );

        return response()->json(['message' => 'Translation saved', 'data' => $record]);
    }

    public function destroyTranslation(string $id): JsonResponse
    {
        AdministrativeDivisionTranslation::where('id', $id)->delete();
        return response()->json(['message' => 'Translation deleted']);
    }
}

