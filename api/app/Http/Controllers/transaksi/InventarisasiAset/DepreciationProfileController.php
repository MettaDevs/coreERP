<?php

namespace App\Http\Controllers\transaksi\InventarisasiAset;

use App\Http\Controllers\Controller;
use App\Models\transaksi\InventarisasiAset\DepreciationProfile;
use App\Services\NumberSequenceClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use RuntimeException;

class DepreciationProfileController extends Controller
{
    private const RESOURCE = 'profil-penyusutan';

    public function index(Request $request): JsonResponse
    {
        $this->can($request, 'read');
        return response()->json(['data' => DepreciationProfile::query()->where('tenant_id', $this->tenant($request))->orderBy('kode')->get()->map($this->present(...))->values()]);
    }

    public function store(Request $request, NumberSequenceClient $numbers): JsonResponse
    {
        $this->can($request, 'create'); $tenant = $this->tenant($request); $key = (string) $request->header('Idempotency-Key');
        validator(['key' => $key], ['key' => ['required', 'string', 'max:140', 'regex:/^[A-Za-z0-9._:-]+$/']])->validate();
        $data = $this->payload($request->validate($this->rules()));
        if ($existing = DepreciationProfile::withTrashed()->where(['tenant_id' => $tenant, 'creation_key' => $key])->first()) return response()->json(['data' => $this->present($existing)], 200, ['Idempotent-Replayed' => 'true']);
        try { $kode = $numbers->issue('management-aset.profil-penyusutan', $tenant, self::RESOURCE.':'.$key); } catch (RuntimeException $e) { return response()->json(['error' => ['code' => 'number_sequence_unavailable', 'message' => $e->getMessage()]], 503); }
        $profile = DB::transaction(fn () => DepreciationProfile::query()->create(['tenant_id' => $tenant, 'creation_key' => $key, 'kode' => $kode, ...$data]));
        return response()->json(['data' => $this->present($profile)], 201);
    }

    /** @return array<string, array<int, mixed>> */
    private function rules(): array { return [
        'nama' => ['required', 'string', 'max:150'], 'method' => ['required', Rule::in(['straight_line', 'reducing_balance', 'manual', 'consumption'])],
        'frequency' => ['required', Rule::in(['monthly', 'quarterly', 'half_yearly', 'yearly'])], 'year_basis' => ['required', Rule::in(['calendar', 'fiscal'])],
        'convention' => ['nullable', 'string', 'max:40'], 'useful_life_periods' => ['nullable', 'integer', 'min:1'], 'rate_percent' => ['nullable', 'numeric', 'gt:0'],
        'manual_schedule' => ['nullable', 'array'], 'manual_schedule.*.amount' => ['required_with:manual_schedule', 'numeric', 'min:0'], 'aktif' => ['sometimes', 'boolean'],
    ]; }
    /** @param array<string,mixed> $data @return array<string,mixed> */
    private function payload(array $data): array {
        if (in_array($data['method'], ['straight_line', 'reducing_balance'], true) && empty($data['useful_life_periods'])) abort(422, 'Masa manfaat wajib diisi untuk metode ini.');
        if ($data['method'] === 'reducing_balance' && empty($data['rate_percent'])) abort(422, 'Persentase wajib diisi untuk saldo menurun.');
        if ($data['method'] === 'manual' && empty($data['manual_schedule'])) abort(422, 'Jadwal manual wajib diisi.');
        return [...$data, 'aktif' => $data['aktif'] ?? true];
    }
    private function can(Request $r, string $action): void { abort_unless(in_array('management-aset.'.self::RESOURCE.'.'.$action, $r->attributes->get('coreerp.permissions', []), true), 403); }
    private function tenant(Request $r): string { return (string) $r->attributes->get('coreerp.tenant_id'); }
    private function present(DepreciationProfile $p): array { return $p->only(['id','kode','nama','method','frequency','year_basis','convention','useful_life_periods','rate_percent','manual_schedule','aktif']); }
}
