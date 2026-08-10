import { FieldConfig, FieldValue } from './fields';

export type MasterResource =
    | 'group-aset'
    | 'jenis-aset'
    | 'model-aset'
    | 'kondisi-aset'
    | 'pabrikan-aset'
    | 'item-checklist-maintenance'
    | 'analisa-maintenance'
    | 'tipe-lokasi-aset'
    | 'lokasi-aset'
    | 'tipe-atribut'
    | 'buku-penyusutan'
    | 'profil-penyusutan';

export type MasterAction = 'read' | 'create' | 'update' | 'archive';

export type Permission =
    | `management-aset.${MasterResource}.${MasterAction}`
    | `management-aset.aset.${'read' | 'create' | 'mutate'}`
    | 'management-aset.mutasi-aset.read'
    | `management-aset.${'perencanaan-aset' | 'permintaan-pembelian-aset' | 'pemeliharaan-aset' | 'penjualan-aset' | 'pemusnahan-aset'}.${'read' | 'create'}`
    | 'management-aset.monitoring-aset.read'
    | 'management-aset.fixed-asset-parameters.read'
    | 'management-aset.fixed-asset-posting-profiles.read'
    | `management-aset.penyusutan.${'read' | 'create' | 'finalize' | 'correct'}`;

export function permission(resource: MasterResource, action: MasterAction): Permission {
    return `management-aset.${resource}.${action}`;
}

export type ParentSummary = { id: string; kode: string; nama: string };

export type MasterRecord = {
    id: string;
    kode: string;
    nama: string;
    keterangan: string | null;
    aktif: boolean;
} & Record<string, unknown>;

/**
 * Induk satu master. Sebuah master dapat memiliki lebih dari satu induk yang saling
 * lepas, jadi daftar induk tidak menyiratkan urutan maupun penyaringan bertingkat:
 * memilih satu induk tidak pernah membatasi pilihan induk lainnya.
 */
export type MasterParentConfig = {
    resource: MasterResource;
    /** Kolom foreign key yang dikirim dan diterima API. */
    field: string;
    /** Kunci ringkasan induk pada respons API. */
    summaryKey: string;
    label: string;
    required?: boolean;
};

export type MasterConfig = {
    resource: MasterResource;
    nav: string;
    title: string;
    subtitle: string;
    kodeLabel: string;
    namaLabel: string;
    /** Sebutan satu record pada tombol dan konfirmasi. */
    singular: string;
    parents?: MasterParentConfig[];
    /** Kolom di luar bentuk dasar master, dirender lewat `DynamicField`. */
    extraFields?: FieldConfig[];
};

