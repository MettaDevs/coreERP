<?php

namespace Modules\Apperp\ManagementAset\Services;

use App\Support\Modules\Contracts\DirektoriOrganisasi;
use App\Support\Modules\Contracts\PenerbitPosting;
use App\Support\Modules\Contracts\PostingTidakSah;
use App\Support\Modules\Contracts\PresisiMataUang;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Modules\Apperp\ManagementAset\Models\master\AssetPostingGroup;
use Modules\Apperp\ManagementAset\Models\master\BukuPenyusutan;
use Modules\Apperp\ManagementAset\Models\master\GroupAset;
use Modules\Apperp\ManagementAset\Models\transaksi\InventarisasiAset\DepreciationPeriod;
use Modules\Apperp\ManagementAset\Support\OrganizationScope;
use RuntimeException;
use stdClass;

/**
 * Proses "Post penyusutan" dan jurnal pembaliknya (feed posting finance, TODO 11; K-14, K-15).
 *
 * **Satu posting untuk satu entitas legal × satu buku × satu tanggal akhir periode**, dari periode
 * asli berstatus final yang belum di-post. `posting_id`-nya tetap untuk entitas, buku, tanggal, dan
 * nomor urut prosesnya; periode yang difinalkan sesudah proses pertama ikut proses berikutnya dengan
 * nomor urut berikutnya. Periode yang sudah dibalik tidak pernah ikut: bebannya sudah ditiadakan.
 *
 * **Bentuk jurnalnya**, bertanggal akhir periode:
 *
 *     Dr beban penyusutan          per group aset dan unit penggunaan (akun laba rugi: BU + department)
 *        Cr akumulasi penyusutan   per group aset dan business unit-nya (akun neraca: BU saja)
 *
 * Diringkas per group, bukan per akun, supaya setiap baris menyebut satu kolom posting group satu
 * group — itu yang membuat akunnya dapat dibaca ulang saat posting yang tertahan divalidasi ulang
 * (keputusan pemilik produk, 24 September 2026). Rincian per aset ikut di `details.assets`.
 *
 * **Hanya buku yang mem-post perolehan aset itu** (buku yang di-post group-nya, K-26) yang mengirim
 * penyusutannya. Buku lain yang lapisannya bukan `none` tetap menyusut di register, tetapi tidak
 * dikirim: di F&O tiap lapisan posting dicatat terpisah, sedangkan feed ini belum membawa lapisan,
 * sehingga mengirim keduanya membuat beban aset yang sama tercatat dua kali. Buku `none` ditolak.
 *
 * **Total jurnal selalu sama dengan register, sampai ke sen.** Nilai periode sudah dibulatkan saat
 * diusulkan (dua desimal, atau kelipatan pembulatan buku); nilai yang lebih halus dari presisi mata
 * uang tidak dibulatkan diam-diam di sini, melainkan menahan proses dengan pesan (K-18, K-20).
 *
 * @phpstan-type HasilTerbit array{posting_id: string, status: string, problems: list<array<string, mixed>>, payload: array<string, mixed>, created: bool}
 * @phpstan-type HasilProses array{
 *     legal_entity_id: string,
 *     period_ends_on: string,
 *     book: array{id: string, kode: string, nama: string, posting_layer: string},
 *     assets: int,
 *     register_total: string,
 *     skipped: array{proposed: int, posted: int, reversed: int, other_book: int},
 *     blockers: list<array{field: string, message: string}>,
 *     posting: HasilTerbit|null,
 * }
 */
final class DepreciationPosting
{
    public const POSTING_TYPE = 'asset.depreciation';

    public const REVERSAL_TYPE = 'asset.depreciation_reversal';

    public const SOURCE_TYPE = 'penyusutan';

    public const SCREEN_URL = '/management-aset/penyusutan';

    /** Batas satu `whereIn`: PostgreSQL menerima paling banyak 65.535 parameter per perintah. */
    private const CHUNK = 1000;

    /** @var array<string, ?string> */
    private array $postedBook = [];

    /** @var array<string, ?AssetPostingGroup> */
    private array $postingGroups = [];

    public function __construct(
        private readonly PenerbitPosting $publisher,
        private readonly PresisiMataUang $precision,
        private readonly AssetPostingAccounts $accounts,
        private readonly PembuatAset $assets,
        private readonly DirektoriOrganisasi $organizations,
    ) {}

