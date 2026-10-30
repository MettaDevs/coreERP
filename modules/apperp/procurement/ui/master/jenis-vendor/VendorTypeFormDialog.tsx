import { useMemo, useRef, useState } from 'react';
import type { FormEvent } from 'react';
import { toast } from 'sonner';
import { ActionButton } from '@apperp/ui/action-button';
import { Card, CardContent, CardHeader, CardTitle } from '@apperp/ui/card';
import { FieldLabel } from '@apperp/ui/field';
import { FormControl } from '@apperp/ui/form-control';
import { Input } from '@apperp/ui/input';
import { NativeSelect, NativeSelectOption } from '@apperp/ui/native-select';
import {
    PageDialog,
    PageDialogBody,
    PageDialogContent,
    PageDialogToolbar,
} from '@apperp/ui/page-dialog';
import { Textarea } from '@apperp/ui/textarea';
import { useRecordTransition } from '@apperp/ui/use-record-transition';
import { ApiError, api, newIdempotencyKey } from '../../api';
import { useSaveFeedback } from '../../use-save-feedback';

export type VendorTypeRecord = {
    id: string;
    kode_jenis: string;
    nama_jenis_vendor: string;
    keterangan?: string | null;
    aktif: boolean;
    version: number;
    created_at?: string;
    updated_at?: string;
};

type FormState = {
    kode_jenis: string;
    nama_jenis_vendor: string;
    keterangan: string;
    aktif: boolean;
};

function buildInitialFormState(
    initialData: VendorTypeRecord | null | undefined,
): FormState {
    if (!initialData) {
        return {
            kode_jenis: '',
            nama_jenis_vendor: '',
            keterangan: '',
            aktif: true,
        };
    }

    return {
        kode_jenis: initialData.kode_jenis ?? '',
        nama_jenis_vendor: initialData.nama_jenis_vendor ?? '',
        keterangan: initialData.keterangan ?? '',
        aktif: initialData.aktif ?? true,
    };
}

type Props = {
    open: boolean;
    onClose: () => void;
    initialData?: VendorTypeRecord | null;
    onSaved: (record: VendorTypeRecord) => void;
    onDelete?: () => void;
};

