<?php

declare(strict_types=1);

namespace Modules\Apperp\ManagementAset\Reporting\Definitions;

use App\Platform\Modules\Contracts\AccountDirectory;
use App\Platform\Modules\Contracts\PostingFeed;
use Brick\Math\BigDecimal;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use Modules\Apperp\ManagementAset\Models\master\AssetPostingGroup;
use Modules\Apperp\ManagementAset\Models\master\GroupAset;
use Modules\Apperp\ManagementAset\Models\transaksi\InventarisasiAset\Aset;
use Modules\Apperp\ManagementAset\Models\transaksi\InventarisasiAset\DepreciationPeriod;
use Modules\Apperp\ManagementAset\Models\transaksi\Reclassification\AssetReclassificationBook;
use Modules\Apperp\ManagementAset\Models\transaksi\ValueAdjustment\AssetValueAdjustment;
use Modules\Apperp\ManagementAset\Models\transaksi\ValueAdjustment\AssetValueAdjustmentLine;
use Modules\Apperp\ManagementAset\Reporting\AdditionalFilters;
use Modules\Apperp\ManagementAset\Reporting\AssetReportFilters;
use Modules\Apperp\ManagementAset\Reporting\Layouts\BuiltinLayout;
use Modules\Apperp\ManagementAset\Reporting\ReportContext;
use Modules\Apperp\ManagementAset\Reporting\ReportData;
use Modules\Apperp\ManagementAset\Reporting\ReportDataItem;
use Modules\Apperp\ManagementAset\Reporting\ReportDefinition;
use Modules\Apperp\ManagementAset\Services\AcquisitionPosting;
use Modules\Apperp\ManagementAset\Services\AssetPostingAccounts;
use Modules\Apperp\ManagementAset\Services\DisposalPosting;
use Modules\Apperp\ManagementAset\Services\PembuatAset;
use Modules\Apperp\ManagementAset\Support\OrganizationScope;

/**
 * Rekonsiliasi aset ke buku besar per group aset dan akun neraca, pada satu tanggal: saldo menurut register
 * aset dibandingkan dengan yang sudah diterbitkan module ini ke feed posting finance, dipecah menurut keadaan
 * posting di feed. Padanan *Fixed Asset - G/L Analysis* Business Central dan saldo aset tetap per posting
 * profile F&O.
 *
 * **Batasnya.** Module ini tidak membaca buku besar milik aplikasi finance — itu sistem lain. Yang dapat ia
 * pastikan hanya bagian rantainya sendiri: setiap mutasi register (perolehan, penyusutan, penurunan dan
 * kenaikan nilai, reklasifikasi antar group, pelepasan) dibawa posting mana, dan posting itu sekarang dalam
 * keadaan apa — sudah dibukukan aplikasi finance, dicatat manual, menunggu diambil, tertahan, ditolak, atau
 * belum terbit sama sekali. Saldo buku besar sendiri dibandingkan di aplikasi finance dengan kolom "sudah
 * dibukukan" dan "dicatat manual" laporan ini.
 *
 * Seperti BC, satu baris per akun posting group: harga perolehan, akumulasi penyusutan, akumulasi penurunan
 * nilai, dan kenaikan nilai, masing-masing dengan saldo alaminya (harga perolehan dan kenaikan nilai di debit,
 * akumulasi di kredit). Hanya buku yang di-post ke finance (K-26) yang dibaca. Mutasi masuk ke group aset
 * pada tanggal mutasinya: aset yang pindah group membawa saldonya lewat reklasifikasi. Koreksi nilai
 * perolehan dihitung bersama perolehannya, mengikuti keadaan jurnal perolehan. Group yang tidak punya buku
 * yang di-post ke finance tidak ikut.
 */
final class AssetLedgerReconciliationReport implements ReportDefinition
{
    /** Komponen saldo dan kolom akun posting group-nya. */
    private const ACCOUNTS = [
        'acq' => 'acquisition_account_id',
        'acm' => 'accumulated_depreciation_account_id',
        'wd' => 'write_down_account_id',
        'ap' => 'appreciation_account_id',
    ];

    /** Keadaan posting di feed ke kolom laporan; `null` berarti belum terbit. */
    private const BUCKETS = [
        'posted' => 'sudah_dibukukan',
        'manual' => 'dicatat_manual',
        'pending' => 'menunggu',
        'held' => 'tertahan',
        'rejected' => 'ditolak',
    ];

