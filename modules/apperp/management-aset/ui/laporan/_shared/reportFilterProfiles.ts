import type { ReportDateParameters } from './reportOptions';

export type FilterChoice = { value: string; label: string };

type ReportFilterControl =
    | { kind: 'date'; key: string; label: string }
    | { kind: 'month'; key: string; label: string }
    | { kind: 'period'; key: string; label: string }
    | {
          kind: 'master';
          key: string;
          resource: string;
          label: string;
          unavailable: string;
      }
    | { kind: 'asset'; key: string }
    | {
          kind: 'choice';
          key: string;
          label: string;
          items: readonly FilterChoice[];
          defaultValue: string;
      }
    | { kind: 'depreciation-book'; key: string; emptyLabel?: string }
    | {
          kind: 'options';
          key: string;
          label: string;
          items: readonly FilterChoice[];
      };

export type ReportFilterDefinition = ReportFilterControl & {
    /** Kolom data item bila nama parameter berbeda dari kolom yang disaring. */
    field?: string;
    dataItem?: string;
    /** Pilihan record lama tetap terlihat sampai dibersihkan; filter kode menjadi penggantinya. */
    onlyWhenActive?: boolean;
};

export type ReportFilterSection = {
    key: string;
    caption: string;
    /** Hanya data item yang dinyatakan di sini yang membuka filter kolom, seperti request page BC. */
    dataItem?: string;
    filters: readonly ReportFilterDefinition[];
    defaultFields?: readonly string[];
};

type ReportPresetProfile =
    | { showPresets: false; dates?: never }
    | { showPresets: true; dates?: ReportDateParameters };

export type ReportFilterProfile = ReportPresetProfile & {
    options: readonly ReportFilterDefinition[];
    sections: readonly ReportFilterSection[];
};

const date = (field: { key: string; label: string }) =>
    ({ kind: 'date', ...field }) satisfies ReportFilterDefinition;

const month = (field: { key: string; label: string }) =>
    ({ kind: 'month', ...field }) satisfies ReportFilterDefinition;

const period = (field: { key: string; label: string }) =>
    ({ kind: 'period', ...field }) satisfies ReportFilterDefinition;

const master = (
    field: Omit<Extract<ReportFilterDefinition, { kind: 'master' }>, 'kind'>,
) => ({ kind: 'master', ...field }) satisfies ReportFilterDefinition;

const options = (
    field: Omit<Extract<ReportFilterDefinition, { kind: 'options' }>, 'kind'>,
) => ({ kind: 'options', ...field }) satisfies ReportFilterDefinition;

const assetGroup = master({
    key: 'group_aset_id',
    resource: 'group-aset',
    label: 'Group aset',
    unavailable:
        'Pilihan group aset tidak dapat dimuat. Minta administrator memberi Anda akses lihat group aset.',
});

const fiscalClassification = master({
    key: 'kelompok_harta_fiskal_id',
    resource: 'reference-data/kelompok-harta-fiskal',
    label: 'Kelompok harta fiskal',
    unavailable:
        'Pilihan kelompok harta fiskal tidak dapat dimuat. Minta administrator memberi Anda akses lihat group aset.',
});

const assetType = master({
    key: 'jenis_aset_id',
    resource: 'jenis-aset',
    label: 'Jenis aset',
    unavailable:
        'Pilihan jenis aset tidak dapat dimuat. Minta administrator memberi Anda akses lihat jenis aset.',
});

const location = master({
    key: 'lokasi_aset_id',
    resource: 'lokasi-aset',
    label: 'Lokasi',
    unavailable:
        'Pilihan lokasi tidak dapat dimuat. Minta administrator memberi Anda akses lihat lokasi aset.',
});

const condition = master({
    key: 'kondisi_aset_id',
    resource: 'kondisi-aset',
    label: 'Kondisi',
    unavailable:
        'Pilihan kondisi tidak dapat dimuat. Minta administrator memberi Anda akses lihat kondisi aset.',
});

const asset = {
    kind: 'asset',
    key: 'asset_id',
    field: 'kode',
    onlyWhenActive: true,
} satisfies ReportFilterDefinition;

const depreciationBook = {
    kind: 'depreciation-book',
    key: 'buku_id',
    dataItem: 'buku',
    field: 'buku_id',
} satisfies ReportFilterDefinition;

const organizationUnit = master({
    key: 'org_unit_id',
    resource: 'reference-data/unit-kerja',
    label: 'Unit organisasi',
    unavailable: 'Pilihan unit organisasi tidak dapat dimuat.',
});

