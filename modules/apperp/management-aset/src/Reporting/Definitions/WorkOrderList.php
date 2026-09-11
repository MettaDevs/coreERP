<?php

namespace Modules\Apperp\ManagementAset\Reporting\Definitions;

use Illuminate\Database\Eloquent\Builder;
use Modules\Apperp\ManagementAset\Models\transaksi\PemeliharaanAset\PemeliharaanAset;
use Modules\Apperp\ManagementAset\Models\transaksi\PemeliharaanAset\PemeliharaanAsetDetail;
use Modules\Apperp\ManagementAset\Reporting\Layouts\BuiltinLayout;
use Modules\Apperp\ManagementAset\Reporting\ReportContext;
use Modules\Apperp\ManagementAset\Reporting\ReportData;
use Modules\Apperp\ManagementAset\Reporting\ReportDefinition;
use Modules\Apperp\ManagementAset\Support\OrganizationScope;

/**
 * Daftar work order untuk dianalisis di Excel: satu baris per work order, dengan filter
 * status dan rentang tanggal. Ini laporan "analitik" dalam pembagian Business Central —
 * layout bawaannya Excel, bukan Word — dan ekspornya dikerjakan worker supaya daftar
 * ribuan baris tidak menahan layar.
 */
final class WorkOrderList implements ReportDefinition
{
    public function code(): string
    {
        return 'daftar-work-order';
    }

    public function name(): string
    {
        return 'Daftar work order';
    }

    public function description(): string
    {
        return 'Seluruh work order dalam jangkauan Anda, satu baris per work order, untuk diolah di Excel.';
    }

    public function permission(): string
    {
        return 'management-aset.pemeliharaan-aset.read';
    }

    public function builtinLayouts(): array
    {
        return [
            new BuiltinLayout('standar', 'Daftar work order standar (Excel)', 'Satu lembar dengan kolom identitas, jadwal, dan ringkasan jam.', 'xlsx'),
        ];
    }

    public function parameterRules(): array
    {
        return [
            'status' => ['nullable', 'string', 'in:draft,dijadwalkan,dikerjakan,selesai,ditutup,dibatalkan'],
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
            'jumlah_work_order' => 'Jumlah work order',
            'dicetak_pada' => 'Tanggal cetak',
        ];
        $rows = [
            'baris.kode' => 'Nomor work order',
            'baris.status' => 'Status',
            'baris.tipe_work_order' => 'Tipe work order',
            'baris.tingkat_layanan' => 'Tingkat layanan',
            'baris.keterangan' => 'Keterangan',
            'baris.jumlah_baris' => 'Jumlah baris pekerjaan',
            'baris.estimasi_jam' => 'Total estimasi jam',
            'baris.aktual_jam' => 'Total aktual jam',
            'baris.diharapkan_mulai' => 'Diharapkan mulai',
            'baris.dijadwalkan_mulai' => 'Dijadwalkan mulai',
            'baris.dijadwalkan_selesai' => 'Dijadwalkan selesai',
            'baris.aktual_mulai' => 'Aktual mulai',
            'baris.aktual_selesai' => 'Aktual selesai',
            'baris.dibuat_pada' => 'Dibuat pada',
        ];

        return [
            ...array_map(fn (string $key, string $label): array => ['key' => $key, 'label' => $label, 'table' => null], array_keys($header), $header),
            ...array_map(fn (string $key, string $label): array => ['key' => $key, 'label' => $label, 'table' => 'baris'], array_keys($rows), $rows),
        ];
    }