    private const UNPUBLISHED = 'belum_diterbitkan';

    private const MONEY = ['saldo_register', 'sudah_dibukukan', 'dicatat_manual', 'menunggu', 'tertahan', 'ditolak', 'belum_diterbitkan', 'selisih'];

    public function code(): string
    {
        return 'laporan-rekonsiliasi-aset-buku-besar';
    }

    public function name(): string
    {
        return 'Rekonsiliasi aset ke buku besar';
    }

    public function description(): string
    {
        return 'Saldo aset per group dan akun menurut register, dibandingkan dengan jurnal yang sudah dikirim ke aplikasi finance beserta keadaannya.';
    }

    public function permission(): string
    {
        return 'management-aset.penyusutan.read';
    }

    public function builtinLayouts(): array
    {
        return [
            new BuiltinLayout('standar', 'Rekonsiliasi aset ke buku besar standar (Excel)', 'Satu baris per group aset dan akun: saldo register, yang sudah dibukukan, yang masih menunggu atau tertahan, dan selisihnya.', 'xlsx'),
        ];
    }

    public function parameterRules(): array
    {
        return [
            ...AssetReportFilters::rules(),
            'per_tanggal' => ['nullable', 'date_format:Y-m-d'],
        ];
    }

    /** Aset yang mutasinya dihitung. */
    public function dataItems(): array
    {
        return [
            new ReportDataItem('aset', 'Aset', Aset::class, 'aset_tr_aset', ['kode']),
        ];
    }

    public function fields(): array
    {
        $fields = [
            ...AssetReportFilters::fields(),
            ['key' => 'per_tanggal', 'label' => 'Per tanggal', 'table' => null, 'type' => 'date'],
            ['key' => 'jumlah_baris', 'label' => 'Jumlah baris', 'table' => null],
            ['key' => 'dicetak_pada', 'label' => 'Tanggal cetak', 'table' => null, 'type' => 'datetime'],
            ['key' => 'baris.nomor', 'label' => 'No.', 'table' => 'baris'],
            ['key' => 'baris.kode_group', 'label' => 'Kode group aset', 'table' => 'baris'],
            ['key' => 'baris.group', 'label' => 'Group aset', 'table' => 'baris'],
            ['key' => 'baris.akun', 'label' => 'Akun posting group', 'table' => 'baris'],
            ['key' => 'baris.kode_akun', 'label' => 'Nomor akun', 'table' => 'baris'],
            ['key' => 'baris.nama_akun', 'label' => 'Nama akun', 'table' => 'baris'],
            ['key' => 'baris.sisi', 'label' => 'Saldo normal', 'table' => 'baris'],
        ];
        foreach (self::MONEY as $key) {
            $fields[] = ['key' => 'baris.'.$key, 'label' => $this->label($key), 'table' => 'baris', 'type' => 'money'];
        }

        return $fields;
    }

