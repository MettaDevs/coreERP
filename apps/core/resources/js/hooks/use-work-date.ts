import { usePage } from '@inertiajs/react';

/** Menampilkan tanggal `YYYY-MM-DD` dalam bentuk panjang, misalnya "30 September 2026". */
export function formatWorkDate(date: string): string {
    const [year, month, day] = date.split('-').map(Number);

    return new Intl.DateTimeFormat('id-ID', {
        dateStyle: 'long',
        timeZone: 'UTC',
    }).format(new Date(Date.UTC(year, month - 1, day)));
}

/**
 * Hari ini (`YYYY-MM-DD`) menurut zona waktu pengguna, dihitung server.
 *
 * Jam perangkat tidak pernah dipakai (area 7, K-10): `new Date().toISOString()` adalah tanggal UTC, dan
 * jam peramban bisa salah atau berbeda zona dengan setelan pengguna. Dipakai untuk "hari ini" yang bukan
 * tanggal transaksi, misalnya tanggal berlaku setelan.
 */
export function useToday(): string {
    const { clock } = usePage().props;

    return clock?.today ?? '';
}

/**
 * Tanggal kerja pengguna: tanggal bawaan untuk transaksi baru, padanan Work Date di Business Central.
 * Bawaannya hari ini menurut zona pengguna; pengguna menggantinya di My Profile untuk sisa sesi.
 *
 * Form transaksi memakai `date` sebagai nilai awal field tanggalnya, bukan hari ini.
 */
export function useWorkDate(): {
    date: string;
    today: string;
    isToday: boolean;
    noticeDismissed: boolean;
} {
    const { workDate, clock } = usePage().props;
    const today = clock?.today ?? '';
    const date = workDate?.value ?? today;

    return {
        date,
        today,
        isToday: date === today,
        noticeDismissed: workDate?.notice_dismissed ?? false,
    };
}
