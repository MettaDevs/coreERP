import { useEffect, useRef, useState } from 'react';
import { Button } from '@apperp/ui/button';
import { Field } from '@apperp/ui/field';
import { Input } from '@apperp/ui/input';
import { api, errorMessage } from '../api';

type Row = { nilai: string };

/**
 * Values untuk atribut teks. Daftar kosong berarti teks bebas; daftar berisi berarti
 * pengguna memilih dari dropdown.
 */
export default function TipeAtributNilai({
    tipeAtributId,
    canEdit,
}: {
    tipeAtributId: string;
    canEdit: boolean;
}) {
    const [rows, setRows] = useState<Row[]>([]);
    const [error, setError] = useState('');
    const [saving, setSaving] = useState(false);
    const [saved, setSaved] = useState(false);
    const containerRef = useRef<HTMLDivElement>(null);

    useEffect(() => {
        let cancelled = false;
        api<{ data: { nilai: string }[] }>(`/tipe-atribut/${tipeAtributId}/nilai`)
            .then((result) => {
                if (!cancelled)
                    setRows(result.data.map((row) => ({ nilai: String(row.nilai ?? '') })));
            })
            .catch((caught) => {
                if (!cancelled) setError(errorMessage(caught, 'Pilihan nilai belum dapat dimuat.'));
            });
        return () => {
            cancelled = true;
        };
    }, [tipeAtributId]);

    async function save() {
        setSaving(true);
        setError('');
        setSaved(false);
        try {
            // Urutan layar menjadi urutan tampil; kirimannya daftar penuh, jadi baris yang
            // dihapus dari layar ikut diarsipkan server.
            await api(`/tipe-atribut/${tipeAtributId}/nilai`, {
                method: 'PUT',
                body: JSON.stringify({
                    rows: rows
                        .filter((row) => row.nilai.trim() !== '')
                        .map((row, index) => ({ nilai: row.nilai.trim(), urutan: index })),
                }),
            });
            setSaved(true);
        } catch (caught) {
            setError(errorMessage(caught, 'Pilihan nilai belum dapat disimpan.'));
        } finally {
            setSaving(false);
        }
    }

    return (
        <div ref={containerRef} className="space-y-3 border-t px-5 py-4">
            <div>
                <p className="font-semibold">Pilihan nilai</p>
                <p className="text-sm text-muted-foreground">
                    Tanpa pilihan, pengguna dapat mengetik bebas. Menambahkan pilihan pertama
                    mengubah isian aset menjadi dropdown.
                </p>
            </div>

            {rows.length === 0 && (
                <p className="text-sm text-muted-foreground">
                    Belum ada pilihan. Atribut ini saat ini memakai teks bebas.
                </p>
            )}

            {rows.map((row, index) => (
                <div key={index} className="flex items-end gap-2">
                    <Field className="flex-1">
                        <Input
                            id={`nilai-${index}`}
                            label={`Pilihan ${index + 1}`}
                            maxLength={150}
                            disabled={!canEdit}
                            value={row.nilai}
                            onChange={(event) =>
                                setRows((current) =>
                                    current.map((item, i) =>
                                        i === index ? { nilai: event.target.value } : item,
                                    ),
                                )
                            }
                        />
                    </Field>
                    {canEdit && (
                        <Button
                            variant="outline"
                            size="sm"
                            type="button"
                            onClick={() => setRows(rows.filter((_, i) => i !== index))}
                        >
                            Hapus
                        </Button>
                    )}
                </div>
            ))}

            {error && <p className="text-sm text-destructive">{error}</p>}
            {saved && <p className="text-sm text-muted-foreground">Pilihan nilai tersimpan.</p>}

            {canEdit && (
                <div className="flex gap-2">
                    <Button
                        variant="outline"
                        type="button"
                        onClick={() => setRows([...rows, { nilai: '' }])}
                    >
                        Tambah pilihan
                    </Button>
                    <Button type="button" disabled={saving} onClick={() => void save()}>
                        {saving ? 'Menyimpan…' : 'Simpan pilihan'}
                    </Button>
                </div>
            )}
        </div>
    );
}
