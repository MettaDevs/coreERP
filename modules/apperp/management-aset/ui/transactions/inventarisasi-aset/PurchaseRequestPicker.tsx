import { useCallback, useEffect, useRef, useState } from 'react';
import type { RefObject } from 'react';
import { ActionButton } from '@apperp/ui/action-button';
import { Button } from '@apperp/ui/button';
import { DataTable } from '@apperp/ui/data-table';
import type { DataTableColumn } from '@apperp/ui/data-table';
import { InlineLookup } from '@apperp/ui/inline-lookup';
import type { LookupLoader } from '@apperp/ui/inline-lookup';
import {
    PageDialog,
    PageDialogContent,
    PageDialogToolbar,
    PageDialogBody,
} from '@apperp/ui/page-dialog';
import { api, errorMessage } from '../../api';
import PurchaseRequestPage from '../permintaan-pembelian-aset/PurchaseRequestPage';
import type {
    PurchaseRequest,
    RequestLine,
} from '../permintaan-pembelian-aset/PurchaseRequestPage';
import type { BarisPenerimaan } from './penerimaan';
import { availableRequestLines } from './receipt-request-lines';

type RequestOption = Pick<PurchaseRequest, 'id' | 'kode' | 'description'>;
const DIRECT_RECEIPT: RequestOption = {
    id: '',
    kode: 'Tanpa permintaan asal',
    description: null,
};
const lineColumns: DataTableColumn<RequestLine>[] = [
    {
        id: 'name',
        header: 'Barang',
        width: 240,
        cell: (line) => line.nama_aset,
    },
    {
        id: 'quantity',
        header: 'Diminta',
        width: 100,
        cell: (line) => line.quantity,
    },
    {
        id: 'received',
        header: 'Diterima',
        width: 100,
        cell: (line) => line.received_quantity ?? 0,
    },
    {
        id: 'remaining',
        header: 'Sisa',
        width: 100,
        cell: (line) => line.remaining_quantity ?? 0,
    },
];

