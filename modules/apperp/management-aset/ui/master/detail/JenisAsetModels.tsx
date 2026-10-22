import { useMemo, useState } from 'react';
import { ActionButton } from '@apperp/ui/action-button';
import type { DataTableColumn } from '@apperp/ui/data-table';
import { DataTable } from '@apperp/ui/data-table';

import { DialogToolbar } from '@apperp/ui/dialog';
import { Empty, EmptyDescription } from '@apperp/ui/empty';
import { FormControl } from '@apperp/ui/form-control';

import { TransferList } from '@apperp/ui/transfer-list';
import type { TransferListItem } from '@apperp/ui/transfer-list';
import { api, errorMessage } from '../../api';
import { useSaveFeedback } from '../../use-save-feedback';
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
    version,
    onVersionChange,
}: {
    jenisAsetId: string;
    detail: JenisAsetDetail | null;
    loading: boolean;
    error: string;
    canEdit: boolean;
    /** Versi record pemilik; penyimpanan rincian ini mengklaimnya. */
    version: number;
    onVersionChange: (version: number) => void;
}) {
    const [saving, setSaving] = useState(false);
    const {
        scopeRef: feedbackScopeRef,
        feedbackSummary,
        reportSaveError,
        clearSaveFeedback,
        fieldError,
    } = useSaveFeedback(undefined, jenisAsetId);

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
        clearSaveFeedback();
        setSaving(true);
        setKerja({ ...aktif, saved: false, saveError: '' });

        try {
            const result = await api<{ data: { version: number } }>(
                `/jenis-aset/${jenisAsetId}/models`,
                {
                    method: 'PUT',
                    body: JSON.stringify({
                        version,
                        model_ids: selected.map((item) => item.id),
                    }),
                },
            );
            onVersionChange(result.data.version);
            setKerja((current) => current && { ...current, saved: true });
        } catch (caught) {
            reportSaveError(caught, 'Pabrikan dan model belum dapat disimpan.');
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
            <div ref={feedbackScopeRef} className="space-y-4">
                {feedbackSummary}
                <DialogToolbar className="px-0">
                    <ActionButton
                        action="save"
                        type="button"
                        disabled={saving}
                        onClick={() => void save()}
                        pending={saving}
                    />
                </DialogToolbar>
                <p className="text-muted-foreground text-sm">
                    Pilih model yang boleh dipakai untuk jenis aset ini. Model
                    dan pabrikan tetap dikelola di master masing-masing.
                </p>
                <FormControl
                    data-error-field="model_ids"
                    error={fieldError('model_ids')}
                >
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
                </FormControl>
                {saveError && (
                    <p className="text-destructive text-sm">{saveError}</p>
                )}
                {saved && (
                    <p className="text-muted-foreground text-sm">
                        Pabrikan dan model tersimpan.
                    </p>
                )}
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

    return (() => {
        const sdkRows = detail.models.map((item, rowIndex) => {
            return { item, rowIndex };
        });
        const sdkColumns: DataTableColumn<(typeof sdkRows)[number]>[] = [
            {
                id: 'column-0',
                header: 'Pabrikan',
                cell: ({ item }) => <>{item.manufacturer ?? '-'}</>,
            } satisfies DataTableColumn<(typeof sdkRows)[number]>,
            {
                id: 'column-1',
                header: 'Model',
                cell: ({ item }) => (
                    <>
                        <div className="font-medium">{item.model}</div>
                        {item.model_number && (
                            <div className="text-muted-foreground text-xs">
                                {item.model_number}
                            </div>
                        )}
                    </>
                ),
            } satisfies DataTableColumn<(typeof sdkRows)[number]>,
            {
                id: 'column-2',
                header: 'Keterangan',
                cell: ({ item }) => <>{item.description ?? '-'}</>,
            } satisfies DataTableColumn<(typeof sdkRows)[number]>,
        ];

        return (
            <DataTable
                scrollMode="inline"
                showRowNumbers={false}
                serverSort
                data={sdkRows}
                columns={sdkColumns}
                getRowKey={({ item }) => item.id}
            />
        );
    })();
}
