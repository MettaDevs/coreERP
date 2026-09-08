import { FieldConfig, FieldValue } from './fields';

export type MasterResource =
    | 'group-aset'
    | 'jenis-aset'
    | 'model-aset'
    | 'kondisi-aset'
    | 'pabrikan-aset'
    | 'item-checklist-maintenance'
    | 'analisa-maintenance'
    | 'maintenance-job-types'
    | 'maintenance-job-type-variants'
    | 'maintenance-job-type-defaults'
    | 'maintenance-checklist-variables'
    | 'maintenance-checklist-templates'
    | 'tipe-work-order'
    | 'tingkat-layanan'
    | 'trade'
    | 'sebab-kerusakan'
    | 'tindakan-perbaikan'
    | 'tipe-lokasi-aset'
    | 'lokasi-aset'
    | 'tipe-atribut'
    | 'buku-penyusutan'
    | 'profil-penyusutan';

export type MasterAction = 'read' | 'create' | 'update' | 'archive';

export type Permission =
    | `management-aset.${MasterResource}.${MasterAction}`
    | `management-aset.aset.${'read' | 'create' | 'update' | 'mutate'}`
    | 'management-aset.mutasi-aset.read'
    | `management-aset.${'perencanaan-aset' | 'permintaan-pembelian-aset' | 'penjualan-aset' | 'pemusnahan-aset'}.${'read' | 'create'}`
    // Work order memisahkan menyusun, menjadwalkan, mengerjakan, dan menutup supaya
    // ketiganya dapat diberikan kepada orang yang berbeda.
    | `management-aset.pemeliharaan-aset.${'read' | 'create' | 'update' | 'archive' | 'schedule' | 'execute' | 'close'}`
    | `management-aset.validasi-status-work-order.${'read' | 'update'}`
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
    showInNavigation?: boolean;
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
                name: 'kelompok_harta_fiskal_id',
                label: 'Kelompok harta fiskal',
                type: 'reference',
                resource: 'reference-data/kelompok-harta-fiskal',
                help: 'Menentukan aturan fiskal dan masa manfaat yang dipakai group ini. Versi aturan baru dapat ditambahkan tanpa mengubah form.',
            },
            {
                name: 'property_type',
                label: 'Perlakuan pencatatan',
                type: 'select',
                options: [
                    { value: 'fixed_asset', label: 'Aset tetap (masuk neraca)' },
                    { value: 'inventory_item', label: 'Barang inventaris (tidak masuk neraca)' },
                    { value: 'other', label: 'Lainnya' },
                ],
                help: 'Barang inventaris tetap dicatat dan dilacak, tetapi tidak disajikan sebagai aset tetap di neraca.',
            },
            {
                name: 'capitalization_threshold',
                label: 'Ambang kapitalisasi',
                type: 'number',
                min: 0,
                step: 0.01,
                help: 'Perolehan di bawah nilai ini tetap dicatat sebagai aset, tetapi bukunya tidak menyusut.',
            },
            {
                name: 'asset_location_id',
                label: 'Lokasi bawaan',
                type: 'reference',
                resource: 'lokasi-aset',
                help: 'Mengisi lokasi saat aset diterima. Hanya nilai awal; lokasi aset dapat diubah setelahnya tanpa menyentuh group.',
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
        showInNavigation: false,
        title: 'Model aset',
        subtitle: 'Katalog model barang per pabrikan yang dapat dipilih saat menerima aset.',
        kodeLabel: 'Kode model aset',
        namaLabel: 'Nama model aset',
        singular: 'model aset',
        parents: [
            {
                resource: 'pabrikan-aset',
                field: 'pabrikan_aset_id',
                summaryKey: 'pabrikan_aset',
                label: 'Pabrikan aset',
            },
            {
                resource: 'jenis-aset',
                field: 'jenis_aset_id',
                summaryKey: 'jenis_aset',
                label: 'Jenis aset',
                required: false,
            },
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
        nav: 'Pabrikan dan model',
        title: 'Pabrikan dan model',
        subtitle: 'Daftar pabrikan dan model aset yang dapat dipilih saat menerima aset.',
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
        resource: 'maintenance-job-types',
        nav: 'Jenis pekerjaan maintenance',
        title: 'Jenis pekerjaan maintenance',
        subtitle:
            'Atur pekerjaan, varian, persyaratan, dan jenis aset yang menggunakan maintenance.',
        kodeLabel: 'Kode jenis pekerjaan',
        namaLabel: 'Nama jenis pekerjaan',
        singular: 'jenis pekerjaan maintenance',
        extraFields: [
            {
                name: 'category_code',
                label: 'Kategori pekerjaan',
                type: 'select',
                required: true,
                options: [
                    { value: 'preventive', label: 'Preventif' },
                    { value: 'corrective', label: 'Korektif' },
                    { value: 'service', label: 'Servis' },
                    { value: 'condition_assessment', label: 'Pemeriksaan kondisi' },
                ],
            },
            {
                name: 'maintenance_downtime_activities',
                label: 'Aktivitas downtime maintenance',
                type: 'boolean',
            },
        ],
    },
    {
        resource: 'maintenance-job-type-variants',
        nav: 'Varian job type',
        showInNavigation: false,
        title: 'Varian jenis pekerjaan maintenance',
        subtitle: 'Pilihan interval atau varian dari jenis pekerjaan maintenance.',
        kodeLabel: 'Kode varian',
        namaLabel: 'Nama varian',
        singular: 'varian job type',
        parents: [
            {
                resource: 'maintenance-job-types',
                field: 'maintenance_job_type_id',
                summaryKey: 'maintenance_job_type',
                label: 'Jenis pekerjaan maintenance',
            },
        ],
    },
    {
        resource: 'maintenance-job-type-defaults',
        nav: 'Default job type',
        title: 'Default jenis pekerjaan maintenance',
        subtitle: 'Nilai bawaan yang dapat dipakai saat menyiapkan pekerjaan maintenance.',
        kodeLabel: 'Kode default',
        namaLabel: 'Nama default',
        singular: 'default job type',
        parents: [
            {
                resource: 'maintenance-job-types',
                field: 'maintenance_job_type_id',
                summaryKey: 'maintenance_job_type',
                label: 'Jenis pekerjaan maintenance',
            },
            {
                resource: 'maintenance-job-type-variants',
                field: 'variant_id',
                summaryKey: 'variant',
                label: 'Varian job type',
                required: false,
            },
            {
                resource: 'maintenance-checklist-templates',
                field: 'checklist_template_id',
                summaryKey: 'checklist_template',
                label: 'Template checklist',
                required: false,
            },
        ],
        extraFields: [
            {
                name: 'trade',
                label: 'Trade',
                type: 'select',
                options: [
                    { value: 'Mekanik', label: 'Mekanik' },
                    { value: 'Elektrik', label: 'Elektrik' },
                    { value: 'HVAC', label: 'HVAC' },
                    { value: 'Teknisi umum', label: 'Teknisi umum' },
                ],
            },
            {
                name: 'functional_location_id',
                label: 'Functional location',
                type: 'reference',
                resource: 'lokasi-aset',
            },
            {
                name: 'jenis_aset_id',
                label: 'Jenis aset',
                type: 'reference',
                resource: 'jenis-aset',
            },
            {
                name: 'pabrikan_aset_id',
                label: 'Pabrikan',
                type: 'reference',
                resource: 'pabrikan-aset',
            },
            { name: 'model_aset_id', label: 'Model', type: 'reference', resource: 'model-aset' },
            { name: 'asset_id', label: 'Aset', type: 'reference', resource: 'aset' },
            { name: 'hours', label: 'Jam kerja', type: 'number', min: 0, step: 0.01 },
            { name: 'items_count', label: 'Items', type: 'number', min: 0, step: 1 },
            { name: 'expenses_count', label: 'Expenses', type: 'number', min: 0, step: 1 },
            { name: 'fees_count', label: 'Fees', type: 'number', min: 0, step: 1 },
        ],
    },
    {
        resource: 'maintenance-checklist-variables',
        nav: 'Variabel checklist',
        title: 'Variabel checklist maintenance',
        subtitle: 'Buat pilihan nilai yang dapat dipakai pada baris checklist.',
        kodeLabel: 'Kode variabel',
        namaLabel: 'Nama variabel',
        singular: 'variabel checklist',
    },
    {
        resource: 'maintenance-checklist-templates',
        nav: 'Template checklist',
        title: 'Template checklist maintenance',
        subtitle: 'Susun baris pemeriksaan yang akan diisi saat maintenance.',
        kodeLabel: 'Kode template',
        namaLabel: 'Nama template',
        singular: 'template checklist',
    },
    {
        resource: 'tipe-work-order',
        nav: 'Tipe work order',
        title: 'Tipe work order',
        subtitle:
            'Tipe pekerjaan dan batasan penugasannya. Aturan isi data diatur pada validasi status work order.',
        kodeLabel: 'Kode tipe work order',
        namaLabel: 'Nama tipe work order',
        singular: 'tipe work order',
        extraFields: [
            {
                name: 'satu_pekerja',
                label: 'Hanya satu pelaksana',
                type: 'boolean',
                help: 'Seluruh baris pekerjaan pada work order tipe ini harus ditugaskan ke orang yang sama.',
            },
        ],
    },
    {
        resource: 'tingkat-layanan',
        nav: 'Tingkat layanan',
        title: 'Tingkat layanan',
        subtitle: 'Urgensi penanganan yang dapat dipilih pada work order.',
        kodeLabel: 'Kode tingkat layanan',
        namaLabel: 'Nama tingkat layanan',
        singular: 'tingkat layanan',
        extraFields: [
            {
                name: 'urutan',
                label: 'Urutan urgensi',
                type: 'number',
                min: 0,
                step: 1,
                help: 'Angka lebih kecil berarti lebih mendesak. Hanya mengurutkan daftar; tidak menghitung tenggat.',
            },
        ],
    },
    {
        resource: 'trade',
        nav: 'Bidang keahlian',
        title: 'Bidang keahlian',
        subtitle:
            'Keahlian yang dibutuhkan sebuah pekerjaan, misalnya mekanik, elektrik, atau HVAC.',
        kodeLabel: 'Kode bidang keahlian',
        namaLabel: 'Nama bidang keahlian',
        singular: 'bidang keahlian',
    },
    {
        resource: 'sebab-kerusakan',
        nav: 'Sebab kerusakan',
        title: 'Sebab kerusakan',
        subtitle: 'Akar sebab yang dapat dipilih saat pekerjaan maintenance ditutup.',
        kodeLabel: 'Kode sebab kerusakan',
        namaLabel: 'Nama sebab kerusakan',
        singular: 'sebab kerusakan',
        extraFields: [
            {
                name: 'minta_keterangan',
                label: 'Minta keterangan saat dipilih',
                type: 'boolean',
                help: 'Aktifkan untuk pilihan seperti “Lainnya”, agar mekanik wajib menjelaskan sebabnya.',
            },
        ],
    },
    {
        resource: 'tindakan-perbaikan',
        nav: 'Tindakan perbaikan',
        title: 'Tindakan perbaikan',
        subtitle:
            'Perbaikan yang dikerjakan, dicatat terpisah dari sebabnya agar keduanya dapat dihitung.',
        kodeLabel: 'Kode tindakan perbaikan',
        namaLabel: 'Nama tindakan perbaikan',
        singular: 'tindakan perbaikan',
        extraFields: [
            {
                name: 'minta_keterangan',
                label: 'Minta keterangan saat dipilih',
                type: 'boolean',
                help: 'Aktifkan untuk pilihan seperti “Lainnya”, agar mekanik wajib menjelaskan tindakan yang dilakukan.',
            },
        ],
    },
    {
        resource: 'tipe-lokasi-aset',
        nav: 'Tipe lokasi aset',
        title: 'Tipe lokasi aset',
        subtitle:
            'Tingkatan lokasi yang dipakai tenant, misalnya site, gedung, lantai, atau ruangan.',
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
            {
                resource: 'lokasi-aset',
                field: 'parent_id',
                summaryKey: 'parent',
                label: 'Lokasi induk',
                required: false,
            },
            {
                resource: 'tipe-lokasi-aset',
                field: 'tipe_lokasi_id',
                summaryKey: 'tipe_lokasi',
                label: 'Tipe lokasi',
                required: false,
            },
        ],
    },
    {
        resource: 'tipe-atribut',
        nav: 'Tipe atribut',
        title: 'Tipe atribut',
        subtitle:
            'Ciri tambahan aset yang dapat dipasang pada jenis aset, tanpa menambah tingkat klasifikasi.',
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
                    { value: 'string', label: 'Teks' },
                    { value: 'decimal', label: 'Desimal' },
                    { value: 'integer', label: 'Bilangan bulat' },
                    { value: 'date', label: 'Tanggal' },
                    { value: 'boolean', label: 'Ya/tidak' },
                ],
                help: 'Tipe data tidak dapat diubah lagi setelah atribut pertama kali diisi pada aset.',
            },
            {
                name: 'satuan_id',
                label: 'Satuan',
                type: 'reference',
                resource: 'reference-data/units-of-measure',
                help: 'Diambil dari daftar satuan Core, bukan diketik, supaya "cm" berarti hal yang sama di seluruh aplikasi. Ditampilkan di belakang isian saat aset diterima.',
                visibleWhen: (form) => form.data_type === 'decimal' || form.data_type === 'integer',
            },
            {
                name: 'min_value',
                label: 'Nilai minimum',
                type: 'number',
                step: 0.000001,
                help: 'Isi bersama nilai maksimum untuk membatasi isian. Kosongkan keduanya bila angka tidak perlu dibatasi.',
                visibleWhen: (form) => form.data_type === 'decimal' || form.data_type === 'integer',
            },
            {
                name: 'max_value',
                label: 'Nilai maksimum',
                type: 'number',
                step: 0.000001,
                help: 'Isi bersama nilai minimum untuk membatasi isian. Kosongkan keduanya bila angka tidak perlu dibatasi.',
                visibleWhen: (form) => form.data_type === 'decimal' || form.data_type === 'integer',
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
                label: 'Lapisan posting',
                type: 'select',
                options: [
                    { value: 'current', label: 'Komersial' },
                    { value: 'operations', label: 'Operasional' },
                    { value: 'tax', label: 'Fiskal' },
                    { value: 'none', label: 'Memorandum' },
                ],
                help: 'Buku komersial mengikuti kebijakan akuntansi tenant/legal entity. Buku fiskal memakai referensi pajak yang berversi; metode dan masa manfaat keduanya boleh berbeda.',
            },
            {
                name: 'export_to_backoffice',
                label: 'Ekspor ke Finance',
                type: 'boolean',
                help: 'Saat ini hanya menyiapkan bridge tanpa jurnal. Biarkan mati sampai kontrak posting dan kepemilikan COA Finance tersedia.',
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
                label: 'Profil utama',
                type: 'reference',
                resource: 'profil-penyusutan',
                help: 'Dipakai bila baris matriks group tidak menentukan profil khusus.',
            },
            {
                name: 'alternative_profile_id',
                label: 'Profil pengganti (opsional)',
                type: 'reference',
                resource: 'profil-penyusutan',
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
            {
                name: 'effective_from',
                label: 'Berlaku mulai',
                type: 'date',
                help: 'Kosong berarti profil tidak dibatasi dari tanggal awal.',
            },
            {
                name: 'effective_to',
                label: 'Berlaku sampai',
                type: 'date',
                help: 'Kosong berarti profil tetap berlaku sampai diganti versi baru.',
            },
            // Field berikut hanya relevan untuk sebagian metode; disembunyikan agar
            // pengguna tidak mengisi nilai yang tidak akan pernah dipakai.
            {
                name: 'useful_life_periods',
                label: 'Masa manfaat (jumlah periode)',
                type: 'number',
                required: true,
                min: 1,
                visibleWhen: (form) =>
                    ['straight_line', 'straight_line_life_remaining', 'reducing_balance'].includes(
                        String(form.method),
                    ),
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
                fromRecord: (raw) =>
                    Array.isArray(raw)
                        ? raw
                              .map((row) => String((row as { amount?: unknown }).amount ?? ''))
                              .join(', ')
                        : '',
                toPayload: (value: FieldValue) => {
                    const amounts = String(value ?? '')
                        .split(/[\n,]/)
                        .map((part) => part.trim())
                        .filter((part) => part !== '');

                    return amounts.length
                        ? amounts.map((amount) => ({ amount: Number(amount) }))
                        : null;
                },
            },
            // Konvensi sengaja tidak ada di sini. Perlakuan periode pertama diambil dari
            // baris matriks group x buku, karena satu profil yang sama dipakai banyak
            // group dengan tanggal mulai yang berbeda. Menyediakannya juga di profil
            // berarti dua tempat mengaku menentukan hal yang sama, dan yang di profil
            // tidak pernah dibaca perhitungan.
        ],
    },
];

export function parentSummaryOf(
    record: MasterRecord,
    parent: MasterParentConfig,
): ParentSummary | null {
    const summary = record[parent.summaryKey];

    return summary && typeof summary === 'object' ? (summary as ParentSummary) : null;
}

export function parentIdOf(record: MasterRecord, parent: MasterParentConfig): string {
    const value = record[parent.field];

    return typeof value === 'string' ? value : '';
}
