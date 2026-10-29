export type PlanningAssetType = { id: string; kode: string; nama: string };

export type PlanningDetail = {
    id?: string;
    nama_aset?: string;
    jenis_aset_kode?: string;
    jenis_aset_nama?: string;
    unit?: string;
    jenis_aset_id: string;
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
        jenis_aset_id: '',
        satuan_id: '',
        quantity: '1',
        requested_specification: '',
        needed_on: null,
        funding_source_id: null,
        estimated_unit_price: null,
    };
}

function isBlankPlanningLine(line: PlanningDraftDetail): boolean {
    return (
        !line.id &&
        !line.jenis_aset_id &&
        !line.satuan_id &&
        line.quantity === '1' &&
        !line.requested_specification.trim() &&
        !line.needed_on &&
        !line.funding_source_id &&
        (line.estimated_unit_price === null || line.estimated_unit_price === '')
    );
}

export function appendPlanningTypes(
    lines: PlanningDraftDetail[],
    selected: PlanningAssetType[],
    createLine: () => PlanningDraftDetail,
): PlanningDraftDetail[] {
    const choices = [
        ...new Map(selected.map((type) => [type.id, type])).values(),
    ];

    if (!choices.length) {
        return lines;
    }

    const blankIndex = lines.findIndex(isBlankPlanningLine);
    const next = [...lines];
    choices.forEach((type, index) => {
        const line =
            index === 0 && blankIndex !== -1 ? next[blankIndex] : createLine();
        const filled = {
            ...line,
            jenis_aset_id: type.id,
            jenis_aset_kode: type.kode,
            jenis_aset_nama: type.nama,
            nama_aset: type.nama,
        };

        if (index === 0 && blankIndex !== -1) {
            next[blankIndex] = filled;
        } else {
            next.push(filled);
        }
    });

    return next;
}