export default function PurchaseRequestPicker({
    companyId,
    orgUnitId,
    permissions,
    initialRequestId,
    value,
    rows,
    rowIndex,
    readOnly,
    portalContainer,
    onChange,
    onSelectLines,
}: {
    companyId?: string;
    orgUnitId?: string | null;
    permissions: string[];
    initialRequestId?: string | null;
    value: string;
    rows: BarisPenerimaan[];
    rowIndex: number;
    readOnly: boolean;
    portalContainer: RefObject<HTMLDivElement | null>;
    onChange: (
        line: RequestLine | null,
        request: PurchaseRequest | null,
    ) => void;
    onSelectLines: (request: PurchaseRequest, ids: string[]) => void;
}) {
    const [requestId, setRequestId] = useState(initialRequestId ?? '');
    const [selected, setSelected] = useState<PurchaseRequest | null>(null);
    const [requestOption, setRequestOption] = useState<RequestOption | null>(
        null,
    );
    const [loadError, setLoadError] = useState<{
        scope: string;
        message: string;
    } | null>(null);
    const [reload, setReload] = useState(0);
    const [loadedScope, setLoadedScope] = useState('');
    const scope = `${companyId ?? ''}:${requestId}`;
    const error = loadError?.scope === scope ? loadError.message : '';
    const [batchOpen, setBatchOpen] = useState(false);
    const [selectedKeys, setSelectedKeys] = useState<string[]>([]);
    const [creating, setCreating] = useState(false);
    const batchRef = useRef<HTMLDivElement>(null);
    const canCreate =
        permissions.includes(
            'management-aset.permintaan-pembelian-aset.create',
        ) && Boolean(companyId && orgUnitId);
    const canRead = permissions.includes(
        'management-aset.permintaan-pembelian-aset.read',
    );

    const busy = Boolean(requestId && canRead && loadedScope !== scope);

    const loadItems = useCallback<LookupLoader<RequestOption>>(
        async ({ query, cursor, signal }) => {
            if (!companyId || !canRead) {
                return { items: [DIRECT_RECEIPT], nextCursor: null };
            }

            const params = new URLSearchParams({
                exclude_cancelled: '1',
                legal_entity_id: companyId,
                q: query,
            });

            if (cursor) {
                params.set('cursor', cursor);
            }

            const result = await api<{
                data: PurchaseRequest[];
                next_cursor: string | null;
            }>(`/permintaan-pembelian-aset?${params}`, { signal });

            return {
                items: [
                    ...(!cursor && !query ? [DIRECT_RECEIPT] : []),
                    ...result.data,
                ],
                nextCursor: result.next_cursor,
            };
        },
        [companyId, canRead],
    );

    useEffect(() => {
        if (!requestId || !canRead) {
            return;
        }

        const controller = new AbortController();
        api<{ data: PurchaseRequest }>(
            `/permintaan-pembelian-aset/${requestId}`,
            { signal: controller.signal },
        )
            .then(({ data }) => {
                if (controller.signal.aborted) {
                    return;
                }

                if (data.legal_entity_id !== companyId) {
                    throw new Error('Permintaan berasal dari perusahaan lain.');
                }

                setSelected(data);
                setRequestOption(data);
            })
            .catch((caught) => {
                if (!controller.signal.aborted) {
                    setLoadError({
                        scope: `${companyId ?? ''}:${requestId}`,
                        message: errorMessage(
                            caught,
                            'Baris permintaan belum dapat dimuat.',
                        ),
                    });
                }
            })
            .finally(() => {
                if (!controller.signal.aborted) {
                    setLoadedScope(`${companyId ?? ''}:${requestId}`);
                }
            });

        return () => controller.abort();
    }, [requestId, companyId, canRead, reload]);
    const request =
        selected?.id === requestId && selected.legal_entity_id === companyId
            ? selected
            : null;
    const available = request
        ? availableRequestLines(request, rows, rowIndex)
        : [];
    const batchLines = available.filter(
        (line) =>
            !rows.some((row) => row.permintaan_pembelian_detail_id === line.id),
    );
    const chooseRequest = (option: RequestOption) => {
        if (option.id === requestId) {
            return;
        }

        setRequestId(option.id);
        setRequestOption(option);
        setSelected(null);
        setLoadedScope('');
        setLoadError(null);
        setSelectedKeys([]);
        onChange(null, null);
    };

    return (
        <div className="space-y-3">
            <InlineLookup<RequestOption>
                label="No. permintaan pembelian"
                value={
                    !requestId
                        ? DIRECT_RECEIPT
                        : requestOption?.id === requestId
                          ? requestOption
                          : null
                }
                displayValue={
                    requestId
                        ? (rows[rowIndex]?.purchase_request_code ?? '')
                        : ''
                }
                loadItems={loadItems}
                columns={[
                    {
                        id: 'code',
                        header: 'Nomor',
                        width: 112,
                        cell: (row) => (row.id ? row.kode : '—'),
                    },
                    {
                        id: 'description',
                        header: 'Keterangan',
                        width: 240,
                        cell: (row) =>
                            row.id ? (row.description ?? '—') : row.kode,
                    },
                ]}
                getItemValue={(row) => row.id}
                getItemLabel={(row) => row.kode}
                getSearchText={(row) => `${row.kode} ${row.description ?? ''}`}
                onValueChange={chooseRequest}
                disabled={readOnly || !companyId}
                portalContainer={portalContainer}
                showDetails={false}
                onCreate={
                    !readOnly && canCreate && canRead
                        ? () => setCreating(true)
                        : undefined
                }
                emptyMessage="Tidak ada permintaan yang tersedia."
            />
            {request && (
                <>
                    <InlineLookup<RequestLine>
                        label="Barang yang diminta"
                        value={
                            request.details?.find(
                                (line) => line.id === value,
                            ) ?? null
                        }
                        items={available}
                        columns={lineColumns}
                        getItemValue={(line) => line.id ?? ''}
                        getItemLabel={(line) => line.nama_aset}
                        getSearchText={(line) =>
                            `${line.nama_aset} ${line.specification}`
                        }
                        onValueChange={(line) => onChange(line, request)}
                        disabled={readOnly || busy}
                        portalContainer={portalContainer}
                        showDetails={false}
                        showFullList={false}
                        emptyMessage="Tidak ada barang tersisa yang dapat diterima."
                    />
                    {!readOnly && batchLines.length > 1 && (
                        <Button
                            type="button"
                            variant="ghost"
                            disabled={busy || batchLines.length === 0}
                            onClick={() => {
                                setSelectedKeys([]);
                                setBatchOpen(true);
                            }}
                        >
                            Pilih beberapa barang…
                        </Button>
                    )}
                </>
            )}
            {busy && (
                <p role="status" className="text-muted-foreground text-sm">
                    Memuat barang yang diminta…
                </p>
            )}
            {error && (
                <p role="alert" className="text-destructive text-sm">
                    {error}
                    <Button
                        type="button"
                        variant="ghost"
                        onClick={() => {
                            setLoadError(null);
                            setLoadedScope('');
                            setReload((value) => value + 1);
                        }}
                    >
                        Coba lagi
                    </Button>
                </p>
            )}
            <PageDialog open={batchOpen} onOpenChange={setBatchOpen}>
                <PageDialogContent
                    ref={batchRef}
                    title="Pilih barang permintaan"
                    recordTitle={request?.kode}
                >
                    <PageDialogToolbar>
                        <ActionButton
                            action="add"
                            type="button"
                            disabled={
                                readOnly ||
                                selectedKeys.length === 0 ||
                                !request
                            }
                            onClick={() => {
                                if (!request || readOnly) {
                                    return;
                                }

                                onSelectLines(request, selectedKeys);
                                setBatchOpen(false);
                            }}
                        />
                        <ActionButton
                            action="cancel"
                            type="button"
                            onClick={() => setBatchOpen(false)}
                        />
                    </PageDialogToolbar>
                    <PageDialogBody>
                        <p className="text-muted-foreground mb-3 text-sm">
                            Centang barang yang diterima. Centang pada kepala
                            tabel memilih semua barang yang masih tersedia.
                        </p>
                        <DataTable
                            data={batchLines}
                            columns={lineColumns}
                            getRowKey={(line) => line.id ?? ''}
                            getRowLabel={(line) => line.nama_aset}
                            scrollMode="inline"
                            selection={{
                                selectedKeys,
                                onSelectedKeysChange: (keys) =>
                                    setSelectedKeys(keys.map(String)),
                            }}
                            emptyMessage="Tidak ada barang tersisa yang dapat diterima."
                        />
                    </PageDialogBody>
                </PageDialogContent>
            </PageDialog>
            {creating && canCreate && (
                <PurchaseRequestPage
                    context={{
                        legal_entity_id: companyId ?? null,
                        org_unit_id: orgUnitId ?? null,
                    }}
                    permissions={permissions}
                    createCard={{
                        onClose: () => setCreating(false),
                        onCreated: (created) => {
                            setCreating(false);
                            chooseRequest(created);
                        },
                    }}
                />
            )}
        </div>
    );
}