    public function data(ReportContext $context, array $parameters): ReportData
    {
        $perTanggal = is_string($parameters['per_tanggal'] ?? null) && $parameters['per_tanggal'] !== '' ? $parameters['per_tanggal'] : $context->now()->toDateString();
        $aset = $this->assets($context, $parameters);
        $bukuDiPost = $this->postedBooks();

        /** @var array<string, array<string, array<string, BigDecimal>>> $saldo group => komponen => kolom => nilai */
        $saldo = [];
        $keadaan = [];
        $tambah = function (string $group, ?string $buku, ?string $postingId, array $nilai) use (&$saldo, &$keadaan, $bukuDiPost, $context): void {
            // Hanya buku yang di-post ke finance pada group itu.
            if ($buku === null || ($bukuDiPost[$group] ?? null) !== $buku) {
                return;
            }
            $kolom = $this->bucket($context->tenantId, $postingId, $keadaan);
            foreach ($nilai as $komponen => $jumlah) {
                $jumlah = BigDecimal::of((string) $jumlah);
                if ($jumlah->isZero()) {
                    continue;
                }
                $saldo[$group][$komponen][$kolom] = ($saldo[$group][$komponen][$kolom] ?? BigDecimal::zero())->plus($jumlah);
            }
        };

        foreach ($this->acquisitions($aset, $perTanggal) as $row) {
            $postingId = $row->penerimaan_aset_id === null ? null : AcquisitionPosting::postingId((string) $row->penerimaan_aset_id, (string) $row->cara_perolehan);
            $dasar = BigDecimal::of((string) $row->acquisition_value)->minus((string) $row->masuk)->plus((string) $row->keluar);
            $tambah((string) $row->grp, (string) $row->buku_id, $postingId, ['acq' => $dasar, 'acm' => $row->opening_accumulated_depreciation]);
        }
        foreach ($this->depreciation($aset, $perTanggal) as $row) {
            $tambah((string) $row->grp, (string) $row->buku_id, $row->posted_posting_id, ['acm' => $row->jumlah]);
        }
        foreach ($this->valueAdjustments($aset, $perTanggal) as $row) {
            $tambah((string) $row->grp, (string) $row->buku_id, $row->posting_id, [$row->jenis === AssetValueAdjustment::WRITE_DOWN ? 'wd' : 'ap' => $row->jumlah]);
        }
        foreach (['keluar' => 'asal', 'masuk' => 'tujuan'] as $arah => $sisi) {
            foreach ($this->reclassifications($aset, $perTanggal, $sisi) as $row) {
                $tanda = $arah === 'keluar' ? -1 : 1;
                $tambah((string) $row->grp, (string) $row->buku_id, $row->dijurnal ? $row->posting_id : null, [
                    'acq' => BigDecimal::of((string) $row->acq)->multipliedBy($tanda),
                    'acm' => BigDecimal::of((string) $row->acm)->multipliedBy($tanda),
                    'wd' => BigDecimal::of((string) $row->wd)->multipliedBy($tanda),
                    'ap' => BigDecimal::of((string) $row->ap)->multipliedBy($tanda),
                ]);
            }
        }
        foreach ($this->disposals($aset, $perTanggal) as $row) {
            $tambah((string) $row->group_aset_id, (string) $row->buku_id, DisposalPosting::postingId((string) $row->id), [
                'acq' => BigDecimal::of((string) $row->acquisition_value)->negated(),
                'acm' => BigDecimal::of((string) $row->accumulated_depreciation)->negated(),
                'wd' => BigDecimal::of((string) $row->write_down_amount)->negated(),
                'ap' => BigDecimal::of((string) $row->appreciation_amount)->negated(),
            ]);
        }

        $lines = $this->lines($context->tenantId, $saldo, $perTanggal);

        return new ReportData(
            fields: [
                ...AssetReportFilters::names($parameters),
                'per_tanggal' => $perTanggal,
                'jumlah_baris' => count($lines),
                'dicetak_pada' => now('UTC')->toIso8601ZuluString(),
            ],
            tables: ['baris' => $lines],
            fileName: 'rekonsiliasi-aset-buku-besar-'.$perTanggal,
        );
    }

