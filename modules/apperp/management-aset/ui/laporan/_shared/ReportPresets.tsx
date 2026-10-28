import { BookmarkPlus } from 'lucide-react';
import { useId, useRef, useState } from 'react';
import { toast } from 'sonner';
import { ActionButton } from '@apperp/ui/action-button';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@apperp/ui/alert-dialog';
import { Button } from '@apperp/ui/button';
import { Checkbox } from '@apperp/ui/checkbox';
import {
    DialogClose,
    Dialog,
    DialogBody,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@apperp/ui/dialog';
import { FieldDescription, FieldError } from '@apperp/ui/field';
import { FormControl } from '@apperp/ui/form-control';
import { InlineLookup } from '@apperp/ui/inline-lookup';
import { Input } from '@apperp/ui/input';
import { Select } from '@apperp/ui/select';
import { ApiError } from '../../api';
import { useSaveFeedback } from '../../use-save-feedback';
import { cleanFilters } from './reportOptions';
import type { Filters, ReportDateParameters } from './reportOptions';
import type { ReportPresetState } from './useReportData';

const NONE = '__none__';

/** Pilihan tanggal pada preset. `fixed` menyimpan tanggal filter apa adanya. */
const RANGE_CHOICES = [
    { value: 'fixed', label: 'Tanggal tetap seperti filter sekarang' },
    { value: 'today', label: 'Hari ini' },
    { value: 'this_month', label: 'Bulan ini' },
    { value: 'last_month', label: 'Bulan lalu' },
    { value: 'this_year', label: 'Tahun ini' },
    { value: 'last_year', label: 'Tahun lalu' },
] as const;

const MONTH_CHOICES = [
    { value: 'fixed', label: 'Bulan tetap seperti filter sekarang' },
    { value: 'this_month', label: 'Bulan ini' },
    { value: 'last_month', label: 'Bulan lalu' },
] as const;

/** Parameter preset: filter yang terisi, dengan tanggal diganti token relatif bila dipilih. */
function presetParameters(
    filters: Filters,
    dates: ReportDateParameters | undefined,
    choice: string,
): Filters {
    const parameters = cleanFilters(filters);

    if (!dates || choice === 'fixed') {
        return parameters;
    }

    if ('month' in dates) {
        return { ...parameters, [dates.month]: `@${choice}` };
    }

    if (choice === 'today') {
        return { ...parameters, [dates.from]: '@today', [dates.to]: '@today' };
    }

    return {
        ...parameters,
        [dates.from]: `@${choice}.start`,
        [dates.to]: `@${choice}.end`,
    };
}

/**
 * Preset laporan (K-25): memasang filter yang tersimpan dengan satu pilihan, dan menyimpan filter yang
 * sedang dipakai dengan nama. Preset pribadi hanya terlihat pemiliknya; preset bersama terlihat semua orang
 * yang boleh menjalankan laporannya, dengan nama pembuatnya. Membagikan preset, dan mengarsipkan preset
 * bersama buatan siapa pun, hanya untuk pemegang izin preset bersama.
 */
export function ReportPresets({
    state,
    dates,
}: {
    state: ReportPresetState;
    dates?: ReportDateParameters;
}) {
    const portal = useRef<HTMLDivElement>(null);
    const archiveScope = useRef<HTMLDivElement>(null);
    const nameErrorId = useId();
    const {
        feedbackSummary: saveFeedback,
        reportSaveError: reportSaveError,
        clearSaveFeedback: clearSaveFeedback,
    } = useSaveFeedback(portal, `${state.reportCode}:new-preset`);
    const [saving, setSaving] = useState(false);
    const [archiving, setArchiving] = useState(false);
    const [name, setName] = useState('');
    const [choice, setChoice] = useState('fixed');
    const [shared, setShared] = useState(false);
    const [nameError, setNameError] = useState('');
    const [busy, setBusy] = useState(false);
    const [archiveBusy, setArchiveBusy] = useState(false);

    const selected =
        state.presets.find((preset) => preset.id === state.selectedId) ?? null;
    const {
        feedbackSummary: archiveFeedback,
        reportSaveError: reportArchiveError,
        clearSaveFeedback: clearArchiveFeedback,
    } = useSaveFeedback(
        archiveScope,
        `${state.reportCode}:${selected?.id ?? 'none'}`,
    );
    const items = [
        { value: NONE, label: 'Tanpa preset' },
        ...state.presets.map((preset) => ({
            value: preset.id,
            label: preset.shared
                ? `${preset.name} · bersama${preset.owner_name ? `, dari ${preset.owner_name}` : ''}`
                : preset.name,
        })),
    ];
    const choices = dates && 'month' in dates ? MONTH_CHOICES : RANGE_CHOICES;
    const canArchive = selected
        ? selected.shared
            ? state.canShare
            : selected.mine
        : false;

    const openSave = () => {
        setName('');
        setChoice('fixed');
        setShared(false);
        setNameError('');
        clearSaveFeedback();
        setSaving(true);
    };

    const save = async () => {
        if (!name.trim()) {
            setNameError('Nama preset wajib diisi.');
            reportSaveError(
                new ApiError(
                    'Nama preset wajib diisi.',
                    { name: ['Nama preset wajib diisi.'] },
                    422,
                ),
                'Preset belum dapat disimpan.',
            );

            return;
        }

        setBusy(true);
        setNameError('');
        clearSaveFeedback();

        try {
            await state.save(
                name.trim(),
                presetParameters(state.filters, dates, choice),
                shared,
            );
            toast.success(
                shared
                    ? 'Preset disimpan dan terlihat oleh semua pengguna yang boleh menjalankan laporan ini.'
                    : 'Preset disimpan. Pilih lagi kapan saja dari daftar Preset.',
            );
            setSaving(false);
            clearSaveFeedback();
        } catch (caught) {
            setNameError(
                caught instanceof ApiError
                    ? (caught.validationErrors.name?.[0] ?? '')
                    : '',
            );
            reportSaveError(caught, 'Preset belum dapat disimpan.');
        } finally {
            setBusy(false);
        }
    };

    const archive = async () => {
        if (!selected) {
            return;
        }

        setArchiveBusy(true);
        clearArchiveFeedback();

        try {
            await state.archive(selected);
            toast.success('Preset diarsipkan.');
            clearArchiveFeedback();
            setArchiving(false);
        } catch (caught) {
            reportArchiveError(caught, 'Preset belum dapat diarsipkan.');
        } finally {
            setArchiveBusy(false);
        }
    };

    return (
        <>
            <div className="w-full">
                <InlineLookup
                    label="Preset"
                    items={items}
                    value={
                        items.find(
                            (item) => item.value === (state.selectedId ?? NONE),
                        ) ?? null
                    }
                    columns={[
                        {
                            id: 'name',
                            header: 'Nama',
                            cell: (item) => item.label,
                        },
                    ]}
                    getItemValue={(item) => item.value}
                    getItemLabel={(item) => item.label}
                    onValueChange={(item) =>
                        state.apply(item.value === NONE ? null : item.value)
                    }
                    onCreate={openSave}
                    showDetails={false}
                    emptyMessage="Belum ada preset untuk laporan ini."
                />
            </div>
            <Button
                type="button"
                variant="outline"
                onClick={openSave}
                className="w-full justify-start"
            >
                <BookmarkPlus />
                Simpan preset
            </Button>
            {canArchive && (
                <ActionButton
                    action="archive"
                    type="button"
                    onClick={() => {
                        clearArchiveFeedback();
                        setArchiving(true);
                    }}
                />
            )}

            <Dialog
                open={saving}
                onOpenChange={(open) => {
                    if (!busy) {
                        setSaving(open);

                        if (!open) {
                            clearSaveFeedback();
                        }
                    }
                }}
            >
                <DialogContent ref={portal} size="compact">
                    <DialogHeader>
                        <DialogTitle>Simpan filter sebagai preset</DialogTitle>
                        <DialogDescription>
                            Filter yang sedang dipakai disimpan dengan nama.
                            Pilih preset itu nanti untuk memasang semua
                            filternya sekaligus.
                        </DialogDescription>
                    </DialogHeader>
                    {saveFeedback && <div className="px-4">{saveFeedback}</div>}
                    <DialogBody className="space-y-4">
                        <FormControl data-error-field="name">
                            <Input
                                label="Nama preset"
                                required
                                maxLength={80}
                                value={name}
                                aria-invalid={!!nameError}
                                aria-describedby={
                                    nameError ? nameErrorId : undefined
                                }
                                onChange={(event) => {
                                    setName(event.target.value);
                                    setNameError('');
                                }}
                            />
                            {nameError && (
                                <FieldError id={nameErrorId}>
                                    {nameError}
                                </FieldError>
                            )}
                        </FormControl>
                        {dates && (
                            <FormControl>
                                <Select
                                    label="Tanggal dalam preset"
                                    items={[...choices]}
                                    value={choice}
                                    onValueChange={(value) =>
                                        setChoice(value ?? 'fixed')
                                    }
                                    portalContainer={portal}
                                />
                                <FieldDescription>
                                    Pilihan seperti “Bulan ini” dihitung ulang
                                    setiap kali preset dipakai, menurut zona
                                    waktu Anda.
                                </FieldDescription>
                            </FormControl>
                        )}
                        {state.canShare && (
                            <FormControl>
                                <label className="flex items-center gap-2 text-sm">
                                    <Checkbox
                                        checked={shared}
                                        onCheckedChange={(value) =>
                                            setShared(value === true)
                                        }
                                    />
                                    Bagikan filter ini ke semua pengguna di
                                    perusahaan ini
                                </label>
                                <FieldDescription>
                                    Tanpa centang, preset hanya terlihat oleh
                                    Anda. Dengan centang, semua orang yang boleh
                                    menjalankan laporan ini dapat memakainya.
                                </FieldDescription>
                            </FormControl>
                        )}
                    </DialogBody>
                    <DialogFooter>
                        <ActionButton
                            action="save"
                            type="button"
                            disabled={busy}
                            onClick={() => void save()}
                            pending={busy}
                        />
                        <DialogClose asChild>
                            <ActionButton
                                action="cancel"
                                type="button"
                                disabled={busy}
                            />
                        </DialogClose>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <AlertDialog
                open={archiving}
                onOpenChange={(open) => {
                    if (!open && !archiveBusy) {
                        setArchiving(false);
                        clearArchiveFeedback();
                    }
                }}
            >
                <AlertDialogContent ref={archiveScope}>
                    <AlertDialogHeader>
                        <AlertDialogTitle>
                            Delete preset {selected?.name ?? ''}?
                        </AlertDialogTitle>
                        <AlertDialogDescription>
                            {selected?.shared
                                ? 'Preset hilang dari daftar Preset semua pengguna.'
                                : 'Preset hilang dari daftar Preset.'}{' '}
                            Filter yang sedang tampil dan laporan yang sudah
                            diekspor tidak berubah.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    {archiveFeedback}
                    <AlertDialogFooter>
                        <AlertDialogCancel disabled={archiveBusy}>
                            Batal
                        </AlertDialogCancel>
                        <AlertDialogAction
                            onClick={(event) => {
                                event.preventDefault();
                                void archive();
                            }}
                            asChild
                        >
                            <ActionButton
                                action="archive"
                                pending={archiveBusy}
                                disabled={archiveBusy}
                            />
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </>
    );
}
