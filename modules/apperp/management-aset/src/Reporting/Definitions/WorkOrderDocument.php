<?php

namespace Modules\Apperp\ManagementAset\Reporting\Definitions;

use Modules\Apperp\ManagementAset\Models\transaksi\PemeliharaanAset\PemeliharaanAset;
use Modules\Apperp\ManagementAset\Models\transaksi\PemeliharaanAset\PemeliharaanAsetChecklist;
use Modules\Apperp\ManagementAset\Models\transaksi\PemeliharaanAset\PemeliharaanAsetDetail;
use Modules\Apperp\ManagementAset\Reporting\Layouts\BuiltinLayout;
use Modules\Apperp\ManagementAset\Reporting\ReportContext;
use Modules\Apperp\ManagementAset\Reporting\ReportData;
use Modules\Apperp\ManagementAset\Reporting\ReportDataException;
use Modules\Apperp\ManagementAset\Reporting\ReportDefinition;
use Modules\Apperp\ManagementAset\Support\OrganizationScope;

/**
 * Dokumen satu work order: header, baris pekerjaan, dan checklist — yang dicetak dan
 * dibawa teknisi ke lapangan, atau dikirim ke vendor pemeliharaan.
 *
 * Dataset ini membaca tabel yang sama dan menegakkan scope organisasi yang sama seperti
 * layar rincian work order. Orang yang tidak dapat membuka work order di layar tidak
 * dapat mencetaknya lewat jalur ini.
 */
final class WorkOrderDocument implements ReportDefinition
{
    public function code(): string
    {
        return 'work-order';
    }

    public function name(): string
    {
        return 'Work order';
    }

    public function description(): string
    {
        return 'Satu work order lengkap dengan baris pekerjaan dan checklist-nya.';
    }

    public function permission(): string
    {
        return 'management-aset.pemeliharaan-aset.read';
    }

    public function builtinLayouts(): array
    {
        return [
            new BuiltinLayout('standar', 'Work order standar (Word)', 'Header, tabel baris pekerjaan, dan checklist per baris.', 'docx'),
        ];
    }

    public function parameterRules(): array
    {
        return ['id' => ['required', 'ulid']];
    }

    public function fields(): array
    {
        $header = [
            'kode' => 'Nomor work order',
            'status' => 'Status',
            'tipe_work_order' => 'Tipe work order',
            'tingkat_layanan' => 'Tingkat layanan',
            'keterangan' => 'Keterangan',
            'penanggung_jawab' => 'Penanggung jawab',
            'diharapkan_mulai' => 'Diharapkan mulai',
            'diharapkan_selesai' => 'Diharapkan selesai',
            'dijadwalkan_mulai' => 'Dijadwalkan mulai',
            'dijadwalkan_selesai' => 'Dijadwalkan selesai',
            'aktual_mulai' => 'Aktual mulai',
            'aktual_selesai' => 'Aktual selesai',
            'jumlah_baris' => 'Jumlah baris pekerjaan',
            'total_estimasi_jam' => 'Total estimasi jam',
            'total_aktual_jam' => 'Total aktual jam',
            'dicetak_pada' => 'Tanggal cetak',
        ];
        $lines = [
            'baris.nomor' => 'Nomor baris',
            'baris.aset_kode' => 'Kode aset',
            'baris.aset_nama' => 'Nama aset',
            'baris.lokasi' => 'Lokasi aset',
            'baris.jenis_pekerjaan' => 'Jenis pekerjaan',
            'baris.varian' => 'Varian pekerjaan',
            'baris.bidang_keahlian' => 'Bidang keahlian',
            'baris.ditugaskan_ke' => 'Ditugaskan ke',
            'baris.dijadwalkan_mulai' => 'Jadwal mulai baris',
            'baris.dijadwalkan_selesai' => 'Jadwal selesai baris',
            'baris.estimasi_jam' => 'Estimasi jam',
            'baris.aktual_jam' => 'Aktual jam',
            'baris.hasil' => 'Hasil',
            'baris.sebab_kerusakan' => 'Sebab kerusakan',
            'baris.tindakan_perbaikan' => 'Tindakan perbaikan',
            'baris.catatan' => 'Catatan baris',
        ];
        $checklist = [
            'checklist.baris' => 'Nomor baris pekerjaan',
            'checklist.nomor' => 'Nomor item',
            'checklist.nama' => 'Nama pemeriksaan',
            'checklist.tipe' => 'Tipe pemeriksaan',
            'checklist.satuan' => 'Satuan',
            'checklist.wajib' => 'Wajib',
            'checklist.instruksi' => 'Instruksi',
            'checklist.nilai' => 'Nilai',
            'checklist.tidak_berlaku' => 'Tidak berlaku',
            'checklist.catatan' => 'Catatan teknisi',
        ];

        return [
            ...$this->describe($header, null),
            ...$this->describe($lines, 'baris'),
            ...$this->describe($checklist, 'checklist'),
        ];
    }

