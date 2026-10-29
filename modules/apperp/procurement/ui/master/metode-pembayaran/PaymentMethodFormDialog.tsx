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

export type PaymentMethodRecord = {
    id: string;
    kode_bayar: string;
    nama_metode_bayar: string;
    keterangan?: string | null;
    aktif: boolean;
    version: number;
    created_at?: string;
    updated_at?: string;
};

type FormState = {
    kode_bayar: string;
    nama_metode_bayar: string;
    keterangan: string;
    aktif: boolean;
};

function buildInitialFormState(
    initialData: PaymentMethodRecord | null | undefined,
): FormState {
    if (!initialData) {
        return {
            kode_bayar: '',
            nama_metode_bayar: '',
            keterangan: '',
            aktif: true,
        };
    }

    return {
        kode_bayar: initialData.kode_bayar ?? '',
        nama_metode_bayar: initialData.nama_metode_bayar ?? '',
        keterangan: initialData.keterangan ?? '',
        aktif: initialData.aktif ?? true,
    };
}

type Props = {
    open: boolean;
    onClose: () => void;
    initialData?: PaymentMethodRecord | null;
    onSaved: (record: PaymentMethodRecord) => void;
    onDelete?: () => void;
};

export function PaymentMethodFormDialog({
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

        if (!form.kode_bayar.trim()) {
            fieldErrors.kode_bayar = ['Kode metode pembayaran wajib diisi.'];
        }

        if (!form.nama_metode_bayar.trim()) {
            fieldErrors.nama_metode_bayar = [
                'Nama metode pembayaran wajib diisi.',
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
                    'Lengkapi kolom wajib yang ditandai sebelum menyimpan metode pembayaran.',
                    validationErrors,
                    422,
                ),
                'menyimpan metode pembayaran',
            );

            return;
        }

        const payload = {
            ...(isEdit && initialData ? { version: initialData.version } : {}),
            kode_bayar: form.kode_bayar.trim(),
            nama_metode_bayar: form.nama_metode_bayar.trim(),
            keterangan: form.keterangan.trim() || null,
            aktif: form.aktif,
        };

        setSaving(true);

        try {
            const response = await api<{ data: PaymentMethodRecord }>(
                isEdit && initialData
                    ? `/metode-pembayaran/${encodeURIComponent(initialData.id)}`
                    : '/metode-pembayaran',
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
                    ? 'Data metode pembayaran berhasil diperbarui.'
                    : 'Data metode pembayaran berhasil disimpan.',
            );
            onSaved(response.data);
            onClose();
        } catch (caught) {
            reportSaveError(caught, 'menyimpan metode pembayaran');
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
                            ? 'Ubah Metode Pembayaran'
                            : 'Tambah Metode Pembayaran'
                    }
                    recordTitle={
                        isEdit && initialData
                            ? `${initialData.kode_bayar} · ${form.nama_metode_bayar || initialData.nama_metode_bayar}`
                            : form.nama_metode_bayar || 'Baru'
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
                                        Identitas Metode Pembayaran
                                    </CardTitle>
                                </CardHeader>
                                <CardContent className="grid grid-cols-1 gap-4 pt-4 text-xs md:grid-cols-2">
                                    <FormControl
                                        data-error-field="kode_bayar"
                                        error={getFieldError('kode_bayar')}
                                    >
                                        <FieldLabel htmlFor="kode_bayar">
                                            Kode Bayar *
                                        </FieldLabel>
                                        <Input
                                            id="kode_bayar"
                                            name="kode_bayar"
                                            value={form.kode_bayar}
                                            onChange={(e) =>
                                                setForm((prev) => ({
                                                    ...prev,
                                                    kode_bayar: e.target.value,
                                                }))
                                            }
                                            placeholder="Contoh: TRF, CASH, BG"
                                            className="font-mono"
                                            aria-invalid={
                                                getFieldError('kode_bayar')
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
                                            data-error-field="nama_metode_bayar"
                                            error={getFieldError(
                                                'nama_metode_bayar',
                                            )}
                                        >
                                            <FieldLabel htmlFor="nama_metode_bayar">
                                                Nama Metode Pembayaran *
                                            </FieldLabel>
                                            <Input
                                                id="nama_metode_bayar"
                                                name="nama_metode_bayar"
                                                value={form.nama_metode_bayar}
                                                onChange={(e) =>
                                                    setForm((prev) => ({
                                                        ...prev,
                                                        nama_metode_bayar:
                                                            e.target.value,
                                                    }))
                                                }
                                                placeholder="Contoh: Transfer Bank, Tunai / Kas Kecil, Bilyet Giro"
                                                aria-invalid={
                                                    getFieldError(
                                                        'nama_metode_bayar',
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
                                                placeholder="Catatan tambahan mengenai ketentuan pembayaran atau rekening perantara"
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
