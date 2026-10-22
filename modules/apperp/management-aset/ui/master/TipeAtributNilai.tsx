import { useEffect, useRef, useState } from 'react';
import { ActionButton } from '@apperp/ui/action-button';

import { FormControl } from '@apperp/ui/form-control';
import { Input } from '@apperp/ui/input';
import { api, errorMessage } from '../api';
import { useSaveFeedback } from '../use-save-feedback';

type Row = { nilai: string };

/**
 * Values untuk atribut teks. Daftar kosong berarti teks bebas; daftar berisi berarti
 * pengguna memilih dari dropdown.
 */
export default function TipeAtributNilai({
    tipeAtributId,
    canEdit,
    version,
    onVersionChange,
}: {
    tipeAtributId: string;
    canEdit: boolean;
    /** Versi record pemilik; penyimpanan rincian ini mengklaimnya. */
    version: number;
    onVersionChange: (version: number) => void;
}) {
    const [rows, setRows] = useState<Row[]>([]);
    const [error, setError] = useState('');
    const [saving, setSaving] = useState(false);
    const {
        scopeRef: feedbackScopeRef,
        feedbackSummary,
        reportSaveError,
        clearSaveFeedback,
        fieldError,
    } = useSaveFeedback(undefined, tipeAtributId);
    const [saved, setSaved] = useState(false);
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
            });

        return () => {
            cancelled = true;
        };
    }, [tipeAtributId]);

    const valueField = (index: number) =>
        rows[index]?.nilai.trim()
            ? `rows.${rows.slice(0, index).filter((item) => item.nilai.trim()).length}.nilai`
            : `empty-values.${index}`;

    async function save() {
        clearSaveFeedback();
        setSaving(true);
        setError('');
        setSaved(false);

        try {
            // Urutan layar menjadi urutan tampil; kirimannya daftar penuh, jadi baris yang
            // dihapus dari layar ikut diarsipkan server.
            const result = await api<{ version: number }>(
                `/tipe-atribut/${tipeAtributId}/nilai`,
                {
                    method: 'PUT',
                    body: JSON.stringify({
                        version,
                        rows: rows
                            .filter((row) => row.nilai.trim() !== '')
                            .map((row, index) => ({
                                nilai: row.nilai.trim(),
                                urutan: index,
                            })),
                    }),
                },
            );
            onVersionChange(result.version);
            setSaved(true);
        } catch (caught) {
            reportSaveError(caught, 'Pilihan nilai belum dapat disimpan.');
        } finally {
            setSaving(false);
        }
    }

    return (
        <div
            ref={(node) => {
                containerRef.current = node;
                feedbackScopeRef.current = node;
            }}
            className="space-y-3 border-t px-5 py-4"
        >
            {feedbackSummary}
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
                        data-error-field={valueField(index)}
                        error={fieldError(valueField(index))}
                    >
                        <Input
                            id={`nilai-${index}`}
                            label={`Pilihan ${index + 1}`}
                            maxLength={150}
                            disabled={!canEdit}
                            value={row.nilai}
                            onChange={(event) =>
                                setRows((current) =>
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
                                setRows(rows.filter((_, i) => i !== index))
                            }
                        />
                    )}
                </div>
            ))}

            {error && <p className="text-destructive text-sm">{error}</p>}
            {saved && (
                <p className="text-muted-foreground text-sm">
                    Pilihan nilai tersimpan.
                </p>
            )}

            {canEdit && (
                <div className="flex gap-2">
                    <ActionButton
                        action="add"
                        type="button"
                        onClick={() => setRows([...rows, { nilai: '' }])}
                    />
                    <ActionButton
                        action="save"
                        type="button"
                        disabled={saving}
                        onClick={() => void save()}
                        pending={saving}
                    />
                </div>
            )}
        </div>
    );
}