    /** `posting_id` satu proses post: entitas legal, buku, tanggal akhir periode, dan nomor urut prosesnya. */
    public static function postingId(string $legalEntityId, string $bookId, string $periodEndsOn, int $sequence): string
    {
        return sprintf('AST-DEP-%s-%s-%s-%d', $legalEntityId, $bookId, str_replace('-', '', $periodEndsOn), $sequence);
    }

    /** `posting_id` pembalikan satu periode: satu baris pembalik, satu posting. */
    public static function reversalPostingId(string $reversalPeriodId): string
    {
        return 'AST-DRV-'.$reversalPeriodId;
    }

    /**
     * Pratinjau proses post tanpa menyimpan apa pun (TODO 11.2.7, K-22): periode yang ikut, jumlah
     * aset, total register, jurnal ringkasnya, dan masalahnya — dengan pemeriksaan yang sama persis
     * dengan penerbitan.
     *
     * @return HasilProses
     */
    public function preview(Request $request, string $legalEntityId, string $bookId, string $periodEndsOn): array
    {
        $tenant = $this->tenant($request);
        $book = $this->book($bookId);
        $run = $this->collect($request, $legalEntityId, $book, $periodEndsOn, false);
        $blockers = $this->blockers($tenant, $book, $run['included']);
        $posting = null;
        if ($blockers === [] && $run['included'] !== []) {
            $input = $this->input($tenant, $legalEntityId, $book, $periodEndsOn, $this->nextSequence($legalEntityId, (string) $book->id, $periodEndsOn), $run['included']);
            $posting = $this->orFail(fn (): array => $this->publisher->pratinjau($input));
        }

        return $this->result($legalEntityId, $book, $periodEndsOn, $run, $blockers, $posting);
    }

    /**
     * Menjalankan proses post (TODO 11.2): satu posting untuk periode final yang belum di-post, lalu
     * menandai periodenya, di satu transaksi. Tidak ada periode yang ikut berarti tidak ada posting —
     * proses kedua untuk buku dan periode yang sama pulang kosong.
     *
     * Proses untuk satu buku berjalan satu per satu (kunci baris master bukunya), supaya nomor urut
     * posting tidak pernah dipakai dua proses sekaligus, termasuk dua pengguna dengan cakupan unit
     * berbeda. Periode yang ikut dikunci lalu dibaca ulang: pembalikan yang selesai lebih dulu
     * mengeluarkannya, dan pembalikan yang datang sesudahnya menunggu lalu menerbitkan jurnal baliknya.
     *
     * @return HasilProses
     *
     * @throws ValidationException Buku `none`, mata uang campuran, atau nilai lebih halus dari presisi.
     * @throws DepreciationPostingFailed
     */
    public function post(Request $request, string $legalEntityId, string $bookId, string $periodEndsOn): array
    {
        $tenant = $this->tenant($request);

        return DB::transaction(function () use ($request, $tenant, $legalEntityId, $bookId, $periodEndsOn): array {
            $book = $this->book($bookId, true);
            $run = $this->collect($request, $legalEntityId, $book, $periodEndsOn, true);
            $blockers = $this->blockers($tenant, $book, $run['included']);
            if ($blockers !== []) {
                throw ValidationException::withMessages($blockers);
            }
            if ($run['included'] === []) {
                return $this->result($legalEntityId, $book, $periodEndsOn, $run, [], null);
            }

            $input = $this->input($tenant, $legalEntityId, $book, $periodEndsOn, $this->nextSequence($legalEntityId, (string) $book->id, $periodEndsOn), $run['included']);
            $posting = $this->orFail(fn (): array => $this->publisher->terbitkan($input));
            foreach (array_chunk(array_column($run['included'], 'id'), self::CHUNK) as $ids) {
                DepreciationPeriod::query()->whereIn('id', $ids)->update(['posted_posting_id' => $posting['posting_id'], 'updated_at' => now()]);
            }

            return $this->result($legalEntityId, $book, $periodEndsOn, $run, [], $posting);
        });
    }

