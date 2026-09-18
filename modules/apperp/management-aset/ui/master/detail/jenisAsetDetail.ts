export type JenisAsetModelSummary = {
    id: string;
    manufacturer: string | null;
    model: string;
    model_number: string | null;
    description: string | null;
};

export type JenisAsetDetail = {
    atribut_count: number | null;
    model_count: number | null;
    aset_count: number | null;
    maintenance_job_type_count: number | null;
    models: JenisAsetModelSummary[] | null;
    available_models: JenisAsetModelSummary[] | null;
};
