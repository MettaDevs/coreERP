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
import { ArrowLeft, CalendarDays, Check, Play } from 'lucide-react';
import { useMemo, useState } from 'react';
import { cn } from '@/lib/utils';
import type { BreadcrumbItem } from '@/types/navigation';

export type CalendarLine = {
    id: string;
    from_time: string | null;
    to_time: string | null;
    efficiency: number;
    property: string | null;
    hours: number;
};

export type CalendarDay = {
    id: string;
    date: string;
    day_of_week: number;
    control: 'open' | 'closed';
    closed_for_pickup: boolean;
    hours: number;
    lines: CalendarLine[];
};

export type TemplateOption = {
    id: string;
    code: string;
    name: string;
};

export type CalendarOption = {
    id: string;
    code: string;
    name: string;
    standard_work_hours: number;
};

type Props = {
    calendar: CalendarOption | null;
    allCalendars: CalendarOption[];
    days: CalendarDay[];
    from: string;
    to: string;
    templates: TemplateOption[];
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
const MONTH_NAMES = [
    'Januari',
    'Februari',
    'Maret',
    'April',
    'Mei',
    'Juni',
    'Juli',
    'Agustus',
    'September',
    'Oktober',
    'November',
    'Desember',
];

function getWeekNumber(d: Date): number {
    const target = new Date(d.valueOf());
    const dayNr = (d.getDay() + 6) % 7;

    target.setDate(target.getDate() - dayNr + 3);

    const firstThursday = target.valueOf();

    target.setMonth(0, 1);

    if (target.getDay() !== 4) {
        target.setMonth(0, 1 + ((4 - target.getDay() + 7) % 7));
    }

    return 1 + Math.ceil((firstThursday - target.valueOf()) / 604800000);
}

export default function WorkingTimeCalendarTimes({
    calendar,
    allCalendars,
    days,
    from,
    to,
    templates,
    canManage,
}: Props) {
    const [selectedDayId, setSelectedDayId] = useState<string | null>(
        days.length > 0 ? days[0].id : null,
    );

    // Filter range state
    const [filterFrom, setFilterFrom] = useState(from);
    const [filterTo, setFilterTo] = useState(to);

    // Compose Dialog state
    const [isComposeOpen, setIsComposeOpen] = useState(false);
    const [composeTemplateId, setComposeTemplateId] = useState<string>(
        templates.length > 0 ? templates[0].id : '',
    );
    const [composeFromDate, setComposeFromDate] = useState(from);
    const [composeToDate, setComposeToDate] = useState(to);
    const [isComposing, setIsComposing] = useState(false);
    const [composeErrors, setComposeErrors] = useState<Record<string, string>>(
        {},
    );

    // Toggle day state
    const [isUpdatingDay, setIsUpdatingDay] = useState(false);

    const selectedDay = useMemo(
        () =>
            days.find((d) => d.id === selectedDayId) ??
            (days.length > 0 ? days[0] : null),
        [days, selectedDayId],
    );

    const handleApplyFilter = () => {
        if (!calendar) {
            return;
        }

        router.get(
            `/settings/working-time-calendar-times/${calendar.id}`,
            { from: filterFrom, to: filterTo },
            { preserveState: true, preserveScroll: true },
        );
    };

    const handleExecuteCompose = () => {
        if (!calendar || !composeTemplateId) {
            return;
        }

        setIsComposing(true);
        setComposeErrors({});

        router.post(
            `/settings/working-time-calendars/${calendar.id}/compose`,
            {
                template_id: composeTemplateId,
                from_date: composeFromDate,
                to_date: composeToDate,
            },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setIsComposeOpen(false);
                },
                onError: (err) => {
                    setComposeErrors(err);
                },
                onFinish: () => setIsComposing(false),
            },
        );
    };

    const handleToggleDayControl = (day: CalendarDay) => {
        if (!calendar || !canManage) {
            return;
        }

        const newControl = day.control === 'open' ? 'closed' : 'open';
        setIsUpdatingDay(true);

        router.put(
            `/settings/working-time-calendars/${calendar.id}/days/${day.id}`,
            {
                control: newControl,
                closed_for_pickup:
                    newControl === 'closed' ? true : day.closed_for_pickup,
            },
            {
                preserveScroll: true,
                onFinish: () => setIsUpdatingDay(false),
            },
        );
    };

    return (
        <>
            <Head
                title={
                    calendar
                        ? `Jadwal Kerja - ${calendar.code}`
                        : 'Jadwal Kerja'
                }
            />

            <div className="flex min-h-[calc(100vh-4rem)] flex-col bg-background">
                {/* Command Bar / Action Ribbon */}
                <div className="sticky top-0 z-20 flex shrink-0 flex-wrap items-center justify-between gap-2 border-b border-border bg-card px-4 py-2 text-xs">
                    <div className="flex flex-wrap items-center gap-2">
                        <Link
                            href="/settings/working-time-calendars"
                            className="inline-flex items-center gap-1 rounded px-2 py-1 text-muted-foreground hover:bg-muted hover:text-foreground"
                        >
                            <ArrowLeft className="size-4" />
                            <span>Kalender</span>
                        </Link>

                        <span className="text-border">|</span>

                        {allCalendars.length > 0 ? (
                            <div className="flex items-center gap-1.5">
                                <span className="font-medium text-muted-foreground">
                                    Pilih Kalender:
                                </span>
                                <select
                                    value={calendar?.id ?? ''}
                                    onChange={(e) => {
                                        if (e.target.value) {
                                            router.visit(
                                                `/settings/working-time-calendar-times/${e.target.value}`,
                                            );
                                        }
                                    }}
                                    className="h-7 rounded border border-input bg-background px-2 text-xs font-semibold text-foreground focus:ring-1 focus:ring-ring focus:outline-none"
                                >
                                    {allCalendars.map((c) => (
                                        <option key={c.id} value={c.id}>
                                            {c.code} - {c.name}
                                        </option>
                                    ))}
                                </select>
                            </div>
                        ) : (
                            <span className="text-xs text-muted-foreground">
                                Belum ada kalender dibuat
                            </span>
                        )}

                        {canManage && calendar && (
                            <Button
                                variant="default"
                                size="sm"
                                type="button"
                                onClick={() => {
                                    setComposeErrors({});
                                    setIsComposeOpen(true);
                                }}
                                className="ml-2 gap-1.5 bg-primary text-primary-foreground hover:bg-primary/90"
                            >
                                <Play className="size-3.5 fill-current" />
                                <span>Buat jadwal dari pola (Compose)</span>
                            </Button>
                        )}

                        {canManage && (
                            <Link
                                href={
                                    calendar
                                        ? `/settings/compose-working-times?calendar_id=${calendar.id}`
                                        : '/settings/compose-working-times'
                                }
                                className="ml-1 inline-flex items-center gap-1.5 rounded-md border border-input bg-background px-2.5 py-1 text-xs font-medium text-muted-foreground hover:bg-accent hover:text-foreground"
                            >
                                <Play className="size-3 fill-current text-primary" />
                                <span>Halaman Jadwal dari Pola</span>
                            </Link>
                        )}
                    </div>

                    {/* Date filter tools */}
                    {calendar && (
                        <div className="flex items-center gap-2">
                            <span className="text-muted-foreground">
                                Periode:
                            </span>
                            <Input
                                type="date"
                                value={filterFrom}
                                onChange={(e) => setFilterFrom(e.target.value)}
                                className="h-7 w-36 text-xs"
                            />
                            <span className="text-muted-foreground">s/d</span>
                            <Input
                                type="date"
                                value={filterTo}
                                onChange={(e) => setFilterTo(e.target.value)}
                                className="h-7 w-36 text-xs"
                            />
                            <Button
                                variant="outline"
                                size="sm"
                                type="button"
                                onClick={handleApplyFilter}
                                className="h-7 px-2.5 text-xs"
                            >
                                Terapkan
                            </Button>
                        </div>
                    )}
                </div>

                {!calendar ? (
                    <div className="flex flex-1 items-center justify-center p-8">
                        <div className="max-w-md text-center">
                            <CalendarDays className="mx-auto size-12 text-muted-foreground/50" />
                            <h3 className="mt-3 text-sm font-semibold text-foreground">
                                Belum ada kalender kerja
                            </h3>
                            <p className="mt-1 text-xs text-muted-foreground">
                                Silakan buat kalender kerja terlebih dahulu di
                                menu <strong>Kalender kerja</strong> sebelum
                                melihat atau mengatur jadwal kerja.
                            </p>
                            <Link
                                href="/settings/working-time-calendars"
                                className="mt-4 inline-flex items-center gap-1.5 rounded-md bg-primary px-3 py-1.5 text-xs font-medium text-primary-foreground hover:bg-primary/90"
                            >
                                Buka Kalender Kerja
                            </Link>
                        </div>
                    </div>
                ) : (
                    /* 2-Level Grid Layout: Working Days (Top) & Working Times (Bottom) */
                    <div className="flex flex-1 flex-col gap-4 p-4">
                        {/* Top Panel: Working days */}
                        <div className="flex flex-col rounded-md border border-border bg-card">
                            <div className="flex items-center justify-between border-b border-border px-4 py-2.5">
                                <span className="text-xs font-semibold tracking-wider text-muted-foreground uppercase">
                                    Hari Kerja (Working Days) — Total:{' '}
                                    {days.length} hari
                                </span>
                                <span className="text-xs text-muted-foreground">
                                    Klik baris untuk melihat rincian jam kerja
                                    di bawah
                                </span>
                            </div>

                            <div className="max-h-72 overflow-y-auto">
                                <Table>
                                    <TableHeader className="sticky top-0 bg-card">
                                        <TableRow>
                                            <TableHead className="w-12 text-center">
                                                Pilih
                                            </TableHead>
                                            <TableHead className="w-32">
                                                Tanggal
                                            </TableHead>
                                            <TableHead className="w-24">
                                                Hari
                                            </TableHead>
                                            <TableHead className="w-20 text-center">
                                                Minggu
                                            </TableHead>
                                            <TableHead className="w-28">
                                                Bulan
                                            </TableHead>
                                            <TableHead className="w-28 text-center">
                                                Kontrol
                                            </TableHead>
                                            <TableHead className="w-36 text-center">
                                                Tutup Pengambilan
                                            </TableHead>
                                            <TableHead className="w-28 text-right">
                                                Total Jam
                                            </TableHead>
                                            {canManage && (
                                                <TableHead className="w-24 text-center">
                                                    Aksi
                                                </TableHead>
                                            )}
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {days.length === 0 ? (
                                            <TableRow>
                                                <TableCell
                                                    colSpan={9}
                                                    className="py-8 text-center text-muted-foreground"
                                                >
                                                    Belum ada jadwal kerja untuk
                                                    periode ini. Klik{' '}
                                                    <strong>
                                                        Buat jadwal dari pola
                                                        (Compose)
                                                    </strong>{' '}
                                                    untuk meng-generate jadwal.
                                                </TableCell>
                                            </TableRow>
                                        ) : (
                                            days.map((day) => {
                                                const isSelected =
                                                    day.id ===
                                                    (selectedDay?.id ?? null);
                                                const dObj = new Date(
                                                    `${day.date}T00:00:00`,
                                                );
                                                const dayName =
                                                    DAY_NAMES[
                                                        day.day_of_week
                                                    ] || '-';
                                                const monthName =
                                                    MONTH_NAMES[
                                                        dObj.getMonth()
                                                    ] || '-';
                                                const weekNr =
                                                    getWeekNumber(dObj);
                                                const isOpen =
                                                    day.control === 'open';

                                                return (
                                                    <TableRow
                                                        key={day.id}
                                                        className={cn(
                                                            'cursor-pointer transition-colors hover:bg-muted/50',
                                                            isSelected &&
                                                                'bg-primary/10 hover:bg-primary/15',
                                                        )}
                                                        onClick={() =>
                                                            setSelectedDayId(
                                                                day.id,
                                                            )
                                                        }
                                                    >
                                                        <TableCell
                                                            className="text-center"
                                                            onClick={(e) =>
                                                                e.stopPropagation()
                                                            }
                                                        >
                                                            <Checkbox
                                                                checked={
                                                                    isSelected
                                                                }
                                                                onCheckedChange={() =>
                                                                    setSelectedDayId(
                                                                        day.id,
                                                                    )
                                                                }
                                                            />
                                                        </TableCell>
                                                        <TableCell className="font-mono text-xs font-semibold text-foreground">
                                                            {day.date}
                                                        </TableCell>
                                                        <TableCell>
                                                            {dayName}
                                                        </TableCell>
                                                        <TableCell className="text-center font-mono text-xs">
                                                            {weekNr}
                                                        </TableCell>
                                                        <TableCell>
                                                            {monthName}
                                                        </TableCell>
                                                        <TableCell className="text-center">
                                                            <span
                                                                className={cn(
                                                                    'inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium',
                                                                    isOpen
                                                                        ? 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-400'
                                                                        : 'bg-muted text-muted-foreground',
                                                                )}
                                                            >
                                                                {isOpen
                                                                    ? 'Open'
                                                                    : 'Closed'}
                                                            </span>
                                                        </TableCell>
                                                        <TableCell className="text-center">
                                                            {day.closed_for_pickup ? (
                                                                <Check className="mx-auto size-4 text-amber-600" />
                                                            ) : (
                                                                '-'
                                                            )}
                                                        </TableCell>
                                                        <TableCell className="text-right font-mono text-xs">
                                                            {day.hours.toFixed(
                                                                2,
                                                            )}{' '}
                                                            jam
                                                        </TableCell>
                                                        {canManage && (
                                                            <TableCell
                                                                className="text-center"
                                                                onClick={(e) =>
                                                                    e.stopPropagation()
                                                                }
                                                            >
                                                                <Button
                                                                    variant="ghost"
                                                                    size="sm"
                                                                    type="button"
                                                                    onClick={() =>
                                                                        handleToggleDayControl(
                                                                            day,
                                                                        )
                                                                    }
                                                                    disabled={
                                                                        isUpdatingDay
                                                                    }
                                                                    className="h-6 px-2 text-[11px]"
                                                                >
                                                                    {isOpen
                                                                        ? 'Tutup'
                                                                        : 'Buka'}
                                                                </Button>
                                                            </TableCell>
                                                        )}
                                                    </TableRow>
                                                );
                                            })
                                        )}
                                    </TableBody>
                                </Table>
                            </div>
                        </div>

                        {/* Bottom Panel: Working times detail for selected day */}
                        <div className="flex flex-1 flex-col rounded-md border border-border bg-card">
                            <div className="flex items-center justify-between border-b border-border px-4 py-2.5">
                                <span className="text-xs font-semibold tracking-wider text-muted-foreground uppercase">
                                    Rincian Jam Kerja (Working Times) — Tanggal:{' '}
                                    <strong className="text-foreground">
                                        {selectedDay ? selectedDay.date : '-'}
                                    </strong>{' '}
                                    (
                                    {selectedDay
                                        ? DAY_NAMES[selectedDay.day_of_week]
                                        : '-'}
                                    )
                                </span>
                                {selectedDay && (
                                    <span className="font-mono text-xs text-muted-foreground">
                                        Total: {selectedDay.hours.toFixed(2)}{' '}
                                        jam kerja
                                    </span>
                                )}
                            </div>

                            <div className="flex-1 overflow-y-auto">
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead className="w-32">
                                                Kalender
                                            </TableHead>
                                            <TableHead className="w-28">
                                                Dari Jam
                                            </TableHead>
                                            <TableHead className="w-28">
                                                Sampai Jam
                                            </TableHead>
                                            <TableHead className="w-32 text-right">
                                                Efisiensi (%)
                                            </TableHead>
                                            <TableHead>Properti</TableHead>
                                            <TableHead className="w-32 text-right">
                                                Durasi Jam
                                            </TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {!selectedDay ||
                                        selectedDay.lines.length === 0 ? (
                                            <TableRow>
                                                <TableCell
                                                    colSpan={6}
                                                    className="py-6 text-center text-muted-foreground"
                                                >
                                                    Tidak ada jam kerja efektif
                                                    pada hari ini (hari libur /
                                                    ditutup).
                                                </TableCell>
                                            </TableRow>
                                        ) : (
                                            selectedDay.lines.map(
                                                (line, idx) => (
                                                    <TableRow
                                                        key={line.id || idx}
                                                    >
                                                        <TableCell className="font-semibold text-foreground">
                                                            {calendar.code}
                                                        </TableCell>
                                                        <TableCell className="font-mono text-xs">
                                                            {line.from_time ||
                                                                '-'}
                                                        </TableCell>
                                                        <TableCell className="font-mono text-xs">
                                                            {line.to_time ||
                                                                '-'}
                                                        </TableCell>
                                                        <TableCell className="text-right font-mono text-xs">
                                                            {line.efficiency.toFixed(
                                                                2,
                                                            )}
                                                            %
                                                        </TableCell>
                                                        <TableCell className="text-muted-foreground">
                                                            {line.property ||
                                                                'Reguler'}
                                                        </TableCell>
                                                        <TableCell className="text-right font-mono text-xs font-semibold">
                                                            {line.hours.toFixed(
                                                                2,
                                                            )}{' '}
                                                            jam
                                                        </TableCell>
                                                    </TableRow>
                                                ),
                                            )
                                        )}
                                    </TableBody>
                                </Table>
                            </div>
                        </div>
                    </div>
                )}
            </div>

            {/* Dialog Compose Working Times (Section 4 in D365 Blueprint) */}
            <Dialog open={isComposeOpen} onOpenChange={setIsComposeOpen}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>
                            Buat Jadwal dari Pola (Compose Working Times)
                        </DialogTitle>
                        <DialogDescription>
                            Generate jadwal hari dan jam kerja secara otomatis
                            untuk rentang tanggal tertentu berdasarkan pola jam
                            kerja yang dipilih.
                        </DialogDescription>
                    </DialogHeader>

                    <DialogBody className="space-y-4 py-2">
                        <div>
                            <label className="text-xs font-semibold text-foreground">
                                Kalender Tujuan
                            </label>
                            <Input
                                value={
                                    calendar
                                        ? `${calendar.code} - ${calendar.name}`
                                        : ''
                                }
                                disabled
                                className="mt-1 bg-muted font-semibold"
                            />
                        </div>

                        <div>
                            <label className="text-xs font-semibold text-foreground">
                                Pola Jam Kerja (Template){' '}
                                <span className="text-destructive">*</span>
                            </label>
                            {templates.length === 0 ? (
                                <p className="mt-1 text-xs text-destructive">
                                    Belum ada template pola jam kerja yang
                                    aktif. Buat template terlebih dahulu di menu{' '}
                                    <strong>Pola jam kerja</strong>.
                                </p>
                            ) : (
                                <select
                                    value={composeTemplateId}
                                    onChange={(e) =>
                                        setComposeTemplateId(e.target.value)
                                    }
                                    className="mt-1 block w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background focus:ring-2 focus:ring-ring focus:outline-none"
                                >
                                    {templates.map((tpl) => (
                                        <option key={tpl.id} value={tpl.id}>
                                            {tpl.code} - {tpl.name}
                                        </option>
                                    ))}
                                </select>
                            )}
                            {composeErrors.template_id && (
                                <p className="mt-1 text-xs text-destructive">
                                    {composeErrors.template_id}
                                </p>
                            )}
                        </div>

                        <div className="grid grid-cols-2 gap-3">
                            <div>
                                <label className="text-xs font-semibold text-foreground">
                                    Dari Tanggal{' '}
                                    <span className="text-destructive">*</span>
                                </label>
                                <Input
                                    type="date"
                                    value={composeFromDate}
                                    onChange={(e) =>
                                        setComposeFromDate(e.target.value)
                                    }
                                    className="mt-1"
                                />
                                {composeErrors.from_date && (
                                    <p className="mt-1 text-xs text-destructive">
                                        {composeErrors.from_date}
                                    </p>
                                )}
                            </div>

                            <div>
                                <label className="text-xs font-semibold text-foreground">
                                    Sampai Tanggal{' '}
                                    <span className="text-destructive">*</span>
                                </label>
                                <Input
                                    type="date"
                                    value={composeToDate}
                                    onChange={(e) =>
                                        setComposeToDate(e.target.value)
                                    }
                                    className="mt-1"
                                />
                                {composeErrors.to_date && (
                                    <p className="mt-1 text-xs text-destructive">
                                        {composeErrors.to_date}
                                    </p>
                                )}
                            </div>
                        </div>

                        <div className="rounded-md bg-muted/60 p-3 text-xs text-muted-foreground">
                            <p className="font-semibold text-foreground">
                                Catatan:
                            </p>
                            <p className="mt-0.5">
                                Jadwal yang sudah ada pada rentang tanggal
                                tersebut akan diperbarui sesuai pola jam kerja
                                mingguan dari template yang dipilih.
                            </p>
                        </div>
                    </DialogBody>

                    <DialogFooter>
                        <DialogAction
                            onClick={handleExecuteCompose}
                            disabled={
                                isComposing ||
                                !composeTemplateId ||
                                !composeFromDate ||
                                !composeToDate
                            }
                        >
                            {isComposing ? 'Memproses…' : 'Eksekusi Jadwal'}
                        </DialogAction>
                        <DialogCancel onClick={() => setIsComposeOpen(false)} />
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}

WorkingTimeCalendarTimes.layout = {
    breadcrumbs: [
        { title: 'Kalender', href: '/settings/working-time-calendars' },
        {
            title: 'Jadwal kerja',
            href: '/settings/working-time-calendar-times',
        },
    ] satisfies BreadcrumbItem[],
};