    /**
     * Baris laporan dari saldo yang terkumpul, urut kode group lalu urutan akun posting group.
     *
     * @param  array<string, array<string, array<string, BigDecimal>>>  $saldo
     * @return list<array<string, mixed>>
     */
    private function lines(string $tenantId, array $saldo, string $perTanggal): array
    {
        $grup = GroupAset::withTrashed()->whereIn('id', array_keys($saldo))->toBase()->get(['id', 'kode', 'nama'])->keyBy('id');
        uksort($saldo, static fn (string $a, string $b): int => strcmp((string) ($grup[$a]->kode ?? $a), (string) ($grup[$b]->kode ?? $b)));
        $postingGroup = [];
        foreach (array_keys($saldo) as $groupId) {
            $postingGroup[$groupId] = app(AssetPostingAccounts::class)->effective($groupId, $perTanggal);
        }
        $akunIds = [];
        foreach ($postingGroup as $baris) {
            foreach (self::ACCOUNTS as $kolom) {
                $id = $baris?->getAttribute($kolom);
                if (is_string($id)) {
                    $akunIds[] = $id;
                }
            }
        }
        $akun = $akunIds === [] ? [] : app(AccountDirectory::class)->findMany($tenantId, array_values(array_unique($akunIds)));

        $lines = [];
        foreach ($saldo as $groupId => $komponen) {
            foreach (self::ACCOUNTS as $kunci => $kolom) {
                if (! isset($komponen[$kunci])) {
                    continue;
                }
                $jumlah = [];
                foreach ([...array_values(self::BUCKETS), self::UNPUBLISHED] as $bucket) {
                    $jumlah[$bucket] = $komponen[$kunci][$bucket] ?? BigDecimal::zero();
                }
                $register = array_reduce($jumlah, static fn (BigDecimal $total, BigDecimal $nilai): BigDecimal => $total->plus($nilai), BigDecimal::zero());
                $akunId = $postingGroup[$groupId]?->getAttribute($kolom);
                $lines[] = [
                    'nomor' => count($lines) + 1,
                    'kode_group' => $grup[$groupId]->kode ?? '—',
                    'group' => $grup[$groupId]->nama ?? '—',
                    'akun' => AssetPostingGroup::ACCOUNTS[$kolom],
                    'kode_akun' => is_string($akunId) ? ($akun[$akunId]['code'] ?? '—') : 'Belum dipetakan',
                    'nama_akun' => is_string($akunId) ? ($akun[$akunId]['name'] ?? '—') : '—',
                    'sisi' => in_array($kunci, ['acq', 'ap'], true) ? 'Debit' : 'Kredit',
                    'saldo_register' => (string) $register,
                    ...array_map(static fn (BigDecimal $nilai): string => (string) $nilai, $jumlah),
                    'selisih' => (string) $register->minus($jumlah['sudah_dibukukan'])->minus($jumlah['dicatat_manual']),
                ];
            }
        }

        return $lines;
    }

    /**
     * Kolom laporan untuk keadaan satu posting di feed. Keadaan dibaca sekali per posting per laporan.
     *
     * @param  array<string, string>  $cache
     */
    private function bucket(string $tenantId, ?string $postingId, array &$cache): string
    {
        if ($postingId === null) {
            return self::UNPUBLISHED;
        }
        if (! array_key_exists($postingId, $cache)) {
            $status = app(PostingFeed::class)->status($tenantId, $postingId)['status'] ?? null;
            $cache[$postingId] = self::BUCKETS[$status] ?? self::UNPUBLISHED;
        }

        return $cache[$postingId];
    }

    /**
     * Id aset yang ikut, sebagai subquery: dalam jangkauan organisasi pengguna, tersaring filter laporan.
     *
     * @param  array<string, mixed>  $parameters
     * @return Builder<Aset>
     */
    private function assets(ReportContext $context, array $parameters): Builder
    {
        $query = Aset::query();
        app(OrganizationScope::class)->asetQuery($query, $context->request());
        AssetReportFilters::apply($query, $parameters, 'aset_tr_aset');
        foreach ($this->dataItems() as $item) {
            AdditionalFilters::apply($query, $item, $parameters, $context);
        }

        return $query->select('aset_tr_aset.id');
    }

    /** @return array<string, string> Buku yang di-post ke finance per group aset. */
    private function postedBooks(): array
    {
        $buku = [];
        foreach (GroupAset::withTrashed()->pluck('id') as $groupId) {
            $id = app(PembuatAset::class)->bukuDiPostId((string) $groupId);
            if ($id !== null) {
                $buku[(string) $groupId] = $id;
            }
        }

        return $buku;
    }

    /**
     * Group aset sebuah aset pada satu tanggal: group asal reklasifikasi pindah group pertama pada atau sesudah
     * tanggal itu, atau group sekarang bila tidak ada. Pada tanggal reklasifikasi sendiri aset masih di group
     * asal: penyusutan sampai tanggal itu harus sudah final sebelum reklasifikasi diposting, dan reklasifikasi
     * itulah yang membawa saldonya ke group baru.
     */
    private function groupAt(string $dateExpression): Expression
    {
        return DB::raw("coalesce((select r.group_aset_asal_id from aset_tr_reklasifikasi_aset_buku r where r.tenant_id = aset_tr_aset.tenant_id and r.aset_asal_id = aset_tr_aset.id and r.jenis = 'pindah_group' and r.tanggal >= {$dateExpression} order by r.tanggal, r.created_at limit 1), aset_tr_aset.group_aset_id) as grp");
    }

