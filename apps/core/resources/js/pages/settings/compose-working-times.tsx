import { Alert, AlertDescription } from '@apperp/ui/alert';
import { Badge } from '@apperp/ui/badge';
import { Button } from '@apperp/ui/button';
import { Field, FieldError } from '@apperp/ui/field';
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
import { AlertCircle, ArrowLeft, Play } from 'lucide-react';
import { useMemo, useState } from 'react';
import { useToday } from '@/hooks/use-work-date';
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

/**
 * Tanggal kalender lokal sebagai `YYYY-MM-DD`.
 *
 * Bukan `toISOString()`: fungsi itu mengubah tanggal ke UTC lebih dulu, jadi tengah malam di
 * WIB/WITA/WIT menjadi hari sebelumnya — "Bulan ini" di bulan September berubah menjadi
 * 31 Agustus sampai 29 September.
 */
function localDate(date: Date): string {
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');

    return `${date.getFullYear()}-${month}-${day}`;
}

export default function ComposeWorkingTimesPage({
    calendars,
    templates,
    initialCalendarId,
    initialTemplateId,
    initialFromDate,
    initialToDate,
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

    // Baris pola dikelompokkan per hari (0 = Senin .. 6 = Minggu)
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

    const today = useToday();

    const setRange = (start: Date, end: Date) => {
        setFromDate(localDate(start));
        setToDate(localDate(end));
    };

    // Bulan dan tahun "sekarang" dari hari ini menurut zona pengguna, bukan dari jam perangkat.
    const [todayYear, todayMonth] = today.split('-').map(Number);

    const handleSetRangeCurrentMonth = () => {
        setRange(
            new Date(todayYear, todayMonth - 1, 1),
            new Date(todayYear, todayMonth, 0),
        );
    };

    const handleSetRangeNextMonth = () => {
        setRange(
            new Date(todayYear, todayMonth, 1),
            new Date(todayYear, todayMonth + 1, 0),
        );
    };

    const handleSetRangeCurrentYear = () => {
        setFromDate(`${todayYear}-01-01`);
        setToDate(`${todayYear}-12-31`);
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
                        <span className="text-sm font-semibold text-foreground">
                            Jadwal dari pola
                        </span>
                    </div>

                    <div className="flex items-center gap-2">
                        <Link
                            href="/settings/working-time-calendars"
                            className="rounded border border-input px-2.5 py-1 text-xs text-muted-foreground hover:bg-accent hover:text-foreground"
                        >
                            Daftar kalender
                        </Link>
                        <Link
                            href="/settings/working-time-templates"
                            className="rounded border border-input px-2.5 py-1 text-xs text-muted-foreground hover:bg-accent hover:text-foreground"
                        >
                            Daftar pola
                        </Link>
                    </div>
                </div>

                <div className="flex-1 p-6">
                    <div className="mx-auto max-w-6xl space-y-6">
                        <p className="text-xs text-muted-foreground">
                            Terapkan pola jam kerja mingguan ke satu kalender
                            kerja untuk rentang tanggal tertentu. Hari kerja dan
                            jam kerjanya diambil dari pola yang dipilih.
                        </p>

                        {errors.general && (
                            <Alert variant="destructive">
                                <AlertCircle />
                                <AlertDescription>
                                    {errors.general}
                                </AlertDescription>
                            </Alert>
                        )}

                        <div className="grid grid-cols-1 gap-6 lg:grid-cols-12">
                            <form
                                onSubmit={handleSubmit}
                                className="space-y-6 rounded-lg border border-border bg-card p-6 lg:col-span-7"
                            >
                                <h2 className="border-b border-border pb-3 text-sm font-semibold text-foreground">
                                    Susun jadwal
                                </h2>

                                {calendars.length === 0 ? (
                                    <div className="rounded border border-warning/30 bg-warning/10 p-3 text-xs text-warning">
                                        Belum ada kalender kerja yang dibuat.{' '}
                                        <Link
                                            href="/settings/working-time-calendars"
                                            className="font-semibold underline hover:no-underline"
                                        >
                                            Buat kalender kerja sekarang
                                        </Link>
                                    </div>
                                ) : (
                                    <Field
                                        data-invalid={Boolean(
                                            errors.calendar_id,
                                        )}
                                    >
                                        <NativeSelect
                                            label="Kalender kerja sasaran"
                                            value={calendarId}
                                            onChange={(e) =>
                                                setCalendarId(e.target.value)
                                            }
                                        >
                                            {calendars.map((c) => (
                                                <NativeSelectOption
                                                    key={c.id}
                                                    value={c.id}
                                                >
                                                    {c.code} — {c.name} (
                                                    {c.standard_work_hours}{' '}
                                                    jam/hari)
                                                </NativeSelectOption>
                                            ))}
                                        </NativeSelect>
                                        {errors.calendar_id && (
                                            <FieldError>
                                                {errors.calendar_id}
                                            </FieldError>
                                        )}
                                    </Field>
                                )}

                                {templates.length === 0 ? (
                                    <div className="rounded border border-warning/30 bg-warning/10 p-3 text-xs text-warning">
                                        Belum ada pola jam kerja aktif.{' '}
                                        <Link
                                            href="/settings/working-time-templates"
                                            className="font-semibold underline hover:no-underline"
                                        >
                                            Buat pola jam kerja sekarang
                                        </Link>
                                    </div>
                                ) : (
                                    <Field
                                        data-invalid={Boolean(
                                            errors.template_id,
                                        )}
                                    >
                                        <NativeSelect
                                            label="Pola jam kerja acuan"
                                            value={templateId}
                                            onChange={(e) =>
                                                setTemplateId(e.target.value)
                                            }
                                        >
                                            {templates.map((t) => (
                                                <NativeSelectOption
                                                    key={t.id}
                                                    value={t.id}
                                                >
                                                    {t.code} — {t.name}
                                                </NativeSelectOption>
                                            ))}
                                        </NativeSelect>
                                        {errors.template_id && (
                                            <FieldError>
                                                {errors.template_id}
                                            </FieldError>
                                        )}
                                    </Field>
                                )}

                                <div className="space-y-3">
                                    <div className="flex flex-wrap items-center gap-1.5 text-[11px]">
                                        <span className="text-muted-foreground">
                                            Pilihan cepat:
                                        </span>
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            onClick={handleSetRangeCurrentMonth}
                                        >
                                            Bulan ini
                                        </Button>
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            onClick={handleSetRangeNextMonth}
                                        >
                                            Bulan depan
                                        </Button>
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            onClick={handleSetRangeCurrentYear}
                                        >
                                            Tahun ini
                                        </Button>
                                    </div>

                                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                                        <Field
                                            data-invalid={Boolean(
                                                errors.from_date,
                                            )}
                                        >
                                            <Input
                                                label="Dari tanggal"
                                                type="date"
                                                required
                                                value={fromDate}
                                                onChange={(e) =>
                                                    setFromDate(e.target.value)
                                                }
                                            />
                                            {errors.from_date && (
                                                <FieldError>
                                                    {errors.from_date}
                                                </FieldError>
                                            )}
                                        </Field>

                                        <Field
                                            data-invalid={Boolean(
                                                errors.to_date,
                                            )}
                                        >
                                            <Input
                                                label="Sampai tanggal"
                                                type="date"
                                                required
                                                value={toDate}
                                                onChange={(e) =>
                                                    setToDate(e.target.value)
                                                }
                                            />
                                            {errors.to_date && (
                                                <FieldError>
                                                    {errors.to_date}
                                                </FieldError>
                                            )}
                                        </Field>
                                    </div>
                                    <p className="text-[11px] text-muted-foreground">
                                        Setiap hari dalam rentang ini yang telah
                                        memiliki jadwal akan ditimpa dengan pola
                                        yang dipilih. Rentang paling panjang 3
                                        tahun.
                                    </p>
                                </div>

                                <div className="flex items-center justify-end gap-3 border-t border-border pt-4">
                                    <Button variant="outline" asChild>
                                        <Link href="/settings/working-time-calendar-times">
                                            Batal
                                        </Link>
                                    </Button>
                                    <Button
                                        type="submit"
                                        disabled={
                                            calendars.length === 0 ||
                                            templates.length === 0 ||
                                            isSubmitting
                                        }
                                    >
                                        <Play className="fill-current" />
                                        {isSubmitting
                                            ? 'Menyusun jadwal…'
                                            : 'Susun jadwal'}
                                    </Button>
                                </div>
                            </form>

                            <div className="space-y-6 lg:col-span-5">
                                {selectedCalendar && (
                                    <div className="rounded-lg border border-border bg-card p-4 text-xs">
                                        <p className="border-b border-border pb-2 font-semibold text-foreground">
                                            Kalender sasaran:{' '}
                                            {selectedCalendar.code}
                                        </p>
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

                                <div className="rounded-lg border border-border bg-card p-4">
                                    <div className="flex items-center justify-between border-b border-border pb-2">
                                        <p className="text-xs font-semibold text-foreground">
                                            Pratinjau pola:{' '}
                                            {selectedTemplate?.code ??
                                                'Belum ada pola'}
                                        </p>
                                        {selectedTemplate && (
                                            <Badge
                                                variant="outline"
                                                className="text-[10px]"
                                            >
                                                {selectedTemplate.lines.length}{' '}
                                                baris jam kerja
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
                                                            Jam kerja
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
                                                                        <Badge className="bg-success/10 text-[10px] text-success">
                                                                            Kerja
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
