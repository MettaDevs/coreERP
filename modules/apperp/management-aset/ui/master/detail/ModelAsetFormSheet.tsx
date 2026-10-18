import { useEffect, useMemo, useRef, useState } from 'react';
import type { FormEvent } from 'react';
import { ActionButton } from '@apperp/ui/action-button';

import {
    Field,
    FieldDescription,
    FieldError,
    FieldGroup,
    FieldLabel,
} from '@apperp/ui/field';
import { Input } from '@apperp/ui/input';
import type { PageDialogNavigation } from '@apperp/ui/page-dialog';
import {
    PageDialog,
    PageDialogContent,
    PageDialogToolbar,
    PageDialogBody,
} from '@apperp/ui/page-dialog';
import { Select } from '@apperp/ui/select';

import { Switch } from '@apperp/ui/switch';
import { Textarea } from '@apperp/ui/textarea';
import { api, errorMessage, newIdempotencyKey } from '../../api';
import {
    RecordCardHeader,
    recordQueryUrl,
} from '../../transactions/_shared/record-card';
import { useRecordCardDirty } from '../../transactions/_shared/use-record-card-dirty';
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
    const creationKey = useRef(newIdempotencyKey());

    const jenisLabel = useMemo(() => {
        const selected = jenisOptions.find(
            (option) => option.id === jenisAsetId,
        );

        return selected ? optionLabel(selected) : 'Tidak ditentukan';
    }, [jenisAsetId, jenisOptions]);

    async function submit(event: FormEvent) {
        event.preventDefault();
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
            setError(errorMessage(caught, 'Model belum dapat disimpan.'));
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
                    <PageDialogBody className="min-h-0 flex-1 overflow-y-auto px-6 py-5">
                        <FieldGroup>
                            <Field>
                                <Input
                                    label="Pabrikan"
                                    value={`${manufacturer.kode} — ${manufacturer.nama}`}
                                    disabled
                                />
                            </Field>
                            <Field>
                                <Input
                                    label="Model"
                                    autoFocus
                                    required
                                    maxLength={150}
                                    value={nama}
                                    onChange={(event) =>
                                        setNama(event.target.value)
                                    }
                                />
                            </Field>
                            {canReadJenis ? (
                                <Field data-invalid={Boolean(jenisError)}>
                                    <Select
                                        {...jenisSource.selectProps}
                                        label="Jenis aset"
                                        items={[
                                            'Tidak ditentukan',
                                            ...jenisOptions.map(optionLabel),
                                        ]}
                                        value={jenisLabel}
                                        placeholder="Pilih jenis aset"
                                        searchPlaceholder="Cari jenis aset"
                                        emptyMessage="Jenis aset tidak ditemukan."
                                        ariaLabel="Pilih jenis aset"
                                        portalContainer={sheetContentRef}
                                        onValueChange={(item) =>
                                            setJenisAsetId(
                                                item === 'Tidak ditentukan'
                                                    ? ''
                                                    : (jenisOptions.find(
                                                          (option) =>
                                                              optionLabel(
                                                                  option,
                                                              ) === item,
                                                      )?.id ?? ''),
                                            )
                                        }
                                    />
                                    {jenisError && (
                                        <FieldDescription>
                                            {jenisError}
                                        </FieldDescription>
                                    )}
                                </Field>
                            ) : (
                                <Field>
                                    <FieldLabel>Jenis aset</FieldLabel>
                                    <FieldDescription>
                                        Jenis aset tidak ditampilkan karena Anda
                                        belum memiliki akses untuk membacanya.
                                    </FieldDescription>
                                </Field>
                            )}
                            <Field>
                                <FieldLabel htmlFor="model-description">
                                    Keterangan
                                </FieldLabel>
                                <Textarea
                                    id="model-description"
                                    rows={4}
                                    maxLength={2000}
                                    value={keterangan}
                                    onChange={(event) =>
                                        setKeterangan(event.target.value)
                                    }
                                />
                            </Field>
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
                        </FieldGroup>
                    </PageDialogBody>
                </form>
            </PageDialogContent>
        </PageDialog>
    );
}