    public function data(ReportContext $context, array $parameters): ReportData
    {
        // Tabel utamanya tidak diberi alias, dan itu keharusan bukan selera: penyaringan tenant
        // disisipkan scope dengan nama tabel yang sebenarnya, sehingga alias pada tabel utama
        // membuat kolom yang disebut scope tidak ada. Tabel yang di-join tetap beralias.
        $query = PemeliharaanAset::query()
            ->leftJoin('aset_m_tipe_work_order as tipe', fn ($join) => $join->on('tipe.id', '=', 'aset_tr_pemeliharaan_aset.tipe_work_order_id')->on('tipe.tenant_id', '=', 'aset_tr_pemeliharaan_aset.tenant_id'))
            ->leftJoin('aset_m_tingkat_layanan as layanan', fn ($join) => $join->on('layanan.id', '=', 'aset_tr_pemeliharaan_aset.tingkat_layanan_id')->on('layanan.tenant_id', '=', 'aset_tr_pemeliharaan_aset.tenant_id'));
        app(OrganizationScope::class)->query($query, $context->request(), 'aset_tr_pemeliharaan_aset.legal_entity_id', 'aset_tr_pemeliharaan_aset.responsible_org_unit_id');
        if (! empty($parameters['status'])) {
            $query->where('aset_tr_pemeliharaan_aset.status', $parameters['status']);
        }
        if (! empty($parameters['dari'])) {
            $query->where('aset_tr_pemeliharaan_aset.created_at', '>=', $parameters['dari'].' 00:00:00');
        }
        if (! empty($parameters['sampai'])) {
            $query->where('aset_tr_pemeliharaan_aset.created_at', '<=', $parameters['sampai'].' 23:59:59');
        }

        // `toBase()` dipakai supaya barisnya tetap objek biasa, bukan model. Scope tenant sudah
        // disisipkan sebelum ini, jadi yang dilewati hanya penghidupan model — dan itu memang
        // yang diinginkan: dataset laporan membaca nilai apa adanya, sedangkan cast model akan
        // mengubah kolom tanggal menjadi objek yang tidak diterima pemformatnya di bawah.
        $rows = $query
            ->selectSub($this->ringkasan('count(*)'), 'jumlah_baris')
            ->selectSub($this->ringkasan('coalesce(sum(aset_tr_pemeliharaan_aset_details.estimasi_jam), 0)'), 'estimasi_jam')
            ->selectSub($this->ringkasan('coalesce(sum(aset_tr_pemeliharaan_aset_details.aktual_jam), 0)'), 'aktual_jam')
            ->addSelect(['aset_tr_pemeliharaan_aset.*', 'tipe.nama as tipe_nama', 'layanan.nama as layanan_nama'])
            ->orderBy('aset_tr_pemeliharaan_aset.kode')
            ->toBase()
            ->get();

        return new ReportData(
            fields: [
                'filter_status' => $parameters['status'] ?? 'Semua',
                'filter_dari' => $parameters['dari'] ?? '',
                'filter_sampai' => $parameters['sampai'] ?? '',
                'jumlah_work_order' => $rows->count(),
                'dicetak_pada' => now()->format('d/m/Y H:i'),
            ],
            tables: [
                'baris' => array_values($rows->map(fn (object $wo): array => [
                    'kode' => $wo->kode,
                    'status' => $wo->status,
                    'tipe_work_order' => $wo->tipe_nama,
                    'tingkat_layanan' => $wo->layanan_nama,
                    'keterangan' => $wo->keterangan,
                    'jumlah_baris' => (int) $wo->jumlah_baris,
                    'estimasi_jam' => round((float) $wo->estimasi_jam, 2),
                    'aktual_jam' => round((float) $wo->aktual_jam, 2),
                    'diharapkan_mulai' => $this->dateTime($wo->diharapkan_mulai),
                    'dijadwalkan_mulai' => $this->dateTime($wo->dijadwalkan_mulai),
                    'dijadwalkan_selesai' => $this->dateTime($wo->dijadwalkan_selesai),
                    'aktual_mulai' => $this->dateTime($wo->aktual_mulai),
                    'aktual_selesai' => $this->dateTime($wo->aktual_selesai),
                    'dibuat_pada' => $this->dateTime($wo->created_at),
                ])->all()),
            ],
            fileName: 'daftar-work-order-'.now()->format('Ymd-Hi'),
        );
    }

    /**
     * Subquery ringkasan baris pekerjaan untuk work order yang sedang dibaca.
     *
     * `literal-string`: ekspresinya hanya boleh teks yang tertulis di berkas ini. Ia masuk ke
     * SQL apa adanya, jadi tipe itulah yang menahan rakitan dari masukan pengguna.
     *
     * Penyaringan tenant pada subquery ini datang dari scope model detailnya, bukan dari
     * `where` yang ditulis tangan seperti sebelumnya.
     *
     * @param  literal-string  $ekspresi
     * @return Builder<PemeliharaanAsetDetail>
     */
    private function ringkasan(string $ekspresi): Builder
    {
        return PemeliharaanAsetDetail::query()
            ->selectRaw($ekspresi)
            ->whereColumn('aset_tr_pemeliharaan_aset_details.pemeliharaan_aset_id', 'aset_tr_pemeliharaan_aset.id');
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
}