    public function data(ReportContext $context, array $parameters): ReportData
    {
        $request = $context->request();
        // Tabel utama tiap query di sini tidak diberi alias, dan itu keharusan bukan selera:
        // penyaringan tenant disisipkan scope dengan nama tabel yang sebenarnya, sehingga alias
        // pada tabel utama membuat kolom yang disebut scope tidak ada. Tabel yang di-join tetap
        // beralias, dan penyamaan `tenant_id` pada klausa `on`-nya dipertahankan — scope tidak
        // menyentuh tabel yang di-join.
        //
        // `toBase()` dipakai supaya barisnya tetap objek biasa, bukan model. Scope sudah
        // disisipkan sebelumnya; yang dilewati hanya penghidupan model, dan itu memang yang
        // diinginkan karena pemformat di bawah menerima string, bukan objek tanggal.
        $query = PemeliharaanAset::query()
            ->leftJoin('aset_m_tipe_work_order as tipe', fn ($join) => $join->on('tipe.id', '=', 'aset_tr_pemeliharaan_aset.tipe_work_order_id')->on('tipe.tenant_id', '=', 'aset_tr_pemeliharaan_aset.tenant_id'))
            ->leftJoin('aset_m_tingkat_layanan as layanan', fn ($join) => $join->on('layanan.id', '=', 'aset_tr_pemeliharaan_aset.tingkat_layanan_id')->on('layanan.tenant_id', '=', 'aset_tr_pemeliharaan_aset.tenant_id'))
            ->whereKey($parameters['id']);
        app(OrganizationScope::class)->query($query, $request, 'aset_tr_pemeliharaan_aset.legal_entity_id', 'aset_tr_pemeliharaan_aset.responsible_org_unit_id');
        $wo = $query->toBase()->first(['aset_tr_pemeliharaan_aset.*', 'tipe.nama as tipe_nama', 'layanan.nama as layanan_nama']);
        if ($wo === null) {
            throw new ReportDataException('Work order tidak ditemukan atau berada di luar unit kerja yang dapat Anda akses.');
        }

        $lines = PemeliharaanAsetDetail::query()
            ->leftJoin('aset_tr_aset as aset', fn ($join) => $join->on('aset.id', '=', 'aset_tr_pemeliharaan_aset_details.aset_id')->on('aset.tenant_id', '=', 'aset_tr_pemeliharaan_aset_details.tenant_id'))
            ->leftJoin('aset_m_lokasi_aset as lokasi', fn ($join) => $join->on('lokasi.id', '=', 'aset_tr_pemeliharaan_aset_details.lokasi_aset_id')->on('lokasi.tenant_id', '=', 'aset_tr_pemeliharaan_aset_details.tenant_id'))
            ->leftJoin('aset_m_maintenance_job_type as pekerjaan', fn ($join) => $join->on('pekerjaan.id', '=', 'aset_tr_pemeliharaan_aset_details.maintenance_job_type_id')->on('pekerjaan.tenant_id', '=', 'aset_tr_pemeliharaan_aset_details.tenant_id'))
            ->leftJoin('aset_m_maintenance_job_type_variant as varian', fn ($join) => $join->on('varian.id', '=', 'aset_tr_pemeliharaan_aset_details.variant_id')->on('varian.tenant_id', '=', 'aset_tr_pemeliharaan_aset_details.tenant_id'))
            ->leftJoin('aset_m_trade as keahlian', fn ($join) => $join->on('keahlian.id', '=', 'aset_tr_pemeliharaan_aset_details.trade_id')->on('keahlian.tenant_id', '=', 'aset_tr_pemeliharaan_aset_details.tenant_id'))
            ->leftJoin('aset_m_sebab_kerusakan as sebab', fn ($join) => $join->on('sebab.id', '=', 'aset_tr_pemeliharaan_aset_details.sebab_kerusakan_id')->on('sebab.tenant_id', '=', 'aset_tr_pemeliharaan_aset_details.tenant_id'))
            ->leftJoin('aset_m_tindakan_perbaikan as tindakan', fn ($join) => $join->on('tindakan.id', '=', 'aset_tr_pemeliharaan_aset_details.tindakan_perbaikan_id')->on('tindakan.tenant_id', '=', 'aset_tr_pemeliharaan_aset_details.tenant_id'))
            ->where('aset_tr_pemeliharaan_aset_details.pemeliharaan_aset_id', $wo->id)
            ->orderBy('aset_tr_pemeliharaan_aset_details.line_number')
            ->toBase()
            ->get([
                'aset_tr_pemeliharaan_aset_details.*',
                'aset.kode as aset_kode', 'aset.nama as aset_nama',
                'lokasi.nama as lokasi_nama',
                'pekerjaan.nama as pekerjaan_nama', 'varian.nama as varian_nama',
                'keahlian.nama as keahlian_nama',
                'sebab.nama as sebab_nama', 'tindakan.nama as tindakan_nama',
            ]);

        $checklist = PemeliharaanAsetChecklist::query()
            ->join('aset_tr_pemeliharaan_aset_details', fn ($join) => $join->on('aset_tr_pemeliharaan_aset_details.id', '=', 'aset_tr_pemeliharaan_aset_checklist.pemeliharaan_aset_detail_id')->on('aset_tr_pemeliharaan_aset_details.tenant_id', '=', 'aset_tr_pemeliharaan_aset_checklist.tenant_id'))
            ->where('aset_tr_pemeliharaan_aset_details.pemeliharaan_aset_id', $wo->id)
            ->orderBy('aset_tr_pemeliharaan_aset_details.line_number')->orderBy('aset_tr_pemeliharaan_aset_checklist.line_number')
            ->toBase()
            ->get(['aset_tr_pemeliharaan_aset_checklist.*', 'aset_tr_pemeliharaan_aset_details.line_number as job_line_number']);

        $fields = [
            'kode' => $wo->kode,
            'status' => $this->statusLabel($wo->status),
            'tipe_work_order' => $wo->tipe_nama,
            'tingkat_layanan' => $wo->layanan_nama,
            'keterangan' => $wo->keterangan,
            'penanggung_jawab' => $wo->penanggung_jawab_user_id,
            'diharapkan_mulai' => $this->dateTime($wo->diharapkan_mulai),
            'diharapkan_selesai' => $this->dateTime($wo->diharapkan_selesai),
            'dijadwalkan_mulai' => $this->dateTime($wo->dijadwalkan_mulai),
            'dijadwalkan_selesai' => $this->dateTime($wo->dijadwalkan_selesai),
            'aktual_mulai' => $this->dateTime($wo->aktual_mulai),
            'aktual_selesai' => $this->dateTime($wo->aktual_selesai),
            'jumlah_baris' => $lines->count(),
            'total_estimasi_jam' => $this->hours($lines->sum(fn (object $line): float => (float) ($line->estimasi_jam ?? 0))),
            'total_aktual_jam' => $this->hours($lines->sum(fn (object $line): float => (float) ($line->aktual_jam ?? 0))),
            'dicetak_pada' => now()->format('d/m/Y H:i'),
        ];

        return new ReportData(
            fields: $fields,
            tables: [
                'baris' => array_values($lines->map(fn (object $line): array => [
                    'nomor' => (int) $line->line_number,
                    'aset_kode' => $line->aset_kode,
                    'aset_nama' => $line->aset_nama,
                    'lokasi' => $line->lokasi_nama,
                    'jenis_pekerjaan' => $line->pekerjaan_nama,
                    'varian' => $line->varian_nama,
                    'bidang_keahlian' => $line->keahlian_nama,
                    'ditugaskan_ke' => $line->ditugaskan_ke_user_id,
                    'dijadwalkan_mulai' => $this->dateTime($line->dijadwalkan_mulai),
                    'dijadwalkan_selesai' => $this->dateTime($line->dijadwalkan_selesai),
                    'estimasi_jam' => $this->hours($line->estimasi_jam),
                    'aktual_jam' => $this->hours($line->aktual_jam),
                    'hasil' => $line->hasil,
                    'sebab_kerusakan' => $line->sebab_nama,
                    'tindakan_perbaikan' => $line->tindakan_nama,
                    'catatan' => $line->catatan,
                ])->all()),
                'checklist' => array_values($checklist->map(fn (object $item): array => [
                    'baris' => (int) $item->job_line_number,
                    'nomor' => rtrim(rtrim((string) $item->line_number, '0'), '.'),
                    'nama' => $item->nama,
                    'tipe' => $item->tipe,
                    'satuan' => $item->satuan,
                    'wajib' => $item->wajib ? 'Ya' : 'Tidak',
                    'instruksi' => $item->instruksi,
                    'nilai' => $item->nilai,
                    'tidak_berlaku' => $item->tidak_berlaku ? 'Ya' : '',
                    'catatan' => $item->catatan_teknisi,
                ])->all()),
            ],
            fileName: $wo->kode,
        );
    }

