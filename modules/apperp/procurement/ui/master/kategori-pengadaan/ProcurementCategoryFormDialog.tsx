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

export type ProcurementCategoryRecord = {
    id: string;
    kode_pengadaan: string;
    kategori_pengadaan: string;
    keterangan?: string | null;
    aktif: boolean;
    version: number;
    created_at?: string;
    updated_at?: string;
};

type FormState = {
    kode_pengadaan: string;
    kategori_pengadaan: string;
    keterangan: string;
    aktif: boolean;
};

function buildInitialFormState(
    initialData: ProcurementCategoryRecord | null | undefined,
): FormState {
    if (!initialData) {
        return {
            kode_pengadaan: '',
            kategori_pengadaan: '',
            keterangan: '',
            aktif: true,
        };
    }

    return {
        kode_pengadaan: initialData.kode_pengadaan ?? '',
        kategori_pengadaan: initialData.kategori_pengadaan ?? '',
        keterangan: initialData.keterangan ?? '',
        aktif: initialData.aktif ?? true,
    };
}

type Props = {
    open: boolean;
    onClose: () => void;
    initialData?: ProcurementCategoryRecord | null;
    onSaved: (record: ProcurementCategoryRecord) => void;
    onDelete?: () => void;
};

export function ProcurementCategoryFormDialog({
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

        if (!form.kode_pengadaan.trim()) {
            fieldErrors.kode_pengadaan = ['Kode pengadaan wajib diisi.'];
        }

        if (!form.kategori_pengadaan.trim()) {
            fieldErrors.kategori_pengadaan = [
                'Nama kategori pengadaan wajib diisi.',
            ];
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
                    'Lengkapi kolom wajib yang ditandai sebelum menyimpan kategori pengadaan.',
                    validationErrors,
                    422,
                ),
                'menyimpan kategori pengadaan',
            );

            return;
        }

        const payload = {
            ...(isEdit && initialData ? { version: initialData.version } : {}),
            kode_pengadaan: form.kode_pengadaan.trim(),
            kategori_pengadaan: form.kategori_pengadaan.trim(),
            keterangan: form.keterangan.trim() || null,
            aktif: form.aktif,
        };

        setSaving(true);

        try {
            const response = await api<{ data: ProcurementCategoryRecord }>(
                isEdit && initialData
                    ? `/kategori-pengadaan/${encodeURIComponent(initialData.id)}`
                    : '/kategori-pengadaan',
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
                    ? 'Data kategori pengadaan berhasil diperbarui.'
                    : 'Data kategori pengadaan berhasil disimpan.',
            );
            onSaved(response.data);
            onClose();
        } catch (caught) {
            reportSaveError(caught, 'menyimpan kategori pengadaan');
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
                            ? 'Ubah Kategori Pengadaan'
                            : 'Tambah Kategori Pengadaan'
                    }
                    recordTitle={
                        isEdit && initialData
                            ? `${initialData.kode_pengadaan} · ${form.kategori_pengadaan || initialData.kategori_pengadaan}`
                            : form.kategori_pengadaan || 'Baru'
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
                                        Identitas Kategori Pengadaan
                                    </CardTitle>
                                </CardHeader>
                                <CardContent className="grid grid-cols-1 gap-4 pt-4 text-xs md:grid-cols-2">
                                    <FormControl
                                        data-error-field="kode_pengadaan"
                                        error={getFieldError('kode_pengadaan')}
                                    >
                                        <FieldLabel htmlFor="kode_pengadaan">
                                            Kode Pengadaan *
                                        </FieldLabel>
                                        <Input
                                            id="kode_pengadaan"
                                            name="kode_pengadaan"
                                            value={form.kode_pengadaan}
                                            onChange={(e) =>
                                                setForm((prev) => ({
                                                    ...prev,
                                                    kode_pengadaan:
                                                        e.target.value,
                                                }))
                                            }
                                            placeholder="Contoh: KAT-BARANG, KAT-JASA"
                                            className="font-mono"
                                            aria-invalid={
                                                getFieldError('kode_pengadaan')
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
                                            data-error-field="kategori_pengadaan"
                                            error={getFieldError(
                                                'kategori_pengadaan',
                                            )}
                                        >
                                            <FieldLabel htmlFor="kategori_pengadaan">
                                                Nama Kategori Pengadaan *
                                            </FieldLabel>
                                            <Input
                                                id="kategori_pengadaan"
                                                name="kategori_pengadaan"
                                                value={form.kategori_pengadaan}
                                                onChange={(e) =>
                                                    setForm((prev) => ({
                                                        ...prev,
                                                        kategori_pengadaan:
                                                            e.target.value,
                                                    }))
                                                }
                                                placeholder="Contoh: Pengadaan Barang Modal, Pengadaan Jasa Konsultansi"
                                                aria-invalid={
                                                    getFieldError(
                                                        'kategori_pengadaan',
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
                                                placeholder="Catatan tambahan mengenai ruang lingkup kategori pengadaan"
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