export const MASTERS: MasterConfig[] = [
    {
        resource: 'group-aset',
        nav: 'Group aset',
        title: 'Group aset',
        subtitle: 'Sumbu finansial aset: dasar penyusutan, akun, dan penomoran.',
        kodeLabel: 'Kode group aset',
        namaLabel: 'Nama group aset',
        singular: 'group aset',
        extraFields: [
            {
                name: 'tipe_harta',
                label: 'Kelompok harta',
                type: 'select',
                help: 'Menentukan masa manfaat dan tarif fiskal aset dalam group ini.',
                options: [
                    { value: 'kelompok_1', label: 'Kelompok 1' },
                    { value: 'kelompok_2', label: 'Kelompok 2' },
                    { value: 'kelompok_3', label: 'Kelompok 3' },
                    { value: 'kelompok_4', label: 'Kelompok 4' },
                    { value: 'bangunan_permanen', label: 'Bangunan permanen' },
                    { value: 'bangunan_non_permanen', label: 'Bangunan tidak permanen' },
                    { value: 'bukan_objek_penyusutan', label: 'Bukan objek penyusutan' },
                ],
            },
            {
                name: 'major_type',
                label: 'Jenis harta',
                type: 'select',
                options: [
                    { value: 'tangible', label: 'Berwujud' },
                    { value: 'intangible', label: 'Tidak berwujud' },
                    { value: 'right_of_use', label: 'Hak guna' },
                    { value: 'low_value', label: 'Bernilai rendah' },
                ],
            },
            {
                name: 'capitalization_threshold',
                label: 'Ambang kapitalisasi',
                type: 'number',
                min: 0,
                step: 0.01,
                help: 'Perolehan di bawah nilai ini dibebankan, tidak dicatat sebagai aset.',
            },
            {
                name: 'posting_layers',
                label: 'Lapisan pembukuan',
                type: 'multiselect',
                options: [
                    { value: 'current', label: 'Komersial' },
                    { value: 'operations', label: 'Operasional' },
                    { value: 'tax', label: 'Fiskal' },
                    { value: 'none', label: 'Memorandum' },
                ],
            },
        ],
    },
    {
        resource: 'jenis-aset',
        nav: 'Jenis aset',
        title: 'Jenis aset',
        subtitle: 'Sumbu teknis aset: dasar maintenance dan atribut.',
        kodeLabel: 'Kode jenis aset',
        namaLabel: 'Nama jenis aset',
        singular: 'jenis aset',
    },
    {
        resource: 'model-aset',
        nav: 'Model aset',
        title: 'Model aset',
        subtitle: 'Katalog model barang per pabrikan yang dapat dipilih saat menerima aset.',
        kodeLabel: 'Kode model aset',
        namaLabel: 'Nama model aset',
        singular: 'model aset',
        parents: [
            { resource: 'pabrikan-aset', field: 'pabrikan_aset_id', summaryKey: 'pabrikan_aset', label: 'Pabrikan aset' },
            { resource: 'jenis-aset', field: 'jenis_aset_id', summaryKey: 'jenis_aset', label: 'Jenis aset', required: false },
        ],
    },
    {
        resource: 'kondisi-aset',
        nav: 'Kondisi aset',
        title: 'Kondisi aset',
        subtitle: 'Daftar kondisi yang dapat dipilih saat menilai aset.',
        kodeLabel: 'Kode kondisi aset',
        namaLabel: 'Nama kondisi aset',
        singular: 'kondisi aset',
    },
    {
        resource: 'pabrikan-aset',
        nav: 'Pabrikan aset',
        title: 'Pabrikan aset',
        subtitle: 'Daftar pabrikan atau merek pembuat aset.',
        kodeLabel: 'Kode pabrikan aset',
        namaLabel: 'Nama pabrikan aset',
        singular: 'pabrikan aset',
    },
    {
        resource: 'item-checklist-maintenance',
        nav: 'Item checklist maintenance',
        title: 'Item checklist maintenance',
        subtitle: 'Item yang diperiksa saat maintenance aset.',
        kodeLabel: 'Kode item checklist maintenance',
        namaLabel: 'Nama item checklist maintenance',
        singular: 'item checklist maintenance',
    },
    {
        resource: 'analisa-maintenance',
        nav: 'Analisa maintenance',
        title: 'Analisa maintenance',
        subtitle: 'Kesimpulan analisa yang dapat dipilih pada hasil maintenance.',
        kodeLabel: 'Kode analisa maintenance',
        namaLabel: 'Nama analisa maintenance',
        singular: 'analisa maintenance',
    },
    {
        resource: 'tipe-lokasi-aset',
        nav: 'Tipe lokasi aset',
        title: 'Tipe lokasi aset',
        subtitle: 'Tingkatan lokasi yang dipakai tenant, misalnya site, gedung, lantai, atau ruangan.',
        kodeLabel: 'Kode tipe lokasi aset',
        namaLabel: 'Nama tipe lokasi aset',
        singular: 'tipe lokasi aset',
    },
    {
        resource: 'lokasi-aset',
        nav: 'Lokasi aset',
        title: 'Lokasi aset',
        subtitle: 'Susun site, gedung, lantai, ruangan, atau area penyimpanan aset.',
        kodeLabel: 'Kode lokasi aset',
        namaLabel: 'Nama lokasi aset',
        singular: 'lokasi aset',
        parents: [
            { resource: 'lokasi-aset', field: 'parent_id', summaryKey: 'parent', label: 'Lokasi induk', required: false },
            { resource: 'tipe-lokasi-aset', field: 'tipe_lokasi_id', summaryKey: 'tipe_lokasi', label: 'Tipe lokasi', required: false },
        ],
        extraFields: [
            {
                name: 'org_unit_id',
                label: 'ID unit organisasi',
                type: 'text',
                help: 'Opsional. Aset yang ditempatkan di lokasi ini memakai unit tersebut sebagai dimensi keuangannya.',
            },
        ],
    },
    {
        resource: 'tipe-atribut',
        nav: 'Tipe atribut',
        title: 'Tipe atribut',
        subtitle: 'Ciri tambahan aset yang dapat dipasang pada jenis aset, tanpa menambah tingkat klasifikasi.',
        kodeLabel: 'Kode tipe atribut',
        namaLabel: 'Nama tipe atribut',
        singular: 'tipe atribut',
        extraFields: [
            {
                name: 'data_type',
                label: 'Tipe data',
                type: 'select',
                required: true,
                options: [
                    { value: 'text', label: 'Teks' },
                    { value: 'number', label: 'Angka' },
                    { value: 'boolean', label: 'Ya / tidak' },
                    { value: 'date', label: 'Tanggal' },
                    { value: 'fixed_list', label: 'Pilihan dari daftar' },
                    { value: 'value_range', label: 'Angka dalam rentang' },
                ],
            },
            {
                name: 'satuan',
                label: 'Satuan',
                type: 'text',
                help: 'Ditampilkan di belakang isian, misalnya liter atau kg.',
                visibleWhen: (form) => form.data_type === 'number' || form.data_type === 'value_range',
            },
            {
                name: 'min_value',
                label: 'Nilai minimum',
                type: 'number',
                required: true,
                visibleWhen: (form) => form.data_type === 'value_range',
            },
            {
                name: 'max_value',
                label: 'Nilai maksimum',
                type: 'number',
                required: true,
                visibleWhen: (form) => form.data_type === 'value_range',
            },
        ],
    },
    {
        resource: 'buku-penyusutan',
        nav: 'Buku penyusutan',
        title: 'Buku penyusutan',
        subtitle: 'Buku yang melacak nilai aset, misalnya satu komersial dan satu fiskal.',
        kodeLabel: 'Kode buku penyusutan',
        namaLabel: 'Nama buku penyusutan',
        singular: 'buku penyusutan',
        extraFields: [
            {
                name: 'posting_layer',
                label: 'Lapisan pembukuan',
                type: 'select',
                options: [
                    { value: 'current', label: 'Komersial' },
                    { value: 'operations', label: 'Operasional' },
                    { value: 'tax', label: 'Fiskal' },
                    { value: 'none', label: 'Memorandum' },
                ],
            },
            {
                name: 'export_to_backoffice',
                label: 'Kirim ke backoffice',
                type: 'boolean',
                help: 'Matikan untuk buku fiskal agar backoffice tidak menjurnal dua kali untuk aset yang sama.',
            },
            {
                name: 'round_off_depreciation',
                label: 'Kelipatan pembulatan penyusutan',
                type: 'number',
                min: 0,
                step: 0.01,
                help: 'Isi 0 untuk memakai nilai sampai dua desimal. Periode terakhir tetap memakai sisa nilai agar buku aset habis tepat.',
            },
            {
                name: 'depreciation_profile_id',
                label: 'ID profil penyusutan bawaan',
                type: 'text',
                help: 'Dipakai bila baris matriks group tidak menentukan profilnya sendiri.',
            },
            {
                name: 'alternative_profile_id',
                label: 'ID profil pengganti',
                type: 'text',
                help: 'Dipakai saat saldo menurun sudah menghasilkan angka lebih kecil daripada garis lurus sisa umur.',
            },
        ],
    },
    {
        resource: 'profil-penyusutan',
        nav: 'Profil penyusutan',
        title: 'Profil penyusutan',
        subtitle: 'Aturan penyusutan yang dapat dipakai ulang oleh banyak aset.',
        kodeLabel: 'Kode profil penyusutan',
        namaLabel: 'Nama profil penyusutan',
        singular: 'profil penyusutan',
        extraFields: [
            {
                name: 'method',
                label: 'Metode',
                type: 'select',
                required: true,
                options: [
                    { value: 'straight_line', label: 'Garis lurus' },
                    { value: 'straight_line_life_remaining', label: 'Garis lurus sisa umur' },
                    { value: 'reducing_balance', label: 'Saldo menurun' },
                    { value: 'manual', label: 'Jadwal manual' },
                    { value: 'consumption', label: 'Berdasarkan pemakaian' },
                ],
            },
            {
                name: 'frequency',
                label: 'Frekuensi',
                type: 'select',
                required: true,
                options: [
                    { value: 'monthly', label: 'Bulanan' },
                    { value: 'quarterly', label: 'Triwulanan' },
                    { value: 'half_yearly', label: 'Semesteran' },
                    { value: 'yearly', label: 'Tahunan' },
                ],
            },
            {
                name: 'year_basis',
                label: 'Dasar tahun',
                type: 'select',
                required: true,
                options: [
                    { value: 'calendar', label: 'Tahun kalender' },
                    { value: 'fiscal', label: 'Tahun fiskal' },
                ],
            },
            // Field berikut hanya relevan untuk sebagian metode; disembunyikan agar
            // pengguna tidak mengisi nilai yang tidak akan pernah dipakai.
            {
                name: 'useful_life_periods',
                label: 'Masa manfaat (jumlah periode)',
                type: 'number',
                required: true,
                min: 1,
                visibleWhen: (form) => ['straight_line', 'straight_line_life_remaining', 'reducing_balance'].includes(String(form.method)),
            },
            {
                name: 'rate_percent',
                label: 'Persentase per tahun',
                type: 'number',
                required: true,
                min: 0,
                step: 0.0001,
                suffix: '%',
                visibleWhen: (form) => form.method === 'reducing_balance',
            },
            {
                name: 'manual_schedule',
                label: 'Jadwal manual',
                type: 'textarea',
                required: true,
                placeholder: '1000000, 900000, 800000',
                help: 'Nilai penyusutan tiap periode, dipisah koma atau baris baru.',
                visibleWhen: (form) => form.method === 'manual',
                fromRecord: (raw) => Array.isArray(raw)
                    ? raw.map((row) => String((row as { amount?: unknown }).amount ?? '')).join(', ')
                    : '',
                toPayload: (value: FieldValue) => {
                    const amounts = String(value ?? '')
                        .split(/[\n,]/)
                        .map((part) => part.trim())
                        .filter((part) => part !== '');

                    return amounts.length ? amounts.map((amount) => ({ amount: Number(amount) })) : null;
                },
            },
            {
                name: 'convention',
                label: 'Konvensi',
                type: 'text',
                help: 'Belum dipakai perhitungan; akan diaktifkan bersama buku penyusutan.',
            },
        ],
    },
];

export function parentSummaryOf(record: MasterRecord, parent: MasterParentConfig): ParentSummary | null {
    const summary = record[parent.summaryKey];

    return summary && typeof summary === 'object' ? (summary as ParentSummary) : null;
}

export function parentIdOf(record: MasterRecord, parent: MasterParentConfig): string {
    const value = record[parent.field];

    return typeof value === 'string' ? value : '';
}
