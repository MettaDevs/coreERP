import { useEffect, useState } from 'react';

import { Field, FieldLabel } from '@apperp/ui/field';
import { FormControl } from '@apperp/ui/form-control';
import { Switch } from '@apperp/ui/switch';
import { TransferList } from '@apperp/ui/transfer-list';
import type { TransferListItem } from '@apperp/ui/transfer-list';
import { api, errorMessage } from '../api';
import type { ApiValidationErrors } from '../api';
import type { MasterDetailsPayload } from './DraftMasterDetails';
import { useMasterOptions } from './useMasterOptions';
import type { MasterOption } from './useMasterOptions';

type Row = { tipe_atribut_id: string; wajib: boolean };

/**
 * Atribut yang menempel pada satu jenis aset, disunting di panel detail jenis aset.
 *
 * Inilah pengganti sub-klasifikasi: pembeda yang hanya dimiliki sebagian client
 * ditambahkan sebagai atribut pada jenisnya, bukan sebagai tingkat klasifikasi baru yang
 * memaksa seluruh client mengisinya. Aset mewarisi daftar ini dari jenis yang dipilih.
 *
 * Pasang/lepasnya lewat `TransferList` (tersedia/terpasang); "wajib diisi" adalah sifat
 * pemasangan itu sendiri (satu komponen atribut aset boleh wajib pada satu jenis aset dan opsional
 * pada jenis lain) sehingga tidak muat pada `TransferList` yang generik, dan disunting
 * lewat daftar terpisah di bawahnya.
 */
export default function JenisAsetAtribut({
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
    const [rows, setRows] = useState<Row[]>([]);
    const source = useMasterOptions(
        'tipe-atribut',
        '',
        rows.map((row) => row.tipe_atribut_id),
    );
    const types = source.options;
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState('');
    const fieldError = (field: string) =>
        Object.entries(validationErrors ?? {})
            .filter(
                ([name]) =>
                    name === `details.attributes.${field}` ||
                    name.startsWith(`details.attributes.${field}.`),
            )
            .flatMap(([, messages]) => messages)
            .join(' ') || undefined;

    useEffect(() => {
        let cancelled = false;
        api<{ data: Record<string, unknown>[] }>(
            `/jenis-aset/${jenisAsetId}/atribut`,
        )
            .then((assigned) => {
                if (cancelled) {
                    return;
                }

                setRows(
                    assigned.data.map((row) => ({
                        tipe_atribut_id: String(row.tipe_atribut_id ?? ''),
                        wajib: Boolean(row.wajib),
                    })),
                );
            })
            .catch((caught) => {
                if (!cancelled) {
                    setError(
                        errorMessage(
                            caught,
                            'Atribut jenis aset belum dapat dimuat.',
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
        label: 'Komponen Atribut Aset tidak dikenal',
    });
    const itemById = (id: string) => {
        const type = types.find((item) => item.id === id);

        return type ? itemOf(type) : unknown(id);
    };

    const remaining: TransferListItem[] = types
        .filter((type) => source.selectProps.visibleValues.includes(type.id))
        .filter((type) => !rows.some((row) => row.tipe_atribut_id === type.id))
        .map(itemOf);
    const selected: TransferListItem[] = rows.map((row) =>
        itemById(row.tipe_atribut_id),
    );

    function changeRows(change: Row[] | ((current: Row[]) => Row[])) {
        const next = typeof change === 'function' ? change(rows) : change;
        setRows(next);
        onChange({
            attributes: {
                rows: next.map((row, index) => ({ ...row, urutan: index })),
            },
        });
    }

    function applyTransfer(next: { selected: TransferListItem[] }) {
        // Baris yang sudah ada mempertahankan `wajib`-nya; baris baru dari sisi kanan
        // mulai sebagai opsional, sama seperti default sebelumnya.
        changeRows(
            next.selected.map(
                (item) =>
                    rows.find((row) => row.tipe_atribut_id === item.id) ?? {
                        tipe_atribut_id: item.id,
                        wajib: false,
                    },
            ),
        );
    }

    if (loading) {
        return <p className="text-muted-foreground text-sm">Memuat rincian�</p>;
    }

    if (error) {
        return <p className="text-destructive text-sm">{error}</p>;
    }

    return (
        <div className="space-y-4">
            <p className="text-muted-foreground text-sm">
                Aset yang memakai jenis ini akan diminta mengisi atribut yang
                terpasang di sebelah kanan saat diterima.
            </p>

            <FormControl
                data-error-field="details.attributes.rows"
                error={fieldError('rows')}
            >
                <TransferList
                    incremental={source.selectProps.incremental}
                    searchValue={source.selectProps.searchValue}
                    onSearchChange={source.selectProps.onSearchChange}
                    remaining={remaining}
                    selected={selected}
                    onChange={applyTransfer}
                    remainingTitle="Komponen Atribut Aset tersedia"
                    selectedTitle="Komponen Atribut Aset terpasang"
                    disabled={!canEdit}
                    remainingEmptyLabel="Semua komponen atribut aset sudah terpasang."
                    selectedEmptyLabel="Belum ada komponen atribut aset yang dipasang."
                />
            </FormControl>

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
                                    changeRows((current) =>
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
        </div>
    );
}
