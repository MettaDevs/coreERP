<?php

declare(strict_types=1);

namespace Modules\Apperp\ManagementAset\Reporting\Definitions;

use Illuminate\Support\Carbon;
use Modules\Apperp\ManagementAset\Models\transaksi\InventarisasiAset\Asset;
use Modules\Apperp\ManagementAset\Models\transaksi\InventarisasiAset\DepreciationPeriod;
use Modules\Apperp\ManagementAset\Reporting\Layouts\BuiltinLayout;
use Modules\Apperp\ManagementAset\Reporting\ReportContext;
use Modules\Apperp\ManagementAset\Reporting\ReportData;
use Modules\Apperp\ManagementAset\Reporting\ReportDefinition;
use Modules\Apperp\ManagementAset\Reporting\ReportHelper;
use Modules\Apperp\ManagementAset\Support\OrganizationScope;

final class LaporanPenyusutanAset implements ReportDefinition
{
    public function code(): string
    {
        return 'laporan-penyusutan-aset';
    }

    public function name(): string
    {
        return 'Laporan penyusutan aset';
    }

    public function description(): string
    {
        return 'Rekapitulasi penyusutan aset, umur ekonomis, akumulasi, dan nilai buku akhir.';
    }

    public function permission(): string
    {
        return 'management-aset.penyusutan.read';
    }

    public function builtinLayouts(): array
    {
        return [
            new BuiltinLayout('standar', 'Laporan penyusutan aset standar (Excel)', 'Satu lembar rekapitulasi penyusutan per aset.', 'xlsx'),
        ];
    }

    public function parameterRules(): array
    {
        return [
            'group_aset_id' => ['nullable', 'ulid'],
            'kelompok_harta_fiskal_id' => ['nullable', 'ulid'],
            'jenis_aset_id' => ['nullable', 'ulid'],
            'asset_id' => ['nullable', 'ulid'],
            'buku_id' => ['nullable', 'ulid'],
            'periode' => ['nullable', 'string', 'regex:/^\d{4}-\d{2}$/'],
        ];
    }

    public function fields(): array
    {
        $header = [
            'filter_group' => 'Filter group aset',
            'filter_golongan' => 'Filter golongan aset',
            'filter_jenis' => 'Filter jenis aset',
            'filter_aset' => 'Filter nama aset',
            'filter_buku' => 'Filter buku penyusutan',
            'filter_periode' => 'Periode laporan',
            'total_nilai_perolehan' => 'Total nilai perolehan',
            'total_penyusutan_tahun' => 'Total penyusutan per tahun',
            'total_penyusutan_bulan' => 'Total penyusutan per bulan',
            'total_akumulasi_penyusutan' => 'Total akumulasi penyusutan',
            'total_nilai_buku_akhir' => 'Total nilai buku akhir',
            'jumlah_aset' => 'Jumlah aset',
            'dicetak_pada' => 'Tanggal cetak',
        ];

        $rows = [
            'baris.nomor' => 'No.',
            'baris.kode' => 'Kode aset',
            'baris.nama' => 'Nama aset',
            'baris.spesifikasi' => 'Spesifikasi',
            'baris.group' => 'Group aset',
            'baris.golongan' => 'Golongan aset',
            'baris.jenis' => 'Jenis aset',
            'baris.bulan_perolehan' => 'Bulan perolehan',
            'baris.tahun_perolehan' => 'Tahun perolehan',
            'baris.umur_ekonomis_tahun' => 'Umur ekonomis (tahun)',
            'baris.umur_ekonomis_bulan' => 'Umur ekonomis (bulan)',
            'baris.umur_ekonomis_saat_ini' => 'Umur ekonomis saat ini',
            'baris.sisa_umur_ekonomis_bulan' => 'Sisa umur ekonomis (bulan)',
            'baris.persentase_penyusutan' => 'Persentase penyusutan',
            'baris.nilai_perolehan' => 'Nilai perolehan',
            'baris.penyusutan_per_tahun' => 'Penyusutan per tahun',
            'baris.penyusutan_per_bulan' => 'Penyusutan per bulan',
            'baris.akumulasi_penyusutan' => 'Akumulasi penyusutan',
            'baris.nilai_buku_akhir' => 'Nilai buku akhir',
        ];

        return [
            ...array_map(fn (string $k, string $l): array => ['key' => $k, 'label' => $l, 'table' => null], array_keys($header), $header),
            ...array_map(fn (string $k, string $l): array => ['key' => $k, 'label' => $l, 'table' => 'baris'], array_keys($rows), $rows),
        ];
    }

