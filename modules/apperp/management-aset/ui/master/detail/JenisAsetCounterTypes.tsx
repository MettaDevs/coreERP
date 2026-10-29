import { useEffect, useState } from 'react';

import { Empty, EmptyDescription } from '@apperp/ui/empty';
import { FormControl } from '@apperp/ui/form-control';
import { TransferList } from '@apperp/ui/transfer-list';
import type { TransferListItem } from '@apperp/ui/transfer-list';
import { api, errorMessage } from '../../api';
import type { ApiValidationErrors } from '../../api';
import type { MasterDetailsPayload } from '../DraftMasterDetails';

type Choice = { id: string; kode: string; nama: string; satuan: string | null };

const itemOf = (item: Choice): TransferListItem => ({
    id: item.id,
    label: item.satuan ? `${item.nama} (${item.satuan})` : item.nama,
    description: item.kode,
});

/**
 * Counter yang boleh dibaca pada aset jenis ini; padanan FastTab Counters pada Asset types F&O.
 *
 * Counter yang tidak dikaitkan ke jenis aset mana pun tetap berlaku untuk semua jenis aset, jadi
 * daftar kosong di sini bukan berarti aset jenis ini tidak dapat dibaca counternya.
 */
export default function JenisAsetCounterTypes({
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
                    name === `details.counters.${field}` ||
                    name.startsWith(`details.counters.${field}.`),
            )
            .flatMap(([, messages]) => messages)
            .join(' ') || undefined;
    const [error, setError] = useState('');

    useEffect(() => {
        let dilepas = false;

        api<{ data: { remaining: Choice[]; selected: Choice[] } }>(
            `/jenis-aset/${jenisAsetId}/counter`,
        )
            .then((result) => {
                if (dilepas) {
                    return;
                }

                setRemaining(result.data.remaining.map(itemOf));
                setSelected(result.data.selected.map(itemOf));
            })
            .catch((caught) => {
                if (!dilepas) {
                    setError(
                        errorMessage(
                            caught,
                            'Daftar counter belum dapat dimuat.',
                        ),
                    );
                }
            })
            .finally(() => {
                if (!dilepas) {
                    setLoading(false);
                }
            });

        return () => {
            dilepas = true;
        };
    }, [jenisAsetId]);

    if (loading) {
        return <p className="text-muted-foreground text-sm">Memuat counter…</p>;
    }

    if (error && remaining.length === 0 && selected.length === 0) {
        return <p className="text-destructive text-sm">{error}</p>;
    }

    if (!canEdit && selected.length === 0) {
        return (
            <Empty>
                <EmptyDescription>
                    Belum ada counter yang dikaitkan khusus ke jenis aset ini.
                    Counter yang tidak dikaitkan ke jenis mana pun tetap dapat
                    dibaca pada asetnya.
                </EmptyDescription>
            </Empty>
        );
    }

    return (
        <div className="space-y-4">
            <p className="text-muted-foreground text-sm">
                Pilih counter yang dibaca pada aset jenis ini. Counter yang
                tidak dikaitkan ke jenis aset mana pun berlaku untuk semua jenis
                aset.
            </p>
            <FormControl
                data-error-field="details.counters.jenis_counter_ids"
                error={fieldError('jenis_counter_ids')}
            >
                <TransferList
                    remaining={remaining}
                    selected={selected}
                    onChange={(next) => {
                        setRemaining(next.remaining);
                        setSelected(next.selected);
                        onChange({
                            counters: {
                                jenis_counter_ids: next.selected.map(
                                    (item) => item.id,
                                ),
                            },
                        });
                    }}
                    remainingTitle="Counter tersisa"
                    selectedTitle="Counter terpilih"
                    disabled={!canEdit}
                    remainingEmptyLabel="Semua counter sudah terpilih."
                    selectedEmptyLabel="Belum ada counter yang dipilih."
                />
            </FormControl>
            {error && <p className="text-destructive text-sm">{error}</p>}
        </div>
    );
}