const responsiblePerson = master({
    key: 'penanggung_jawab_user_id',
    resource: 'reference-data/anggota',
    label: 'Penanggung jawab',
    unavailable: 'Pilihan penanggung jawab tidak dapat dimuat.',
});

const dateRange = [
    date({ key: 'dari', label: 'Dari tanggal' }),
    date({ key: 'sampai', label: 'Sampai tanggal' }),
] satisfies readonly ReportFilterDefinition[];

const assetFilters = [
    assetGroup,
    fiscalClassification,
    assetType,
    location,
    condition,
    asset,
] satisfies readonly ReportFilterDefinition[];

const dateRangePreset = {
    from: 'dari',
    to: 'sampai',
} satisfies ReportDateParameters;
const depreciationMonthPreset = {
    month: 'periode',
} satisfies ReportDateParameters;

const procurementStatuses = [
    { value: 'belum_diminta', label: 'Belum diminta' },
    { value: 'belum_diterima', label: 'Belum diterima' },
    { value: 'diterima_sebagian', label: 'Diterima sebagian' },
    { value: 'diterima_penuh', label: 'Diterima penuh' },
] satisfies readonly FilterChoice[];

const maintenanceStatuses = [
    { value: 'draft', label: 'Draf' },
    { value: 'dijadwalkan', label: 'Dijadwalkan' },
    { value: 'dikerjakan', label: 'Dikerjakan' },
    { value: 'selesai', label: 'Selesai' },
    { value: 'ditutup', label: 'Ditutup' },
    { value: 'dibatalkan', label: 'Dibatalkan' },
] satisfies readonly FilterChoice[];

// Acuan: BC W1 FixedAssets/Reports. Tabel yang hanya dipakai menghitung tidak otomatis
// menjadi filter request page. Opsi di bawah hanya menawarkan fungsi yang dijalankan laporan.
const assetSection = {
    key: 'aset',
    caption: 'Aset',
    dataItem: 'aset',
    defaultFields: ['kode'],
    filters: assetFilters,
} satisfies ReportFilterSection;

// Laporan operasional ini tidak punya padanan langsung pada laporan FA standar BC.
// Atribut register disaring terpisah dari tanggal dokumen dan kondisi/lokasi hasil pemeriksaan.
const relatedAssetSection = {
    key: 'aset',
    caption: 'Aset',
    filters: [
        assetGroup,
        fiscalClassification,
        assetType,
        {
            ...asset,
            dataItem: 'baris',
            field: 'aset_id',
            onlyWhenActive: false,
        },
    ],
} satisfies ReportFilterSection;