    /**
     * Jurnal balik satu periode yang dibalik (TODO 11.3), di dalam transaksi pembalikannya. Hanya bila
     * periode aslinya sudah di-post; bila belum, tidak ada yang perlu dibalik di buku besar dan hasilnya
     * `null`. Barisnya kebalikan porsi aset itu saja, bertanggal periode asal — sama dengan jurnal
     * asalnya dan dengan baris pembaliknya di register (keputusan pemilik produk, 24 September 2026) —
     * sehingga akun dan dimensinya terbaca sama dengan jurnal asal.
     *
     * @param  stdClass  $original  Baris periode asli: `amount`, `period_starts_on`, `period_ends_on`, `legal_entity_id`, `usage_org_unit_id`, `posted_posting_id`.
     * @param  stdClass  $asset  Buku aset beserta asetnya: `aset_code`, `group_aset_id`, `book_code`, `currency_code`.
     * @return HasilTerbit|null
     *
     * @throws ValidationException Nilainya lebih halus dari presisi mata uang yang berlaku sekarang.
     * @throws DepreciationPostingFailed
     */
    public function publishReversal(string $tenantId, stdClass $original, string $reversalPeriodId, stdClass $asset, string $reason): ?array
    {
        if (($original->posted_posting_id ?? null) === null) {
            return null;
        }

        $tanggal = self::date($original->period_ends_on);
        $mataUang = (string) $asset->currency_code;
        $desimal = $this->decimals($tenantId, $mataUang);
        $nilai = BigDecimal::of((string) $original->amount);
        if ($nilai->strippedOfTrailingZeros()->getScale() > $desimal) {
            throw ValidationException::withMessages(['id' => sprintf(
                'Penyusutan ini bernilai %s, lebih halus dari presisi %s yang berlaku sekarang (%d desimal), jadi jurnal baliknya tidak dapat disusun sama persis dengan jurnal asalnya.',
                $nilai,
                $mataUang,
                $desimal,
            )]);
        }
        $jumlah = (string) $nilai->toScale($desimal, RoundingMode::Unnecessary);

        $group = (string) $asset->group_aset_id;
        $unit = (string) $original->usage_org_unit_id;
        $grup = $this->groups([$group]);
        $namaGroup = $grup[$group]->nama ?? $group;
        $bu = $this->organizations->unitBisnisInduk($tenantId, [$unit], $tanggal)[$unit]['id'] ?? $unit;
        $label = Carbon::parse($tanggal)->format('d/m/Y');

        $input = [
            'tenant_id' => $tenantId,
            'posting_id' => self::reversalPostingId($reversalPeriodId),
            'posting_type' => self::REVERSAL_TYPE,
            'legal_entity_id' => (string) $original->legal_entity_id,
            'currency_code' => $mataUang,
            'posting_date' => $tanggal,
            'document_date' => $tanggal,
            'occurred_at' => now()->toIso8601String(),
            'reverses_posting_id' => (string) $original->posted_posting_id,
            'source_document' => [
                'module' => AcquisitionPosting::MODULE,
                'type' => self::SOURCE_TYPE,
                'number' => null,
                'description' => mb_substr(sprintf('Pembalikan penyusutan %s s.d. %s: %s', $asset->aset_code, $label, $reason), 0, 255),
                'id' => $reversalPeriodId,
                'url' => self::SCREEN_URL,
            ],
            'lines' => [
                $this->line($group, $bu, 'accumulated_depreciation_account_id', $jumlah, '0', sprintf('Pembalikan akumulasi penyusutan s.d. %s · %s', $label, $namaGroup), $tanggal, $grup),
                $this->line($group, $unit, 'depreciation_expense_account_id', '0', $jumlah, sprintf('Pembalikan beban penyusutan s.d. %s · %s', $label, $namaGroup), $tanggal, $grup),
            ],
            'details' => ['assets' => [[
                'asset_code' => (string) $asset->aset_code,
                'asset_group' => $grup[$group]->kode ?? null,
                'book' => (string) $asset->book_code,
                'period_starts_on' => self::date($original->period_starts_on),
                'period_ends_on' => $tanggal,
                'depreciation_amount' => $jumlah,
            ]]],
        ];

        return $this->orFail(fn (): array => $this->publisher->terbitkan($input));
    }

