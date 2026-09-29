import { Alert, AlertDescription, AlertTitle } from '@apperp/ui/alert';
import { Badge } from '@apperp/ui/badge';
import { Button } from '@apperp/ui/button';
import { Link, router } from '@inertiajs/react';
import { CalendarClock, X } from 'lucide-react';
import { formatWorkDate, useWorkDate } from '@/hooks/use-work-date';

const PROFILE = '/settings/profile';

/**
 * Pengingat selama tanggal kerja bukan hari ini, seperti pengingat Work Date di Business Central.
 *
 * Transaksi baru memakai tanggal kerja sebagai tanggal bawaannya, jadi tanggal yang dilupakan akan diam-diam
 * menjadi tanggal dokumen. Pengingatnya boleh ditutup untuk sisa sesi, berbeda dengan spanduk lingkungan dan
 * lisensi: tanggal kerja keputusan pengguna sendiri. Setelah ditutup, tanggalnya tetap terlihat di header
 * lewat {@see WorkDateBadge}.
 */
export default function WorkDateNotice() {
    const { date, isToday, noticeDismissed } = useWorkDate();

    if (isToday || noticeDismissed || date === '') {
        return null;
    }

    return (
        <div className="px-4 pt-3">
            <Alert role="status">
                <CalendarClock />
                <AlertTitle>
                    Tanggal kerja {formatWorkDate(date)}, bukan hari ini
                </AlertTitle>
                <AlertDescription>
                    <p>
                        Transaksi baru memakai tanggal ini sebagai tanggalnya.
                    </p>
                    <div className="mt-2 flex flex-wrap gap-2">
                        <Button asChild size="sm" variant="outline">
                            <Link href={PROFILE}>Ubah tanggal kerja</Link>
                        </Button>
                        <Button
                            size="sm"
                            variant="outline"
                            onClick={() =>
                                router.patch(
                                    '/settings/date-time',
                                    { work_date: null },
                                    { preserveScroll: true },
                                )
                            }
                        >
                            Pakai hari ini
                        </Button>
                        <Button
                            size="sm"
                            variant="ghost"
                            onClick={() =>
                                router.post(
                                    '/settings/date-time/dismiss-work-date-notice',
                                    {},
                                    { preserveScroll: true },
                                )
                            }
                        >
                            <X />
                            Tutup
                        </Button>
                    </div>
                </AlertDescription>
            </Alert>
        </div>
    );
}

/** Tanggal kerja di header setelah pengingatnya ditutup; membuka My Profile untuk menggantinya. */
export function WorkDateBadge() {
    const { date, isToday, noticeDismissed } = useWorkDate();

    if (isToday || !noticeDismissed || date === '') {
        return null;
    }

    return (
        <Link href={PROFILE} className="shrink-0">
            <Badge variant="outline" className="gap-1.5">
                <CalendarClock className="size-3.5" />
                Tanggal kerja {formatWorkDate(date)}
            </Badge>
        </Link>
    );
}
