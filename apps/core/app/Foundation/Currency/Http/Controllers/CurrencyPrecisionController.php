<?php

namespace App\Foundation\Currency\Http\Controllers;

use App\Foundation\Currency\Models\CurrencyPrecision;
use App\Foundation\Currency\Support\MoneyPrecision;
use App\Http\Controllers\Controller;
use App\Support\Modules\Contracts\RowVersion;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Presisi uang per mata uang (K-20), padanan bagian Rounding pada Currency Card Business Central.
 *
 * Hanya IDR yang dapat disetel di fase ini (K-19). Perubahan berlaku untuk posting yang terbit
 * sesudahnya; posting yang sudah terbit menyimpan nilai dengan jumlah desimal pada saat terbit.
 */
final class CurrencyPrecisionController extends Controller
{
    /**
     * Mata uang yang dapat disetel beserta nama tampilannya. Master mata uang penuh belum ada
     * (FIN-20); setiap kode di sini harus punya bawaan di `MoneyPrecision::DEFAULTS`.
     */
    private const NAMA = ['IDR' => 'Rupiah Indonesia'];

    public function index(Request $request, MoneyPrecision $presisi): Response
    {
        $tenant = $this->currentMembership($request)->tenant_id;
        $versions = CurrencyPrecision::query()->where('tenant_id', $tenant)->pluck('version', 'currency_code');

        return Inertia::render('foundation/currency/currencies', [
            'canManage' => $request->user()?->can('manage-reference-data') ?? false,
            'limits' => [
                'amount_decimals' => MoneyPrecision::MAX_AMOUNT_DECIMALS,
                'unit_amount_decimals' => MoneyPrecision::MAX_UNIT_AMOUNT_DECIMALS,
            ],
            'currencies' => array_map(
                static fn (string $kode): array => [
                    'code' => $kode,
                    'name' => self::NAMA[$kode],
                    ...$presisi->forCurrency($tenant, $kode),
                    // 0 selama mata uang ini masih memakai bawaan; lihat RowVersion::claimIfExists().
                    'version' => $versions[$kode] ?? 0,
                ],
                array_keys(self::NAMA),
            ),
        ]);
    }

    public function update(Request $request, string $currency): RedirectResponse
    {
        abort_unless($request->user()?->can('manage-reference-data'), 403);
        abort_unless(array_key_exists($currency, self::NAMA), 404);
        $tenant = $this->currentMembership($request)->tenant_id;
        $data = $request->validate([
            'amount_decimals' => ['required', 'integer', 'between:0,'.MoneyPrecision::MAX_AMOUNT_DECIMALS],
            'unit_amount_decimals' => [
                'required', 'integer', 'between:0,'.MoneyPrecision::MAX_UNIT_AMOUNT_DECIMALS,
                'gte:amount_decimals',
            ],
        ], [
            'unit_amount_decimals.gte' => 'Presisi harga satuan tidak boleh lebih kasar dari presisi nilai.',
        ]);

        $values = ['amount_decimals' => (int) $data['amount_decimals'], 'unit_amount_decimals' => (int) $data['unit_amount_decimals']];

        DB::transaction(function () use ($request, $tenant, $currency, $values): void {
            $key = ['tenant_id' => $tenant, 'currency_code' => $currency];
            RowVersion::claimIfExists(CurrencyPrecision::query()->where($key), RowVersion::expected($request));
            CurrencyPrecision::query()->updateOrCreate($key, $values);
        });

        return back()->with('status', 'Presisi '.$currency.' disimpan. Berlaku untuk posting berikutnya.');
    }
}
