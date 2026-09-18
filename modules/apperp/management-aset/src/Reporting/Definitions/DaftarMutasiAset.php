<?php

namespace Modules\Apperp\ManagementAset\Reporting\Definitions;

use Modules\Apperp\ManagementAset\Models\transaksi\MutasiAset\MutasiAsetDetail;
use Modules\Apperp\ManagementAset\Reporting\Layouts\BuiltinLayout;
use Modules\Apperp\ManagementAset\Reporting\ReportContext;
use Modules\Apperp\ManagementAset\Reporting\ReportData;
use Modules\Apperp\ManagementAset\Reporting\ReportDefinition;
use Modules\Apperp\ManagementAset\Services\DirektoriAset;
use Modules\Apperp\ManagementAset\Support\MutasiStatus;
use Modules\Apperp\ManagementAset\Support\OrganizationScope;

/**
 * Laporan mutasi aset untuk diolah di Excel: **satu baris per aset yang berpindah**, bukan
 * satu baris per dokumen.
 *
 * Bentuk ini mengikuti kolom yang diminta QA — tanggal, nomor bukti, kode dan nama aset,
 * lokasi asal, lokasi tujuan, penanggung jawab, PIC penerima, kondisi, keterangan — dan
 * bentuk itu memang yang benar: pertanyaan yang dijawab laporan mutasi adalah "barang ini
 * pindah ke mana", dan barang adalah barisnya. Berita acara yang memuat sepuluh aset
 * muncul sebagai sepuluh baris, masing-masing dengan nomor bukti yang sama.
 *
 * Hanya dokumen selesai yang ikut secara bawaan. Draf belum memindahkan apa pun; memasukkan
 * mereka membuat total laporan tidak cocok dengan keadaan aset yang sebenarnya.
 */
final class DaftarMutasiAset implements ReportDefinition
{
    public function code(): string
    {
        return 'daftar-mutasi-aset';
    }

    public function name(): string
    {
        return 'Daftar mutasi aset';
    }

    public function description(): string
    {
        return 'Seluruh perpindahan aset dalam jangkauan Anda, satu baris per aset, untuk diolah di Excel.';
    }

    public function permission(): string
    {
        return 'management-aset.mutasi-aset.read';
    }

    public function builtinLayouts(): array
    {
        return [
            new BuiltinLayout('standar', 'Daftar mutasi standar (Excel)', 'Satu lembar dengan kolom bukti, aset, asal, tujuan, dan kondisi.', 'xlsx'),
        ];
    }

    public function parameterRules(): array
    {
        return [
            'status' => ['nullable', 'string', 'in:'.implode(',', MutasiStatus::semua())],
            'dari' => ['nullable', 'date_format:Y-m-d'],
            'sampai' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:dari'],
        ];
    }

    public function fields(): array
    {
        $header = [
            'filter_status' => 'Filter status',
            'filter_dari' => 'Filter tanggal mulai',
            'filter_sampai' => 'Filter tanggal akhir',
            'jumlah_baris' => 'Jumlah aset berpindah',
            'dicetak_pada' => 'Tanggal cetak',
        ];
        $rows = [
            'baris.tanggal' => 'Tanggal mutasi',
            'baris.kode' => 'No. bukti mutasi',
            'baris.status' => 'Status',
            'baris.aset_kode' => 'Kode aset',
            'baris.aset_nama' => 'Nama aset',
            'baris.serial_number' => 'Nomor seri',
            'baris.asal_lokasi' => 'Lokasi asal',
            'baris.tujuan_lokasi' => 'Lokasi tujuan',
            'baris.asal_unit_kerja' => 'Unit asal',
            'baris.tujuan_unit_kerja' => 'Unit tujuan',
            'baris.diserahkan_oleh' => 'Diserahkan oleh',
            'baris.diterima_oleh' => 'PIC penerima',
            'baris.kondisi' => 'Kondisi aset',
            'baris.alasan' => 'Alasan mutasi',
            'baris.keterangan' => 'Keterangan',
        ];

        return [
            ...array_map(
                static fn (string $key, string $label): array => ['key' => $key, 'label' => $label, 'table' => null],
                array_keys($header),
                $header,
            ),
            ...array_map(
                static fn (string $key, string $label): array => ['key' => $key, 'label' => $label, 'table' => 'baris'],
                array_keys($rows),
                $rows,
            ),
        ];
    }