export function VendorTypeFormDialog({
    open,
    onClose,
    initialData,
    onSaved,
    onDelete,
}: Props) {
    const isEdit = Boolean(initialData?.id);
    const recordKey = initialData?.id ?? 'new';

    const contentRef = useRef<HTMLDivElement>(null);
    const creationKey = useRef(newIdempotencyKey());

    const baseline = useMemo(
        () => buildInitialFormState(initialData),
        [initialData],
    );
    const [form, setForm] = useState<FormState>(() =>
        buildInitialFormState(initialData),
    );
    const [saving, setSaving] = useState(false);

    const {
        reportSaveError,
        clearSaveFeedback,
        fieldError: getFieldError,
        feedbackSummary,
    } = useSaveFeedback(contentRef, recordKey);

    const dirty = useMemo(
        () => JSON.stringify(form) !== JSON.stringify(baseline),
        [form, baseline],
    );

    const transition = useRecordTransition(dirty, saving);

    const validateBeforeSubmit = (): Record<string, string[]> | null => {
        const fieldErrors: Record<string, string[]> = {};

        if (!form.kode_jenis.trim()) {
            fieldErrors.kode_jenis = ['Kode jenis vendor wajib diisi.'];
        }

        if (!form.nama_jenis_vendor.trim()) {
            fieldErrors.nama_jenis_vendor = ['Nama jenis vendor wajib diisi.'];
        }

        if (Object.keys(fieldErrors).length === 0) {
            return null;
        }

        return fieldErrors;
    };

    const handleSubmit = async (e: FormEvent) => {
        e.preventDefault();
        clearSaveFeedback();

        const validationErrors = validateBeforeSubmit();

        if (validationErrors) {
            reportSaveError(
                new ApiError(
                    'Lengkapi kolom wajib yang ditandai sebelum menyimpan jenis vendor.',
                    validationErrors,
                    422,
                ),
                'menyimpan jenis vendor',
            );

            return;
        }

        const payload = {
            ...(isEdit && initialData ? { version: initialData.version } : {}),
            kode_jenis: form.kode_jenis.trim(),
            nama_jenis_vendor: form.nama_jenis_vendor.trim(),
            keterangan: form.keterangan.trim() || null,
            aktif: form.aktif,
        };

        setSaving(true);

        try {
            const response = await api<{ data: VendorTypeRecord }>(
                isEdit && initialData
                    ? `/jenis-vendor/${encodeURIComponent(initialData.id)}`
                    : '/jenis-vendor',
                {
                    method: isEdit ? 'PUT' : 'POST',
                    headers: isEdit
                        ? undefined
                        : { 'Idempotency-Key': creationKey.current },
                    body: JSON.stringify(payload),
                },
            );

            toast.success(
                isEdit
                    ? 'Data jenis vendor berhasil diperbarui.'
                    : 'Data jenis vendor berhasil disimpan.',
            );
            onSaved(response.data);
            onClose();
        } catch (caught) {
            reportSaveError(caught, 'menyimpan jenis vendor');
        } finally {
            setSaving(false);
        }
    };

    if (!open) {
        return null;
    }

    return (
        <>
            <PageDialog
                open={open}
                onOpenChange={(nextOpen) =>
                    !nextOpen && transition.request(onClose)
                }
            >
                <PageDialogContent
                    ref={contentRef}
                    aria-describedby={undefined}
                    title={
                        isEdit
                            ? 'Ubah Jenis Vendor/Supplier'
                            : 'Tambah Jenis Vendor/Supplier'
                    }
                    recordTitle={
                        isEdit && initialData
                            ? `${initialData.kode_jenis} · ${form.nama_jenis_vendor || initialData.nama_jenis_vendor}`
                            : form.nama_jenis_vendor || 'Baru'
                    }
                    editing
                    busy={saving}
                    onDelete={isEdit ? onDelete : undefined}
                >
                    <form
                        noValidate
                        onSubmit={handleSubmit}
                        className="flex min-h-0 flex-1 flex-col"
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
                                disabled={saving}
                                onClick={() => transition.request(onClose)}
                            />
                        </PageDialogToolbar>

                        {feedbackSummary}

                        <PageDialogBody
                            className="space-y-4"
                            scroll-region="true"
                        >
                            <Card className="shadow-xs border">
                                <CardHeader className="border-b py-3">
                                    <CardTitle className="text-sm">
                                        Identitas Jenis Vendor/Supplier
                                    </CardTitle>
                                </CardHeader>
                                <CardContent className="grid grid-cols-1 gap-4 pt-4 text-xs md:grid-cols-2">
                                    <FormControl
                                        data-error-field="kode_jenis"
                                        error={getFieldError('kode_jenis')}
                                    >
                                        <FieldLabel htmlFor="kode_jenis">
                                            Kode Jenis Vendor *
                                        </FieldLabel>
                                        <Input
                                            id="kode_jenis"
                                            name="kode_jenis"
                                            value={form.kode_jenis}
                                            onChange={(e) =>
                                                setForm((prev) => ({
                                                    ...prev,
                                                    kode_jenis: e.target.value,
                                                }))
                                            }
                                            placeholder="Contoh: DISTRIBUTOR, PRINCIPAL"
                                            className="font-mono"
                                            aria-invalid={
                                                getFieldError('kode_jenis')
                                                    ? true
                                                    : undefined
                                            }
                                        />
                                    </FormControl>

                                    <FormControl
                                        data-error-field="aktif"
                                        error={getFieldError('aktif')}
                                    >
                                        <FieldLabel htmlFor="aktif">
                                            Status *
                                        </FieldLabel>
                                        <NativeSelect
                                            id="aktif"
                                            name="aktif"
                                            value={form.aktif ? '1' : '0'}
                                            onChange={(e) =>
                                                setForm((prev) => ({
                                                    ...prev,
                                                    aktif:
                                                        e.target.value === '1',
                                                }))
                                            }
                                        >
                                            <NativeSelectOption value="1">
                                                Aktif
                                            </NativeSelectOption>
                                            <NativeSelectOption value="0">
                                                Nonaktif
                                            </NativeSelectOption>
                                        </NativeSelect>
                                    </FormControl>

                                    <div className="md:col-span-2">
                                        <FormControl
                                            data-error-field="nama_jenis_vendor"
                                            error={getFieldError(
                                                'nama_jenis_vendor',
                                            )}
                                        >
                                            <FieldLabel htmlFor="nama_jenis_vendor">
                                                Nama Jenis Vendor/Supplier *
                                            </FieldLabel>
                                            <Input
                                                id="nama_jenis_vendor"
                                                name="nama_jenis_vendor"
                                                value={form.nama_jenis_vendor}
                                                onChange={(e) =>
                                                    setForm((prev) => ({
                                                        ...prev,
                                                        nama_jenis_vendor:
                                                            e.target.value,
                                                    }))
                                                }
                                                placeholder="Contoh: Distributor Resmi, Subkontraktor, Agen Tunggal"
                                                aria-invalid={
                                                    getFieldError(
                                                        'nama_jenis_vendor',
                                                    )
                                                        ? true
                                                        : undefined
                                                }
                                            />
                                        </FormControl>
                                    </div>

                                    <div className="md:col-span-2">
                                        <FormControl
                                            data-error-field="keterangan"
                                            error={getFieldError('keterangan')}
                                        >
                                            <FieldLabel htmlFor="keterangan">
                                                Keterangan
                                            </FieldLabel>
                                            <Textarea
                                                id="keterangan"
                                                name="keterangan"
                                                rows={3}
                                                value={form.keterangan}
                                                onChange={(e) =>
                                                    setForm((prev) => ({
                                                        ...prev,
                                                        keterangan:
                                                            e.target.value,
                                                    }))
                                                }
                                                placeholder="Catatan tambahan mengenai klasifikasi atau syarat jenis rekanan"
                                            />
                                        </FormControl>
                                    </div>
                                </CardContent>
                            </Card>
                        </PageDialogBody>
                    </form>
                </PageDialogContent>
            </PageDialog>
            {transition.confirmation}
        </>
    );
}
