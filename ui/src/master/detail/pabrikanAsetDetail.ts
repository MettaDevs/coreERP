export type PabrikanAsetDetail = {
    model_count: number | null;
    asset_count: number | null;
};

export type PabrikanModelRecord = {
    id: string;
    kode: string;
    nama: string;
    keterangan: string | null;
    aktif: boolean;
    pabrikan_aset_id: string;
    jenis_aset_id: string | null;
    jenis_aset: { id: string; kode: string; nama: string } | null;
    asset_count: number | null;
};
