import { Select } from '@apperp/ui/select';
import { useEffect, useState } from 'react';
import type { RefObject } from 'react';
import { api, errorMessage } from '../../api';
import { useRecordOptions } from '../../use-record-list';
import type {
    PurchaseRequest,
    RequestLine,
} from '../permintaan-pembelian-aset/PurchaseRequestPage';

// Nilai UI saja; rujukan yang dikirim ke dokumen tetap kosong/null.
const DIRECT_RECEIPT = '__direct_receipt__';

export default function PurchaseRequestPicker({
    companyId,
    initialRequestId,
    value,
    readOnly,
    portalContainer,
    onChange,
}: {
    companyId?: string;
    initialRequestId?: string | null;
    value: string;
    readOnly: boolean;
    portalContainer: RefObject<HTMLDivElement | null>;
    onChange: (line: RequestLine | null) => void;
}) {
    const [requestId, setRequestId] = useState<string | null>(
        initialRequestId ?? null,
    );
    const [selected, setSelected] = useState<PurchaseRequest | null>(null);
    const [error, setError] = useState('');
    const [busy, setBusy] = useState(false);

    const source = useRecordOptions<PurchaseRequest>(
        `/permintaan-pembelian-aset?exclude_cancelled=1&legal_entity_id=${encodeURIComponent(companyId ?? '')}`,
        (item) => item.kode,
        requestId ?? '',
    );
    const requests = source.options;

    useEffect(() => {
        if (!requestId) {
            return;
        }

        let cancelled = false;
        api<{ data: PurchaseRequest }>(
            `/permintaan-pembelian-aset/${requestId}`,
        )
            .then((result) => {
                if (!cancelled) {
                    setSelected(result.data);
                    setError('');
                }
            })
            .catch((caught) => {
                if (!cancelled) {
                    setError(
                        errorMessage(
                            caught,
                            'Baris permintaan belum dapat dimuat.',
                        ),
                    );
                }
            })
            .finally(() => {
                if (!cancelled) {
                    setBusy(false);
                }
            });

        return () => {
            cancelled = true;
        };
    }, [requestId]);

    return (
        <fieldset className="space-y-2" disabled={readOnly || busy}>
            <Select
                {...source.selectProps}
                label="No. permintaan pembelian"
                items={[
                    { value: DIRECT_RECEIPT, label: 'Tanpa permintaan asal' },
                    ...requests.map((item) => ({
                        value: item.id,
                        label: item.kode,
                    })),
                ]}
                value={requestId ?? DIRECT_RECEIPT}
                portalContainer={portalContainer}
                placeholder="Pilih permintaan"
                emptyMessage="Tidak ada permintaan yang tersedia."
                onValueChange={(id) => {
                    const nextId = id === DIRECT_RECEIPT ? null : id;
                    setRequestId(nextId);
                    setSelected(null);
                    setBusy(Boolean(nextId));
                    onChange(null);
                }}
            />
            {selected && (
                <Select
                    label="Barang yang diminta"
                    items={(selected.details ?? [])
                        .filter(
                            (line) =>
                                Number(line.remaining_quantity) > 0 ||
                                line.id === value,
                        )
                        .map((line) => ({
                            value: line.id!,
                            label: `${line.nama_aset} · baris ${(selected.details?.indexOf(line) ?? 0) + 1} · diminta ${line.quantity} · diterima ${line.received_quantity} · sisa ${line.remaining_quantity}`,
                        }))}
                    value={value || null}
                    portalContainer={portalContainer}
                    placeholder="Pilih barang"
                    emptyMessage="Semua barang pada permintaan ini sudah diterima."
                    onValueChange={(id) =>
                        onChange(
                            selected.details?.find((line) => line.id === id) ??
                                null,
                        )
                    }
                />
            )}
            {error && (
                <p role="alert" className="text-destructive text-sm">
                    {error}
                </p>
            )}
        </fieldset>
    );
}
