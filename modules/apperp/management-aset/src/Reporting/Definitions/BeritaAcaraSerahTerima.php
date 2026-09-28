<?php

namespace Modules\Apperp\ManagementAset\Reporting\Definitions;

use Modules\Apperp\ManagementAset\Models\transaksi\MutasiAset\MutasiAset;
use Modules\Apperp\ManagementAset\Models\transaksi\MutasiAset\MutasiAsetDetail;
use Modules\Apperp\ManagementAset\Reporting\Layouts\BuiltinLayout;
use Modules\Apperp\ManagementAset\Reporting\ReportContext;
use Modules\Apperp\ManagementAset\Reporting\ReportData;
use Modules\Apperp\ManagementAset\Reporting\ReportDataException;
use Modules\Apperp\ManagementAset\Reporting\ReportDefinition;
use Modules\Apperp\ManagementAset\Services\DirektoriAset;
use Modules\Apperp\ManagementAset\Support\MutasiStatus;
use Modules\Apperp\ManagementAset\Support\OrganizationScope;

/**
 * Berita acara serah terima aset — lembar yang ditandatangani kedua pihak saat barang
 * benar-benar berpindah tangan.
 *
 * Dokumen ini tidak punya padanan di Dynamics 365: F&O mencatat perpindahan sebagai
 * riwayat pemasangan pada functional location dan berhenti di situ. Bukti serah terima
 * bertanda tangan adalah praktik Indonesia, jadi bentuknya diambil dari sana, bukan dari
 * produk mana pun.
 *
 * Ia hanya mau mencetak dokumen yang sudah selesai. Berita acara adalah bukti bahwa
 * sesuatu **sudah** terjadi; mencetaknya dari draf menghasilkan lembar bertanda tangan
 * untuk perpindahan yang belum pernah berlangsung, dan lembar itu tidak bisa ditarik
 * kembali setelah ditandatangani.
 */
final class BeritaAcaraSerahTerima implements ReportDefinition
{
    public function code(): string
    {
        return 'berita-acara-serah-terima';
    }

    public function name(): string
    {
        return 'Berita acara serah terima aset';
    }

    public function description(): string
    {
        return 'Satu berita acara serah terima beserta seluruh aset yang berpindah di dalamnya.';
    }

    public function permission(): string
    {
        return 'management-aset.mutasi-aset.read';
    }

    public function builtinLayouts(): array
    {
        return [
            new BuiltinLayout('standar', 'Berita acara standar (Word)', 'Kop, identitas kedua pihak, tabel aset, dan blok tanda tangan.', 'docx'),
        ];
    }

    public function parameterRules(): array
    {
        return ['id' => ['required', 'ulid']];
    }

    public function fields(): array
    {
        $header = [
            'kode' => 'Nomor berita acara',
            'tanggal' => 'Tanggal serah terima',
            'alasan' => 'Alasan mutasi',
            'keterangan' => 'Keterangan',
            'tujuan_lokasi' => 'Lokasi tujuan',
            'tujuan_unit_kerja' => 'Unit kerja tujuan',
            'diserahkan_oleh' => 'Diserahkan oleh',
            'diterima_oleh' => 'Diterima oleh',
            'jumlah_aset' => 'Jumlah aset',
            'dicetak_pada' => 'Tanggal cetak',
        ];
        $lines = [
            'baris.nomor' => 'Nomor baris',
            'baris.aset_kode' => 'Kode aset',
            'baris.aset_nama' => 'Nama aset',
            'baris.serial_number' => 'Nomor seri',
            'baris.asal_lokasi' => 'Lokasi asal',
            'baris.asal_unit_kerja' => 'Unit kerja asal',
            'baris.kondisi' => 'Kondisi aset',
            'baris.catatan' => 'Catatan baris',
        ];

        return [
            ...array_map(
                static fn (string $key, string $label): array => ['key' => $key, 'label' => $label, 'table' => null],
                array_keys($header),
                $header,
            ),
            ...array_map(
                static fn (string $key, string $label): array => ['key' => $key, 'label' => $label, 'table' => 'baris'],
                array_keys($lines),
                $lines,
            ),
        ];
    }

