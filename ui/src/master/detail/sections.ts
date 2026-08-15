import { FieldConfig, FieldValue } from '../fields';
import { MasterConfig } from '../masters';

/**
 * Satu seksi lipat pada panel detail. Pembagiannya milik tampilan, bukan milik API:
 * field yang sama tetap dikirim dalam satu payload.
 */
export type DetailSection = {
    id: string;
    title: string;
    fields: FieldConfig[];
    /** Seksi ini memuat matriks buku penyusutan, bukan field biasa. */
    books?: boolean;
    /** Seksi ini memuat kotak angka bawaan (Attributes/Models/Assets/…), bukan field biasa. */
    counters?: boolean;
    /** Seksi ini memuat daftar atribut jenis aset, bukan field biasa. */
    attributes?: boolean;
    /** Seksi ini memuat model yang dikaitkan dengan jenis aset, bukan field biasa. */
    models?: boolean;
    /** Seksi ringkasan khusus pabrikan dan jumlah turunan yang boleh dibaca. */
    manufacturerDetails?: boolean;
    /** Seksi grid model yang dimiliki pabrikan. */
    manufacturerModels?: boolean;
    /** Seksi setup job type maintenance, varian, requirement, dan relasi jenis aset. */
    maintenanceJobType?: boolean;
    /** Seksi nilai variabel checklist. */
    checklistVariableValues?: boolean;
    /** Seksi baris template checklist. */
    checklistTemplateLines?: boolean;
    /** Seksi relasi jenis pekerjaan maintenance pada jenis aset. */
    maintenanceJobTypes?: boolean;
    /** Seksi ini belum punya tabel/relasi pendukung; tampil sebagai penjelasan kosong. */
    placeholder?: string;
    defaultOpen?: boolean;
};

/**
 * `keterangan` dan `aktif` melekat pada setiap master dan tidak pernah dideklarasikan di
 * `extraFields`. Di sini keduanya dibungkus sebagai field biasa supaya seluruh panel
 * detail berjalan lewat satu jalur render, termasuk perlakuan mode bacanya.
 */
const KETERANGAN: FieldConfig = { name: 'keterangan', label: 'Keterangan', type: 'textarea' };
const AKTIF: FieldConfig = { name: 'aktif', label: 'Data aktif dan dapat dipilih', type: 'boolean' };

export function sectionsFor(config: MasterConfig): DetailSection[] {
    const byName = new Map((config.extraFields ?? []).map((field) => [field.name, field]));
    const pick = (...names: string[]) =>
        names.map((name) => byName.get(name)).filter((field): field is FieldConfig => Boolean(field));

    if (config.resource === 'group-aset') {
        return [
            {
                id: 'umum',
                title: 'Umum',
                defaultOpen: true,
                fields: pick('kelompok_harta_fiskal_id', 'property_type', 'capitalization_threshold'),
            },
            {
                id: 'bawaan',
                title: 'Bawaan dan pembukuan',
                defaultOpen: true,
                fields: pick('asset_location_id', 'posting_layers'),
            },
            { id: 'buku', title: 'Buku penyusutan', books: true, fields: [] },
            { id: 'lain', title: 'Lain-lain', fields: [KETERANGAN, AKTIF] },
        ];
    }

    if (config.resource === 'jenis-aset') {
        return [
            { id: 'umum', title: 'Umum', defaultOpen: true, counters: true, fields: [] },
            {
                id: 'job-maintenance',
                title: 'Jenis pekerjaan maintenance',
                fields: [],
                maintenanceJobTypes: true,
            },
            {
                id: 'counter-aset',
                title: 'Counter aset',
                fields: [],
                placeholder: 'Counter aset (misalnya jam pakai atau suhu) belum dapat dipasang di sini. Fitur ini menyusul setelah master counter aset dan relasinya ke jenis aset dibangun.',
            },
            { id: 'atribut', title: 'Tipe atribut', attributes: true, fields: [] },
            { id: 'models', title: 'Pabrikan dan model', models: true, fields: [] },
            {
                id: 'kondisi-aset',
                title: 'Kondisi aset',
                fields: [],
                placeholder: 'Templat kondisi belum dapat dibatasi per jenis aset di sini. Kondisi aset yang ada masih berlaku untuk seluruh jenis; pembatasan per jenis menyusul saat relasinya dibangun.',
            },
            { id: 'lain', title: 'Lain-lain', fields: [KETERANGAN, AKTIF] },
        ];
    }

    if (config.resource === 'maintenance-job-types') {
        return [
            { id: 'details', title: 'Details', defaultOpen: true, maintenanceJobType: true, fields: [] },
            { id: 'general', title: 'General', defaultOpen: true, fields: pick('category_code', 'maintenance_downtime_activities') },
            { id: 'description', title: 'Description', defaultOpen: true, fields: [KETERANGAN] },
            { id: 'status', title: 'Status', fields: [AKTIF] },
        ];
    }

    if (config.resource === 'maintenance-checklist-variables') {
        return [
            { id: 'values', title: 'General', defaultOpen: true, checklistVariableValues: true, fields: [] },
            { id: 'status', title: 'Status', fields: [KETERANGAN, AKTIF] },
        ];
    }

    if (config.resource === 'maintenance-checklist-templates') {
        return [
            { id: 'lines', title: 'Maintenance checklist lines', defaultOpen: true, checklistTemplateLines: true, fields: [] },
            { id: 'status', title: 'Status', fields: [KETERANGAN, AKTIF] },
        ];
    }

    if (config.resource === 'pabrikan-aset') {
        return [
            {
                id: 'details',
                title: 'Rincian',
                defaultOpen: true,
                manufacturerDetails: true,
                fields: [KETERANGAN, AKTIF],
            },
            {
                id: 'models',
                title: 'Model',
                defaultOpen: true,
                manufacturerModels: true,
                fields: [],
            },
        ];
    }

    // Master lain belum memakai tata letak ini. Bentuk cadangan ini ada supaya menambah
    // satu master ke daftar tidak langsung membuat halaman kosong.
    return [
        { id: 'umum', title: 'Umum', defaultOpen: true, fields: [...(config.extraFields ?? [])] },
        { id: 'lain', title: 'Lain-lain', fields: [KETERANGAN, AKTIF] },
    ];
}

/**
 * Ringkasan yang tampil di baris judul selagi seksi tertutup.
 *
 * Hanya field yang labelnya dapat diketahui tanpa memuat apa pun yang ikut diringkas.
 * Field `reference` sengaja dilewati: labelnya milik master lain dan baru diketahui
 * setelah pilihannya dimuat, jadi meringkasnya di sini akan menampilkan ULID mentah.
 */
export function summaryFor(section: DetailSection, values: Record<string, FieldValue>): string | null {
    const parts: string[] = [];

    for (const field of section.fields) {
        const value = values[field.name];
        if (value === '' || value === null || value === undefined) continue;

        if (field.type === 'select') {
            const label = field.options?.find((option) => option.value === value)?.label;
            if (label) parts.push(label);
        } else if (field.type === 'multiselect' && Array.isArray(value) && value.length > 0) {
            parts.push(value
                .map((item) => field.options?.find((option) => option.value === item)?.label ?? item)
                .join(', '));
        } else if (field.type === 'boolean') {
            parts.push(value ? 'Aktif' : 'Tidak aktif');
        } else if (field.type === 'number' || field.type === 'text' || field.type === 'date') {
            parts.push(`${field.label}: ${String(value)}`);
        }
    }

    return parts.length > 0 ? parts.join(' · ') : null;
}
