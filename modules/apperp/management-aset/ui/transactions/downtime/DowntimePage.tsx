import { usePage } from '@inertiajs/react';
import { useRef, useState } from 'react';
import { toast } from 'sonner';
import { useDateTimeFormat } from '@/hooks/use-date-time';
import { ActionButton } from '@apperp/ui/action-button';
import { Badge } from '@apperp/ui/badge';
import { Button } from '@apperp/ui/button';
import { Checkbox } from '@apperp/ui/checkbox';
import { DataTable } from '@apperp/ui/data-table';
import type { DataTableColumn } from '@apperp/ui/data-table';
import { DateTimePicker } from '@apperp/ui/date-time-picker';

import {
    Empty,
    EmptyDescription,
    EmptyHeader,
    EmptyTitle,
} from '@apperp/ui/empty';
import { FieldError } from '@apperp/ui/field';
import { FormControl, FormGrid } from '@apperp/ui/form-control';
import { Input } from '@apperp/ui/input';
import {
    PageDialog,
    PageDialogContent,
    PageDialogToolbar,
    PageDialogClose,
    PageDialogBody,
} from '@apperp/ui/page-dialog';
import { RecordActionBar } from '@apperp/ui/record-action-bar';
import { useRecordNavigation } from '@apperp/ui/use-record-navigation';
import { useRecordTransition } from '@apperp/ui/use-record-transition';
import { zonedInput } from '../../_shared/format';
import { RecordLookup } from '../../_shared/RecordLookup';
import { ApiError, api, newIdempotencyKey } from '../../api';
import { optionLabel, useMasterOptions } from '../../master/useMasterOptions';
import { useRecordList } from '../../use-record-list';
import { useSaveFeedback } from '../../use-save-feedback';
import { RecordCardHeader, recordQueryUrl } from '../_shared/record-card';
import { useRecordCardDirty } from '../_shared/use-record-card-dirty';
import { useRecordQueryTarget } from '../_shared/use-record-query-target';
import type { Downtime } from './downtime';

type Draft = {
    id: string | null;
    version: number;
    aset_id: string;
    mulai: string;
    selesai: string;
    alasan_downtime_id: string;
    keterangan: string;
};

/**
 * Pencatatan downtime aset; padanan *Maintenance downtime* Dynamics 365 F&O.
 *
 * Downtime dari work order dibuka dan ditutup otomatis oleh perpindahan status work order; layar ini
 * untuk mencatat henti yang tidak lewat work order dan mengoreksi waktunya. Waktu diketik dan
 * ditampilkan menurut zona pengguna.
 */
