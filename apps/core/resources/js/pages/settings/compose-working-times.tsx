import { Badge } from '@apperp/ui/badge';
import { Button } from '@apperp/ui/button';
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
import {
    ArrowLeft,
    CalendarCheck,
    CalendarDays,
    Clock,
    Info,
    Play,
    Sparkles,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import type { BreadcrumbItem } from '@/types/navigation';

export type TemplateLine = {
    id: string;
    day_of_week: number;
    from_time: string | null;
    to_time: string | null;
    efficiency: number;
    property: string | null;
    hours: number;
    closed_for_pickup: boolean;
};

export type TemplateItem = {
    id: string;
    code: string;
    name: string;
    lines: TemplateLine[];
};

export type CalendarItem = {
    id: string;
    code: string;
    name: string;
    standard_work_hours: number;
};

type Props = {
    calendars: CalendarItem[];
    templates: TemplateItem[];
    initialCalendarId?: string | null;
    initialTemplateId?: string | null;
    initialFromDate: string;
    initialToDate: string;
    canManage: boolean;
};

const DAY_NAMES = [
    'Senin',
    'Selasa',
    'Rabu',
    'Kamis',
    'Jumat',
    'Sabtu',
    'Minggu',
];

export default function ComposeWorkingTimesPage({
    calendars,
    templates,
    initialCalendarId,
    initialTemplateId,
    initialFromDate,
    initialToDate,
    canManage,
}: Props) {
    const [calendarId, setCalendarId] = useState<string>(
        initialCalendarId && calendars.some((c) => c.id === initialCalendarId)
            ? initialCalendarId
            : (calendars[0]?.id ?? ''),
    );

    const [templateId, setTemplateId] = useState<string>(
        initialTemplateId && templates.some((t) => t.id === initialTemplateId)
            ? initialTemplateId
            : (templates[0]?.id ?? ''),
    );

    const [fromDate, setFromDate] = useState<string>(initialFromDate);
    const [toDate, setToDate] = useState<string>(initialToDate);
    const [isSubmitting, setIsSubmitting] = useState<boolean>(false);
    const [errors, setErrors] = useState<Record<string, string>>({});

    const selectedCalendar = useMemo(
        () => calendars.find((c) => c.id === calendarId) ?? null,
        [calendars, calendarId],
    );

    const selectedTemplate = useMemo(
        () => templates.find((t) => t.id === templateId) ?? null,
        [templates, templateId],
    );

    // Group lines of the selected template by day of week (0 = Senin .. 6 = Minggu)
    const templatePreviewByDay = useMemo(() => {
        if (!selectedTemplate) {
            return [];
        }

        return DAY_NAMES.map((name, dayIndex) => {
            const dayLines = selectedTemplate.lines.filter(
                (l) => l.day_of_week === dayIndex,
            );
            const totalHours = dayLines.reduce(
                (acc, l) => acc + (l.hours || 0),
                0,
            );
            const isOpen = dayLines.length > 0 && totalHours > 0;

            return {
                dayIndex,
                dayName: name,
                isOpen,
                totalHours,
                lines: dayLines,
            };
        });
    }, [selectedTemplate]);

    // Quick range presets
    const handleSetRangeCurrentMonth = () => {
        const now = new Date();
        const start = new Date(now.getFullYear(), now.getMonth(), 1);
        const end = new Date(now.getFullYear(), now.getMonth() + 1, 0);

        setFromDate(start.toISOString().slice(0, 10));
        setToDate(end.toISOString().slice(0, 10));
    };

    const handleSetRangeNextMonth = () => {
        const now = new Date();
        const start = new Date(now.getFullYear(), now.getMonth() + 1, 1);
        const end = new Date(now.getFullYear(), now.getMonth() + 2, 0);

        setFromDate(start.toISOString().slice(0, 10));
        setToDate(end.toISOString().slice(0, 10));
    };

    const handleSetRangeCurrentYear = () => {
        const year = new Date().getFullYear();

        setFromDate(`${year}-01-01`);
        setToDate(`${year}-12-31`);
    };

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();

        if (!calendarId) {
            setErrors({
                calendar_id: 'Pilih kalender kerja sasaran terlebih dahulu.',
            });

            return;
        }

        if (!templateId) {
            setErrors({
                template_id: 'Pilih pola jam kerja acuan terlebih dahulu.',
            });

            return;
        }

        if (!fromDate) {
            setErrors({ from_date: 'Tanggal mulai wajib diisi.' });

            return;
        }

        if (!toDate) {
            setErrors({ to_date: 'Tanggal selesai wajib diisi.' });

            return;
        }

        if (fromDate > toDate) {
            setErrors({
                to_date:
                    'Tanggal selesai tidak boleh lebih awal dari tanggal mulai.',
            });

            return;
        }

        setErrors({});
        setIsSubmitting(true);

        router.post(
            '/settings/compose-working-times',
            {
                calendar_id: calendarId,
                template_id: templateId,
                from_date: fromDate,
                to_date: toDate,
            },
            {
                preserveScroll: true,
                onError: (err) => {
                    setErrors(err);
                },
                onFinish: () => {
                    setIsSubmitting(false);
                },
            },
        );
    };

    return (
        <>
            <Head title="Jadwal dari pola" />

            <div className="flex min-h-[calc(100vh-4rem)] flex-col bg-background">
                {/* Header Ribbon */}
                <div className="sticky top-0 z-20 flex shrink-0 flex-wrap items-center justify-between gap-2 border-b border-border bg-card px-6 py-3 text-xs">
                    <div className="flex items-center gap-3">
                        <Link
                            href="/settings/working-time-calendar-times"
                            className="inline-flex items-center gap-1 text-muted-foreground hover:text-foreground"
                        >
                            <ArrowLeft className="size-4" />
                            <span>Jadwal kerja</span>
                        </Link>
                        <span className="text-border">|</span>
                        <div className="flex items-center gap-2">
                            <CalendarCheck className="size-4 text-primary" />
                            <span className="text-sm font-semibold text-foreground">
                                Jadwal dari pola (Compose working times)
                            </span>
                        </div>
                    </div>

                    <div className="flex items-center gap-2">
                        <Link
                            href="/settings/working-time-calendars"
                            className="rounded border border-input px-2.5 py-1 text-xs text-muted-foreground hover:bg-accent hover:text-foreground"
                        >
                            Daftar Kalender
                        </Link>
                        <Link
                            href="/settings/working-time-templates"
                            className="rounded border border-input px-2.5 py-1 text-xs text-muted-foreground hover:bg-accent hover:text-foreground"
                        >
                            Daftar Pola
                        </Link>
                    </div>
                </div>

                {/* Page Content */}
                <div className="flex-1 p-6">
                    <div className="mx-auto max-w-6xl space-y-6">
                        {/* Info Banner */}
                        <div className="flex items-start gap-3 rounded-lg border border-primary/20 bg-primary/5 p-4 text-xs text-foreground">
                            <Sparkles className="mt-0.5 size-4 shrink-0 text-primary" />
                            <div className="space-y-1">
                                <p className="font-semibold text-foreground">
                                    Penyusunan Jadwal Kerja Otomatis
                                </p>
                                <p className="text-muted-foreground">
                                    Fitur ini menerapkan pola jam kerja mingguan
                                    (working time template) ke dalam kalender
                                    kerja untuk rentang tanggal yang Anda
                                    tentukan. Hari kerja, jam kerja per shift,
                                    efisiensi, dan kapasitas akan dihitung
                                    secara otomatis.
                                </p>
                            </div>
                        </div>

                        {/* General Form Error */}
                        {errors.general && (
                            <div className="flex items-center gap-2 rounded-md border border-destructive/30 bg-destructive/10 p-3 text-xs text-destructive">
                                <Info className="size-4 shrink-0" />
                                <span>{errors.general}</span>
                            </div>
                        )}

                        <div className="grid grid-cols-1 gap-6 lg:grid-cols-12">
                            {/* Left Column: Form Setup (7 cols) */}
                            <form
                                onSubmit={handleSubmit}
                                className="space-y-6 rounded-lg border border-border bg-card p-6 lg:col-span-7"
                            >
                                <div className="border-b border-border pb-3">
                                    <h2 className="text-sm font-semibold text-foreground">
                                        Parameter Penyusunan Jadwal
                                    </h2>
                                    <p className="text-xs text-muted-foreground">
                                        Pilih kalender sasaran, pola acuan,
                                        serta rentang tanggal pelaksanaan.
                                    </p>
                                </div>

                                {/* Field 1: Target Calendar */}
                                <div className="space-y-1.5">
                                    <label className="text-xs font-semibold text-foreground">
                                        Kalender Kerja Sasaran{' '}
                                        <span className="text-destructive">
                                            *
                                        </span>
                                    </label>
                                    {calendars.length === 0 ? (
                                        <div className="rounded border border-amber-500/30 bg-amber-500/10 p-3 text-xs text-amber-600 dark:text-amber-400">
                                            Belum ada kalender kerja yang
                                            dibuat.{' '}
                                            <Link
                                                href="/settings/working-time-calendars"
                                                className="font-semibold underline hover:no-underline"
                                            >
                                                Buat kalender kerja sekarang
                                            </Link>
                                        </div>
                                    ) : (
                                        <select
                                            value={calendarId}
                                            onChange={(e) =>
                                                setCalendarId(e.target.value)
                                            }
                                            className="w-full rounded-md border border-input bg-background px-3 py-2 text-xs font-medium text-foreground focus:ring-1 focus:ring-ring focus:outline-none"
                                        >
                                            {calendars.map((c) => (
                                                <option key={c.id} value={c.id}>
                                                    {c.code} — {c.name} (
                                                    {c.standard_work_hours}{' '}
                                                    jam/hari)
                                                </option>
                                            ))}
                                        </select>
                                    )}
                                    {errors.calendar_id && (
                                        <p className="text-xs text-destructive">
                                            {errors.calendar_id}
                                        </p>
                                    )}
                                </div>

                                {/* Field 2: Working Time Template */}
                                <div className="space-y-1.5">
                                    <label className="text-xs font-semibold text-foreground">
                                        Pola Jam Kerja Acuan{' '}
                                        <span className="text-destructive">
                                            *
                                        </span>
                                    </label>
                                    {templates.length === 0 ? (
                                        <div className="rounded border border-amber-500/30 bg-amber-500/10 p-3 text-xs text-amber-600 dark:text-amber-400">
                                            Belum ada pola jam kerja aktif.{' '}
                                            <Link
                                                href="/settings/working-time-templates"
                                                className="font-semibold underline hover:no-underline"
                                            >
                                                Buat pola jam kerja sekarang
                                            </Link>
                                        </div>
                                    ) : (
                                        <select
                                            value={templateId}
                                            onChange={(e) =>
                                                setTemplateId(e.target.value)
                                            }
                                            className="w-full rounded-md border border-input bg-background px-3 py-2 text-xs font-medium text-foreground focus:ring-1 focus:ring-ring focus:outline-none"
                                        >
                                            {templates.map((t) => (
                                                <option key={t.id} value={t.id}>
                                                    {t.code} — {t.name}
                                                </option>
                                            ))}
                                        </select>
                                    )}
                                    {errors.template_id && (
                                        <p className="text-xs text-destructive">
                                            {errors.template_id}
                                        </p>
                                    )}
                                </div>

                                {/* Field 3: Date Range */}
                                <div className="space-y-2">
                                    <div className="flex items-center justify-between">
                                        <label className="text-xs font-semibold text-foreground">
                                            Rentang Tanggal{' '}
                                            <span className="text-destructive">
                                                *
                                            </span>
                                        </label>
                                        {/* Presets */}
                                        <div className="flex items-center gap-1.5 text-[11px]">
                                            <span className="text-muted-foreground">
                                                Pilihan cepat:
                                            </span>
                                            <button
                                                type="button"
                                                onClick={
                                                    handleSetRangeCurrentMonth
                                                }
                                                className="rounded bg-muted px-2 py-0.5 text-muted-foreground hover:bg-accent hover:text-foreground"
                                            >
                                                Bulan Ini
                                            </button>
                                            <button
                                                type="button"
                                                onClick={
                                                    handleSetRangeNextMonth
                                                }
                                                className="rounded bg-muted px-2 py-0.5 text-muted-foreground hover:bg-accent hover:text-foreground"
                                            >
                                                Bulan Depan
                                            </button>
                                            <button
                                                type="button"
                                                onClick={
                                                    handleSetRangeCurrentYear
                                                }
                                                className="rounded bg-muted px-2 py-0.5 text-muted-foreground hover:bg-accent hover:text-foreground"
                                            >
                                                Tahun Ini
                                            </button>
                                        </div>
                                    </div>

                                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                                        <div className="space-y-1">
                                            <span className="text-[11px] text-muted-foreground">
                                                Dari Tanggal:
                                            </span>
                                            <Input
                                                type="date"
                                                value={fromDate}
                                                onChange={(e) =>
                                                    setFromDate(e.target.value)
                                                }
                                                className="h-8 text-xs"
                                            />
                                            {errors.from_date && (
                                                <p className="text-xs text-destructive">
                                                    {errors.from_date}
                                                </p>
                                            )}
                                        </div>

                                        <div className="space-y-1">
                                            <span className="text-[11px] text-muted-foreground">
                                                Sampai Tanggal:
                                            </span>
                                            <Input
                                                type="date"
                                                value={toDate}
                                                onChange={(e) =>
                                                    setToDate(e.target.value)
                                                }
                                                className="h-8 text-xs"
                                            />
                                            {errors.to_date && (
                                                <p className="text-xs text-destructive">
                                                    {errors.to_date}
                                                </p>
                                            )}
                                        </div>
                                    </div>
                                    <p className="text-[11px] text-muted-foreground">
                                        Setiap hari dalam rentang ini yang telah
                                        memiliki jadwal akan ditimpa dengan
                                        konfigurasi pola terbaru.
                                    </p>
                                </div>

                                {/* Form Submit Actions */}
                                <div className="flex items-center justify-end gap-3 border-t border-border pt-4">
                                    <Link
                                        href="/settings/working-time-calendar-times"
                                        className="rounded-md border border-input px-4 py-2 text-xs font-medium text-muted-foreground hover:bg-accent hover:text-foreground"
                                    >
                                        Batal
                                    </Link>
                                    <Button
                                        type="submit"
                                        disabled={
                                            !canManage ||
                                            calendars.length === 0 ||
                                            templates.length === 0 ||
                                            isSubmitting
                                        }
                                        className="gap-2 bg-primary text-primary-foreground hover:bg-primary/90"
                                    >
                                        <Play className="size-3.5 fill-current" />
                                        <span>
                                            {isSubmitting
                                                ? 'Memproses Jadwal…'
                                                : 'Terapkan Jadwal dari Pola'}
                                        </span>
                                    </Button>
                                </div>
                            </form>

                            {/* Right Column: Template Preview & Calendar Info (5 cols) */}
                            <div className="space-y-6 lg:col-span-5">
                                {/* Calendar Summary Card */}
                                {selectedCalendar && (
                                    <div className="rounded-lg border border-border bg-card p-4 text-xs">
                                        <div className="flex items-center gap-2 border-b border-border pb-2 text-xs font-semibold text-foreground">
                                            <CalendarDays className="size-4 text-primary" />
                                            <span>
                                                Kalender Sasaran:{' '}
                                                {selectedCalendar.code}
                                            </span>
                                        </div>
                                        <div className="mt-3 space-y-1 text-muted-foreground">
                                            <p className="font-medium text-foreground">
                                                {selectedCalendar.name}
                                            </p>
                                            <p>
                                                Standar jam kerja per hari:{' '}
                                                <span className="font-semibold text-foreground">
                                                    {
                                                        selectedCalendar.standard_work_hours
                                                    }{' '}
                                                    jam
                                                </span>
                                            </p>
                                        </div>
                                    </div>
                                )}

                                {/* Template Schedule Preview */}
                                <div className="rounded-lg border border-border bg-card p-4">
                                    <div className="flex items-center justify-between border-b border-border pb-2">
                                        <div className="flex items-center gap-2 text-xs font-semibold text-foreground">
                                            <Clock className="size-4 text-primary" />
                                            <span>
                                                Pratinjau Pola:{' '}
                                                {selectedTemplate?.code ??
                                                    'Belum ada pola'}
                                            </span>
                                        </div>
                                        {selectedTemplate && (
                                            <Badge
                                                variant="outline"
                                                className="text-[10px]"
                                            >
                                                {selectedTemplate.lines.length}{' '}
                                                Baris Shift
                                            </Badge>
                                        )}
                                    </div>

                                    {selectedTemplate ? (
                                        <div className="mt-3 overflow-hidden rounded border border-border">
                                            <Table>
                                                <TableHeader>
                                                    <TableRow>
                                                        <TableHead className="w-20 text-xs">
                                                            Hari
                                                        </TableHead>
                                                        <TableHead className="w-16 text-center text-xs">
                                                            Status
                                                        </TableHead>
                                                        <TableHead className="text-xs">
                                                            Jam Kerja
                                                        </TableHead>
                                                        <TableHead className="w-16 text-right text-xs">
                                                            Total
                                                        </TableHead>
                                                    </TableRow>
                                                </TableHeader>
                                                <TableBody>
                                                    {templatePreviewByDay.map(
                                                        (item) => (
                                                            <TableRow
                                                                key={
                                                                    item.dayIndex
                                                                }
                                                            >
                                                                <TableCell className="text-xs font-medium text-foreground">
                                                                    {
                                                                        item.dayName
                                                                    }
                                                                </TableCell>
                                                                <TableCell className="text-center text-xs">
                                                                    {item.isOpen ? (
                                                                        <Badge className="bg-emerald-500/10 text-[10px] text-emerald-600 hover:bg-emerald-500/20 dark:text-emerald-400">
                                                                            Buka
                                                                        </Badge>
                                                                    ) : (
                                                                        <Badge
                                                                            variant="secondary"
                                                                            className="text-[10px] text-muted-foreground"
                                                                        >
                                                                            Libur
                                                                        </Badge>
                                                                    )}
                                                                </TableCell>
                                                                <TableCell className="text-xs text-muted-foreground">
                                                                    {item.lines
                                                                        .length >
                                                                    0 ? (
                                                                        <div className="space-y-0.5">
                                                                            {item.lines.map(
                                                                                (
                                                                                    l,
                                                                                ) => (
                                                                                    <div
                                                                                        key={
                                                                                            l.id
                                                                                        }
                                                                                        className="flex items-center gap-1 font-mono text-[11px]"
                                                                                    >
                                                                                        <span>
                                                                                            {
                                                                                                l.from_time
                                                                                            }{' '}
                                                                                            -{' '}
                                                                                            {
                                                                                                l.to_time
                                                                                            }
                                                                                        </span>
                                                                                        {l.property && (
                                                                                            <span className="text-muted-foreground">
                                                                                                (
                                                                                                {
                                                                                                    l.property
                                                                                                }

                                                                                                )
                                                                                            </span>
                                                                                        )}
                                                                                    </div>
                                                                                ),
                                                                            )}
                                                                        </div>
                                                                    ) : (
                                                                        <span className="text-muted-foreground italic">
                                                                            -
                                                                        </span>
                                                                    )}
                                                                </TableCell>
                                                                <TableCell className="text-right font-mono text-xs font-semibold text-foreground">
                                                                    {item.totalHours.toFixed(
                                                                        1,
                                                                    )}{' '}
                                                                    j
                                                                </TableCell>
                                                            </TableRow>
                                                        ),
                                                    )}
                                                </TableBody>
                                            </Table>
                                        </div>
                                    ) : (
                                        <div className="py-8 text-center text-xs text-muted-foreground">
                                            Pilih pola jam kerja untuk melihat
                                            rincian jam per hari.
                                        </div>
                                    )}
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </>
    );
}

ComposeWorkingTimesPage.layout = {
    breadcrumbs: [
        { title: 'Kalender', href: '/settings/working-time-calendars' },
        {
            title: 'Jadwal dari pola',
            href: '/settings/compose-working-times',
        },
    ] satisfies BreadcrumbItem[],
};
