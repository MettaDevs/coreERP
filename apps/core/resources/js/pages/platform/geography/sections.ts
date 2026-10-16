import type { Section } from './types';

export const addressSections: {
    key: Section;
    label: string;
    codeLabel?: string;
}[] = [
    { key: 'parameters', label: 'Komponen alamat' },
    { key: 'addressFormat', label: 'Format alamat' },
    { key: 'countries', label: 'Negara / wilayah', codeLabel: 'Kode negara' },
    { key: 'provinces', label: 'Provinsi', codeLabel: 'Kode provinsi' },
    { key: 'regencies', label: 'Kabupaten', codeLabel: 'Kode kabupaten' },
    { key: 'cities', label: 'Kota', codeLabel: 'Kode kota' },
    { key: 'districts', label: 'Kecamatan', codeLabel: 'Kode kecamatan' },
    { key: 'villages', label: 'Kelurahan / desa', codeLabel: 'Kode wilayah' },
    { key: 'streets', label: 'Jalan', codeLabel: 'RT / RW' },
    {
        key: 'groupOfHouses',
        label: 'Kompleks / perumahan',
        codeLabel: 'Nama kompleks',
    },
    { key: 'landPlots', label: 'Kavling', codeLabel: 'Nomor kavling' },
    { key: 'buildings', label: 'Bangunan', codeLabel: 'Blok / bangunan' },
    { key: 'postalCodes', label: 'Kode pos', codeLabel: 'Kode pos' },
];

export const addressFieldLabels: Record<string, string> = {
    code: 'Kode',
    display_code: 'Kode tampilan',
    iso3: 'Kode ISO tiga huruf',
    name: 'Nama',
    description: 'Keterangan',
    phone_code: 'Kode telepon',
    timezone: 'Zona waktu',
    resolved_timezone: 'Zona waktu yang digunakan',
    country_name: 'Negara / wilayah',
    province_name: 'Provinsi',
    regency_name: 'Kabupaten / kota',
    district_name: 'Kecamatan',
    village_name: 'Kelurahan / desa',
    postal_code: 'Kode pos',
    area_name: 'Nama wilayah',
    type: 'Jenis wilayah',
    rt: 'RT',
    rw: 'RW',
    plot_number: 'Nomor kavling',
    block: 'Blok',
    unit: 'Unit',
    floor: 'Lantai',
    status: 'Status',
    active: 'Aktif',
    source: 'Sumber data',
    source_reference: 'Referensi sumber',
    intrastat: 'Kode Intrastat',
    it_state_code: 'Kode provinsi Italia',
    state_code: 'Kode wilayah',
    it_county_code: 'Kode kabupaten Italia',
    es_county_code: 'Kode kabupaten Spanyol',
    default_state: 'Provinsi utama',
    union_territory: 'Wilayah persatuan',
    override_postal_code: 'Menggunakan kode pos tersendiri',
};

export const addressParameterLabels = {
    use_province: 'Provinsi',
    use_regency: 'Kabupaten / kota',
    use_district: 'Kecamatan',
    use_village: 'Kelurahan / desa',
    use_rt_rw: 'RT / RW',
    use_postal_code: 'Kode pos',
    use_building: 'Bangunan',
} as const;