    public function data(ReportContext $context, array $parameters): ReportData
    {
        $periodeStr = $parameters['periode'] ?? now()->format('Y-m');
        $cutoffDate = Carbon::createFromFormat('Y-m', $periodeStr)->endOfMonth()->format('Y-m-d');
        $targetYear = (int) substr($periodeStr, 0, 4);
        $targetMonth = (int) substr($periodeStr, 5, 2);

        $query = Asset::query()
            ->leftJoin('aset_m_group_aset as group_aset', fn ($j) => $j->on('group_aset.id', '=', 'aset_tr_penerimaan_aset.group_aset_id')->on('group_aset.tenant_id', '=', 'aset_tr_penerimaan_aset.tenant_id'))
            ->leftJoin('aset_m_kelompok_harta_fiskal as fiskal', fn ($j) => $j->on('fiskal.id', '=', 'aset_tr_penerimaan_aset.kelompok_harta_fiskal_id')->on('fiskal.tenant_id', '=', 'aset_tr_penerimaan_aset.tenant_id'))
            ->leftJoin('aset_m_jenis_aset as jenis', fn ($j) => $j->on('jenis.id', '=', 'aset_tr_penerimaan_aset.jenis_aset_id')->on('jenis.tenant_id', '=', 'aset_tr_penerimaan_aset.tenant_id'))
            ->leftJoin('aset_m_model_aset as model', fn ($j) => $j->on('model.id', '=', 'aset_tr_penerimaan_aset.model_aset_id')->on('model.tenant_id', '=', 'aset_tr_penerimaan_aset.tenant_id'))
            ->join('aset_tr_buku_aset as buku', fn ($j) => $j->on('buku.asset_id', '=', 'aset_tr_penerimaan_aset.id')->on('buku.tenant_id', '=', 'aset_tr_penerimaan_aset.tenant_id'))
            ->leftJoin('aset_m_profil_penyusutan as profil', fn ($j) => $j->on('profil.id', '=', 'buku.depreciation_profile_id')->on('profil.tenant_id', '=', 'buku.tenant_id'))
            ->leftJoin('aset_m_buku_penyusutan as master_buku', fn ($j) => $j->on('master_buku.id', '=', 'buku.buku_id')->on('master_buku.tenant_id', '=', 'buku.tenant_id'));

        app(OrganizationScope::class)->assetQuery($query, $context->request(), 'aset_tr_penerimaan_aset.legal_entity_id', 'aset_tr_penerimaan_aset.responsible_org_unit_id');

        if (! empty($parameters['group_aset_id'])) {
            $query->where('aset_tr_penerimaan_aset.group_aset_id', $parameters['group_aset_id']);
        }
        if (! empty($parameters['kelompok_harta_fiskal_id'])) {
            $query->where('aset_tr_penerimaan_aset.kelompok_harta_fiskal_id', $parameters['kelompok_harta_fiskal_id']);
        }
        if (! empty($parameters['jenis_aset_id'])) {
            $query->where('aset_tr_penerimaan_aset.jenis_aset_id', $parameters['jenis_aset_id']);
        }
        if (! empty($parameters['asset_id'])) {
            $query->where('aset_tr_penerimaan_aset.id', $parameters['asset_id']);
        }
        if (! empty($parameters['buku_id'])) {
            $query->where('buku.buku_id', $parameters['buku_id']);
        } else {
            $query->where(function ($q): void {
                $q->where('master_buku.posting_layer', 'current')
                    ->orWhereNull('buku.buku_id');
            });
        }

        $akumulasiSubquery = DepreciationPeriod::query()
            ->selectRaw('coalesce(sum(case when reverses_period_id is not null then -amount else amount end), 0)')
            ->whereColumn('aset_tr_penyusutan_aset.asset_book_id', 'buku.id')
            ->where('aset_tr_penyusutan_aset.period_ends_on', '<=', $cutoffDate);

        $rows = $query
            ->selectSub($akumulasiSubquery, 'akumulasi_penyusutan_tercatat')
            ->addSelect([
                'aset_tr_penerimaan_aset.id',
                'aset_tr_penerimaan_aset.kode',
                'aset_tr_penerimaan_aset.nama',
                'aset_tr_penerimaan_aset.model_number',
                'aset_tr_penerimaan_aset.serial_number',
                'aset_tr_penerimaan_aset.acquired_on',
                'buku.acquisition_value as buku_acquisition_value',
                'buku.useful_life_periods as buku_useful_life_periods',
                'buku.accumulated_depreciation as buku_accumulated_depreciation',
                'buku.net_book_value as buku_net_book_value',
                'group_aset.nama as group_nama',
                'fiskal.label as fiskal_label',
                'fiskal.useful_life_years as fiskal_years',
                'fiskal.straight_line_rate_percent as fiskal_rate',
                'jenis.nama as jenis_nama',
                'model.nama as model_nama',
                'profil.rate_percent as profil_rate',
                'master_buku.nama as master_buku_nama',
            ])
            ->orderBy('aset_tr_penerimaan_aset.kode')
            ->toBase()
            ->get();

        $totPerolehan = 0.0;
        $totPenyusutanThn = 0.0;
        $totPenyusutanBln = 0.0;
        $totAkumulasi = 0.0;
        $totNilaiBuku = 0.0;

        $barisData = [];
        $nomor = 1;

        foreach ($rows as $item) {
            $acquiredDate = $item->acquired_on ? Carbon::parse($item->acquired_on) : null;
            $bulanPerolehan = $acquiredDate ? ReportHelper::bulanIndo($acquiredDate->month) : '—';
            $tahunPerolehan = $acquiredDate ? (string) $acquiredDate->year : '—';

            // Umur ekonomis tahun & bulan
            $ueTahun = (int) ($item->fiskal_years ?? ($item->buku_useful_life_periods ? intdiv($item->buku_useful_life_periods, 12) : 0));
            $ueBulan = $ueTahun > 0 ? ($ueTahun * 12) : (int) ($item->buku_useful_life_periods ?? 0);

            // Umur ekonomis saat ini (bulan berjalan dari perolehan sampai periode cutoff)
            $ueSaatIni = 0;
            if ($acquiredDate) {
                $diffMonths = (($targetYear - $acquiredDate->year) * 12) + ($targetMonth - $acquiredDate->month);
                $ueSaatIni = max(0, $diffMonths);
            }
            $sisaUeBulan = max(0, $ueBulan - $ueSaatIni);

            // Persentase penyusutan
            $ratePercent = (float) ($item->profil_rate ?? $item->fiskal_rate ?? ($ueTahun > 0 ? (100.0 / $ueTahun) : 0.0));
            $persenStr = round($ratePercent, 2).'%';

            // Nilai perolehan
            $nilaiPerolehan = (float) $item->buku_acquisition_value;

            // Penyusutan per tahun & bulan
            $penyusutanTahun = $nilaiPerolehan * ($ratePercent / 100.0);
            $penyusutanBulan = $penyusutanTahun / 12.0;

            // Akumulasi penyusutan: jika ada di histori periode penyusutan, gunakan itu;
            // jika belum ada periode terbit, estimasi berdasarkan penyusutan per bulan x UE saat ini
            $akumulasi = (float) $item->akumulasi_penyusutan_tercatat;
            if ($akumulasi <= 0.0 && $ueSaatIni > 0 && $penyusutanBulan > 0.0) {
                $akumulasi = min($nilaiPerolehan, $penyusutanBulan * $ueSaatIni);
            }
            $nilaiBukuAkhir = max(0.0, $nilaiPerolehan - $akumulasi);

            $totPerolehan += $nilaiPerolehan;
            $totPenyusutanThn += $penyusutanTahun;
            $totPenyusutanBln += $penyusutanBulan;
            $totAkumulasi += $akumulasi;
            $totNilaiBuku += $nilaiBukuAkhir;

            $barisData[] = [
                'nomor' => $nomor++,
                'kode' => $item->kode,
                'nama' => $item->nama,
                'spesifikasi' => ReportHelper::spesifikasi($item->model_nama, $item->model_number, $item->serial_number),
                'group' => $item->group_nama ?? '—',
                'golongan' => $item->fiskal_label ?? '—',
                'jenis' => $item->jenis_nama ?? '—',
                'bulan_perolehan' => $bulanPerolehan,
                'tahun_perolehan' => $tahunPerolehan,
                'umur_ekonomis_tahun' => $ueTahun,
                'umur_ekonomis_bulan' => $ueBulan,
                'umur_ekonomis_saat_ini' => $ueSaatIni,
                'sisa_umur_ekonomis_bulan' => $sisaUeBulan,
                'persentase_penyusutan' => $persenStr,
                'nilai_perolehan' => ReportHelper::rupiah($nilaiPerolehan),
                'penyusutan_per_tahun' => ReportHelper::rupiah($penyusutanTahun, true),
                'penyusutan_per_bulan' => ReportHelper::rupiah($penyusutanBulan, true),
                'akumulasi_penyusutan' => ReportHelper::rupiah($akumulasi, true),
                'nilai_buku_akhir' => ReportHelper::rupiah($nilaiBukuAkhir, true),
            ];
        }

        $namaBulanLaporan = ReportHelper::bulanIndo($targetMonth).' '.$targetYear;

        return new ReportData(
            fields: [
                'filter_group' => $parameters['group_aset_id'] ?? 'Semua',
                'filter_golongan' => $parameters['kelompok_harta_fiskal_id'] ?? 'Semua',
                'filter_jenis' => $parameters['jenis_aset_id'] ?? 'Semua',
                'filter_aset' => $parameters['asset_id'] ?? 'Semua',
                'filter_buku' => $parameters['buku_id'] ?? 'Semua',
                'filter_periode' => $namaBulanLaporan,
                'total_nilai_perolehan' => ReportHelper::rupiah($totPerolehan),
                'total_penyusutan_tahun' => ReportHelper::rupiah($totPenyusutanThn, true),
                'total_penyusutan_bulan' => ReportHelper::rupiah($totPenyusutanBln, true),
                'total_akumulasi_penyusutan' => ReportHelper::rupiah($totAkumulasi, true),
                'total_nilai_buku_akhir' => ReportHelper::rupiah($totNilaiBuku, true),
                'jumlah_aset' => count($barisData),
                'dicetak_pada' => now()->format('d/m/Y H:i'),
            ],
            tables: [
                'baris' => $barisData,
            ],
            fileName: 'laporan-penyusutan-aset-'.$periodeStr,
        );
    }
}
