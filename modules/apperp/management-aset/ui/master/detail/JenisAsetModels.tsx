import { useMemo, useState } from 'react';
import { Button } from '@apperp/ui/button';
import { Empty, EmptyDescription } from '@apperp/ui/empty';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@apperp/ui/table';
import { TransferList } from '@apperp/ui/transfer-list';
import type { TransferListItem } from '@apperp/ui/transfer-list';
import { api, errorMessage } from '../../api';
import type { JenisAsetDetail, JenisAsetModelSummary } from './jenisAsetDetail';

type Asal = { remaining: TransferListItem[]; selected: TransferListItem[] };
type Kerja = Asal & { asal: Asal; saved: boolean; saveError: string };

function itemOf(model: JenisAsetModelSummary): TransferListItem {
    return {
        id: model.id,
        label: model.model,
        description:
            [model.manufacturer, model.model_number]
                .filter(Boolean)
                .join(' - ') || undefined,
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
    const [saving, setSaving] = useState(false);

    /**
     * Isi transfer list adalah salinan kerja: mula-mula persis seperti yang datang dari
     * server, lalu berubah mengikuti pilihan pengguna.
     *
     * Salinan itu dicatat bersama `asal` yang melahirkannya, dan yang dipakai saat render
     * hanyalah salinan yang asalnya masih sama dengan detail terbaru. Detail baru dari
     * server dengan sendirinya membatalkan salinan lama beserta penanda tersimpan dan
     * pesan kesalahannya — tanpa effect yang menyalin ulang props ke dalam state.
     */
    const asal = useMemo(
        () => ({
            remaining: (detail?.available_models ?? []).map(itemOf),
            selected: (detail?.models ?? []).map(itemOf),
        }),
        [detail],
    );
    const [kerja, setKerja] = useState<Kerja | null>(null);
    const aktif: Kerja =
        kerja?.asal === asal
            ? kerja
            : {
                  asal,
                  remaining: asal.remaining,
                  selected: asal.selected,
                  saved: false,
                  saveError: '',
              };
    const { remaining, selected, saved, saveError } = aktif;

    async function save() {
        setSaving(true);
        setKerja({ ...aktif, saved: false, saveError: '' });

        try {
            await api(`/jenis-aset/${jenisAsetId}/models`, {
                method: 'PUT',
                body: JSON.stringify({
                    model_ids: selected.map((item) => item.id),
                }),
            });
            setKerja((current) => current && { ...current, saved: true });
        } catch (caught) {
            setKerja(
                (current) =>
                    current && {
                        ...current,
                        saveError: errorMessage(
                            caught,
                            'Pabrikan dan model belum dapat disimpan.',
                        ),
                    },
            );
        } finally {
            setSaving(false);
        }
    }

    if (loading) {
        return (
            <p className="text-muted-foreground text-sm">
                Memuat daftar pabrikan dan model...
            </p>
        );
    }

    if (error) {
        return <p className="text-destructive text-sm">{error}</p>;
    }

    if (!detail || detail.models === null || detail.available_models === null) {
        return (
            <Empty>
                <EmptyDescription>
                    Daftar pabrikan dan model belum tersedia.
                </EmptyDescription>
            </Empty>
        );
    }

    if (canEdit) {
        return (
            <div className="space-y-4">
                <p className="text-muted-foreground text-sm">
                    Pilih model yang boleh dipakai untuk jenis aset ini. Model
                    dan pabrikan tetap dikelola di master masing-masing.
                </p>
                <TransferList
                    remaining={remaining}
                    selected={selected}
                    onChange={(next) =>
                        setKerja({
                            ...aktif,
                            remaining: next.remaining,
                            selected: next.selected,
                            saved: false,
                        })
                    }
                    remainingTitle="Model tersedia"
                    selectedTitle="Model terpasang"
                    disabled={saving}
                    remainingEmptyLabel="Semua model aktif sudah terpasang."
                    selectedEmptyLabel="Belum ada model yang dipasang."
                />
                {saveError && (
                    <p className="text-destructive text-sm">{saveError}</p>
                )}
                {saved && (
                    <p className="text-muted-foreground text-sm">
                        Pabrikan dan model tersimpan.
                    </p>
                )}
                <Button
                    type="button"
                    disabled={saving}
                    onClick={() => void save()}
                >
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
                                    <div className="text-muted-foreground text-xs">
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
