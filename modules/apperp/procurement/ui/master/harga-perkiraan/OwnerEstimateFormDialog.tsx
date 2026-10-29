import { useMemo, useRef, useState } from 'react';
import type { FormEvent } from 'react';
import { toast } from 'sonner';
import { useWorkDate } from '@/hooks/use-work-date';
import { ActionButton } from '@apperp/ui/action-button';
import { Card, CardContent, CardHeader, CardTitle } from '@apperp/ui/card';
import { FieldLabel } from '@apperp/ui/field';
import { FormControl } from '@apperp/ui/form-control';
import { Input } from '@apperp/ui/input';
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

export type OwnerEstimateRecord = {
    id: string;
    group_item: string;
    jenis_item: string;
    kode_item: string;
    nama_item: string;
    satuan: string;
    harga_perkiraan: string;
    tanggal_update: string | null;
    keterangan?: string | null;
    version: number;
    created_at?: string;
    updated_at?: string;
};

type FormState = {
    group_item: string;
    jenis_item: string;
    kode_item: string;
    nama_item: string;
    satuan: string;
    harga_perkiraan: string;
    tanggal_update: string;
    keterangan: string;
};

export function normalizeCanonicalPrice(
    value: string | number | null | undefined,
): string {
    if (value === null || value === undefined) {
        return '';
    }

    const raw = String(value).trim();

    if (raw === '') {
        return '';
    }

    const normalized = raw.replace(',', '.');

    if (!/^\d{1,16}(\.\d{0,2})?$/.test(normalized)) {
        return normalized;
    }

    const [intPart = '0', fracPart = ''] = normalized.split('.');
    const cleanInt = intPart.replace(/^0+(?=\d)/, '') || '0';
    const cleanFrac = `${fracPart}00`.slice(0, 2);

    return `${cleanInt}.${cleanFrac}`;
}

export function formatIdrCurrency(
    value: string | number | null | undefined,
): string {
    const canonical = normalizeCanonicalPrice(value);

    if (!canonical || !/^\d{1,16}\.\d{2}$/.test(canonical)) {
        return 'Rp 0,00';
    }

    const [intPart, fracPart] = canonical.split('.');
    const formattedInt = intPart.replace(/\B(?=(\d{3})+(?!\d))/g, '.');

    return `Rp ${formattedInt},${fracPart}`;
}

function sanitizeDecimalInput(raw: string): string {
    const withDot = raw.replace(/,/g, '.');
    const cleaned = withDot.replace(/[^0-9.]/g, '');
    const firstDot = cleaned.indexOf('.');

    if (firstDot === -1) {
        return cleaned.slice(0, 16);
    }

    const intPart = cleaned.slice(0, firstDot).slice(0, 16);
    const fracPart = cleaned
        .slice(firstDot + 1)
        .replace(/\./g, '')
        .slice(0, 2);

    return `${intPart}.${fracPart}`;
}

function buildInitialFormState(
    initialData: OwnerEstimateRecord | null | undefined,
    defaultDate: string,
): FormState {
    if (!initialData) {
        return {
            group_item: '',
            jenis_item: '',
            kode_item: '',
            nama_item: '',
            satuan: '',
            harga_perkiraan: '0.00',
            tanggal_update: defaultDate,
            keterangan: '',
        };
    }

    return {
        group_item: initialData.group_item ?? '',
        jenis_item: initialData.jenis_item ?? '',
        kode_item: initialData.kode_item ?? '',
        nama_item: initialData.nama_item ?? '',
        satuan: initialData.satuan ?? '',
        harga_perkiraan:
            normalizeCanonicalPrice(initialData.harga_perkiraan) || '0.00',
        tanggal_update: initialData.tanggal_update || defaultDate,
        keterangan: initialData.keterangan ?? '',
    };
}

type Props = {
    open: boolean;
    onClose: () => void;
    initialData?: OwnerEstimateRecord | null;
    onSaved: (record: OwnerEstimateRecord) => void;
    onDelete?: () => void;
};

