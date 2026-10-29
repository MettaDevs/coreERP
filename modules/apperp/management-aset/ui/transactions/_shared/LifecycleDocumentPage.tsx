import { useCallback, useEffect, useState } from 'react';
import { ActionButton } from '@apperp/ui/action-button';

import {
    Card,
    CardAction,
    CardContent,
    CardHeader,
    CardTitle,
} from '@apperp/ui/card';
import { DatePicker } from '@apperp/ui/date-picker';

import {
    Empty,
    EmptyDescription,
    EmptyHeader,
    EmptyTitle,
} from '@apperp/ui/empty';

import { FormControl } from '@apperp/ui/form-control';
import { Input } from '@apperp/ui/input';
import {
    PageDialog,
    PageDialogContent,
    PageDialogToolbar,
    PageDialogBody,
} from '@apperp/ui/page-dialog';
import { useRecordTransition } from '@apperp/ui/use-record-transition';

import { api, errorMessage, newIdempotencyKey } from '../../api';
import { useSaveFeedback } from '../../use-save-feedback';

type Context = { legal_entity_id: string | null; org_unit_id: string | null };
type Record = {
    id: string;
    kode: string;
    tanggal: string;
    status: string;
    keterangan: string | null;
};
export type LifecycleConfig = {
    resource: string;
    title: string;
    action: string;
    needsAset: boolean;
    financial: boolean;
};

export default function LifecycleDocumentPage({
    context,
    config,
}: {
    context: Context;
    config: LifecycleConfig;
}) {
    const { scopeRef, feedbackSummary, reportSaveError, clearSaveFeedback } =
        useSaveFeedback(undefined, config.resource);
    const [records, setRecords] = useState<Record[]>([]);
    const [open, setOpen] = useState(false);
    const [error, setError] = useState('');
    const [saving, setSaving] = useState(false);
    const [draftDirty, setDraftDirty] = useState(false);
    const load = useCallback(
        () =>
            api<{ data: Record[] }>('/' + config.resource)
                .then((result) => setRecords(result.data))
                .catch((caught) =>
                    setError(errorMessage(caught, 'Data belum dapat dimuat.')),
                ),
        [config.resource],
    );
    useEffect(() => {
        void load();
    }, [load]);
    async function save(form: HTMLFormElement) {
        if (!context.legal_entity_id || !context.org_unit_id) {
            reportSaveError(
                null,
                'Pilih perusahaan dan unit kerja aktif di CoreERP sebelum membuat dokumen.',
            );

            return;
        }

        const values = new FormData(form);
        setSaving(true);
        setError('');

        try {
            await api('/' + config.resource, {
                method: 'POST',
                headers: { 'Idempotency-Key': newIdempotencyKey() },
                body: JSON.stringify({
                    legal_entity_id: context.legal_entity_id,
                    responsible_org_unit_id: context.org_unit_id,
                    tanggal: values.get('tanggal'),
                    aset_id: values.get('aset_id') || null,
                    nilai: values.get('nilai') || null,
                    keterangan: values.get('keterangan') || null,
                }),
            });
            clearSaveFeedback();
            setOpen(false);
            form.reset();
            await load();
        } catch (caught) {
            reportSaveError(caught, 'Dokumen belum dapat disimpan.');
        } finally {
            setSaving(false);
        }
    }

    const {
        request: requestDocumentTransition,
        confirmation: documentTransitionConfirmation,
    } = useRecordTransition(draftDirty, saving);
    const closeDocument = () => requestDocumentTransition(() => setOpen(false));

    return (
        <Card className="min-h-full rounded-none border-0 shadow-none">
            {documentTransitionConfirmation}
            <CardHeader className="border-b">
                <CardTitle>{config.title}</CardTitle>
                <CardAction>
                    <ActionButton
                        action="create"
                        onClick={() => {
                            clearSaveFeedback();
                            setDraftDirty(false);
                            setOpen(true);
                        }}
                    />
                </CardAction>
            </CardHeader>
            <CardContent className="px-0">
                {error && (
                    <p className="text-destructive px-5 py-3 text-sm">
                        {error}
                    </p>
                )}
                {!records.length ? (
                    <Empty>
                        <EmptyHeader>
                            <EmptyTitle>Belum ada dokumen</EmptyTitle>
                            <EmptyDescription>
                                Dokumen ini tidak mengharuskan aset sudah
                                diterima, kecuali transaksi yang memang memilih
                                aset.
                            </EmptyDescription>
                        </EmptyHeader>
                    </Empty>
                ) : (
                    <div className="divide-y">
                        {records.map((record) => (
                            <div
                                key={record.id}
                                className="flex items-center justify-between px-5 py-3"
                            >
                                <div>
                                    <p className="font-medium">{record.kode}</p>
                                    <p className="text-muted-foreground text-sm">
                                        {record.tanggal}
                                        {record.keterangan
                                            ? ` — ${record.keterangan}`
                                            : ''}
                                    </p>
                                </div>
                                <span className="text-sm">{record.status}</span>
                            </div>
                        ))}
                    </div>
                )}
            </CardContent>
            <PageDialog
                open={open}
                onOpenChange={(value) => {
                    if (!value) {
                        closeDocument();
                    }
                }}
            >
                <PageDialogContent
                    ref={scopeRef}
                    title={config.title}
                    busy={saving}
                >
                    <form
                        noValidate
                        onChangeCapture={() => setDraftDirty(true)}
                        className="flex min-h-0 flex-1 flex-col"
                        onSubmit={(event) => {
                            event.preventDefault();
                            void save(event.currentTarget);
                        }}
                    >
                        <PageDialogToolbar>
                            <ActionButton
                                action="save"
                                type="submit"
                                disabled={saving}
                                pending={saving}
                            />
                            <ActionButton
                                action="cancel"
                                type="button"
                                onClick={closeDocument}
                            />
                        </PageDialogToolbar>
                        {feedbackSummary}
                        <PageDialogBody className="min-h-0 flex-1 space-y-4 overflow-y-auto p-4">
                            <FormControl>
                                <DatePicker
                                    name="tanggal"
                                    label="Tanggal"

                                    required
                                />
                            </FormControl>
                            {config.needsAset && (
                                <FormControl>
                                    <Input
                                        name="aset_id"
                                        label="ID aset"
                                        required
                                    />
                                </FormControl>
                            )}
                            {config.financial && (
                                <FormControl>
                                    <Input
                                        name="nilai"
                                        label="Nilai usulan"
                                        type="number"
                                        min="0"
                                        step="0.01"
                                    />
                                </FormControl>
                            )}
                            <FormControl>
                                <Input name="keterangan" label="Keterangan" />
                            </FormControl>
                        </PageDialogBody>
                    </form>
                </PageDialogContent>
            </PageDialog>
        </Card>
    );
}
