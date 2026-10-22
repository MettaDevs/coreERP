import type {
    PurchaseRequest,
    RequestLine,
} from '../permintaan-pembelian-aset/PurchaseRequestPage';
import type { BarisPenerimaan } from './penerimaan';

export function availableRequestLines(
    request: PurchaseRequest,
    rows: BarisPenerimaan[],
    currentIndex: number,
) {
    const linked = new Set(
        rows
            .filter((_, index) => index !== currentIndex)
            .map((row) => row.permintaan_pembelian_detail_id)
            .filter(Boolean),
    );

    return (request.details ?? []).filter(
        (line) =>
            Boolean(line.id) &&
            !linked.has(line.id ?? '') &&
            Math.floor(Number(line.remaining_quantity)) >= 1,
    );
}

export function requestLinePatch(
    line: RequestLine,
    request: PurchaseRequest,
): Partial<BarisPenerimaan> {
    return {
        permintaan_pembelian_detail_id: line.id ?? '',
        purchase_request_id: request.id,
        purchase_request_code: request.kode,
        nama: line.nama_aset,
        jenis_aset_id: line.jenis_aset_id,
        jumlah: Math.floor(Number(line.remaining_quantity)),
        keterangan: line.specification,
    };
}

export function appendRequestLines(
    rows: BarisPenerimaan[],
    currentIndex: number,
    request: PurchaseRequest,
    selectedIds: string[],
    createBlank: () => BarisPenerimaan,
): BarisPenerimaan[] {
    const selected = new Set(selectedIds);
    const linked = new Set(
        rows.map((row) => row.permintaan_pembelian_detail_id).filter(Boolean),
    );
    const lines = availableRequestLines(request, rows, currentIndex).filter(
        (line) => selected.has(line.id ?? '') && !linked.has(line.id ?? ''),
    );

    if (lines.length === 0) {
        return rows;
    }

    const current = rows[currentIndex];
    const blank = createBlank();
    const untouched =
        current &&
        !current.id &&
        Object.entries(blank).every(
            ([key, value]) =>
                key === 'clientKey' ||
                JSON.stringify(current[key as keyof BarisPenerimaan]) ===
                    JSON.stringify(value),
        );
    const added = lines.map((line) => ({
        ...createBlank(),
        ...requestLinePatch(line, request),
    }));

    return untouched
        ? [
              ...rows.slice(0, currentIndex),
              ...added,
              ...rows.slice(currentIndex + 1),
          ]
        : [...rows, ...added];
}
