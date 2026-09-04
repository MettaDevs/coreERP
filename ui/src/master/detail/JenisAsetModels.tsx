import { useEffect, useState } from 'react';
import { Button } from '@apperp/ui/button';
import { Empty, EmptyDescription } from '@apperp/ui/empty';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@apperp/ui/table';
import { TransferList, type TransferListItem } from '@apperp/ui/transfer-list';
import { api, errorMessage } from '../../api';
import { JenisAsetDetail, JenisAsetModelSummary } from './jenisAsetDetail';

function itemOf(model: JenisAsetModelSummary): TransferListItem {
    return {
        id: model.id,
        label: model.model,
        description:
            [model.manufacturer, model.model_number].filter(Boolean).join(' - ') || undefined,
    };
}

export default function JenisAsetModels({
    jenisAsetId,
    detail,
    loading,
    error,
    canEdit,
}: {
    jenisAsetId: string;
    detail: JenisAsetDetail | null;
    loading: boolean;
    error: string;
    canEdit: boolean;
}) {
    const [remaining, setRemaining] = useState<TransferListItem[]>([]);
    const [selected, setSelected] = useState<TransferListItem[]>([]);
    const [saving, setSaving] = useState(false);
    const [saved, setSaved] = useState(false);
    const [saveError, setSaveError] = useState('');

    useEffect(() => {
        setRemaining((detail?.available_models ?? []).map(itemOf));
        setSelected((detail?.models ?? []).map(itemOf));
        setSaved(false);
        setSaveError('');
    }, [detail]);

    async function save() {
        setSaving(true);
        setSaved(false);
        setSaveError('');
        try {
            await api(`/jenis-aset/${jenisAsetId}/models`, {
                method: 'PUT',
                body: JSON.stringify({ model_ids: selected.map((item) => item.id) }),
            });
            setSaved(true);
        } catch (caught) {
            setSaveError(errorMessage(caught, 'Pabrikan dan model belum dapat disimpan.'));
        } finally {
            setSaving(false);
        }
    }

    if (loading)
        return <p className="text-sm text-muted-foreground">Memuat daftar pabrikan dan model...</p>;
    if (error) return <p className="text-sm text-destructive">{error}</p>;
    if (!detail || detail.models === null || detail.available_models === null) {
        return (
            <Empty>
                <EmptyDescription>Daftar pabrikan dan model belum tersedia.</EmptyDescription>
            </Empty>
        );
    }

    if (canEdit) {
        return (
            <div className="space-y-4">
                <p className="text-sm text-muted-foreground">
                    Pilih model yang boleh dipakai untuk jenis aset ini. Model dan pabrikan tetap
                    dikelola di master masing-masing.
                </p>
                <TransferList
                    remaining={remaining}
                    selected={selected}
                    onChange={(next) => {
                        setRemaining(next.remaining);
                        setSelected(next.selected);
                        setSaved(false);
                    }}
                    remainingTitle="Model tersedia"
                    selectedTitle="Model terpasang"
                    disabled={saving}
                    remainingEmptyLabel="Semua model aktif sudah terpasang."
                    selectedEmptyLabel="Belum ada model yang dipasang."
                />
                {saveError && <p className="text-sm text-destructive">{saveError}</p>}
                {saved && (
                    <p className="text-sm text-muted-foreground">Pabrikan dan model tersimpan.</p>
                )}
                <Button type="button" disabled={saving} onClick={() => void save()}>
                    {saving ? 'Menyimpan...' : 'Simpan pabrikan dan model'}
                </Button>
            </div>
        );
    }

    if (detail.models.length === 0) {
        return (
            <Empty>
                <EmptyDescription>
                    Belum ada model yang dikaitkan dengan jenis aset ini.
                </EmptyDescription>
            </Empty>
        );
    }

    return (
        <div className="overflow-x-auto rounded-md border">
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead>Pabrikan</TableHead>
                        <TableHead>Model</TableHead>
                        <TableHead>Keterangan</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {detail.models.map((item) => (
                        <TableRow key={item.id}>
                            <TableCell>{item.manufacturer ?? '-'}</TableCell>
                            <TableCell>
                                <div className="font-medium">{item.model}</div>
                                {item.model_number && (
                                    <div className="text-xs text-muted-foreground">
                                        {item.model_number}
                                    </div>
                                )}
                            </TableCell>
                            <TableCell>{item.description ?? '-'}</TableCell>
                        </TableRow>
                    ))}
                </TableBody>
            </Table>
        </div>
    );
}