    /**
     * Periode kandidat satu proses post, dipilah: yang ikut, dan hitungan yang tidak ikut beserta
     * alasannya. Cakupan unit pengguna berlaku; nomor urut proses tidak (lihat `nextSequence()`).
     *
     * @return array{included: list<stdClass>, skipped: array{proposed: int, posted: int, reversed: int, other_book: int}}
     */
    private function collect(Request $request, string $legalEntityId, stdClass $book, string $periodEndsOn, bool $lock): array
    {
        $rows = $this->candidates($request, $legalEntityId, (string) $book->id, $periodEndsOn);
        $locked = null;
        if ($lock) {
            $ids = [];
            foreach ($rows as $row) {
                if ($this->eligible($row, $book) === null) {
                    $ids[] = (string) $row->id;
                }
            }
            sort($ids);
            $locked = [];
            foreach (array_chunk($ids, self::CHUNK) as $potongan) {
                foreach (DepreciationPeriod::query()->whereIn('id', $potongan)->orderBy('id')->lockForUpdate()->toBase()->pluck('id') as $id) {
                    $locked[(string) $id] = true;
                }
            }
            // Dibaca ulang sesudah kunci didapat: perintah baru melihat pembalikan dan proses post lain
            // yang sudah selesai selama menunggu kunci.
            $rows = $this->candidates($request, $legalEntityId, (string) $book->id, $periodEndsOn);
        }

        $included = [];
        $skipped = ['proposed' => 0, 'posted' => 0, 'reversed' => 0, 'other_book' => 0];
        foreach ($rows as $row) {
            $alasan = $this->eligible($row, $book);
            if ($alasan !== null) {
                $skipped[$alasan]++;

                continue;
            }
            // Difinalkan sesudah kunci diambil: ikut proses berikutnya, bukan proses yang tidak menguncinya.
            if ($locked !== null && ! isset($locked[(string) $row->id])) {
                continue;
            }
            $included[] = $row;
        }

        return ['included' => $included, 'skipped' => $skipped];
    }

    /**
     * Alasan satu periode tidak ikut, atau `null` bila ikut.
     *
     * @return 'proposed'|'posted'|'reversed'|'other_book'|null
     */
    private function eligible(stdClass $row, stdClass $book): ?string
    {
        return match (true) {
            $row->status !== 'final' => 'proposed',
            (bool) $row->reversed => 'reversed',
            $row->posted_posting_id !== null => 'posted',
            $this->postedBook((string) $row->group_aset_id) !== (string) $book->id => 'other_book',
            default => null,
        };
    }

    /** @return list<stdClass> */
    private function candidates(Request $request, string $legalEntityId, string $bookId, string $periodEndsOn): array
    {
        $query = DepreciationPeriod::query()
            ->join('aset_tr_buku_aset as book', function ($join): void {
                $join->on('book.id', '=', 'aset_tr_penyusutan_aset.buku_aset_id')->on('book.tenant_id', '=', 'aset_tr_penyusutan_aset.tenant_id');
            })
            ->join('aset_tr_aset as aset', function ($join): void {
                $join->on('aset.id', '=', 'book.aset_id')->on('aset.tenant_id', '=', 'book.tenant_id');
            })
            ->where('aset_tr_penyusutan_aset.legal_entity_id', $legalEntityId)
            ->where('aset_tr_penyusutan_aset.period_ends_on', $periodEndsOn)
            ->whereNull('aset_tr_penyusutan_aset.reverses_period_id')
            ->where('book.buku_id', $bookId);
        app(OrganizationScope::class)->query($query, $request, 'aset_tr_penyusutan_aset.legal_entity_id', 'aset_tr_penyusutan_aset.usage_org_unit_id');

        return array_values($query->select([
            'aset_tr_penyusutan_aset.id',
            'aset_tr_penyusutan_aset.status',
            'aset_tr_penyusutan_aset.amount',
            'aset_tr_penyusutan_aset.usage_org_unit_id',
            'aset_tr_penyusutan_aset.posted_posting_id',
            'aset_tr_penyusutan_aset.period_starts_on',
            'aset.kode as aset_code',
            'aset.group_aset_id',
            'aset.currency_code',
            'book.book_code',
            DB::raw('exists (select 1 from aset_tr_penyusutan_aset as pembalik where pembalik.tenant_id = aset_tr_penyusutan_aset.tenant_id and pembalik.reverses_period_id = aset_tr_penyusutan_aset.id) as reversed'),
        ])->orderBy('aset.kode')->orderBy('aset_tr_penyusutan_aset.id')->toBase()->get()->all());
    }

