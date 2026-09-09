import { useEffect, useState } from 'react';
import { Button } from '@apperp/ui/button';
import { Field, FieldLabel } from '@apperp/ui/field';
import { Switch } from '@apperp/ui/switch';
import { TransferList, type TransferListItem } from '@apperp/ui/transfer-list';
import { api, errorMessage } from '../api';
import { MasterOption } from './useMasterOptions';

type Row = { tipe_atribut_id: string; wajib: boolean };

/**
 * Atribut yang menempel pada satu jenis aset, disunting di panel detail jenis aset.
 *
 * Inilah pengganti sub-klasifikasi: pembeda yang hanya dimiliki sebagian client
 * ditambahkan sebagai atribut pada jenisnya, bukan sebagai tingkat klasifikasi baru yang
 * memaksa seluruh client mengisinya. Aset mewarisi daftar ini dari jenis yang dipilih.
 *
 * Pasang/lepasnya lewat `TransferList` (tersedia/terpasang); "wajib diisi" adalah sifat
 * pemasangan itu sendiri (satu tipe atribut boleh wajib pada satu jenis aset dan opsional
 * pada jenis lain) sehingga tidak muat pada `TransferList` yang generik, dan disunting
 * lewat daftar terpisah di bawahnya.
 */
export default function JenisAsetAtribut({
    jenisAsetId,
    canEdit,
}: {
    jenisAsetId: string;
    canEdit: boolean;
}) {
    const [types, setTypes] = useState<MasterOption[]>([]);
    const [rows, setRows] = useState<Row[]>([]);
    const [error, setError] = useState('');
    const [saving, setSaving] = useState(false);
    const [saved, setSaved] = useState(false);

    useEffect(() => {
        let cancelled = false;
        Promise.all([
            api<{ data: MasterOption[] }>(
                '/tipe-atribut?per_page=100&aktif=true',
            ),
            api<{ data: Record<string, unknown>[] }>(
                `/jenis-aset/${jenisAsetId}/atribut`,
            ),
        ])
            .then(([typeList, assigned]) => {
                if (cancelled) return;
                setTypes(typeList.data);
                setRows(
                    assigned.data.map((row) => ({
                        tipe_atribut_id: String(row.tipe_atribut_id ?? ''),
                        wajib: Boolean(row.wajib),
                    })),
                );
            })
            .catch((caught) => {
                if (!cancelled)
                    setError(
                        errorMessage(
                            caught,
                            'Atribut jenis aset belum dapat dimuat.',
                        ),
                    );
            });
        return () => {
            cancelled = true;
        };
    }, [jenisAsetId]);

    /**
     * Nama dahulu, lalu satuan, lalu keterangan — urutan yang sama dipakai daftar tipe
     * atribut di F&O. Kode sengaja tidak ikut: yang membedakan "Depth cm" dari "Depth m"
     * adalah satuannya, dan itulah yang perlu terbaca saat memilih, bukan nomor urutnya.
     */
    const itemOf = (type: MasterOption): TransferListItem => {
        const satuan =
            typeof type.satuan === 'string' ? type.satuan.trim() : '';
        const labels: Record<string, string> = {
            string: 'Teks',
            decimal: 'Desimal',
            integer: 'Bilangan bulat',
            date: 'Tanggal',
            boolean: 'Ya/tidak',
        };
        const dataType = String(type.data_type ?? '');
        const values = Number(type.values_count ?? 0);
        const inputMode =
            dataType === 'string'
                ? values > 0
                    ? `${values} pilihan`
                    : 'Teks bebas'
                : '';

        return {
            id: type.id,
            label: type.nama,
            description:
                [labels[dataType] ?? dataType, satuan, inputMode]
                    .filter(Boolean)
                    .join(' · ') || undefined,
        };
    };
    const unknown = (id: string): TransferListItem => ({
        id,
        label: 'Tipe atribut tidak dikenal',
    });
    const itemById = (id: string) => {
        const type = types.find((item) => item.id === id);
        return type ? itemOf(type) : unknown(id);
    };

    const remaining: TransferListItem[] = types
        .filter((type) => !rows.some((row) => row.tipe_atribut_id === type.id))
        .map(itemOf);
    const selected: TransferListItem[] = rows.map((row) =>
        itemById(row.tipe_atribut_id),
    );

    function applyTransfer(next: { selected: TransferListItem[] }) {
        // Baris yang sudah ada mempertahankan `wajib`-nya; baris baru dari sisi kanan
        // mulai sebagai opsional, sama seperti default sebelumnya.
        setRows(
            next.selected.map(
                (item) =>
                    rows.find((row) => row.tipe_atribut_id === item.id) ?? {
                        tipe_atribut_id: item.id,
                        wajib: false,
                    },
            ),
        );
    }

    async function save() {
        setSaving(true);
        setError('');
        setSaved(false);
        try {
            await api(`/jenis-aset/${jenisAsetId}/atribut`, {
                method: 'PUT',
                body: JSON.stringify({
                    rows: rows.map((row, index) => ({ ...row, urutan: index })),
                }),
            });
            setSaved(true);
        } catch (caught) {
            setError(errorMessage(caught, 'Atribut belum dapat disimpan.'));
        } finally {
            setSaving(false);
        }
    }

    return (
        <div className="space-y-4">
            <p className="text-muted-foreground text-sm">
                Aset yang memakai jenis ini akan diminta mengisi atribut yang
                terpasang di sebelah kanan saat diterima.
            </p>

            <TransferList
                remaining={remaining}
                selected={selected}
                onChange={applyTransfer}
                remainingTitle="Tipe atribut tersedia"
                selectedTitle="Tipe atribut terpasang"
                disabled={!canEdit}
                remainingEmptyLabel="Semua tipe atribut sudah terpasang."
                selectedEmptyLabel="Belum ada tipe atribut yang dipasang."
            />

            {rows.length > 0 && (
                <div className="space-y-2">
                    <p className="text-sm font-medium">Wajib diisi</p>
                    {rows.map((row, index) => (
                        <Field
                            key={row.tipe_atribut_id}
                            orientation="horizontal"
                        >
                            <Switch
                                id={`wajib-${row.tipe_atribut_id}`}
                                disabled={!canEdit}
                                checked={row.wajib}
                                onCheckedChange={(checked) =>
                                    setRows((current) =>
                                        current.map((item, i) =>
                                            i === index
                                                ? { ...item, wajib: checked }
                                                : item,
                                        ),
                                    )
                                }
                            />
                            <FieldLabel
                                htmlFor={`wajib-${row.tipe_atribut_id}`}
                            >
                                {itemById(row.tipe_atribut_id).label}
                            </FieldLabel>
                        </Field>
                    ))}
                </div>
            )}

            {error && <p className="text-destructive text-sm">{error}</p>}
            {saved && (
                <p className="text-muted-foreground text-sm">
                    Atribut tersimpan.
                </p>
            )}

            {canEdit && (
                <Button
                    type="button"
                    disabled={saving}
                    onClick={() => void save()}
                >
                    {saving ? 'Menyimpan…' : 'Simpan atribut'}
                </Button>
            )}
        </div>
    );
}
