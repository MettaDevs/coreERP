import { useEffect, useState } from 'react';

import { Empty, EmptyDescription } from '@apperp/ui/empty';
import { FormControl } from '@apperp/ui/form-control';
import { TransferList } from '@apperp/ui/transfer-list';
import type { TransferListItem } from '@apperp/ui/transfer-list';
import { api, errorMessage } from '../../api';
import type { ApiValidationErrors } from '../../api';
import type { MasterDetailsPayload } from '../DraftMasterDetails';

type Choice = { id: string; kode: string; nama: string; aktif: boolean };

const itemOf = (item: Choice): TransferListItem => ({
    id: item.id,
    label: item.aktif ? item.nama : `${item.nama} (Tidak aktif)`,
    description: item.kode,
});

export default function AssetTypeConditions({
    assetTypeId,
    canEdit,
    onChange,
    validationErrors,
}: {
    assetTypeId: string;
    canEdit: boolean;
    onChange: (details: MasterDetailsPayload) => void;
    validationErrors?: ApiValidationErrors;
}) {
    const [remaining, setRemaining] = useState<TransferListItem[]>([]);
    const [selected, setSelected] = useState<TransferListItem[]>([]);
    const [loading, setLoading] = useState(true);
    const fieldError = (field: string) =>
        Object.entries(validationErrors ?? {})
            .filter(
                ([name]) =>
                    name === `details.conditions.${field}` ||
                    name.startsWith(`details.conditions.${field}.`),
            )
            .flatMap(([, messages]) => messages)
            .join(' ') || undefined;
    const [error, setError] = useState('');

    useEffect(() => {
        let cancelled = false;

        api<{ data: { remaining: Choice[]; selected: Choice[] } }>(
            `/jenis-aset/${assetTypeId}/conditions`,
        )
            .then((result) => {
                if (cancelled) {
                    return;
                }

                setRemaining(result.data.remaining.map(itemOf));
                setSelected(result.data.selected.map(itemOf));
            })
            .catch((caught) => {
                if (!cancelled) {
                    setError(
                        errorMessage(
                            caught,
                            'Daftar kondisi belum dapat dimuat.',
                        ),
                    );
                }
            })
            .finally(() => {
                if (!cancelled) {
                    setLoading(false);
                }
            });

        return () => {
            cancelled = true;
        };
    }, [assetTypeId]);

    if (loading) {
        return <p className="text-muted-foreground text-sm">Memuat kondisi…</p>;
    }

    if (error && remaining.length === 0 && selected.length === 0) {
        return <p className="text-destructive text-sm">{error}</p>;
    }

    if (!canEdit && selected.length === 0) {
        return (
            <Empty>
                <EmptyDescription>
                    Belum ada kondisi yang dikaitkan khusus ke jenis aset ini.
                    Semua kondisi aktif tetap dapat dipilih pada aset jenis ini.
                </EmptyDescription>
            </Empty>
        );
    }

    return (
        <div className="space-y-4">
            <p className="text-muted-foreground text-sm">
                Pilih kondisi yang boleh dipakai pada aset jenis ini. Jika belum
                ada yang dipilih, semua kondisi aktif dapat dipakai.
            </p>
            <FormControl
                data-error-field="details.conditions.condition_ids"
                error={fieldError('condition_ids')}
            >
                <TransferList
                    remaining={remaining}
                    selected={selected}
                    onChange={(next) => {
                        setRemaining(next.remaining);
                        setSelected(next.selected);
                        onChange({
                            conditions: {
                                condition_ids: next.selected.map(
                                    (item) => item.id,
                                ),
                            },
                        });
                    }}
                    remainingTitle="Kondisi tersisa"
                    selectedTitle="Kondisi terpilih"
                    disabled={!canEdit}
                    remainingEmptyLabel="Semua kondisi sudah terpilih."
                    selectedEmptyLabel="Belum ada kondisi yang dipilih."
                />
            </FormControl>
            {error && <p className="text-destructive text-sm">{error}</p>}
        </div>
    );
}
