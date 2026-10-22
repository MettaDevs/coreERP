import { useEffect, useRef, useState } from 'react';
import type { FormEvent } from 'react';
import { ActionButton } from '@apperp/ui/action-button';

import {
    Field,
    FieldDescription,
    FieldError,
    FieldLabel,
} from '@apperp/ui/field';
import { FormControl, FormGrid } from '@apperp/ui/form-control';
import { Input } from '@apperp/ui/input';
import type { PageDialogNavigation } from '@apperp/ui/page-dialog';
import {
    PageDialog,
    PageDialogContent,
    PageDialogToolbar,
    PageDialogBody,
} from '@apperp/ui/page-dialog';

import { Switch } from '@apperp/ui/switch';
import { Textarea } from '@apperp/ui/textarea';
import RecordLookup from '../../_shared/RecordLookup';
import { api, newIdempotencyKey } from '../../api';
import {
    RecordCardHeader,
    recordQueryUrl,
} from '../../transactions/_shared/record-card';
import { useRecordCardDirty } from '../../transactions/_shared/use-record-card-dirty';
import { useSaveFeedback } from '../../use-save-feedback';
import type { MasterRecord, ParentSummary } from '../masters';
import { useMasterOptions } from '../useMasterOptions';
import type { PabrikanModelRecord } from './pabrikanAsetDetail';

function optionLabel(option: ParentSummary): string {
    return `${option.kode} — ${option.nama}`;
}

