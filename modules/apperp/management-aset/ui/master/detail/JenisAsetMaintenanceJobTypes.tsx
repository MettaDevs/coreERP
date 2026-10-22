import { useEffect, useState } from 'react';
import { ActionButton } from '@apperp/ui/action-button';

import { DialogToolbar } from '@apperp/ui/dialog';
import { Empty, EmptyDescription } from '@apperp/ui/empty';
import { FormControl } from '@apperp/ui/form-control';
import { TransferList } from '@apperp/ui/transfer-list';
import type { TransferListItem } from '@apperp/ui/transfer-list';
import { api, errorMessage } from '../../api';
import { useSaveFeedback } from '../../use-save-feedback';

type Choice = { id: string; kode: string; nama: string };
const itemOf = (item: Choice): TransferListItem => ({
    id: item.id,
    label: item.nama,
    description: item.kode,
});

export default function JenisAsetMaintenanceJobTypes({
    jenisAsetId,
    canEdit,
    version,
    onVersionChange,
}: {
    jenisAsetId: string;
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
    } = useSaveFeedback(undefined, jenisAsetId);
    const [error, setError] = useState('');
    const [saved, setSaved] = useState(false);

    // Pengambilan data lahir di dalam effect: state baru disetel setelah jawaban server
    // tiba, bukan pada commit render yang sama. Tidak ada `setLoading(true)` di awal —
    // penandanya sudah bernilai benar sejak state pertama, dan RecordDetailPane memasang
    // `key` sehingga ganti jenis aset memasang ulang panel ini dari keadaan bersih.
    useEffect(() => {
        let dilepas = false;

        const muat = async () => {
            try {
                const result = await api<{
                    data: { remaining: Choice[]; selected: Choice[] };
                }>(`/jenis-aset/${jenisAsetId}/maintenance-job-types`);

                if (dilepas) {
                    return;
                }

                setRemaining(result.data.remaining.map(itemOf));
                setSelected(result.data.selected.map(itemOf));
            } catch (caught) {
                if (dilepas) {
                    return;
                }

                setError(
                    errorMessage(
                        caught,
                        'Daftar jenis pekerjaan maintenance belum dapat dimuat.',
                    ),
                );
            } finally {
                if (!dilepas) {
                    setLoading(false);
                }
            }
        };

        void muat();

        return () => {
            dilepas = true;
        };
    }, [jenisAsetId]);

    async function save() {
        clearSaveFeedback();
        setSaving(true);
        setError('');

        try {
            const result = await api<{ version: number }>(
                `/jenis-aset/${jenisAsetId}/maintenance-job-types`,
                {
                    method: 'PUT',
                    body: JSON.stringify({
                        version,
                        jenis_aset_ids: selected.map((item) => item.id),
                    }),
                },
            );
            onVersionChange(result.version);
            setSaved(true);
        } catch (caught) {
            reportSaveError(caught, 'Relasi maintenance belum dapat disimpan.');
        } finally {
            setSaving(false);
        }
    }

    if (loading) {
        return (
            <p className="text-muted-foreground text-sm">
                Memuat jenis pekerjaan maintenance…
            </p>
        );
    }

    if (error) {
        return <p className="text-destructive text-sm">{error}</p>;
    }

    if (!canEdit && selected.length === 0) {
        return (
            <Empty>
                <EmptyDescription>
                    Belum ada jenis pekerjaan maintenance yang dikaitkan.
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
                Pilih jenis pekerjaan yang dapat dipakai oleh jenis aset ini.
            </p>
            <FormControl
                data-error-field="jenis_aset_ids"
                error={fieldError('jenis_aset_ids')}
            >
                <TransferList
                    remaining={remaining}
                    selected={selected}
                    onChange={(next) => {
                        setRemaining(next.remaining);
                        setSelected(next.selected);
                        setSaved(false);
                    }}
                    remainingTitle="Jenis pekerjaan tersisa"
                    selectedTitle="Jenis pekerjaan terpilih"
                    disabled={!canEdit || saving}
                    remainingEmptyLabel="Semua pekerjaan sudah terpilih."
                    selectedEmptyLabel="Belum ada pekerjaan yang dipilih."
                />
            </FormControl>

            {saved && (
                <p className="text-muted-foreground text-sm">
                    Relasi jenis pekerjaan tersimpan.
                </p>
            )}
        </div>
    );
}