    /**
     * Perolehan per buku aset: harga perolehan buku, yang masuk dan keluar lewat pecah aset, dan akumulasi saldo
     * awal aset lama, dengan penerimaannya.
     *
     * @param  Builder<Aset>  $aset
     * @return iterable<\stdClass>
     */
    private function acquisitions(Builder $aset, string $perTanggal): iterable
    {
        $pecah = fn (string $kolom) => AssetReclassificationBook::query()
            ->selectRaw('coalesce(sum(nilai_perolehan), 0)')
            ->whereColumn('aset_tr_reklasifikasi_aset_buku.'.$kolom, 'buku.id')
            ->whereColumn('aset_tr_reklasifikasi_aset_buku.buku_aset_asal_id', '!=', 'aset_tr_reklasifikasi_aset_buku.buku_aset_tujuan_id');

        return Aset::query()
            ->join('aset_tr_buku_aset as buku', fn (JoinClause $join) => $join->on('buku.aset_id', '=', 'aset_tr_aset.id')->on('buku.tenant_id', '=', 'aset_tr_aset.tenant_id'))
            ->leftJoin('aset_tr_penerimaan_aset as penerimaan', fn (JoinClause $join) => $join->on('penerimaan.id', '=', 'aset_tr_aset.penerimaan_aset_id')->on('penerimaan.tenant_id', '=', 'aset_tr_aset.tenant_id'))
            ->whereIn('aset_tr_aset.id', $aset)
            ->where('aset_tr_aset.acquired_on', '<=', $perTanggal)
            ->select(['buku.buku_id', 'buku.acquisition_value', 'buku.opening_accumulated_depreciation', 'aset_tr_aset.penerimaan_aset_id', 'penerimaan.cara_perolehan', $this->groupAt('aset_tr_aset.acquired_on')])
            ->selectSub($pecah('buku_aset_tujuan_id'), 'masuk')
            ->selectSub($pecah('buku_aset_asal_id'), 'keluar')
            ->toBase()
            ->get();
    }

    /**
     * Penyusutan final sampai tanggal laporan per group, buku, dan posting "Post penyusutan" yang membawanya.
     *
     * @param  Builder<Aset>  $aset
     * @return iterable<\stdClass>
     */
    private function depreciation(Builder $aset, string $perTanggal): iterable
    {
        return DepreciationPeriod::query()
            ->join('aset_tr_buku_aset as buku', fn (JoinClause $join) => $join->on('buku.id', '=', 'aset_tr_penyusutan_aset.buku_aset_id')->on('buku.tenant_id', '=', 'aset_tr_penyusutan_aset.tenant_id'))
            ->join('aset_tr_aset', fn (JoinClause $join) => $join->on('aset_tr_aset.id', '=', 'buku.aset_id')->on('aset_tr_aset.tenant_id', '=', 'buku.tenant_id'))
            ->whereIn('aset_tr_aset.id', $aset)
            ->where('aset_tr_penyusutan_aset.status', 'final')
            ->where('aset_tr_penyusutan_aset.period_ends_on', '<=', $perTanggal)
            ->select(['buku.buku_id', 'aset_tr_penyusutan_aset.posted_posting_id', $this->groupAt('aset_tr_penyusutan_aset.period_ends_on')])
            ->selectRaw('sum(aset_tr_penyusutan_aset.amount) as jumlah')
            ->groupBy('grp', 'buku.buku_id', 'aset_tr_penyusutan_aset.posted_posting_id')
            ->toBase()
            ->get();
    }