    public function data(ReportContext $context, array $parameters): ReportData
    {
        // Query berangkat dari baris, bukan dari header, karena satuannya aset. Scope
        // organisasi tetap ditegakkan pada kolom header yang di-join: baris tidak memikul
        // entitas legal maupun unit kerja sendiri, dan menyaring pada kolom yang tidak ada
        // akan lolos begitu saja.
        $query = MutasiAsetDetail::query()
            ->join('aset_tr_mutasi_aset as mutasi', fn ($join) => $join
                ->on('mutasi.id', '=', 'aset_tr_mutasi_aset_details.mutasi_aset_id')
                ->on('mutasi.tenant_id', '=', 'aset_tr_mutasi_aset_details.tenant_id'))
            ->leftJoin('aset_tr_aset as aset', fn ($join) => $join
                ->on('aset.id', '=', 'aset_tr_mutasi_aset_details.aset_id')
                ->on('aset.tenant_id', '=', 'aset_tr_mutasi_aset_details.tenant_id'))
            ->leftJoin('aset_m_lokasi_aset as asal', fn ($join) => $join
                ->on('asal.id', '=', 'aset_tr_mutasi_aset_details.asal_lokasi_id')
                ->on('asal.tenant_id', '=', 'aset_tr_mutasi_aset_details.tenant_id'))
            ->leftJoin('aset_m_lokasi_aset as tujuan', fn ($join) => $join
                ->on('tujuan.id', '=', 'mutasi.tujuan_lokasi_id')
                ->on('tujuan.tenant_id', '=', 'mutasi.tenant_id'))
            ->leftJoin('aset_m_kondisi_aset as kondisi', fn ($join) => $join
                ->on('kondisi.id', '=', 'aset_tr_mutasi_aset_details.kondisi_aset_id')
                ->on('kondisi.tenant_id', '=', 'aset_tr_mutasi_aset_details.tenant_id'))
            // Dokumen yang sudah diarsipkan tidak boleh muncul; `join` biasa tidak
            // menyaringnya karena soft delete hanya berlaku pada model tabel utamanya.
            ->whereNull('mutasi.deleted_at');
        app(OrganizationScope::class)->query($query, $context->request(), 'mutasi.legal_entity_id', 'mutasi.responsible_org_unit_id');

        $query->where('mutasi.status', $parameters['status'] ?? MutasiStatus::SELESAI);
        if (! empty($parameters['dari'])) {
            $query->whereDate('mutasi.tanggal', '>=', $parameters['dari']);
        }
        if (! empty($parameters['sampai'])) {
            $query->whereDate('mutasi.tanggal', '<=', $parameters['sampai']);
        }

        $direktori = app(DirektoriAset::class);
        $rows = $query
            ->orderBy('mutasi.tanggal')
            ->orderBy('mutasi.kode')
            ->orderBy('aset_tr_mutasi_aset_details.line_number')
            ->toBase()
            ->get([
                'aset_tr_mutasi_aset_details.*',
                'mutasi.kode', 'mutasi.tanggal', 'mutasi.status', 'mutasi.alasan', 'mutasi.keterangan',
                'mutasi.tujuan_org_unit_id', 'mutasi.diserahkan_oleh_user_id', 'mutasi.diterima_oleh_user_id',
                'aset.kode as aset_kode', 'aset.nama as aset_nama', 'aset.serial_number as aset_serial_number',
                'asal.nama as asal_lokasi_nama', 'tujuan.nama as tujuan_lokasi_nama',
                'kondisi.nama as kondisi_nama',
            ]);

        return new ReportData(
            fields: [
                'filter_status' => $parameters['status'] ?? MutasiStatus::SELESAI,
                'filter_dari' => $parameters['dari'] ?? '',
                'filter_sampai' => $parameters['sampai'] ?? '',
                'jumlah_baris' => $rows->count(),
                'dicetak_pada' => now()->format('d/m/Y H:i'),
            ],
            tables: [
                'baris' => array_values($rows->map(fn (object $row): array => [
                    'tanggal' => $this->tanggal($row->tanggal),
                    'kode' => $row->kode,
                    'status' => $row->status,
                    'aset_kode' => $row->aset_kode,
                    'aset_nama' => $row->aset_nama,
                    'serial_number' => $row->aset_serial_number,
                    'asal_lokasi' => $row->asal_lokasi_nama,
                    'tujuan_lokasi' => $row->tujuan_lokasi_nama,
                    // Kolom laporan menyebut nama unit dan nama orang. Sebuah ULID di
                    // lembar Excel tidak dapat disaring, diurutkan, maupun dikenali.
                    'asal_unit_kerja' => $direktori->namaUnit($context->tenantId, $row->asal_org_unit_id),
                    'tujuan_unit_kerja' => $direktori->namaUnit($context->tenantId, $row->tujuan_org_unit_id),
                    'diserahkan_oleh' => $direktori->namaOrang($context->tenantId, $row->diserahkan_oleh_user_id) ?? $row->diserahkan_oleh_user_id,
                    'diterima_oleh' => $direktori->namaOrang($context->tenantId, $row->diterima_oleh_user_id) ?? $row->diterima_oleh_user_id,
                    'kondisi' => $row->kondisi_nama,
                    'alasan' => $row->alasan,
                    'keterangan' => $row->keterangan,
                ])->all()),
            ],
            fileName: 'daftar-mutasi-aset-'.now()->format('Ymd-Hi'),
        );
    }

    private function tanggal(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $stempel = strtotime($value);

        return $stempel === false ? $value : date('d/m/Y', $stempel);
    }
}