    public function data(ReportContext $context, array $parameters): ReportData
    {
        $request = $context->request();
        // Tabel utama tidak diberi alias: penyaringan tenant disisipkan scope dengan nama
        // tabel yang sebenarnya, sehingga alias di sana membuat kolomnya tidak ditemukan.
        $query = MutasiAset::query()
            ->leftJoin('aset_m_lokasi_aset as tujuan', fn ($join) => $join
                ->on('tujuan.id', '=', 'aset_tr_mutasi_aset.tujuan_lokasi_id')
                ->on('tujuan.tenant_id', '=', 'aset_tr_mutasi_aset.tenant_id'))
            ->whereKey($parameters['id']);
        app(OrganizationScope::class)->query($query, $request, 'aset_tr_mutasi_aset.legal_entity_id', 'aset_tr_mutasi_aset.responsible_org_unit_id');
        $mutasi = $query->toBase()->first(['aset_tr_mutasi_aset.*', 'tujuan.nama as tujuan_lokasi_nama']);
        if ($mutasi === null) {
            throw new ReportDataException('Mutasi tidak ditemukan atau berada di luar unit kerja yang dapat Anda akses.');
        }
        if ($mutasi->status !== MutasiStatus::SELESAI) {
            throw new ReportDataException('Berita acara hanya dapat dicetak setelah mutasi diselesaikan.');
        }

        $direktori = app(DirektoriAset::class);
        $lines = MutasiAsetDetail::query()
            ->leftJoin('aset_tr_aset as aset', fn ($join) => $join
                ->on('aset.id', '=', 'aset_tr_mutasi_aset_details.aset_id')
                ->on('aset.tenant_id', '=', 'aset_tr_mutasi_aset_details.tenant_id'))
            ->leftJoin('aset_m_lokasi_aset as asal', fn ($join) => $join
                ->on('asal.id', '=', 'aset_tr_mutasi_aset_details.asal_lokasi_id')
                ->on('asal.tenant_id', '=', 'aset_tr_mutasi_aset_details.tenant_id'))
            ->leftJoin('aset_m_kondisi_aset as kondisi', fn ($join) => $join
                ->on('kondisi.id', '=', 'aset_tr_mutasi_aset_details.kondisi_aset_id')
                ->on('kondisi.tenant_id', '=', 'aset_tr_mutasi_aset_details.tenant_id'))
            ->where('aset_tr_mutasi_aset_details.mutasi_aset_id', $mutasi->id)
            ->orderBy('aset_tr_mutasi_aset_details.line_number')
            ->toBase()
            ->get([
                'aset_tr_mutasi_aset_details.*',
                'aset.kode as aset_kode',
                'aset.nama as aset_nama',
                'aset.serial_number as aset_serial_number',
                'asal.nama as asal_lokasi_nama',
                'kondisi.nama as kondisi_nama',
            ]);

        return new ReportData(
            fields: [
                'kode' => $mutasi->kode,
                'tanggal' => $this->tanggalPanjang($mutasi->tanggal),
                'alasan' => $mutasi->alasan,
                'keterangan' => $mutasi->keterangan,
                'tujuan_lokasi' => $mutasi->tujuan_lokasi_nama,
                // Nama, bukan id. Blok tanda tangan yang berbunyi "( 01JQ… )" tidak dapat
                // dipakai sebagai bukti serah terima oleh siapa pun.
                'tujuan_unit_kerja' => $direktori->namaUnit($context->tenantId, $mutasi->tujuan_org_unit_id),
                'diserahkan_oleh' => $direktori->namaOrang($context->tenantId, $mutasi->diserahkan_oleh_user_id) ?? $mutasi->diserahkan_oleh_user_id,
                'diterima_oleh' => $direktori->namaOrang($context->tenantId, $mutasi->diterima_oleh_user_id) ?? $mutasi->diterima_oleh_user_id,
                'jumlah_aset' => $lines->count(),
                'dicetak_pada' => now()->format('d/m/Y H:i'),
            ],
            tables: [
                'baris' => array_values($lines->map(static fn (object $line): array => [
                    'nomor' => (int) $line->line_number,
                    'aset_kode' => $line->aset_kode,
                    'aset_nama' => $line->aset_nama,
                    'serial_number' => $line->aset_serial_number,
                    'asal_lokasi' => $line->asal_lokasi_nama,
                    'asal_unit_kerja' => $direktori->namaUnit($context->tenantId, $line->asal_org_unit_id),
                    'kondisi' => $line->kondisi_nama,
                    'catatan' => $line->catatan,
                ])->all()),
            ],
            fileName: 'bast-'.$mutasi->kode,
        );
    }

    /**
     * Tanggal dalam bahasa Indonesia, bukan `d/m/Y`.
     *
     * Berita acara dibaca dan ditandatangani orang, bukan dipilah mesin, dan kalimat
     * penutupnya berbunyi "Denpasar, 23 Juli 2026" — bukan "23/07/2026". Nama bulan
     * ditulis di sini dan tidak diambil dari locale sistem: locale container tidak dijamin
     * ada, dan bulan yang berubah menjadi bahasa Inggris pada dokumen bertanda tangan
     * adalah cacat yang baru ketahuan setelah dicetak.
     */
    private function tanggalPanjang(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $stempel = strtotime($value);
        if ($stempel === false) {
            return $value;
        }

        $bulan = [
            1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
            'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember',
        ];

        return date('j', $stempel).' '.$bulan[(int) date('n', $stempel)].' '.date('Y', $stempel);
    }
}
