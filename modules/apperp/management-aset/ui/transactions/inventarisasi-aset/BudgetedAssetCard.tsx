import { useRef, useState } from 'react';
import { ActionButton } from '@apperp/ui/action-button';
import { Checkbox } from '@apperp/ui/checkbox';
import { FormControl, FormGrid } from '@apperp/ui/form-control';
import { Input } from '@apperp/ui/input';
import {
    PageDialog,
    PageDialogContent,
    PageDialogToolbar,
    PageDialogBody,
} from '@apperp/ui/page-dialog';
import { Textarea } from '@apperp/ui/textarea';
import { useRecordTransition } from '@apperp/ui/use-record-transition';
import RecordLookup from '../../_shared/RecordLookup';
import { api, newIdempotencyKey } from '../../api';
import MasterSelect from '../../master/MasterSelect';
import { useRecordOptions } from '../../use-record-list';
import { useSaveFeedback } from '../../use-save-feedback';

export type BudgetedAsset = {
    id: string;
    kode: string;
    nama: string;
    jenis_aset_id: string;
    group_aset_id: string;
    legal_entity_id: string;
    responsible_org_unit_id: string;
    is_budgeted: boolean;
    keterangan: string | null;
    version: number;
};

/** Kartu yang sama dipakai Inventarisasi dan Baru pada lookup perencanaan. */
export default function BudgetedAssetCard({
    companyId,
    unitId,
    asset,
    permissions,
    onClose,
    onSaved,
}: {
    companyId: string;
    unitId: string | null;
    asset?: BudgetedAsset;
    permissions: string[];
    onClose: () => void;
    onSaved: (asset: BudgetedAsset) => void;
}) {
    const [draft, setDraft] = useState(() => ({
        nama: asset?.nama ?? '',
        group_aset_id: asset?.group_aset_id ?? '',
        jenis_aset_id: asset?.jenis_aset_id ?? '',
        responsible_org_unit_id: asset?.responsible_org_unit_id ?? unitId ?? '',
        keterangan: asset?.keterangan ?? '',
    }));
    const [original, setOriginal] = useState(() => JSON.stringify(draft));
    const [busy, setBusy] = useState(false);
    const contentRef = useRef<HTMLDivElement>(null);
    const key = useRef(newIdempotencyKey());
    const feedback = useSaveFeedback(contentRef, asset?.id);
    const transition = useRecordTransition(
        JSON.stringify(draft) !== original,
        busy,
    );
    const close = () => transition.request(onClose);
    const canSave = permissions.includes(
        `management-aset.aset.${asset ? 'update' : 'create'}`,
    );
    const units = useRecordOptions<{ id: string; nama: string }>(
        '/reference-data/unit-kerja',
        (record) => record.nama,
        draft.responsible_org_unit_id,
    );
    const save = async (event: React.FormEvent) => {
        event.preventDefault();
        feedback.clearSaveFeedback();
        setBusy(true);

        try {
            const result = await api<{ data: BudgetedAsset }>(
                asset ? `/aset/${asset.id}/planning` : '/aset',
                {
                    method: asset ? 'PATCH' : 'POST',
                    headers: asset
                        ? undefined
                        : { 'Idempotency-Key': key.current },
                    body: JSON.stringify({
                        ...draft,
                        legal_entity_id: companyId,
                        is_budgeted: true,
                        ...(asset ? { version: asset.version } : {}),
                    }),
                },
            );
            setOriginal(JSON.stringify(draft));
            onSaved(result.data);
        } catch (caught) {
            feedback.reportSaveError(
                caught,
                'Aset rencana belum dapat disimpan.',
            );
        } finally {
            setBusy(false);
        }
    };

    return (
        <>
            {transition.confirmation}
            <PageDialog
                open
                onOpenChange={(open) => {
                    if (!open) {
                        close();
                    }
                }}
            >
                <PageDialogContent
                    ref={contentRef}
                    title="Inventarisasi aset"
                    recordTitle={
                        asset ? `${asset.kode} - ${asset.nama}` : 'Baru'
                    }
                    busy={busy}
                >
                    <form noValidate onSubmit={save}>
                        <PageDialogToolbar>
                            {canSave && (
                                <ActionButton
                                    action="save"
                                    type="submit"
                                    pending={busy}
                                />
                            )}
                            <ActionButton
                                action="cancel"
                                onClick={close}
                                disabled={busy}
                            />
                        </PageDialogToolbar>
                        {feedback.feedbackSummary}
                        <PageDialogBody>
                            <FormGrid>
                                <FormControl hint="Aset ini hanya untuk perencanaan. Belum menjadi perolehan, tidak disusutkan, dan tidak diposting ke pembukuan aktual.">
                                    <label className="flex items-center gap-2">
                                        <Checkbox checked disabled />
                                        Aset sebagai perencanaan
                                    </label>
                                </FormControl>
                                <FormControl>
                                    <Input
                                        label="Kode aset"
                                        readOnly
                                        value={asset?.kode ?? ''}
                                        placeholder="Dibuat otomatis saat disimpan"
                                    />
                                </FormControl>
                                <FormControl
                                    error={feedback.fieldError('nama')}
                                    data-error-field="nama"
                                >
                                    <Input
                                        label="Nama aset"
                                        required
                                        value={draft.nama}
                                        readOnly={!canSave}
                                        onChange={(event) =>
                                            setDraft({
                                                ...draft,
                                                nama: event.target.value,
                                            })
                                        }
                                    />
                                </FormControl>
                                <FormControl
                                    error={feedback.fieldError(
                                        'responsible_org_unit_id',
                                    )}
                                    data-error-field="responsible_org_unit_id"
                                >
                                    <RecordLookup
                                        label="Unit penanggung jawab"
                                        required
                                        source={units}
                                        value={
                                            draft.responsible_org_unit_id ||
                                            null
                                        }
                                        items={units.options.map((record) => ({
                                            value: record.id,
                                            label: record.nama,
                                        }))}
                                        portalContainer={contentRef}
                                        allowCreate={false}
                                        disabled={!canSave}
                                        onValueChange={(value) =>
                                            setDraft({
                                                ...draft,
                                                responsible_org_unit_id:
                                                    value ?? '',
                                            })
                                        }
                                    />
                                </FormControl>
                                <FormControl
                                    error={feedback.fieldError('group_aset_id')}
                                    data-error-field="group_aset_id"
                                >
                                    <MasterSelect
                                        resource="group-aset"
                                        label="Group aset"
                                        required
                                        value={draft.group_aset_id}
                                        permissions={permissions}
                                        disabled={!canSave}
                                        portalContainer={contentRef}
                                        onValueChange={(value) =>
                                            setDraft({
                                                ...draft,
                                                group_aset_id: value,
                                            })
                                        }
                                    />
                                </FormControl>
                                <FormControl
                                    error={feedback.fieldError('jenis_aset_id')}
                                    data-error-field="jenis_aset_id"
                                >
                                    <MasterSelect
                                        resource="jenis-aset"
                                        label="Jenis aset"
                                        required
                                        value={draft.jenis_aset_id}
                                        permissions={permissions}
                                        disabled={!canSave}
                                        portalContainer={contentRef}
                                        onValueChange={(value) =>
                                            setDraft({
                                                ...draft,
                                                jenis_aset_id: value,
                                            })
                                        }
                                    />
                                </FormControl>
                                <FormControl>
                                    <Textarea
                                        label="Keterangan"
                                        value={draft.keterangan}
                                        readOnly={!canSave}
                                        onChange={(event) =>
                                            setDraft({
                                                ...draft,
                                                keterangan: event.target.value,
                                            })
                                        }
                                    />
                                </FormControl>
                            </FormGrid>
                        </PageDialogBody>
                    </form>
                </PageDialogContent>
            </PageDialog>
        </>
    );
}