    /**
     * @param  array<string, string>  $labels
     * @return list<array{key: string, label: string, table: ?string}>
     */
    private function describe(array $labels, ?string $table): array
    {
        return array_map(
            fn (string $key, string $label): array => ['key' => $key, 'label' => $label, 'table' => $table],
            array_keys($labels),
            $labels,
        );
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'draft' => 'Draf',
            'dijadwalkan' => 'Dijadwalkan',
            'dikerjakan' => 'Dikerjakan',
            'selesai' => 'Selesai',
            'ditutup' => 'Ditutup',
            'dibatalkan' => 'Dibatalkan',
            default => $status,
        };
    }

    private function dateTime(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        // `strtotime()` memulangkan `false` untuk teks yang bukan tanggal. Nilai itu
        // diperlakukan sebagai 0, persis seperti sebelumnya ketika PHP sendiri yang
        // mengubah `false` menjadi 0 di dalam `date()`. Memulangkan `null` memang lebih
        // benar, tetapi itu perubahan perilaku dan bukan bagian dari perbaikan tipe ini.
        $stempel = strtotime($value);

        return date('d/m/Y H:i', $stempel === false ? 0 : $stempel);
    }

    private function hours(string|int|float|null $value): ?float
    {
        return $value === null ? null : round((float) $value, 2);
    }
}