export const REPORT_FILTER_PROFILES = {
    'laporan-monitoring-aset': {
        showPresets: false,
        options: [],
        sections: [
            {
                key: 'monitoring',
                caption: 'Monitoring',
                dataItem: 'monitoring',
                filters: [
                    ...dateRange.map((filter) => ({
                        ...filter,
                        field: 'tanggal',
                    })),
                    location,
                ],
            },
            {
                key: 'baris',
                caption: 'Hasil pemeriksaan',
                dataItem: 'baris',
                filters: [
                    condition,
                    {
                        ...responsiblePerson,
                        field: 'sistem_custodian_user_id',
                        label: 'Penanggung jawab saat diperiksa',
                    },
                    {
                        ...organizationUnit,
                        field: 'sistem_org_unit_id',
                        label: 'Unit organisasi saat diperiksa',
                    },
                ],
            },
            relatedAssetSection,
        ],
    },
    'daftar-mutasi-aset': {
        showPresets: false,
        options: [],
        sections: [
            {
                key: 'mutasi',
                caption: 'Mutasi',
                dataItem: 'mutasi',
                filters: [
                    ...dateRange.map((filter) => ({
                        ...filter,
                        field: 'tanggal',
                    })),
                    {
                        kind: 'choice',
                        key: 'status',
                        label: 'Status mutasi',
                        defaultValue: 'selesai',
                        items: [
                            { value: 'draft', label: 'Draf' },
                            { value: 'selesai', label: 'Selesai' },
                        ],
                    },
                ],
            },
            {
                key: 'baris',
                caption: 'Baris mutasi',
                dataItem: 'baris',
                filters: [],
            },
            relatedAssetSection,
        ],
    },
    // Fixed Asset - Book Value 01/02: buku dan tanggal adalah Opsi; filter register di Fixed Asset.
    'laporan-nilai-buku-aset': {
        showPresets: true,
        dates: dateRangePreset,
        options: [depreciationBook, ...dateRange],
        sections: [assetSection],
    },
    'laporan-pemeliharaan-aset': {
        showPresets: false,
        options: [],
        sections: [
            {
                key: 'work_order',
                caption: 'Work order',
                dataItem: 'work_order',
                filters: [
                    ...dateRange.map((filter) => ({
                        ...filter,
                        field: 'created_at',
                    })),
                    options({
                        key: 'status',
                        label: 'Status work order',
                        items: maintenanceStatuses,
                    }),
                    master({
                        key: 'tingkat_layanan_id',
                        resource: 'tingkat-layanan',
                        label: 'Tingkat layanan',
                        unavailable:
                            'Pilihan tingkat layanan tidak dapat dimuat.',
                    }),
                    { ...organizationUnit, field: 'responsible_org_unit_id' },
                ],
            },
            {
                key: 'baris',
                caption: 'Baris pekerjaan',
                dataItem: 'baris',
                filters: [
                    { ...location, label: 'Lokasi pekerjaan' },
                    {
                        ...master({
                            key: 'teknisi_user_id',
                            resource: 'reference-data/anggota',
                            label: 'Teknisi',
                            unavailable: 'Pilihan teknisi tidak dapat dimuat.',
                        }),
                        field: 'ditugaskan_ke_user_id',
                    },
                ],
            },
            relatedAssetSection,
        ],
    },
    // Pelepasan mencetak dokumen beserta asetnya. Keduanya boleh difilter; buku hanya menentukan nilai.
    'laporan-pemusnahan-aset': {
        showPresets: true,
        dates: dateRangePreset,
        options: [depreciationBook, ...dateRange],
        sections: [
            {
                key: 'dokumen',
                caption: 'Dokumen pemusnahan',
                dataItem: 'dokumen',
                filters: [],
            },
            assetSection,
        ],
    },
    // Status pemenuhan dihitung lintas rencana, permintaan, dan penerimaan; bukan status record rencana.
    'laporan-pengadaan-aset': {
        showPresets: true,
        dates: dateRangePreset,
        options: [
            ...dateRange,
            assetType,
            organizationUnit,
            options({
                key: 'status',
                label: 'Status pemenuhan',
                items: procurementStatuses,
            }),
        ],
        sections: [
            {
                key: 'rencana',
                caption: 'Rencana pengadaan',
                dataItem: 'rencana',
                filters: [],
            },
        ],
    },
    'laporan-penjualan-aset': {
        showPresets: true,
        dates: dateRangePreset,
        options: [depreciationBook, ...dateRange],
        sections: [
            {
                key: 'dokumen',
                caption: 'Dokumen penjualan',
                dataItem: 'dokumen',
                filters: [],
            },
            assetSection,
        ],
    },
    // Varian bulanan laporan nilai buku; periode adalah bulan perhitungan, bukan tanggal master aset.
    'laporan-penyusutan-aset': {
        showPresets: true,
        dates: depreciationMonthPreset,
        options: [
            depreciationBook,
            period({ key: 'periode', label: 'Periode' }),
        ],
        sections: [assetSection],
    },
    // Fixed Asset Acquisition List: periode perolehan di Opsi, atribut register di Fixed Asset.
    'laporan-perolehan-aset': {
        showPresets: true,
        dates: dateRangePreset,
        options: [
            { ...depreciationBook, emptyLabel: 'Tanpa filter buku' },
            ...dateRange.map((filter) => ({
                ...filter,
                dataItem: 'aset',
                field: 'acquired_on',
            })),
        ],
        sections: [assetSection],
    },
    // Fixed Asset Projected Value: buku dan periode proyeksi di Opsi; filter register terpisah.
    'laporan-proyeksi-penyusutan-aset': {
        showPresets: true,
        options: [
            depreciationBook,
            month({ key: 'dari', label: 'Dari bulan' }),
            month({ key: 'sampai', label: 'Sampai bulan' }),
        ],
        sections: [assetSection],
    },
    // Rekonsiliasi adalah fungsi lokal; tanggal saldo berbeda dari filter tanggal perolehan aset.
    'laporan-rekonsiliasi-aset-buku-besar': {
        showPresets: true,
        options: [date({ key: 'per_tanggal', label: 'Per tanggal' })],
        sections: [
            {
                ...assetSection,
                filters: [assetGroup, assetType, location, condition, asset],
            },
        ],
    },
} satisfies Record<string, ReportFilterProfile>;

export type ReportCode = keyof typeof REPORT_FILTER_PROFILES;