    /**
     * Yang menahan proses post, berkunci field. Masalah pemetaan akun tidak di sini: posting itu tetap
     * terbit sebagai `held` dan periodenya tetap ditandai (K-18).
     *
     * @param  list<stdClass>  $included
     * @return array<string, string>
     */
    private function blockers(string $tenantId, stdClass $book, array $included): array
    {
        if ($book->posting_layer === BukuPenyusutan::POSTING_LAYER_NONE) {
            return ['buku_id' => sprintf(
                'Buku %s tidak di-post ke aplikasi finance karena lapisan posting-nya none. Penyusutannya tetap tercatat di register aset.',
                $book->kode,
            )];
        }

        $mataUang = array_values(array_unique(array_map(static fn (stdClass $row): string => (string) $row->currency_code, $included)));
        if (count($mataUang) > 1) {
            return ['legal_entity_id' => sprintf(
                'Penyusutan yang akan di-post memakai lebih dari satu mata uang (%s). Satu posting hanya untuk satu mata uang.',
                implode(', ', $mataUang),
            )];
        }
        if ($mataUang === []) {
            return [];
        }

        try {
            $desimal = $this->precision->nilai($tenantId, $mataUang[0]);
        } catch (RuntimeException $kegagalan) {
            return ['legal_entity_id' => $kegagalan->getMessage()];
        }
        $halus = count(array_filter(
            $included,
            static fn (stdClass $row): bool => BigDecimal::of((string) $row->amount)->strippedOfTrailingZeros()->getScale() > $desimal,
        ));
        if ($halus > 0) {
            return ['buku_id' => sprintf(
                'Penyusutan %d aset di buku %s lebih halus dari presisi %s (%d desimal), jadi jurnalnya tidak akan sama persis dengan register. Atur pembulatan penyusutan di matriks group x buku (Master data › Group aset) supaya penyusutan berikutnya sesuai presisi.',
                $halus,
                $book->kode,
                $mataUang[0],
                $desimal,
            )];
        }

        return [];
    }

    /**
     * Masukan `PenerbitPosting` satu proses post.
     *
     * @param  list<stdClass>  $rows
     * @return array<string, mixed>
     */
    private function input(string $tenantId, string $legalEntityId, stdClass $book, string $periodEndsOn, int $sequence, array $rows): array
    {
        $mataUang = (string) $rows[0]->currency_code;
        $desimal = $this->decimals($tenantId, $mataUang);
        $grup = $this->groups(array_map(static fn (stdClass $row): string => (string) $row->group_aset_id, $rows));
        $unit = array_values(array_unique(array_map(static fn (stdClass $row): string => (string) $row->usage_org_unit_id, $rows)));
        $bisnis = $this->organizations->unitBisnisInduk($tenantId, $unit, $periodEndsOn);

        $beban = [];
        $akumulasi = [];
        foreach ($rows as $row) {
            $group = (string) $row->group_aset_id;
            $pengguna = (string) $row->usage_org_unit_id;
            // Business unit yang tidak dapat diturunkan: barisnya menyebut unit penggunanya, dan Core
            // menahan posting dengan masalah yang menyebut unit itu.
            $bu = $bisnis[$pengguna]['id'] ?? $pengguna;

            $kunci = $group.'|'.$pengguna;
            $beban[$kunci] ??= ['group' => $group, 'unit' => $pengguna, 'amount' => BigDecimal::zero()];
            $beban[$kunci]['amount'] = $beban[$kunci]['amount']->plus((string) $row->amount);

            $kunci = $group.'|'.$bu;
            $akumulasi[$kunci] ??= ['group' => $group, 'unit' => $bu, 'amount' => BigDecimal::zero()];
            $akumulasi[$kunci]['amount'] = $akumulasi[$kunci]['amount']->plus((string) $row->amount);
        }
        // Urutan baris tetap untuk masukan yang sama: posting yang diterbitkan ulang dibandingkan isinya.
        $urut = static fn (array $a, array $b): int => [$grup[$a['group']]->kode ?? $a['group'], $a['unit']] <=> [$grup[$b['group']]->kode ?? $b['group'], $b['unit']];
        uasort($beban, $urut);
        uasort($akumulasi, $urut);

        $label = Carbon::parse($periodEndsOn)->format('d/m/Y');
        $baris = [];
        foreach ($beban as $bagian) {
            $baris[] = $this->line($bagian['group'], $bagian['unit'], 'depreciation_expense_account_id', (string) $bagian['amount']->toScale($desimal, RoundingMode::Unnecessary), '0', sprintf('Beban penyusutan s.d. %s · %s', $label, $grup[$bagian['group']]->nama ?? $bagian['group']), $periodEndsOn, $grup);
        }
        foreach ($akumulasi as $bagian) {
            $baris[] = $this->line($bagian['group'], $bagian['unit'], 'accumulated_depreciation_account_id', '0', (string) $bagian['amount']->toScale($desimal, RoundingMode::Unnecessary), sprintf('Akumulasi penyusutan s.d. %s · %s', $label, $grup[$bagian['group']]->nama ?? $bagian['group']), $periodEndsOn, $grup);
        }

        return [
            'tenant_id' => $tenantId,
            'posting_id' => self::postingId($legalEntityId, (string) $book->id, $periodEndsOn, $sequence),
            'posting_type' => self::POSTING_TYPE,
            'legal_entity_id' => $legalEntityId,
            'currency_code' => $mataUang,
            'posting_date' => $periodEndsOn,
            'document_date' => $periodEndsOn,
            'occurred_at' => now()->toIso8601String(),
            'source_document' => [
                'module' => AcquisitionPosting::MODULE,
                'type' => self::SOURCE_TYPE,
                'number' => null,
                'description' => sprintf('Penyusutan buku %s s.d. %s', $book->kode, $label),
                'id' => null,
                'url' => self::SCREEN_URL,
            ],
            'lines' => $baris,
            'details' => ['assets' => array_map(static fn (stdClass $row): array => [
                'asset_code' => (string) $row->aset_code,
                'asset_group' => $grup[(string) $row->group_aset_id]->kode ?? null,
                'book' => (string) $row->book_code,
                'period_starts_on' => self::date($row->period_starts_on),
                'period_ends_on' => $periodEndsOn,
                'depreciation_amount' => (string) BigDecimal::of((string) $row->amount)->toScale($desimal, RoundingMode::Unnecessary),
            ], $rows)],
        ];
    }

