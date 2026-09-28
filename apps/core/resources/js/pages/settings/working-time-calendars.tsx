import { ActionButton } from '@apperp/ui/action-button';
import { Alert, AlertDescription } from '@apperp/ui/alert';
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
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@apperp/ui/table';
import { Head, Link, router } from '@inertiajs/react';
import { AlertCircle, CalendarCheck, Clock, Copy } from 'lucide-react';
import { useMemo, useState } from 'react';
import { cn } from '@/lib/utils';
import type { BreadcrumbItem } from '@/types/navigation';

export type CalendarItem = {
    id: string;
    code: string;
    name: string;
    description: string | null;
    base_calendar_id: string | null;
    base_calendar_code: string | null;
    base_calendar_name: string | null;
    standard_work_hours: number;
    is_active: boolean;
    legal_entity_id: string | null;
    legal_entity_name: string | null;
    company_code: string | null;
};

type Props = {
    calendars: CalendarItem[];
    currentLegalEntity: {
        id: string;
        name: string;
        company_code: string | null;
    } | null;
    canManage: boolean;
};

export default function WorkingTimeCalendars({
    calendars,
    currentLegalEntity,
    canManage,
}: Props) {
    const [selectedCalendarId, setSelectedCalendarId] = useState<string | null>(
        calendars.length > 0 ? calendars[0].id : null,
    );

    const [isCreateOpen, setIsCreateOpen] = useState(false);
    const [isEditOpen, setIsEditOpen] = useState(false);
    const [isCopyOpen, setIsCopyOpen] = useState(false);
    const [isDeleting, setIsDeleting] = useState(false);
    const [isSubmitting, setIsSubmitting] = useState(false);

    // Form states
    const [formCode, setFormCode] = useState('');
    const [formName, setFormName] = useState('');
    const [formDesc, setFormDesc] = useState('');
    const [formBaseId, setFormBaseId] = useState<string>('');
    const [formHours, setFormHours] = useState<number>(8);
    const [formErrors, setFormErrors] = useState<Record<string, string>>({});

    // Copy states
    const [copyTargetCode, setCopyTargetCode] = useState('');
    const [copyTargetName, setCopyTargetName] = useState('');

    const selectedCalendar = useMemo(
        () => calendars.find((c) => c.id === selectedCalendarId) ?? null,
        [calendars, selectedCalendarId],
    );

    const handleOpenCreate = () => {
        setFormCode('');
        setFormName('');
        setFormDesc('');
        setFormBaseId('');
        setFormHours(8);
        setFormErrors({});
        setIsCreateOpen(true);
    };

    const handleOpenEdit = () => {
        if (!selectedCalendar) {
            return;
        }

        setFormCode(selectedCalendar.code);
        setFormName(selectedCalendar.name);
        setFormDesc(selectedCalendar.description || '');
        setFormBaseId(selectedCalendar.base_calendar_id || '');
        setFormHours(selectedCalendar.standard_work_hours);
        setFormErrors({});
        setIsEditOpen(true);
    };

    const handleOpenCopy = () => {
        if (!selectedCalendar) {
            return;
        }

        setCopyTargetCode(`${selectedCalendar.code}-COPY`);
        setCopyTargetName(`${selectedCalendar.name} (Salinan)`);
        setFormErrors({});
        setIsCopyOpen(true);
    };

    const handleCreate = () => {
        setIsSubmitting(true);
        setFormErrors({});
        router.post(
            '/settings/working-time-calendars',
            {
                code: formCode.toUpperCase().trim(),
                name: formName.trim(),
                description: formDesc.trim() || null,
                base_calendar_id: formBaseId || null,
                standard_work_hours: formHours,
            },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setIsCreateOpen(false);
                },
                onError: (err) => {
                    setFormErrors(err);
                },
                onFinish: () => setIsSubmitting(false),
            },
        );
    };

    const handleUpdate = () => {
        if (!selectedCalendar) {
            return;
        }

        setIsSubmitting(true);
        setFormErrors({});
        router.put(
            `/settings/working-time-calendars/${selectedCalendar.id}`,
            {
                code: formCode.toUpperCase().trim(),
                name: formName.trim(),
                description: formDesc.trim() || null,
                base_calendar_id: formBaseId || null,
                standard_work_hours: formHours,
            },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setIsEditOpen(false);
                },
                onError: (err) => {
                    setFormErrors(err);
                },
                onFinish: () => setIsSubmitting(false),
            },
        );
    };

    const handleDelete = () => {
        if (!selectedCalendar) {
            return;
        }

        if (
            !confirm(
                `Apakah Anda yakin ingin mengarsipkan kalender kerja "${selectedCalendar.name}"?`,
            )
        ) {
            return;
        }

        setIsDeleting(true);
        router.delete(
            `/settings/working-time-calendars/${selectedCalendar.id}`,
            {
                preserveScroll: true,
                onSuccess: () => {
                    setSelectedCalendarId(null);
                },
                onFinish: () => setIsDeleting(false),
            },
        );
    };

    const handleExecuteCopy = () => {
        if (!selectedCalendar) {
            return;
        }

        setIsSubmitting(true);
        setFormErrors({});
        router.post(
            `/settings/working-time-calendars/${selectedCalendar.id}/copy`,
            {
                code: copyTargetCode.toUpperCase().trim(),
                name: copyTargetName.trim(),
            },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setIsCopyOpen(false);
                },
                onError: (err) => {
                    setFormErrors(err);
                },
                onFinish: () => setIsSubmitting(false),
            },
        );
    };

    const handleNavigateToTimes = () => {
        if (!selectedCalendar) {
            return;
        }

        router.visit(
            `/settings/working-time-calendars/${selectedCalendar.id}/times`,
        );
    };

    return (
        <>
            <Head title="Kalender kerja" />

            <div className="flex min-h-[calc(100vh-4rem)] flex-col bg-background">
                {/* Command Ribbon */}
                <div className="sticky top-0 z-20 flex shrink-0 flex-wrap items-center gap-2 border-b border-border bg-card px-4 py-2 text-xs">
                    <span className="mr-2 text-sm font-semibold text-foreground">
                        Kalender kerja
                    </span>

                    {canManage && (
                        <ActionButton
                            action="create"
                            size="sm"
                            type="button"
                            onClick={handleOpenCreate}
                        >
                            + Baru
                        </ActionButton>
                    )}

                    {canManage && (
                        <ActionButton
                            action="edit"
                            size="sm"
                            type="button"
                            disabled={!selectedCalendar}
                            onClick={handleOpenEdit}
                        >
                            Ubah
                        </ActionButton>
                    )}

                    {canManage && (
                        <ActionButton
                            action="archive"
                            size="sm"
                            type="button"
                            disabled={!selectedCalendar || isDeleting}
                            onClick={handleDelete}
                        >
                            {isDeleting ? 'Mengarsipkan…' : 'Arsipkan'}
                        </ActionButton>
                    )}

                    <Button
                        variant="outline"
                        size="sm"
                        type="button"
                        disabled={!selectedCalendar}
                        onClick={handleNavigateToTimes}
                        className="border-primary/40 text-primary hover:bg-primary/10"
                    >
                        <Clock className="size-4" />
                        <span>Jadwal kerja</span>
                    </Button>

                    {canManage && (
                        <Button
                            variant="outline"
                            size="sm"
                            type="button"
                            disabled={!selectedCalendar}
                            onClick={handleOpenCopy}
                        >
                            <Copy className="size-4" />
                            <span>Salin kalender</span>
                        </Button>
                    )}

                    {canManage && (
                        <Link
                            href={
                                selectedCalendar
                                    ? `/settings/compose-working-times?calendar_id=${selectedCalendar.id}`
                                    : '/settings/compose-working-times'
                            }
                            className="inline-flex items-center gap-1.5 rounded-md border border-primary/30 bg-primary/5 px-3 py-1.5 text-xs font-medium text-primary hover:bg-primary/10"
                        >
                            <CalendarCheck className="size-4" />
                            <span>Jadwal dari pola</span>
                        </Link>
                    )}
                </div>

                {/* Notice if no legal entity selected */}
                {!currentLegalEntity && (
                    <div className="mx-4 mt-4 flex items-center justify-between rounded-md border border-warning/30 bg-warning/10 p-3 text-xs text-warning">
                        <span>
                            Belum ada entitas legal yang aktif pada sesi ini.
                            Kalender kerja selalu milik satu entitas legal.
                        </span>
                        <a
                            href="/settings/organization"
                            className="font-semibold underline"
                        >
                            Atur organisasi &rarr;
                        </a>
                    </div>
                )}

                {/* Content Table */}
                <div className="flex-1 p-4">
                    <div className="rounded-md border border-border bg-card">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead className="w-12 text-center">
                                        Pilih
                                    </TableHead>
                                    <TableHead className="w-40">
                                        Kalender
                                    </TableHead>
                                    <TableHead>Nama</TableHead>
                                    <TableHead className="w-48">
                                        Kalender dasar
                                    </TableHead>
                                    <TableHead className="w-40 text-right">
                                        Jam kerja standar
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {calendars.length === 0 ? (
                                    <TableRow>
                                        <TableCell
                                            colSpan={5}
                                            className="py-8 text-center text-muted-foreground"
                                        >
                                            Belum ada kalender kerja.
                                            {canManage && (
                                                <>
                                                    {' '}
                                                    Klik <strong>
                                                        + Baru
                                                    </strong>{' '}
                                                    untuk menambahkan.
                                                </>
                                            )}
                                        </TableCell>
                                    </TableRow>
                                ) : (
                                    calendars.map((calendar) => {
                                        const isSelected =
                                            calendar.id === selectedCalendarId;

                                        return (
                                            <TableRow
                                                key={calendar.id}
                                                className={cn(
                                                    'cursor-pointer transition-colors hover:bg-muted/50',
                                                    isSelected &&
                                                        'bg-primary/10 hover:bg-primary/15',
                                                )}
                                                onClick={() =>
                                                    setSelectedCalendarId(
                                                        calendar.id,
                                                    )
                                                }
                                                onDoubleClick={
                                                    handleNavigateToTimes
                                                }
                                            >
                                                <TableCell
                                                    className="text-center"
                                                    onClick={(e) =>
                                                        e.stopPropagation()
                                                    }
                                                >
                                                    <Checkbox
                                                        checked={isSelected}
                                                        onCheckedChange={() =>
                                                            setSelectedCalendarId(
                                                                calendar.id,
                                                            )
                                                        }
                                                    />
                                                </TableCell>
                                                <TableCell className="font-semibold text-foreground">
                                                    {calendar.code}
                                                </TableCell>
                                                <TableCell>
                                                    {calendar.name}
                                                </TableCell>
                                                <TableCell className="text-muted-foreground">
                                                    {calendar.base_calendar_code
                                                        ? `${calendar.base_calendar_code} - ${calendar.base_calendar_name}`
                                                        : '-'}
                                                </TableCell>
                                                <TableCell className="text-right font-mono">
                                                    {calendar.standard_work_hours.toFixed(
                                                        2,
                                                    )}{' '}
                                                    jam
                                                </TableCell>
                                            </TableRow>
                                        );
                                    })
                                )}
                            </TableBody>
                        </Table>
                    </div>
                </div>
            </div>

            <Dialog open={isCreateOpen} onOpenChange={setIsCreateOpen}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Tambah kalender kerja</DialogTitle>
                        <DialogDescription>
                            Kalender kerja baru untuk entitas legal{' '}
                            {currentLegalEntity?.name ?? 'yang aktif'}.
                        </DialogDescription>
                    </DialogHeader>

                    <DialogBody className="space-y-4 py-2">
                        <CalendarFormFields
                            code={formCode}
                            onCodeChange={setFormCode}
                            name={formName}
                            onNameChange={setFormName}
                            baseId={formBaseId}
                            onBaseIdChange={setFormBaseId}
                            hours={formHours}
                            onHoursChange={setFormHours}
                            description={formDesc}
                            onDescriptionChange={setFormDesc}
                            baseOptions={calendars}
                            errors={formErrors}
                        />
                    </DialogBody>

                    <DialogFooter>
                        <DialogAction
                            onClick={handleCreate}
                            disabled={isSubmitting || !formCode || !formName}
                        >
                            {isSubmitting ? 'Menyimpan…' : 'Simpan'}
                        </DialogAction>
                        <DialogCancel onClick={() => setIsCreateOpen(false)} />
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <Dialog open={isEditOpen} onOpenChange={setIsEditOpen}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Ubah kalender kerja</DialogTitle>
                        <DialogDescription>
                            Perbarui kode, nama, dan jam kerja standar kalender
                            ini.
                        </DialogDescription>
                    </DialogHeader>

                    <DialogBody className="space-y-4 py-2">
                        <CalendarFormFields
                            code={formCode}
                            onCodeChange={setFormCode}
                            name={formName}
                            onNameChange={setFormName}
                            baseId={formBaseId}
                            onBaseIdChange={setFormBaseId}
                            hours={formHours}
                            onHoursChange={setFormHours}
                            description={formDesc}
                            onDescriptionChange={setFormDesc}
                            baseOptions={calendars.filter(
                                (c) => c.id !== selectedCalendar?.id,
                            )}
                            errors={formErrors}
                        />
                    </DialogBody>

                    <DialogFooter>
                        <DialogAction
                            onClick={handleUpdate}
                            disabled={isSubmitting || !formCode || !formName}
                        >
                            {isSubmitting ? 'Menyimpan…' : 'Simpan perubahan'}
                        </DialogAction>
                        <DialogCancel onClick={() => setIsEditOpen(false)} />
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <Dialog open={isCopyOpen} onOpenChange={setIsCopyOpen}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Salin kalender kerja</DialogTitle>
                        <DialogDescription>
                            Salin kalender{' '}
                            <strong>{selectedCalendar?.code}</strong> beserta
                            seluruh hari kerja dan jam kerjanya ke kalender
                            baru.
                        </DialogDescription>
                    </DialogHeader>

                    <DialogBody className="space-y-4 py-2">
                        <Field data-invalid={Boolean(formErrors.code)}>
                            <Input
                                label="Kode kalender baru"
                                required
                                value={copyTargetCode}
                                onChange={(e) =>
                                    setCopyTargetCode(
                                        e.target.value.toUpperCase(),
                                    )
                                }
                                autoFocus
                            />
                            {formErrors.code && (
                                <FieldError>{formErrors.code}</FieldError>
                            )}
                        </Field>

                        <Field data-invalid={Boolean(formErrors.name)}>
                            <Input
                                label="Nama kalender baru"
                                required
                                value={copyTargetName}
                                onChange={(e) =>
                                    setCopyTargetName(e.target.value)
                                }
                            />
                            {formErrors.name && (
                                <FieldError>{formErrors.name}</FieldError>
                            )}
                        </Field>
                    </DialogBody>

                    <DialogFooter>
                        <DialogAction
                            onClick={handleExecuteCopy}
                            disabled={
                                isSubmitting ||
                                !copyTargetCode ||
                                !copyTargetName
                            }
                        >
                            {isSubmitting ? 'Menyalin…' : 'Salin kalender'}
                        </DialogAction>
                        <DialogCancel onClick={() => setIsCopyOpen(false)} />
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}

