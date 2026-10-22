import { useEffect, useState } from 'react';
import { ActionButton } from '@apperp/ui/action-button';

import { DialogToolbar } from '@apperp/ui/dialog';
import { Empty, EmptyDescription } from '@apperp/ui/empty';
import { FormControl } from '@apperp/ui/form-control';
import { TransferList } from '@apperp/ui/transfer-list';
import type { TransferListItem } from '@apperp/ui/transfer-list';
import { api, errorMessage } from '../../api';
import { useSaveFeedback } from '../../use-save-feedback';

type Choice = { id: string; kode: string; nama: string; aktif: boolean };

const itemOf = (item: Choice): TransferListItem => ({
    id: item.id,
    label: item.aktif ? item.nama : `${item.nama} (Tidak aktif)`,
    description: item.kode,
});

export default function AssetTypeConditions({
    assetTypeId,
    canEdit,
    version,
    onVersionChange,
}: {
    assetTypeId: string;
    canEdit: boolean;
    /** Versi record pemilik; penyimpanan rincian ini mengklaimnya. */
    version: number;
    onVersionChange: (version: number) => void;
}) {
    const [remaining, setRemaining] = useState<TransferListItem[]>([]);
    const [selected, setSelected] = useState<TransferListItem[]>([]);
    const [loading, setLoading] = useState(true);
    const [saving, setSaving] = useState(false);
    const {
        scopeRef: feedbackScopeRef,
        feedbackSummary,
        reportSaveError,
        clearSaveFeedback,
        fieldError,
    } = useSaveFeedback(undefined, assetTypeId);
    const [error, setError] = useState('');
    const [saved, setSaved] = useState(false);

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

    async function save() {
        clearSaveFeedback();
        setSaving(true);
        setError('');

        try {
            const result = await api<{ version: number }>(
                `/jenis-aset/${assetTypeId}/conditions`,
                {
                    method: 'PUT',
                    body: JSON.stringify({
                        version,
                        condition_ids: selected.map((item) => item.id),
                    }),
                },
            );
            onVersionChange(result.version);
            setSaved(true);
        } catch (caught) {
            reportSaveError(caught, 'Kondisi belum dapat disimpan.');
        } finally {
            setSaving(false);
        }
    }

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
        <div ref={feedbackScopeRef} className="space-y-4">
            {feedbackSummary}
            {canEdit && (
                <DialogToolbar className="px-0">
                    <ActionButton
                        action="save"
                        type="button"
                        disabled={saving}
                        onClick={() => void save()}
                        pending={saving}
                    />
                </DialogToolbar>
            )}
            <p className="text-muted-foreground text-sm">
                Pilih kondisi yang boleh dipakai pada aset jenis ini. Jika belum
                ada yang dipilih, semua kondisi aktif dapat dipakai.
            </p>
            <FormControl
                data-error-field="condition_ids"
                error={fieldError('condition_ids')}
            >
                <TransferList
                    remaining={remaining}
                    selected={selected}
                    onChange={(next) => {
                        setRemaining(next.remaining);
                        setSelected(next.selected);
                        setSaved(false);
                    }}
                    remainingTitle="Kondisi tersisa"
                    selectedTitle="Kondisi terpilih"
                    disabled={!canEdit || saving}
                    remainingEmptyLabel="Semua kondisi sudah terpilih."
                    selectedEmptyLabel="Belum ada kondisi yang dipilih."
                />
            </FormControl>

            {saved && (
                <p className="text-muted-foreground text-sm">
                    Kondisi jenis aset tersimpan.
                </p>
            )}
            {error && <p className="text-destructive text-sm">{error}</p>}
        </div>
    );
}