    /**
     * Satu baris jurnal yang menyebut satu kolom posting group satu group, sehingga akunnya dapat dibaca
     * ulang saat posting yang tertahan divalidasi ulang (`mapping.reference`).
     *
     * @param  array<string, stdClass>  $groups
     * @return array<string, mixed>
     */
    private function line(string $groupId, string $orgUnitId, string $column, string $debit, string $credit, string $description, string $postingDate, array $groups): array
    {
        $akun = $this->postingGroup($groupId, $postingDate)?->getAttribute($column);

        return [
            'account_id' => is_string($akun) ? $akun : null,
            'debit' => $debit,
            'credit' => $credit,
            'description' => mb_substr($description, 0, 255),
            'org_unit_id' => $orgUnitId,
            'mapping' => [
                'label' => sprintf('Group %s · %s', $groups[$groupId]->kode ?? $groupId, lcfirst(AssetPostingGroup::ACCOUNTS[$column])),
                'fix_url' => AcquisitionPosting::POSTING_GROUP_URL,
                'reference' => PostingGroupAccountResolver::reference($groupId, $column),
            ],
        ];
    }

    /**
     * Nomor urut proses berikutnya untuk entitas, buku, dan tanggal akhir ini: satu lebih dari jumlah
     * posting yang sudah menandai periodenya. Dihitung atas seluruh periode, tanpa cakupan unit
     * pengguna, supaya dua pengguna dengan cakupan berbeda tidak memakai nomor yang sama.
     */
    private function nextSequence(string $legalEntityId, string $bookId, string $periodEndsOn): int
    {
        return 1 + DepreciationPeriod::query()
            ->join('aset_tr_buku_aset as book', function ($join): void {
                $join->on('book.id', '=', 'aset_tr_penyusutan_aset.buku_aset_id')->on('book.tenant_id', '=', 'aset_tr_penyusutan_aset.tenant_id');
            })
            ->where('aset_tr_penyusutan_aset.legal_entity_id', $legalEntityId)
            ->where('aset_tr_penyusutan_aset.period_ends_on', $periodEndsOn)
            ->whereNull('aset_tr_penyusutan_aset.reverses_period_id')
            ->whereNotNull('aset_tr_penyusutan_aset.posted_posting_id')
            ->where('book.buku_id', $bookId)
            ->distinct()
            ->count('aset_tr_penyusutan_aset.posted_posting_id');
    }