export function OwnerEstimateFormDialog({
    open,
    onClose,
    initialData,
    onSaved,
    onDelete,
}: Props) {
    const { date: workDate } = useWorkDate();
    const isEdit = Boolean(initialData?.id);
    const recordKey = initialData?.id ?? 'new';

    const contentRef = useRef<HTMLDivElement>(null);
    const creationKey = useRef(newIdempotencyKey());

    const baseline = useMemo(
        () => buildInitialFormState(initialData, workDate),
        [initialData, workDate],
    );
    const [form, setForm] = useState<FormState>(() =>
        buildInitialFormState(initialData, workDate),
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

        if (!form.group_item.trim()) {
            fieldErrors.group_item = ['Group item wajib diisi.'];
        }

        if (!form.jenis_item.trim()) {
            fieldErrors.jenis_item = ['Jenis item wajib diisi.'];
        }

        if (!form.kode_item.trim()) {
            fieldErrors.kode_item = ['Kode item wajib diisi.'];
        }

        if (!form.nama_item.trim()) {
            fieldErrors.nama_item = ['Nama item wajib diisi.'];
        }

        if (!form.satuan.trim()) {
            fieldErrors.satuan = ['Satuan wajib diisi.'];
        }

        const rawPrice = form.harga_perkiraan.trim().replace(',', '.');

        if (!rawPrice) {
            fieldErrors.harga_perkiraan = ['Harga perkiraan wajib diisi.'];
        } else if (!/^\d{1,16}(\.\d{1,2})?$/.test(rawPrice)) {
            fieldErrors.harga_perkiraan = [
                'Harga perkiraan harus berupa angka desimal positif (maksimal 16 digit bulat dan 2 angka desimal).',
            ];
        }

        if (!form.tanggal_update.trim()) {
            fieldErrors.tanggal_update = ['Tanggal update wajib diisi.'];
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
                    'Lengkapi kolom wajib yang ditandai sebelum menyimpan harga perkiraan.',
                    validationErrors,
                    422,
                ),
                'menyimpan harga perkiraan',
            );

            return;
        }

        const canonicalPrice = normalizeCanonicalPrice(form.harga_perkiraan);

        const payload = {
            ...(isEdit && initialData ? { version: initialData.version } : {}),
            group_item: form.group_item.trim(),
            jenis_item: form.jenis_item.trim(),
            kode_item: form.kode_item.trim(),
            nama_item: form.nama_item.trim(),
            satuan: form.satuan.trim(),
            harga_perkiraan: canonicalPrice,
            tanggal_update: form.tanggal_update.trim(),
            keterangan: form.keterangan.trim() || null,
        };

        setSaving(true);

        try {
            const response = await api<{ data: OwnerEstimateRecord }>(
                isEdit && initialData
                    ? `/harga-perkiraan/${encodeURIComponent(initialData.id)}`
                    : '/harga-perkiraan',
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
                    ? 'Data harga perkiraan berhasil diperbarui.'
                    : 'Data harga perkiraan berhasil disimpan.',
            );
            onSaved(response.data);
            onClose();
        } catch (caught) {
            reportSaveError(caught, 'menyimpan harga perkiraan');
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
                            ? 'Ubah Harga Perkiraan Sendiri (HPS)'
                            : 'Tambah Harga Perkiraan Sendiri (HPS)'
                    }
                    recordTitle={
                        isEdit && initialData
                            ? `${initialData.kode_item} · ${form.nama_item || initialData.nama_item}`
                            : form.nama_item || 'Baru'
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
                                        Rincian Item & Harga Perkiraan
                                    </CardTitle>
                                </CardHeader>
                                <CardContent className="grid grid-cols-1 gap-4 pt-4 text-xs md:grid-cols-2">
                                    <FormControl
                                        data-error-field="group_item"
                                        error={getFieldError('group_item')}
                                    >
                                        <FieldLabel htmlFor="group_item">
                                            Group Item *
                                        </FieldLabel>
                                        <Input
                                            id="group_item"
                                            name="group_item"
                                            value={form.group_item}
                                            onChange={(e) =>
                                                setForm((prev) => ({
                                                    ...prev,
                                                    group_item: e.target.value,
                                                }))
                                            }
                                            placeholder="Contoh: Material, Jasa, Alat"
                                            aria-invalid={
                                                getFieldError('group_item')
                                                    ? true
                                                    : undefined
                                            }
                                        />
                                    </FormControl>

                                    <FormControl
                                        data-error-field="jenis_item"
                                        error={getFieldError('jenis_item')}
                                    >
                                        <FieldLabel htmlFor="jenis_item">
                                            Jenis Item *
                                        </FieldLabel>
                                        <Input
                                            id="jenis_item"
                                            name="jenis_item"
                                            value={form.jenis_item}
                                            onChange={(e) =>
                                                setForm((prev) => ({
                                                    ...prev,
                                                    jenis_item: e.target.value,
                                                }))
                                            }
                                            placeholder="Contoh: Besi & Baja, Elektrikal"
                                            aria-invalid={
                                                getFieldError('jenis_item')
                                                    ? true
                                                    : undefined
                                            }
                                        />
                                    </FormControl>

                                    <FormControl
                                        data-error-field="kode_item"
                                        error={getFieldError('kode_item')}
                                    >
                                        <FieldLabel htmlFor="kode_item">
                                            Kode Item *
                                        </FieldLabel>
                                        <Input
                                            id="kode_item"
                                            name="kode_item"
                                            value={form.kode_item}
                                            onChange={(e) =>
                                                setForm((prev) => ({
                                                    ...prev,
                                                    kode_item: e.target.value,
                                                }))
                                            }
                                            placeholder="Contoh: MAT-BS-001"
                                            className="font-mono"
                                            aria-invalid={
                                                getFieldError('kode_item')
                                                    ? true
                                                    : undefined
                                            }
                                        />
                                    </FormControl>

                                    <FormControl
                                        data-error-field="satuan"
                                        error={getFieldError('satuan')}
                                    >
                                        <FieldLabel htmlFor="satuan">
                                            Satuan *
                                        </FieldLabel>
                                        <Input
                                            id="satuan"
                                            name="satuan"
                                            value={form.satuan}
                                            onChange={(e) =>
                                                setForm((prev) => ({
                                                    ...prev,
                                                    satuan: e.target.value,
                                                }))
                                            }
                                            placeholder="Contoh: Batang, Kg, Unit, Lot"
                                            aria-invalid={
                                                getFieldError('satuan')
                                                    ? true
                                                    : undefined
                                            }
                                        />
                                    </FormControl>

                                    <div className="md:col-span-2">
                                        <FormControl
                                            data-error-field="nama_item"
                                            error={getFieldError('nama_item')}
                                        >
                                            <FieldLabel htmlFor="nama_item">
                                                Nama Item *
                                            </FieldLabel>
                                            <Input
                                                id="nama_item"
                                                name="nama_item"
                                                value={form.nama_item}
                                                onChange={(e) =>
                                                    setForm((prev) => ({
                                                        ...prev,
                                                        nama_item:
                                                            e.target.value,
                                                    }))
                                                }
                                                placeholder="Masukkan nama atau spesifikasi item"
                                                aria-invalid={
                                                    getFieldError('nama_item')
                                                        ? true
                                                        : undefined
                                                }
                                            />
                                        </FormControl>
                                    </div>

                                    <FormControl
                                        data-error-field="harga_perkiraan"
                                        error={getFieldError('harga_perkiraan')}
                                        hint="Gunakan titik (.) atau koma (,) untuk memisahkan hingga 2 angka desimal."
                                    >
                                        <FieldLabel htmlFor="harga_perkiraan">
                                            Harga Perkiraan (Rp) *
                                        </FieldLabel>
                                        <Input
                                            id="harga_perkiraan"
                                            name="harga_perkiraan"
                                            type="text"
                                            inputMode="decimal"
                                            value={form.harga_perkiraan}
                                            onChange={(e) =>
                                                setForm((prev) => ({
                                                    ...prev,
                                                    harga_perkiraan:
                                                        sanitizeDecimalInput(
                                                            e.target.value,
                                                        ),
                                                }))
                                            }
                                            onBlur={() =>
                                                setForm((prev) => ({
                                                    ...prev,
                                                    harga_perkiraan:
                                                        normalizeCanonicalPrice(
                                                            prev.harga_perkiraan,
                                                        ) ||
                                                        prev.harga_perkiraan,
                                                }))
                                            }
                                            placeholder="0.00"
                                            className="text-right font-mono font-semibold"
                                            aria-invalid={
                                                getFieldError('harga_perkiraan')
                                                    ? true
                                                    : undefined
                                            }
                                        />
                                        <p className="text-muted-foreground mt-1 text-right font-mono text-[11px]">
                                            {formatIdrCurrency(
                                                form.harga_perkiraan,
                                            )}
                                        </p>
                                    </FormControl>

                                    <FormControl
                                        data-error-field="tanggal_update"
                                        error={getFieldError('tanggal_update')}
                                    >
                                        <FieldLabel htmlFor="tanggal_update">
                                            Tanggal Update *
                                        </FieldLabel>
                                        <Input
                                            id="tanggal_update"
                                            name="tanggal_update"
                                            type="date"
                                            value={form.tanggal_update}
                                            onChange={(e) =>
                                                setForm((prev) => ({
                                                    ...prev,
                                                    tanggal_update:
                                                        e.target.value,
                                                }))
                                            }
                                            aria-invalid={
                                                getFieldError('tanggal_update')
                                                    ? true
                                                    : undefined
                                            }
                                        />
                                    </FormControl>

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
                                                rows={2}
                                                value={form.keterangan}
                                                onChange={(e) =>
                                                    setForm((prev) => ({
                                                        ...prev,
                                                        keterangan:
                                                            e.target.value,
                                                    }))
                                                }
                                                placeholder="Catatan sumber survei pasar, kontrak acuan, atau spesifikasi tambahan"
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
