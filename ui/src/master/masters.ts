export type MasterResource =
    | 'entitas-aset'
    | 'group-aset'
    | 'kategori-aset'
    | 'jenis-aset'
    | 'kondisi-aset'
    | 'pabrikan-aset'
    | 'item-checklist-maintenance'
    | 'analisa-maintenance'
    | 'lokasi-aset';

export type MasterAction = 'read' | 'create' | 'update' | 'archive';

export type Permission =
    | `management-aset.${MasterResource}.${MasterAction}`
    | `management-aset.aset.${'read' | 'create' | 'mutate'}`
    | 'management-aset.mutasi-aset.read'
    | `management-aset.${'perencanaan-aset' | 'permintaan-pembelian-aset' | 'pemeliharaan-aset' | 'penjualan-aset' | 'pemusnahan-aset'}.${'read' | 'create'}`
    | 'management-aset.monitoring-aset.read'
    | `management-aset.profil-penyusutan.${'read' | 'create'}`
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

/** Induk pada rantai klasifikasi aset: group -> kategori -> jenis -> entitas. */
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
    parent?: MasterParentConfig;
};

export const MASTERS: MasterConfig[] = [
    {
        resource: 'entitas-aset',
        nav: 'Entitas aset',
        title: 'Entitas aset',
        subtitle: 'Daftar aset yang dapat dipilih saat membuat transaksi.',
        kodeLabel: 'Kode entitas aset',
        namaLabel: 'Nama entitas aset',
        singular: 'entitas aset',
        parent: { resource: 'jenis-aset', field: 'jenis_aset_id', summaryKey: 'jenis_aset', label: 'Jenis aset' },
    },
    {
        resource: 'group-aset',
        nav: 'Group aset',
        title: 'Group aset',
        subtitle: 'Kelompok besar untuk mengelompokkan entitas aset.',
        kodeLabel: 'Kode group aset',
        namaLabel: 'Nama group aset',
        singular: 'group aset',
    },
    {
        resource: 'kategori-aset',
        nav: 'Kategori aset',
        title: 'Kategori aset',
        subtitle: 'Pembagian kategori di dalam satu group aset.',
        kodeLabel: 'Kode kategori aset',
        namaLabel: 'Nama kategori aset',
        singular: 'kategori aset',
        parent: { resource: 'group-aset', field: 'group_aset_id', summaryKey: 'group_aset', label: 'Group aset' },
    },
    {
        resource: 'jenis-aset',
        nav: 'Jenis aset',
        title: 'Jenis aset',
        subtitle: 'Jenis paling rinci di dalam satu kategori aset.',
        kodeLabel: 'Kode jenis aset',
        namaLabel: 'Nama jenis aset',
        singular: 'jenis aset',
        parent: { resource: 'kategori-aset', field: 'kategori_aset_id', summaryKey: 'kategori_aset', label: 'Kategori aset' },
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
        resource: 'lokasi-aset',
        nav: 'Lokasi aset',
        title: 'Lokasi aset',
        subtitle: 'Susun site, gedung, lantai, ruangan, atau area penyimpanan aset.',
        kodeLabel: 'Kode lokasi aset',
        namaLabel: 'Nama lokasi aset',
        singular: 'lokasi aset',
        parent: { resource: 'lokasi-aset', field: 'parent_id', summaryKey: 'parent', label: 'Lokasi induk', required: false },
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