    /**
     * Master buku penyusutan, termasuk yang sudah diarsipkan: periodenya tetap boleh di-post. Dengan
     * `$lock`, baris master itu dikunci sebagai antrean proses post buku tersebut.
     */
    private function book(string $bookId, bool $lock = false): stdClass
    {
        $query = BukuPenyusutan::withTrashed()->whereKey($bookId);
        if ($lock) {
            $query->lockForUpdate();
        }
        $book = $query->toBase()->first(['id', 'kode', 'nama', 'posting_layer']);
        if ($book === null) {
            throw ValidationException::withMessages(['buku_id' => 'Buku penyusutan ini tidak ditemukan.']);
        }

        return $book;
    }

    /**
     * Group yang disebut, termasuk yang sudah diarsipkan: periode lama tetap menyebut kodenya.
     *
     * @param  list<string>  $groupIds
     * @return array<string, stdClass>
     */
    private function groups(array $groupIds): array
    {
        $hasil = [];
        foreach (array_chunk(array_values(array_unique($groupIds)), self::CHUNK) as $ids) {
            foreach (GroupAset::withTrashed()->whereIn('id', $ids)->toBase()->get(['id', 'kode', 'nama']) as $grup) {
                $hasil[(string) $grup->id] = $grup;
            }
        }

        return $hasil;
    }

    private function postedBook(string $groupId): ?string
    {
        if (! array_key_exists($groupId, $this->postedBook)) {
            $this->postedBook[$groupId] = $this->assets->bukuDiPostId($groupId);
        }

        return $this->postedBook[$groupId];
    }

    private function postingGroup(string $groupId, string $postingDate): ?AssetPostingGroup
    {
        $kunci = $groupId.'|'.$postingDate;
        if (! array_key_exists($kunci, $this->postingGroups)) {
            $this->postingGroups[$kunci] = $this->accounts->effective($groupId, $postingDate);
        }

        return $this->postingGroups[$kunci];
    }

    /** @return int<0, max> */
    private function decimals(string $tenantId, string $currency): int
    {
        try {
            $desimal = $this->precision->nilai($tenantId, $currency);
        } catch (RuntimeException $kegagalan) {
            throw ValidationException::withMessages(['legal_entity_id' => $kegagalan->getMessage()]);
        }
        // Pola yang sama dengan `MoneyPrecision::round()` di Core.
        if ($desimal < 0) {
            throw new InvalidArgumentException('Jumlah desimal tidak boleh negatif.');
        }

        return $desimal;
    }

    /**
     * @param  array{included: list<stdClass>, skipped: array{proposed: int, posted: int, reversed: int, other_book: int}}  $run
     * @param  array<string, string>  $blockers
     * @param  HasilTerbit|null  $posting
     * @return HasilProses
     */
    private function result(string $legalEntityId, stdClass $book, string $periodEndsOn, array $run, array $blockers, ?array $posting): array
    {
        $total = BigDecimal::zero();
        foreach ($run['included'] as $row) {
            $total = $total->plus((string) $row->amount);
        }

        return [
            'legal_entity_id' => $legalEntityId,
            'period_ends_on' => $periodEndsOn,
            'book' => ['id' => (string) $book->id, 'kode' => (string) $book->kode, 'nama' => (string) $book->nama, 'posting_layer' => (string) $book->posting_layer],
            'assets' => count($run['included']),
            'register_total' => (string) $total->toScale(2, RoundingMode::Unnecessary),
            'skipped' => $run['skipped'],
            'blockers' => array_map(
                static fn (string $field, string $message): array => ['field' => $field, 'message' => $message],
                array_keys($blockers),
                array_values($blockers),
            ),
            'posting' => $posting,
        ];
    }

    /**
     * `PostingTidakSah` adalah bug penerbit, bukan keadaan yang diserahkan ke pengguna (K-22). Ia tetap
     * dilaporkan ke pemantauan kesalahan, lalu diterjemahkan menjadi kegagalan yang dapat dibaca orang;
     * pemanggilnya membatalkan transaksinya.
     *
     * @param  callable(): HasilTerbit  $aksi
     * @return HasilTerbit
     */
    private function orFail(callable $aksi): array
    {
        try {
            return $aksi();
        } catch (PostingTidakSah $kegagalan) {
            report($kegagalan);

            throw new DepreciationPostingFailed($kegagalan);
        }
    }

    private function tenant(Request $request): string
    {
        return (string) $request->attributes->get('coreerp.tenant_id');
    }

    private static function date(mixed $value): string
    {
        return substr((string) $value, 0, 10);
    }
}
