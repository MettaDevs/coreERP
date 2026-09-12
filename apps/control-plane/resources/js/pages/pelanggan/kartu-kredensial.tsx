import { Button } from '@apperp/ui/button';
import { useState } from 'react';

export type Kredensial = {
    tenant: string;
    nama: string;
    email: string;
    kataSandi: string;
};

/**
 * Kata sandi sementara, ditampilkan sekali — dan seluruh fitur "operator melahirkan pelanggan"
 * berdiri atau jatuh di sini.
 *
 * Repo ini tidak punya satu pun jalur email: nol `Mailable`, nol `Mail::`, nol `->notify(`. Jadi
 * undangan tidak dapat dikirim, dan satu-satunya cara admin pertama masuk adalah operator
 * membacakan kata sandi ini di telepon atau menempelkannya ke chat. Layar yang menyembunyikannya —
 * di balik tombol "lihat", di balik gulir, atau di balik pesan hijau yang menghilang setelah tiga
 * detik — membuat seluruh alurnya tidak dapat dipakai.
 *
 * Karena itu tiga hal ditegakkan di komponen ini:
 *
 * 1. kata sandinya terbaca apa adanya, besar dan monospace, tanpa perlu satu klik pun;
 * 2. ada tombol salin, karena menyalin dari layar dengan tangan adalah cara paling mudah
 *    menukar `l` dengan `1` lalu menyalahkan sistemnya;
 * 3. peringatan bahwa ia tidak akan muncul lagi ditulis **di samping kata sandinya**, bukan di
 *    bawah — peringatan yang harus digulir untuk dibaca adalah peringatan yang datang terlambat.
 */
export default function KartuKredensial({
    kredensial,
}: {
    kredensial: Kredensial;
}) {
    const [tersalin, setTersalin] = useState<string | null>(null);
    const [gagalSalin, setGagalSalin] = useState(false);

    async function salin(apa: string, teks: string) {
        // Clipboard API tidak ada pada origin yang bukan HTTPS maupun localhost, dan konsol ini
        // akan dibuka lewat alamat internal. Kegagalannya disebut apa adanya: operator tetap dapat
        // menyorot teksnya, asalkan ia tahu tombolnya memang tidak bekerja.
        try {
            await navigator.clipboard.writeText(teks);
            setGagalSalin(false);
            setTersalin(apa);
            window.setTimeout(() => setTersalin(null), 2500);
        } catch {
            setGagalSalin(true);
        }
    }

    return (
        <section className="rounded-lg border border-amber-300 bg-amber-50 p-5 text-amber-950 dark:border-amber-900/60 dark:bg-amber-950/40 dark:text-amber-50">
            <h2 className="text-base font-semibold">
                Akun admin untuk {kredensial.nama} sudah dibuat
            </h2>
            <p className="mt-1 text-sm">
                Sampaikan dua baris di bawah kepada pelanggan sekarang.{' '}
                <strong className="font-semibold">
                    Kata sandinya tidak akan muncul lagi
                </strong>{' '}
                — ia tidak disimpan di mana pun, dan menutup halaman ini
                menghilangkannya. Kalau telanjur hilang, tidak ada satu pun cara
                membacanya lagi dari konsol ini.
            </p>

            <dl className="mt-4 space-y-2">
                <div className="bg-background flex flex-wrap items-center justify-between gap-3 rounded-md border border-amber-200 px-4 py-3 dark:border-amber-900/60">
                    <div className="min-w-0">
                        <dt className="text-muted-foreground text-xs">
                            Email admin
                        </dt>
                        <dd className="truncate font-mono text-sm">
                            {kredensial.email}
                        </dd>
                    </div>
                    <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        onClick={() => void salin('email', kredensial.email)}
                    >
                        {tersalin === 'email' ? 'Tersalin' : 'Salin'}
                    </Button>
                </div>

                <div className="bg-background flex flex-wrap items-center justify-between gap-3 rounded-md border border-amber-200 px-4 py-3 dark:border-amber-900/60">
                    <div className="min-w-0">
                        <dt className="text-muted-foreground text-xs">
                            Kata sandi sementara
                        </dt>
                        <dd className="truncate font-mono text-lg font-semibold tracking-wide">
                            {kredensial.kataSandi}
                        </dd>
                    </div>
                    <Button
                        type="button"
                        size="sm"
                        onClick={() =>
                            void salin('sandi', kredensial.kataSandi)
                        }
                    >
                        {tersalin === 'sandi' ? 'Tersalin' : 'Salin kata sandi'}
                    </Button>
                </div>
            </dl>

            {gagalSalin && (
                <p className="mt-3 text-sm font-medium">
                    Peramban menolak menyalin — biasanya karena halaman ini
                    dibuka lewat alamat yang bukan HTTPS. Sorot dan salin
                    teksnya dengan tangan.
                </p>
            )}

            <p className="text-muted-foreground mt-3 text-xs">
                Tenant {kredensial.tenant}. Minta pelanggan menggantinya pada
                masuk pertama.
            </p>
        </section>
    );
}
