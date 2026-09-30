import { BookmarkPlus } from 'lucide-react';
import { useState } from 'react';
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
    Dialog,
    DialogAction,
    DialogBody,
    DialogCancel,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@apperp/ui/dialog';
import { Field, FieldDescription, FieldError } from '@apperp/ui/field';
import { Input } from '@apperp/ui/input';
import { NativeSelect, NativeSelectOption } from '@apperp/ui/native-select';
import { Select } from '@apperp/ui/select';
import { errorMessage } from '../../api';
import { cleanFilters } from './reportOptions';
import type { Filters } from './reportOptions';
import type { ReportPresetState } from './useReportData';

/**
 * Parameter tanggal sebuah laporan: rentang dua tanggal, atau satu bulan. Dipakai untuk menawarkan tanggal
 * relatif saat menyimpan preset.
 */
export type ReportDateParameters =
    { from: string; to: string } | { month: string };

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
    const [saving, setSaving] = useState(false);
    const [archiving, setArchiving] = useState(false);
    const [name, setName] = useState('');
    const [choice, setChoice] = useState('fixed');
    const [shared, setShared] = useState(false);
    const [nameError, setNameError] = useState('');
    const [busy, setBusy] = useState(false);

    const selected =
        state.presets.find((preset) => preset.id === state.selectedId) ?? null;
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
        setSaving(true);
    };

    const save = async () => {
        if (!name.trim()) {
            setNameError('Nama preset wajib diisi.');

            return;
        }

        setBusy(true);

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
        } catch (caught) {
            setNameError(errorMessage(caught, 'Preset belum dapat disimpan.'));
        } finally {
            setBusy(false);
        }
    };

    const archive = async () => {
        if (!selected) {
            return;
        }

        try {
            await state.archive(selected);
            toast.success('Preset diarsipkan.');
        } catch (caught) {
            toast.error(errorMessage(caught, 'Preset belum dapat diarsipkan.'));
        } finally {
            setArchiving(false);
        }
    };

    return (
        <>
            <div className="w-full sm:w-56">
                <Select
                    label="Preset"
                    items={items}
                    value={state.selectedId ?? NONE}
                    onValueChange={(next) =>
                        state.apply(
                            next === null || next === NONE ? null : next,
                        )
                    }
                    searchPlaceholder="Cari preset"
                    emptyMessage="Belum ada preset untuk laporan ini."
                />
            </div>
            <Button type="button" variant="outline" onClick={openSave}>
                <BookmarkPlus />
                Simpan preset
            </Button>
            {canArchive && (
                <ActionButton
                    action="archive"
                    type="button"
                    onClick={() => setArchiving(true)}
                >
                    Arsipkan preset
                </ActionButton>
            )}

            <Dialog open={saving} onOpenChange={setSaving}>
                <DialogContent size="compact">
                    <DialogHeader>
                        <DialogTitle>Simpan filter sebagai preset</DialogTitle>
                        <DialogDescription>
                            Filter yang sedang dipakai disimpan dengan nama.
                            Pilih preset itu nanti untuk memasang semua
                            filternya sekaligus.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogBody className="space-y-4">
                        <Field>
                            <Input
                                label="Nama preset"
                                required
                                maxLength={80}
                                value={name}
                                onChange={(event) => {
                                    setName(event.target.value);
                                    setNameError('');
                                }}
                            />
                            {nameError && <FieldError>{nameError}</FieldError>}
                        </Field>
                        {dates && (
                            <Field>
                                <NativeSelect
                                    label="Periode pada preset"
                                    value={choice}
                                    onChange={(event) =>
                                        setChoice(event.target.value)
                                    }
                                >
                                    {choices.map((item) => (
                                        <NativeSelectOption
                                            key={item.value}
                                            value={item.value}
                                        >
                                            {item.label}
                                        </NativeSelectOption>
                                    ))}
                                </NativeSelect>
                                <FieldDescription>
                                    Pilihan seperti “Bulan ini” dihitung ulang
                                    setiap kali preset dipakai, menurut zona
                                    waktu Anda.
                                </FieldDescription>
                            </Field>
                        )}
                        {state.canShare && (
                            <Field>
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
                            </Field>
                        )}
                    </DialogBody>
                    <DialogFooter>
                        <DialogAction
                            type="button"
                            disabled={busy}
                            onClick={() => void save()}
                        >
                            {busy ? 'Menyimpan…' : 'Simpan preset'}
                        </DialogAction>
                        <DialogCancel type="button">Batal</DialogCancel>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <AlertDialog
                open={archiving}
                onOpenChange={(open) => !open && setArchiving(false)}
            >
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>
                            Arsipkan preset {selected?.name ?? ''}?
                        </AlertDialogTitle>
                        <AlertDialogDescription>
                            {selected?.shared
                                ? 'Preset hilang dari daftar Preset semua pengguna.'
                                : 'Preset hilang dari daftar Preset.'}{' '}
                            Filter yang sedang tampil dan laporan yang sudah
                            diekspor tidak berubah.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>Batal</AlertDialogCancel>
                        <AlertDialogAction onClick={() => void archive()}>
                            Arsipkan
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </>
    );
}
