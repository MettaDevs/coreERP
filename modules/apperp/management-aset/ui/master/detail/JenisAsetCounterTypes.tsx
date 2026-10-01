import { useEffect, useState } from 'react';
import { Button } from '@apperp/ui/button';
import { Empty, EmptyDescription } from '@apperp/ui/empty';
import { TransferList } from '@apperp/ui/transfer-list';
import type { TransferListItem } from '@apperp/ui/transfer-list';
import { api, errorMessage } from '../../api';

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
    const [error, setError] = useState('');
    const [saved, setSaved] = useState(false);

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

    async function save() {
        setSaving(true);
        setError('');

        try {
            const result = await api<{ version: number }>(
                `/jenis-aset/${jenisAsetId}/counter`,
                {
                    method: 'PUT',
                    body: JSON.stringify({
                        version,
                        jenis_counter_ids: selected.map((item) => item.id),
                    }),
                },
            );
            onVersionChange(result.version);
            setSaved(true);
        } catch (caught) {
            setError(errorMessage(caught, 'Counter belum dapat disimpan.'));
        } finally {
            setSaving(false);
        }
    }

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
            <TransferList
                remaining={remaining}
                selected={selected}
                onChange={(next) => {
                    setRemaining(next.remaining);
                    setSelected(next.selected);
                    setSaved(false);
                }}
                remainingTitle="Counter tersisa"
                selectedTitle="Counter terpilih"
                disabled={!canEdit || saving}
                remainingEmptyLabel="Semua counter sudah terpilih."
                selectedEmptyLabel="Belum ada counter yang dipilih."
            />
            {canEdit && (
                <Button
                    type="button"
                    disabled={saving}
                    onClick={() => void save()}
                >
                    {saving ? 'Menyimpan…' : 'Simpan counter'}
                </Button>
            )}
            {saved && (
                <p className="text-muted-foreground text-sm">
                    Counter jenis aset tersimpan.
                </p>
            )}
            {error && <p className="text-destructive text-sm">{error}</p>}
        </div>
    );
}
