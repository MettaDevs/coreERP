/** Bentuk data layar downtime dan KPI pemeliharaan, sama dengan kontraknya. */
export type Downtime = {
    id: string;
    aset_id: string;
    aset_kode: string;
    aset_nama: string | null;
    mulai: string;
    selesai: string | null;
    alasan_downtime_id: string | null;
    alasan_downtime_nama: string | null;
    masuk_kpi: boolean;
    pemeliharaan_aset_kode: string | null;
    permintaan_pemeliharaan_kode: string | null;
    sumber: 'manual' | 'work_order';
    keterangan: string | null;
    durasi_jam: number;
    terbuka: boolean;
    version: number;
};

export type KpiFigures = {
    jumlah_aset: number;
    total_jam: number;
    downtime_jam: number;
    uptime_jam: number;
    availability_persen: number | null;
    jumlah_henti: number;
    jumlah_kerusakan: number;
    mtbf_jam: number;
    jam_perbaikan: number;
    mttr_jam: number;
    wo_selesai: number;
};

export type KpiRow = KpiFigures & {
    kunci: string | null;
    kode: string | null;
    nama: string | null;
};