type CalendarFormFieldsProps = {
    code: string;
    onCodeChange: (value: string) => void;
    name: string;
    onNameChange: (value: string) => void;
    baseId: string;
    onBaseIdChange: (value: string) => void;
    hours: number;
    onHoursChange: (value: number) => void;
    description: string;
    onDescriptionChange: (value: string) => void;
    baseOptions: CalendarItem[];
    errors: Record<string, string>;
};

/** Isian kalender yang sama untuk dialog tambah dan ubah. */
function CalendarFormFields({
    code,
    onCodeChange,
    name,
    onNameChange,
    baseId,
    onBaseIdChange,
    hours,
    onHoursChange,
    description,
    onDescriptionChange,
    baseOptions,
    errors,
}: CalendarFormFieldsProps) {
    return (
        <>
            {errors.general && (
                <Alert variant="destructive">
                    <AlertCircle />
                    <AlertDescription>{errors.general}</AlertDescription>
                </Alert>
            )}

            <Field data-invalid={Boolean(errors.code)}>
                <Input
                    label="Kode kalender"
                    required
                    value={code}
                    onChange={(e) => onCodeChange(e.target.value.toUpperCase())}
                    autoFocus
                />
                {errors.code ? (
                    <FieldError>{errors.code}</FieldError>
                ) : (
                    <FieldDescription>
                        Huruf, angka, garis bawah, atau tanda hubung, misalnya
                        STD atau PROD-24.
                    </FieldDescription>
                )}
            </Field>

            <Field data-invalid={Boolean(errors.name)}>
                <Input
                    label="Nama kalender"
                    required
                    value={name}
                    onChange={(e) => onNameChange(e.target.value)}
                />
                {errors.name && <FieldError>{errors.name}</FieldError>}
            </Field>

            <Field data-invalid={Boolean(errors.base_calendar_id)}>
                <NativeSelect
                    label="Kalender dasar"
                    value={baseId}
                    onChange={(e) => onBaseIdChange(e.target.value)}
                >
                    <NativeSelectOption value="">
                        Tanpa kalender dasar
                    </NativeSelectOption>
                    {baseOptions.map((c) => (
                        <NativeSelectOption key={c.id} value={c.id}>
                            {c.code} - {c.name}
                        </NativeSelectOption>
                    ))}
                </NativeSelect>
                {errors.base_calendar_id && (
                    <FieldError>{errors.base_calendar_id}</FieldError>
                )}
            </Field>

            <Field data-invalid={Boolean(errors.standard_work_hours)}>
                <Input
                    label="Jam kerja standar per hari"
                    type="number"
                    step="0.5"
                    min="0"
                    max="24"
                    value={hours}
                    onChange={(e) => onHoursChange(Number(e.target.value))}
                />
                {errors.standard_work_hours && (
                    <FieldError>{errors.standard_work_hours}</FieldError>
                )}
            </Field>

            <Input
                label="Keterangan"
                value={description}
                onChange={(e) => onDescriptionChange(e.target.value)}
            />
        </>
    );
}

WorkingTimeCalendars.layout = {
    breadcrumbs: [
        { title: 'Kalender', href: '/settings/working-time-calendars' },
        { title: 'Kalender kerja', href: '/settings/working-time-calendars' },
    ] satisfies BreadcrumbItem[],
};
