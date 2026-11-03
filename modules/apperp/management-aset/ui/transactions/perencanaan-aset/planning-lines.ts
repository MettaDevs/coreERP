export type PlanningDetail = {
    id?: string;
    nama_aset?: string;
    jenis_aset_kode?: string;
    jenis_aset_nama?: string;
    unit?: string;
    planned_asset_id: string;
    planned_asset_code?: string | null;
    satuan_id: string;
    quantity: string;
    requested_specification: string;
    needed_on: string | null;
    funding_source_id: string | null;
    funding_source_name?: string | null;
    estimated_unit_price: string | null;
};

export type PlanningDraftDetail = PlanningDetail & { lineKey: string };

export function createPlanningLine(lineKey: string): PlanningDraftDetail {
    return {
        lineKey,
        planned_asset_id: '',
        satuan_id: '',
        quantity: '1',
        requested_specification: '',
        needed_on: null,
        funding_source_id: null,
        estimated_unit_price: null,
    };
}

export function clearPlanningAssets(
    lines: PlanningDraftDetail[],
): PlanningDraftDetail[] {
    return lines.map((line) => ({
        ...line,
        planned_asset_id: '',
        planned_asset_code: null,
        nama_aset: undefined,
    }));
}