    /**
     * Penurunan dan kenaikan nilai yang sudah diposting sampai tanggal laporan, per group, buku, jenis, dan posting.
     *
     * @param  Builder<Aset>  $aset
     * @return iterable<\stdClass>
     */
    private function valueAdjustments(Builder $aset, string $perTanggal): iterable
    {
        return AssetValueAdjustmentLine::query()
            ->join('aset_tr_penyesuaian_nilai_aset as dokumen', fn (JoinClause $join) => $join->on('dokumen.id', '=', 'aset_tr_penyesuaian_nilai_aset_details.penyesuaian_nilai_aset_id')->on('dokumen.tenant_id', '=', 'aset_tr_penyesuaian_nilai_aset_details.tenant_id'))
            ->join('aset_tr_aset', fn (JoinClause $join) => $join->on('aset_tr_aset.id', '=', 'aset_tr_penyesuaian_nilai_aset_details.aset_id')->on('aset_tr_aset.tenant_id', '=', 'aset_tr_penyesuaian_nilai_aset_details.tenant_id'))
            ->whereIn('aset_tr_aset.id', $aset)
            ->where('dokumen.status', AssetValueAdjustment::POSTED)
            ->whereNull('dokumen.deleted_at')
            ->where('dokumen.tanggal', '<=', $perTanggal)
            ->select(['dokumen.buku_id', 'dokumen.jenis', 'dokumen.posting_id', $this->groupAt('dokumen.tanggal')])
            ->selectRaw('sum(aset_tr_penyesuaian_nilai_aset_details.nilai) as jumlah')
            ->groupBy('grp', 'dokumen.buku_id', 'dokumen.jenis', 'dokumen.posting_id')
            ->toBase()
            ->get();
    }

    /**
     * Pemindahan reklasifikasi sampai tanggal laporan dari sisi aset asal (`asal`, group asal) atau tujuan
     * (`tujuan`, group tujuan), dengan posting dokumennya untuk buku yang dibawa jurnal.
     *
     * @param  Builder<Aset>  $aset
     * @return iterable<\stdClass>
     */
    private function reclassifications(Builder $aset, string $perTanggal, string $sisi): iterable
    {
        return AssetReclassificationBook::query()
            ->join('aset_tr_reklasifikasi_aset as dokumen', fn (JoinClause $join) => $join->on('dokumen.id', '=', 'aset_tr_reklasifikasi_aset_buku.reklasifikasi_aset_id')->on('dokumen.tenant_id', '=', 'aset_tr_reklasifikasi_aset_buku.tenant_id'))
            ->whereIn('aset_tr_reklasifikasi_aset_buku.aset_'.$sisi.'_id', $aset)
            ->where('aset_tr_reklasifikasi_aset_buku.tanggal', '<=', $perTanggal)
            ->select(['aset_tr_reklasifikasi_aset_buku.buku_id', 'aset_tr_reklasifikasi_aset_buku.dijurnal', 'dokumen.posting_id', DB::raw('aset_tr_reklasifikasi_aset_buku.group_aset_'.$sisi.'_id as grp')])
            ->selectRaw('sum(nilai_perolehan) as acq, sum(akumulasi_penyusutan) as acm, sum(penurunan_nilai) as wd, sum(kenaikan_nilai) as ap')
            ->groupBy('grp', 'aset_tr_reklasifikasi_aset_buku.buku_id', 'aset_tr_reklasifikasi_aset_buku.dijurnal', 'dokumen.posting_id')
            ->toBase()
            ->get();
    }

    /**
     * Buku yang ditutup karena asetnya dilepas sampai tanggal laporan, dengan saldonya saat ditutup.
     *
     * @param  Builder<Aset>  $aset
     * @return iterable<\stdClass>
     */
    private function disposals(Builder $aset, string $perTanggal): iterable
    {
        return Aset::query()
            ->join('aset_tr_buku_aset as buku', fn (JoinClause $join) => $join->on('buku.aset_id', '=', 'aset_tr_aset.id')->on('buku.tenant_id', '=', 'aset_tr_aset.tenant_id'))
            ->whereIn('aset_tr_aset.id', $aset)
            ->where('buku.status', 'closed')
            ->where('buku.closed_on', '<=', $perTanggal)
            ->toBase()
            ->get(['aset_tr_aset.id', 'aset_tr_aset.group_aset_id', 'buku.buku_id', 'buku.acquisition_value', 'buku.accumulated_depreciation', 'buku.write_down_amount', 'buku.appreciation_amount']);
    }

    private function label(string $key): string
    {
        return [
            'saldo_register' => 'Saldo register aset',
            'sudah_dibukukan' => 'Sudah dibukukan aplikasi finance',
            'dicatat_manual' => 'Dicatat manual',
            'menunggu' => 'Menunggu aplikasi finance',
            'tertahan' => 'Tertahan',
            'ditolak' => 'Ditolak aplikasi finance',
            'belum_diterbitkan' => 'Belum dikirim',
            'selisih' => 'Belum ada di buku besar',
        ][$key];
    }
}
