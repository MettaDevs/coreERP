import { useEffect, useRef, useState } from 'react';
import { ActionButton } from '@apperp/ui/action-button';

import { FormControl } from '@apperp/ui/form-control';
import { Input } from '@apperp/ui/input';
import { api, errorMessage } from '../api';
import type { ApiValidationErrors } from '../api';
import type { MasterDetailsPayload } from './DraftMasterDetails';

type Row = { nilai: string };

/**
 * Values untuk atribut teks. Daftar kosong berarti teks bebas; daftar berisi berarti
 * pengguna memilih dari dropdown.
 */
export default function TipeAtributNilai({
    tipeAtributId,
    canEdit,
    onChange,
    validationErrors,
}: {
    tipeAtributId: string;
    canEdit: boolean;
    onChange: (details: MasterDetailsPayload) => void;
    validationErrors?: ApiValidationErrors;
}) {
    const [rows, setRows] = useState<Row[]>([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState('');
    const fieldError = (field: string) =>
        Object.entries(validationErrors ?? {})
            .filter(
                ([name]) =>
                    name === `details.values.${field}` ||
                    name.startsWith(`details.values.${field}.`),
            )
            .flatMap(([, messages]) => messages)
            .join(' ') || undefined;
    const containerRef = useRef<HTMLDivElement>(null);

    useEffect(() => {
        let cancelled = false;
        api<{ data: { nilai: string }[] }>(
            `/tipe-atribut/${tipeAtributId}/nilai`,
        )
            .then((result) => {
                if (!cancelled) {
                    setRows(
                        result.data.map((row) => ({
                            nilai: String(row.nilai ?? ''),
                        })),
                    );
                }
            })
            .catch((caught) => {
                if (!cancelled) {
                    setError(
                        errorMessage(
                            caught,
                            'Pilihan nilai belum dapat dimuat.',
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
    }, [tipeAtributId]);

    const valueField = (index: number) =>
        rows[index]?.nilai.trim()
            ? `rows.${rows.slice(0, index).filter((item) => item.nilai.trim()).length}.nilai`
            : `empty-values.${index}`;

    function changeRows(change: Row[] | ((current: Row[]) => Row[])) {
        const next = typeof change === 'function' ? change(rows) : change;
        setRows(next);
        onChange({
            values: {
                rows: next
                    .filter((row) => row.nilai.trim() !== '')
                    .map((row, index) => ({
                        nilai: row.nilai.trim(),
                        urutan: index,
                    })),
            },
        });
    }

    if (loading) {
        return <p className="text-muted-foreground text-sm">Memuat rincian�</p>;
    }

    if (error) {
        return <p className="text-destructive text-sm">{error}</p>;
    }

    return (
        <div
            ref={(node) => {
                containerRef.current = node;
            }}
            className="space-y-3 border-t px-5 py-4"
        >
            <div>
                <p className="font-semibold">Pilihan nilai</p>
                <p className="text-muted-foreground text-sm">
                    Tanpa pilihan, pengguna dapat mengetik bebas. Menambahkan
                    pilihan pertama mengubah isian aset menjadi dropdown.
                </p>
            </div>

            {rows.length === 0 && (
                <p className="text-muted-foreground text-sm">
                    Belum ada pilihan. Atribut ini saat ini memakai teks bebas.
                </p>
            )}

            {rows.map((row, index) => (
                <div key={index} className="flex items-end gap-2">
                    <FormControl
                        className="flex-1"
                        data-error-field={`details.values.${valueField(index)}`}
                        error={fieldError(valueField(index))}
                    >
                        <Input
                            id={`nilai-${index}`}
                            label={`Pilihan ${index + 1}`}
                            maxLength={150}
                            disabled={!canEdit}
                            value={row.nilai}
                            onChange={(event) =>
                                changeRows((current) =>
                                    current.map((item, i) =>
                                        i === index
                                            ? { nilai: event.target.value }
                                            : item,
                                    ),
                                )
                            }
                            aria-invalid={!!fieldError(valueField(index))}
                        />
                    </FormControl>
                    {canEdit && (
                        <ActionButton
                            action="delete"
                            type="button"
                            onClick={() =>
                                changeRows(rows.filter((_, i) => i !== index))
                            }
                        />
                    )}
                </div>
            ))}

            {error && <p className="text-destructive text-sm">{error}</p>}

            {canEdit && (
                <div className="flex gap-2">
                    <ActionButton
                        action="add"
                        type="button"
                        onClick={() => changeRows([...rows, { nilai: '' }])}
                    />
                </div>
            )}
        </div>
    );
}