export default function DowntimePage({
    permissions,
}: {
    permissions: string[];
}) {
    const {
        scopeRef: pageFeedbackScopeRef,
        feedbackSummary: pageFeedbackSummary,
        reportSaveError: pageFeedbackReportSaveError,
        clearSaveFeedback: pageFeedbackClearSaveFeedback,
    } = useSaveFeedback();
    const pageFeedback = {
        reportSaveError: pageFeedbackReportSaveError,
        clearSaveFeedback: pageFeedbackClearSaveFeedback,
    };

    const can = (action: string) =>
        permissions.includes(`management-aset.downtime-aset.${action}`);
    const { clock } = usePage().props;
    const timeZone = clock?.timezone ?? 'UTC';
    const formatDateTime = useDateTimeFormat();
    const [reload, setReload] = useState(0);
    const [filterAset, setFilterAset] = useState('');
    const [openOnly, setOpenOnly] = useState(false);
    const [openedDowntime, setOpenedDowntime] = useState<Downtime | null>(null);
    const [draft, setDraft] = useState<Draft | null>(null);
    const assetSource = useMasterOptions('aset', '', draft?.aset_id ?? '');
    const assets = assetSource.options;
    const reasons = useMasterOptions(
        'alasan-downtime',
        '',
        draft?.alasan_downtime_id ?? '',
    );
    const [errors, setErrors] = useState<Record<string, string[]>>({});
    const [busy, setBusy] = useState(false);
    const [draftDirty, setDraftDirty] = useState(false);
    const dialogRef = useRef<HTMLDivElement>(null);
    const {
        feedbackSummary: formFeedbackSummary,
        reportSaveError: formFeedbackReportSaveError,
        clearSaveFeedback: formFeedbackClearSaveFeedback,
    } = useSaveFeedback(dialogRef, draft ? 'editing' : 'closed');
    const formFeedback = {
        reportSaveError: formFeedbackReportSaveError,
        clearSaveFeedback: formFeedbackClearSaveFeedback,
    };

    const message = (field: string) => errors[field]?.[0];

    const list = useRecordList<Downtime>('/downtime-aset', '', {
        aset_id: filterAset,
        terbuka: openOnly ? '1' : '',
        refresh: String(reload),
    });
    const rows = list.loading && !list.items.length ? null : list.items;

    async function save() {
        if (!draft) {
            return;
        }

        setBusy(true);
        setErrors({});
        const body = {
            mulai: draft.mulai,
            selesai: draft.selesai || null,
            alasan_downtime_id: draft.alasan_downtime_id || null,
            keterangan: draft.keterangan || null,
        };

        try {
            await (draft.id
                ? api(`/downtime-aset/${draft.id}`, {
                      method: 'PATCH',
                      body: JSON.stringify({ ...body, version: draft.version }),
                  })
                : api('/downtime-aset', {
                      method: 'POST',
                      headers: { 'Idempotency-Key': newIdempotencyKey() },
                      body: JSON.stringify({ ...body, aset_id: draft.aset_id }),
                  }));
            formFeedback.clearSaveFeedback();
            toast.success('Downtime disimpan.');
            clearRecordTarget();
            setDraft(null);
            setReload((current) => current + 1);
        } catch (caught) {
            if (caught instanceof ApiError) {
                setErrors(caught.validationErrors);
            }

            formFeedback.reportSaveError(
                caught,
                'Downtime belum dapat disimpan.',
            );
        } finally {
            setBusy(false);
        }
    }

    async function archive(row: Pick<Downtime, 'id' | 'version'>) {
        setBusy(true);

        try {
            await api(`/downtime-aset/${row.id}`, {
                method: 'DELETE',
                body: JSON.stringify({ version: row.version }),
            });
            pageFeedback.clearSaveFeedback();
            toast.success('Downtime diarsipkan.');
            clearRecordTarget();
            setDraft((current) => (current?.id === row.id ? null : current));
            setReload((current) => current + 1);
        } catch (caught) {
            (draft?.id === row.id
                ? formFeedback
                : pageFeedback
            ).reportSaveError(caught, 'Downtime belum dapat diarsipkan.');
        } finally {
            setBusy(false);
        }
    }

    const assetChoices = assets.map((asset) => ({
        value: asset.id,
        label: optionLabel(asset),
    }));
    const columns: DataTableColumn<Downtime>[] = [
        {
            id: 'aset',
            header: 'Aset',
            cell: (row) =>
                [row.aset_kode, row.aset_nama].filter(Boolean).join(' · '),
            sortValue: (row) => row.aset_kode,
            minWidth: 200,
        },
        {
            id: 'mulai',
            header: 'Mulai',
            cell: (row) => formatDateTime(row.mulai),
            sortValue: (row) => row.mulai,
            width: 170,
        },
        {
            id: 'selesai',
            header: 'Selesai',
            cell: (row) =>
                row.terbuka ? (
                    <Badge variant="destructive">Masih berhenti</Badge>
                ) : (
                    formatDateTime(row.selesai)
                ),
            width: 170,
        },
        {
            id: 'durasi',
            header: 'Durasi (jam)',
            cell: (row) => row.durasi_jam.toLocaleString('id-ID'),
            sortValue: (row) => row.durasi_jam,
            align: 'right',
            width: 120,
        },
        {
            id: 'alasan',
            header: 'Alasan',
            cell: (row) => (
                <span className="flex items-center gap-2">
                    {row.alasan_downtime_nama ?? '—'}
                    {!row.masuk_kpi && (
                        <Badge variant="outline">Tidak dihitung KPI</Badge>
                    )}
                </span>
            ),
            width: 220,
        },
        {
            id: 'sumber',
            header: 'Sumber',
            cell: (row) =>
                [row.pemeliharaan_aset_kode, row.permintaan_pemeliharaan_kode]
                    .filter(Boolean)
                    .join(' · ') ||
                (row.sumber === 'work_order' ? 'Work order' : 'Dicatat manual'),
            width: 200,
        },
    ];

    const openEdit = (row: Downtime) => {
        formFeedback.clearSaveFeedback();

        setOpenedDowntime(row);
        setErrors({});
        setDraft({
            id: row.id,
            version: row.version,
            aset_id: row.aset_id,
            mulai: zonedInput(row.mulai, timeZone),
            selesai: zonedInput(row.selesai, timeZone),
            alasan_downtime_id: row.alasan_downtime_id ?? '',
            keterangan: row.keterangan ?? '',
        });
    };

    const editable = draft?.id ? can('update') : can('create');
    const clearRecordTarget = useRecordQueryTarget({
        scopeKey: 'downtime-aset',
        enabled: can('read'),
        onOpen: async (id, signal) => {
            const result = await api<{ data: Downtime }>(
                '/downtime-aset/' + id,
                { signal },
            );

            if (!signal.aborted) {
                openEdit(result.data);
            }
        },
    });
    const {
        request: requestDraftTransition,
        confirmation: draftTransitionConfirmation,
    } = useRecordTransition(draftDirty, busy);
    const newDowntime = () => {
        requestDraftTransition(() => {
            clearRecordTarget();
            (() => {
                setErrors({});
                setDraft({
                    id: null,
                    version: 0,
                    aset_id: filterAset,
                    mulai: '',
                    selesai: '',
                    alasan_downtime_id: '',
                    keterangan: '',
                });
            })();
        });
    };
    const closeDowntime = () => {
        requestDraftTransition(() => {
            clearRecordTarget();
            setDraft(null);
        });
    };
    useRecordCardDirty({
        value: draft,
        ready: draft !== null,
        scopeKey: draft?.id ?? 'new',
        onDirtyChange: setDraftDirty,
    });
    const draftNavigation = useRecordNavigation({
        items: list.items,
        currentId: draft?.id ?? null,
        getId: (item) => item.id,
        onNavigate: (id) => {
            const row = list.items.find((item) => item.id === id);

            if (row) {
                openEdit(row);
            }
        },
        scopeKey: JSON.stringify([filterAset, openOnly, list.sort]),
        hasMore: list.hasMore,
        loading: list.loading,
        loadMore: list.loadMore,
        disabled: busy || draftDirty,
    });

    return (
        <div ref={pageFeedbackScopeRef}>
            {draftTransitionConfirmation}
            <RecordActionBar
                feedback={pageFeedbackSummary}
                title="Downtime aset"
            >
                {can('create') && (
                    <Button type="button" onClick={newDowntime}>
                        Catat downtime
                    </Button>
                )}
            </RecordActionBar>

            <div className="flex flex-col gap-3 border-b px-5 py-3 sm:flex-row sm:flex-wrap sm:items-end">
                <div className="w-full sm:w-80">
                    <FormControl>
                        <RecordLookup
                            source={assetSource}
                            label="Aset"
                            items={[
                                { value: '', label: 'Semua aset' },
                                ...assetChoices,
                            ]}
                            value={filterAset || null}
                            placeholder="Semua aset"
                            searchPlaceholder="Cari kode atau nama aset"
                            ariaLabel="Saring berdasarkan aset"
                            onValueChange={(value) =>
                                setFilterAset(value ?? '')
                            }
                        />
                    </FormControl>
                </div>
                <label className="flex items-center gap-2 text-sm">
                    <Checkbox
                        checked={openOnly}
                        onCheckedChange={(checked) =>
                            setOpenOnly(checked === true)
                        }
                    />
                    Hanya aset yang masih berhenti
                </label>
            </div>

            {rows !== null && rows.length === 0 ? (
                <Empty>
                    <EmptyHeader>
                        <EmptyTitle>Belum ada downtime tercatat</EmptyTitle>
                        <EmptyDescription>
                            Downtime tercatat otomatis saat work order dengan
                            pekerjaan yang menuntut aset berhenti mulai
                            dikerjakan, atau dicatat di sini saat aset berhenti
                            di luar work order.
                        </EmptyDescription>
                    </EmptyHeader>
                </Empty>
            ) : (
                <DataTable
                    columns={columns}
                    incremental={list}
                    sort={list.sort}
                    onSortChange={list.onSortChange}
                    data={rows ?? []}
                    getRowKey={(row) => row.id}
                    getRowLabel={(row) => row.aset_kode}
                    actions={[
                        ...(can('update')
                            ? [
                                  {
                                      id: 'ubah',
                                      label: 'Koreksi waktu atau alasan',
                                  },
                              ]
                            : []),
                        ...(can('archive')
                            ? [
                                  {
                                      id: 'arsip',
                                      label: 'Delete',
                                      destructive: true,
                                  },
                              ]
                            : []),
                    ]}
                    onRowAction={(id, row) =>
                        id === 'arsip' ? void archive(row) : openEdit(row)
                    }
                />
            )}

            <PageDialog
                open={draft !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        closeDowntime();
                    }
                }}
            >
                <PageDialogContent
                    ref={dialogRef}
                    title={'Downtime aset'}
                    busy={busy}
                    onNew={draft?.id && can('create') ? newDowntime : undefined}
                    navigation={draft?.id ? draftNavigation : undefined}
                >
                    <RecordCardHeader
                        recordTitle={
                            draft?.id
                                ? [
                                      openedDowntime?.id === draft.id
                                          ? openedDowntime.aset_kode
                                          : assets.find(
                                                (a) => a.id === draft.aset_id,
                                            )?.kode,
                                      formatDateTime(draft.mulai),
                                  ]
                                      .filter(Boolean)
                                      .join(' · ')
                                : 'Baru'
                        }
                        editing={editable}
                        recordUrl={
                            draft?.id
                                ? recordQueryUrl('downtime-aset', draft.id)
                                : undefined
                        }
                        onDelete={
                            draft?.id && can('archive')
                                ? () => {
                                      if (draft?.id) {
                                          void archive({
                                              id: draft.id,
                                              version: draft.version,
                                          });
                                      }
                                  }
                                : undefined
                        }
                    />
                    {editable && (
                        <PageDialogToolbar>
                            <ActionButton
                                action="save"
                                type="button"
                                disabled={
                                    busy || !draft?.aset_id || !draft?.mulai
                                }
                                onClick={() => void save()}
                                pending={busy}
                            />
                            <PageDialogClose asChild>
                                <ActionButton action="cancel" type="button" />
                            </PageDialogClose>
                        </PageDialogToolbar>
                    )}
                    {formFeedbackSummary}
                    {draft && (
                        <PageDialogBody className="space-y-4">
                            <FormControl data-error-field="aset_id">
                                {editable ? (
                                    <RecordLookup
                                        source={assetSource}
                                        label="Aset"
                                        required
                                        items={assetChoices}
                                        value={draft.aset_id || null}
                                        placeholder="Pilih aset"
                                        searchPlaceholder="Cari kode atau nama aset"
                                        ariaLabel="Aset"
                                        portalContainer={dialogRef}
                                        onValueChange={(value) =>
                                            !draft.id &&
                                            setDraft({
                                                ...draft,
                                                aset_id: value ?? '',
                                            })
                                        }
                                    />
                                ) : (
                                    <Input
                                        label="Aset"
                                        readOnly
                                        value={
                                            openedDowntime?.aset_id ===
                                            draft.aset_id
                                                ? [
                                                      openedDowntime.aset_kode,
                                                      openedDowntime.aset_nama,
                                                  ]
                                                      .filter(Boolean)
                                                      .join(' · ')
                                                : (assets.find(
                                                      (row) =>
                                                          row.id ===
                                                          draft.aset_id,
                                                  )?.nama ?? '—')
                                        }
                                    />
                                )}
                                {message('aset_id') && (
                                    <FieldError>
                                        {message('aset_id')}
                                    </FieldError>
                                )}
                            </FormControl>
                            <FormGrid>
                                <FormControl data-error-field="mulai">
                                    <DateTimePicker
                                        portalContainer={dialogRef}
                                        readOnly={!editable}
                                        label="Mulai berhenti"

                                        required
                                        value={draft.mulai}
                                        onValueChange={(value) =>
                                            setDraft({
                                                ...draft,
                                                mulai: value,
                                            })
                                        }
                                    />
                                </FormControl>
                                <FormControl
                                    data-error-field="selesai"
                                    hint={
                                        <>Kosongkan bila aset masih berhenti.</>
                                    }
                                >
                                    <DateTimePicker
                                        portalContainer={dialogRef}
                                        readOnly={!editable}
                                        label="Kembali beroperasi"

                                        value={draft.selesai}
                                        onValueChange={(value) =>
                                            setDraft({
                                                ...draft,
                                                selesai: value,
                                            })
                                        }
                                    />
                                </FormControl>
                            </FormGrid>
                            {(message('mulai') ?? message('selesai')) && (
                                <p className="text-destructive text-sm">
                                    {message('mulai') ?? message('selesai')}
                                </p>
                            )}
                            <FormControl
                                data-error-field="alasan_downtime_id"
                                hint={
                                    <>
                                        Downtime tanpa alasan tetap mengurangi
                                        ketersediaan aset pada KPI. Pilih alasan
                                        yang tidak dihitung KPI untuk henti
                                        terencana.
                                    </>
                                }
                            >
                                {editable ? (
                                    <RecordLookup
                                        source={reasons}
                                        label="Alasan"
                                        items={reasons.options.map(
                                            (option) => ({
                                                value: option.id,
                                                label: optionLabel(option),
                                            }),
                                        )}
                                        value={draft.alasan_downtime_id || null}
                                        placeholder="Tanpa alasan"
                                        ariaLabel="Alasan downtime"
                                        portalContainer={dialogRef}
                                        onValueChange={(value) =>
                                            setDraft({
                                                ...draft,
                                                alasan_downtime_id: value ?? '',
                                            })
                                        }
                                    />
                                ) : (
                                    <Input
                                        label="Alasan"
                                        readOnly
                                        value={
                                            openedDowntime?.alasan_downtime_id ===
                                            draft.alasan_downtime_id
                                                ? (openedDowntime?.alasan_downtime_nama ??
                                                  'Tanpa alasan')
                                                : (reasons.options.find(
                                                      (row) =>
                                                          row.id ===
                                                          draft.alasan_downtime_id,
                                                  )?.nama ?? 'Tanpa alasan')
                                        }
                                    />
                                )}
                            </FormControl>
                            <FormControl data-error-field="keterangan">
                                <Input
                                    readOnly={!editable}
                                    label="Keterangan"
                                    maxLength={2000}
                                    value={draft.keterangan}
                                    onChange={(event) =>
                                        setDraft({
                                            ...draft,
                                            keterangan: event.target.value,
                                        })
                                    }
                                />
                            </FormControl>
                        </PageDialogBody>
                    )}
                </PageDialogContent>
            </PageDialog>
        </div>
    );
}