export default function ModelAsetFormSheet({
    manufacturer,
    value,
    canReadJenis,
    onClose,
    onSaved,
    onNew,
    onDelete,
    navigation,
    onDirtyChange,
    onBusyChange,
}: {
    manufacturer: MasterRecord;
    value: PabrikanModelRecord | null;
    canReadJenis: boolean;
    onClose: () => void;
    onSaved: () => void | Promise<void>;
    onNew?: () => void;
    onDelete?: () => void;
    navigation?: PageDialogNavigation;
    onDirtyChange?: (dirty: boolean) => void;
    onBusyChange?: (busy: boolean) => void;
}) {
    const [nama, setNama] = useState(value?.nama ?? '');
    const [keterangan, setKeterangan] = useState(value?.keterangan ?? '');
    const [aktif, setAktif] = useState(value?.aktif ?? true);
    const [jenisAsetId, setJenisAsetId] = useState(value?.jenis_aset_id ?? '');
    const jenisSource = useMasterOptions(
        canReadJenis ? 'jenis-aset' : null,
        '',
        jenisAsetId,
    );
    const jenisOptions = jenisSource.options;
    const jenisError = jenisSource.error;
    const [error, setError] = useState('');
    const [saving, setSaving] = useState(false);
    useEffect(() => {
        onBusyChange?.(saving);

        return () => onBusyChange?.(false);
    }, [saving, onBusyChange]);
    const sheetContentRef = useRef<HTMLDivElement>(null);
    const { feedbackSummary, reportSaveError, clearSaveFeedback, fieldError } =
        useSaveFeedback(sheetContentRef, value?.id);
    const creationKey = useRef(newIdempotencyKey());

    async function submit(event: FormEvent) {
        event.preventDefault();
        clearSaveFeedback();
        setSaving(true);
        setError('');

        try {
            await api(`/${'model-aset'}${value ? `/${value.id}` : ''}`, {
                method: value ? 'PATCH' : 'POST',
                headers: value
                    ? undefined
                    : { 'Idempotency-Key': creationKey.current },
                body: JSON.stringify({
                    ...(value ? { version: value.version } : {}),
                    nama,
                    keterangan,
                    aktif,
                    pabrikan_aset_id: manufacturer.id,
                    jenis_aset_id: jenisAsetId || null,
                }),
            });
            await onSaved();
        } catch (caught) {
            reportSaveError(caught, 'Model belum dapat disimpan.');
        } finally {
            setSaving(false);
        }
    }

    useRecordCardDirty({
        value: { nama, keterangan, aktif, jenisAsetId },
        ready: true,
        scopeKey: value?.id ?? 'new',
        onDirtyChange,
    });

    return (
        <PageDialog open onOpenChange={(open) => !open && onClose()}>
            <PageDialogContent
                ref={sheetContentRef}
                title={'Model aset'}
                busy={saving}
                onNew={value ? onNew : undefined}
                navigation={value ? navigation : undefined}
            >
                <RecordCardHeader
                    recordUrl={
                        value
                            ? recordQueryUrl('model-aset', value.id)
                            : undefined
                    }
                    recordTitle={
                        value ? `${value.kode} · ${nama}` : nama || 'Baru'
                    }
                    editing
                    onDelete={value ? onDelete : undefined}
                />
                <form
                    noValidate
                    className="flex min-h-0 flex-1 flex-col"
                    onSubmit={submit}
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
                            disabled={saving}
                            type="button"
                            onClick={onClose}
                        />
                    </PageDialogToolbar>
                    {feedbackSummary}
                    <PageDialogBody className="min-h-0 flex-1 overflow-y-auto px-6 py-5">
                        <FormGrid columns={1}>
                            <FormControl>
                                <Input
                                    label="Pabrikan"
                                    value={`${manufacturer.kode} — ${manufacturer.nama}`}
                                    disabled
                                />
                            </FormControl>
                            <FormControl
                                data-error-field={'nama'}
                                error={fieldError('nama')}
                            >
                                <Input
                                    label="Model"
                                    autoFocus
                                    required
                                    maxLength={150}
                                    value={nama}
                                    onChange={(event) =>
                                        setNama(event.target.value)
                                    }
                                    aria-invalid={!!fieldError('nama')}
                                />
                            </FormControl>
                            {canReadJenis ? (
                                <FormControl
                                    data-error-field="jenis_aset_id"
                                    error={fieldError('jenis_aset_id')}
                                    data-invalid={Boolean(
                                        jenisError ||
                                        fieldError('jenis_aset_id'),
                                    )}
                                >
                                    <RecordLookup
                                        source={jenisSource}
                                        label="Jenis aset"
                                        items={[
                                            {
                                                value: '',
                                                label: 'Tidak ditentukan',
                                            },
                                            ...jenisOptions.map((item) => ({
                                                value: item.id,
                                                label: optionLabel(item),
                                            })),
                                        ]}
                                        value={jenisAsetId || ''}
                                        placeholder="Pilih jenis aset"
                                        searchPlaceholder="Cari jenis aset"

                                        ariaLabel="Pilih jenis aset"
                                        portalContainer={sheetContentRef}
                                        onValueChange={(item) =>
                                            setJenisAsetId(item ?? '')
                                        }
                                    />
                                    {jenisError && (
                                        <FieldDescription>
                                            {jenisError}
                                        </FieldDescription>
                                    )}
                                </FormControl>
                            ) : (
                                <FormControl
                                    hint={
                                        <>
                                            Jenis aset tidak ditampilkan karena
                                            Anda belum memiliki akses untuk
                                            membacanya.
                                        </>
                                    }
                                >
                                    <FieldLabel>Jenis aset</FieldLabel>
                                </FormControl>
                            )}
                            <FormControl
                                data-error-field={'keterangan'}
                                error={fieldError('keterangan')}
                            >
                                <Textarea
                                    label="Keterangan"
                                    id="model-description"
                                    rows={4}
                                    maxLength={2000}
                                    value={keterangan}
                                    onChange={(event) =>
                                        setKeterangan(event.target.value)
                                    }
                                />
                            </FormControl>
                            <Field orientation="horizontal">
                                <Switch
                                    id="model-active"
                                    checked={aktif}
                                    onCheckedChange={setAktif}
                                />
                                <FieldLabel htmlFor="model-active">
                                    Data aktif dan dapat dipilih
                                </FieldLabel>
                            </Field>
                            {error && <FieldError>{error}</FieldError>}
                        </FormGrid>
                    </PageDialogBody>
                </form>
            </PageDialogContent>
        </PageDialog>
    );
}
