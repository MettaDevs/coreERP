import { useEffect, useState } from 'react';

import { Empty, EmptyDescription } from '@apperp/ui/empty';
import { FormControl } from '@apperp/ui/form-control';
import { TransferList } from '@apperp/ui/transfer-list';
import type { TransferListItem } from '@apperp/ui/transfer-list';
import { api, errorMessage } from '../../api';
import type { ApiValidationErrors } from '../../api';
import type { MasterDetailsPayload } from '../DraftMasterDetails';

type Choice = { id: string; kode: string; nama: string };
const itemOf = (item: Choice): TransferListItem => ({
    id: item.id,
    label: item.nama,
    description: item.kode,
});

export default function JenisAsetMaintenanceJobTypes({
    jenisAsetId,
    canEdit,
    onChange,
    validationErrors,
}: {
    jenisAsetId: string;
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
                    name === `details.job_types.${field}` ||
                    name.startsWith(`details.job_types.${field}.`),
            )
            .flatMap(([, messages]) => messages)
            .join(' ') || undefined;
    const [error, setError] = useState('');

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
        <div className="space-y-4">
            <p className="text-muted-foreground text-sm">
                Pilih jenis pekerjaan yang dapat dipakai oleh jenis aset ini.
            </p>
            <FormControl
                data-error-field="details.job_types.jenis_aset_ids"
                error={fieldError('jenis_aset_ids')}
            >
                <TransferList
                    remaining={remaining}
                    selected={selected}
                    onChange={(next) => {
                        setRemaining(next.remaining);
                        setSelected(next.selected);
                        onChange({
                            job_types: {
                                jenis_aset_ids: next.selected.map(
                                    (item) => item.id,
                                ),
                            },
                        });
                    }}
                    remainingTitle="Jenis pekerjaan tersisa"
                    selectedTitle="Jenis pekerjaan terpilih"
                    disabled={!canEdit}
                    remainingEmptyLabel="Semua pekerjaan sudah terpilih."
                    selectedEmptyLabel="Belum ada pekerjaan yang dipilih."
                />
            </FormControl>
        </div>
    );
}
