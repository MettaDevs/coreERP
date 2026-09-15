import { ActionButton } from '@apperp/ui/action-button';
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
import { Input } from '@apperp/ui/input';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@apperp/ui/table';
import { Head, Link, router } from '@inertiajs/react';
import { CalendarCheck, CalendarDays, Clock, Copy, Plus, Trash2, ArrowLeft } from 'lucide-react';
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
        if (!selectedCalendar) return;
        setFormCode(selectedCalendar.code);
        setFormName(selectedCalendar.name);
        setFormDesc(selectedCalendar.description || '');
        setFormBaseId(selectedCalendar.base_calendar_id || '');
        setFormHours(selectedCalendar.standard_work_hours);
        setFormErrors({});
        setIsEditOpen(true);
    };

    const handleOpenCopy = () => {
        if (!selectedCalendar) return;
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
                standard_work_hours: Number(formHours) || 8,
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
        if (!selectedCalendar) return;
        setIsSubmitting(true);
        setFormErrors({});
        router.put(
            `/settings/working-time-calendars/${selectedCalendar.id}`,
            {
                code: formCode.toUpperCase().trim(),
                name: formName.trim(),
                description: formDesc.trim() || null,
                base_calendar_id: formBaseId || null,
                standard_work_hours: Number(formHours) || 8,
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
        if (!selectedCalendar) return;
        if (!confirm(`Apakah Anda yakin ingin mengarsipkan kalender kerja "${selectedCalendar.name}"?`)) {
            return;
        }

        setIsDeleting(true);
        router.delete(`/settings/working-time-calendars/${selectedCalendar.id}`, {
            preserveScroll: true,
            onSuccess: () => {
                setSelectedCalendarId(null);
            },
            onFinish: () => setIsDeleting(false),
        });
    };

    const handleExecuteCopy = () => {
        if (!selectedCalendar) return;
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
        if (!selectedCalendar) return;
        router.visit(`/settings/working-time-calendars/${selectedCalendar.id}/times`);
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
                        <span>Jadwal kerja (Working times)</span>
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
                    <div className="mx-4 mt-4 flex items-center justify-between rounded-md border border-amber-500/30 bg-amber-500/10 p-3 text-xs text-amber-800 dark:text-amber-300">
                        <span>
                            <strong>Pemberitahuan:</strong> Belum ada entitas legal yang aktif pada sesi ini. Kalender kerja memerlukan entitas legal aktif.
                        </span>
                        <a
                            href="/settings/organization"
                            className="font-semibold underline hover:text-amber-900 dark:hover:text-amber-100"
                        >
                            Atur Organisasi &rarr;
                        </a>
                    </div>
                )}

                {/* Content Table */}
                <div className="flex-1 p-4">
                    <div className="rounded-md border border-border bg-card">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead className="w-12 text-center">Pilih</TableHead>
                                    <TableHead className="w-40">Kalender</TableHead>
                                    <TableHead>Nama</TableHead>
                                    <TableHead className="w-48">Kalender dasar</TableHead>
                                    <TableHead className="w-40 text-right">Jam kerja standar</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {calendars.length === 0 ? (
                                    <TableRow>
                                        <TableCell colSpan={5} className="py-8 text-center text-muted-foreground">
                                            Belum ada kalender kerja. Klik <strong>+ Baru</strong> untuk menambahkan.
                                        </TableCell>
                                    </TableRow>
                                ) : (
                                    calendars.map((calendar) => {
                                        const isSelected = calendar.id === selectedCalendarId;
                                        return (
                                            <TableRow
                                                key={calendar.id}
                                                className={cn(
                                                    'cursor-pointer transition-colors hover:bg-muted/50',
                                                    isSelected && 'bg-primary/10 hover:bg-primary/15',
                                                )}
                                                onClick={() => setSelectedCalendarId(calendar.id)}
                                                onDoubleClick={handleNavigateToTimes}
                                            >
                                                <TableCell className="text-center" onClick={(e) => e.stopPropagation()}>
                                                    <Checkbox
                                                        checked={isSelected}
                                                        onCheckedChange={() => setSelectedCalendarId(calendar.id)}
                                                    />
                                                </TableCell>
                                                <TableCell className="font-semibold text-foreground">
                                                    {calendar.code}
                                                </TableCell>
                                                <TableCell>{calendar.name}</TableCell>
                                                <TableCell className="text-muted-foreground">
                                                    {calendar.base_calendar_code
                                                        ? `${calendar.base_calendar_code} - ${calendar.base_calendar_name}`
                                                        : '-'}
                                                </TableCell>
                                                <TableCell className="text-right font-mono">
                                                    {calendar.standard_work_hours.toFixed(2)} jam
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

            {/* Dialog Create Calendar */}
            <Dialog open={isCreateOpen} onOpenChange={setIsCreateOpen}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Tambah Kalender Kerja Baru</DialogTitle>
                        <DialogDescription>
                            Definisikan master kalender kerja untuk entitas legal aktif.
                        </DialogDescription>
                    </DialogHeader>

                    <DialogBody className="space-y-4 py-2">
                        {formErrors.general && (
                            <div className="rounded-md border border-destructive/30 bg-destructive/10 p-3 text-xs text-destructive">
                                {formErrors.general}
                            </div>
                        )}
                        <div>
                            <label className="text-xs font-semibold text-foreground">
                                Kode Kalender <span className="text-destructive">*</span>
                            </label>
                            <Input
                                value={formCode}
                                onChange={(e) => setFormCode(e.target.value.toUpperCase())}
                                placeholder="Contoh: 24HR, PROD, PAYROLL"
                                className="mt-1"
                                autoFocus
                            />
                            {formErrors.code && (
                                <p className="mt-1 text-xs text-destructive">{formErrors.code}</p>
                            )}
                        </div>

                        <div>
                            <label className="text-xs font-semibold text-foreground">
                                Nama Kalender <span className="text-destructive">*</span>
                            </label>
                            <Input
                                value={formName}
                                onChange={(e) => setFormName(e.target.value)}
                                placeholder="Contoh: Production 24 Hours"
                                className="mt-1"
                            />
                            {formErrors.name && (
                                <p className="mt-1 text-xs text-destructive">{formErrors.name}</p>
                            )}
                        </div>

                        <div>
                            <label className="text-xs font-semibold text-foreground">
                                Kalender Dasar (Opsional)
                            </label>
                            <select
                                value={formBaseId}
                                onChange={(e) => setFormBaseId(e.target.value)}
                                className="mt-1 block w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background focus:outline-none focus:ring-2 focus:ring-ring"
                            >
                                <option value="">-- Tanpa Kalender Dasar --</option>
                                {calendars.map((c) => (
                                    <option key={c.id} value={c.id}>
                                        {c.code} - {c.name}
                                    </option>
                                ))}
                            </select>
                            {formErrors.base_calendar_id && (
                                <p className="mt-1 text-xs text-destructive">{formErrors.base_calendar_id}</p>
                            )}
                        </div>

                        <div>
                            <label className="text-xs font-semibold text-foreground">
                                Jam Kerja Standar per Hari
                            </label>
                            <Input
                                type="number"
                                step="0.5"
                                min="0"
                                max="24"
                                value={formHours}
                                onChange={(e) => setFormHours(Number(e.target.value))}
                                className="mt-1"
                            />
                            {formErrors.standard_work_hours && (
                                <p className="mt-1 text-xs text-destructive">{formErrors.standard_work_hours}</p>
                            )}
                        </div>

                        <div>
                            <label className="text-xs font-semibold text-foreground">
                                Keterangan (Opsional)
                            </label>
                            <Input
                                value={formDesc}
                                onChange={(e) => setFormDesc(e.target.value)}
                                placeholder="Catatan atau tujuan kalender..."
                                className="mt-1"
                            />
                        </div>
                    </DialogBody>

                    <DialogFooter>
                        <DialogAction onClick={handleCreate} disabled={isSubmitting || !formCode || !formName}>
                            {isSubmitting ? 'Menyimpan…' : 'Simpan'}
                        </DialogAction>
                        <DialogCancel onClick={() => setIsCreateOpen(false)} />
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/* Dialog Edit Calendar */}
            <Dialog open={isEditOpen} onOpenChange={setIsEditOpen}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Ubah Kalender Kerja</DialogTitle>
                        <DialogDescription>
                            Perbarui rincian master kalender kerja.
                        </DialogDescription>
                    </DialogHeader>

                    <DialogBody className="space-y-4 py-2">
                        <div>
                            <label className="text-xs font-semibold text-foreground">
                                Kode Kalender <span className="text-destructive">*</span>
                            </label>
                            <Input
                                value={formCode}
                                onChange={(e) => setFormCode(e.target.value.toUpperCase())}
                                className="mt-1"
                            />
                            {formErrors.code && (
                                <p className="mt-1 text-xs text-destructive">{formErrors.code}</p>
                            )}
                        </div>

                        <div>
                            <label className="text-xs font-semibold text-foreground">
                                Nama Kalender <span className="text-destructive">*</span>
                            </label>
                            <Input
                                value={formName}
                                onChange={(e) => setFormName(e.target.value)}
                                className="mt-1"
                            />
                            {formErrors.name && (
                                <p className="mt-1 text-xs text-destructive">{formErrors.name}</p>
                            )}
                        </div>

                        <div>
                            <label className="text-xs font-semibold text-foreground">
                                Kalender Dasar (Opsional)
                            </label>
                            <select
                                value={formBaseId}
                                onChange={(e) => setFormBaseId(e.target.value)}
                                className="mt-1 block w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background focus:outline-none focus:ring-2 focus:ring-ring"
                            >
                                <option value="">-- Tanpa Kalender Dasar --</option>
                                {calendars
                                    .filter((c) => c.id !== selectedCalendar?.id)
                                    .map((c) => (
                                        <option key={c.id} value={c.id}>
                                            {c.code} - {c.name}
                                        </option>
                                    ))}
                            </select>
                            {formErrors.base_calendar_id && (
                                <p className="mt-1 text-xs text-destructive">{formErrors.base_calendar_id}</p>
                            )}
                        </div>

                        <div>
                            <label className="text-xs font-semibold text-foreground">
                                Jam Kerja Standar per Hari
                            </label>
                            <Input
                                type="number"
                                step="0.5"
                                min="0"
                                max="24"
                                value={formHours}
                                onChange={(e) => setFormHours(Number(e.target.value))}
                                className="mt-1"
                            />
                            {formErrors.standard_work_hours && (
                                <p className="mt-1 text-xs text-destructive">{formErrors.standard_work_hours}</p>
                            )}
                        </div>

                        <div>
                            <label className="text-xs font-semibold text-foreground">
                                Keterangan (Opsional)
                            </label>
                            <Input
                                value={formDesc}
                                onChange={(e) => setFormDesc(e.target.value)}
                                className="mt-1"
                            />
                        </div>
                    </DialogBody>

                    <DialogFooter>
                        <DialogAction onClick={handleUpdate} disabled={isSubmitting || !formCode || !formName}>
                            {isSubmitting ? 'Menyimpan…' : 'Simpan Perubahan'}
                        </DialogAction>
                        <DialogCancel onClick={() => setIsEditOpen(false)} />
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/* Dialog Copy Calendar */}
            <Dialog open={isCopyOpen} onOpenChange={setIsCopyOpen}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Salin Kalender Kerja</DialogTitle>
                        <DialogDescription>
                            Salin kalender <strong>{selectedCalendar?.code}</strong> beserta seluruh rincian hari kerja dan jam kerjanya ke kalender baru.
                        </DialogDescription>
                    </DialogHeader>

                    <DialogBody className="space-y-4 py-2">
                        <div>
                            <label className="text-xs font-semibold text-foreground">
                                Kode Kalender Baru <span className="text-destructive">*</span>
                            </label>
                            <Input
                                value={copyTargetCode}
                                onChange={(e) => setCopyTargetCode(e.target.value.toUpperCase())}
                                className="mt-1"
                                autoFocus
                            />
                            {formErrors.code && (
                                <p className="mt-1 text-xs text-destructive">{formErrors.code}</p>
                            )}
                        </div>

                        <div>
                            <label className="text-xs font-semibold text-foreground">
                                Nama Kalender Baru <span className="text-destructive">*</span>
                            </label>
                            <Input
                                value={copyTargetName}
                                onChange={(e) => setCopyTargetName(e.target.value)}
                                className="mt-1"
                            />
                            {formErrors.name && (
                                <p className="mt-1 text-xs text-destructive">{formErrors.name}</p>
                            )}
                        </div>
                    </DialogBody>

                    <DialogFooter>
                        <DialogAction onClick={handleExecuteCopy} disabled={isSubmitting || !copyTargetCode || !copyTargetName}>
                            {isSubmitting ? 'Menyalin…' : 'Salin Kalender'}
                        </DialogAction>
                        <DialogCancel onClick={() => setIsCopyOpen(false)} />
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}

WorkingTimeCalendars.layout = {
    breadcrumbs: [
        { title: 'Kalender', href: '/settings/working-time-calendars' },
        { title: 'Kalender kerja', href: '/settings/working-time-calendars' },
    ] satisfies BreadcrumbItem[],
};
