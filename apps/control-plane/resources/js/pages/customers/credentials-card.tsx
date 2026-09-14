import { Button } from '@apperp/ui/button';
import { useState } from 'react';

export type Credentials = {
    tenant: string;
    name: string;
    email: string;
    password: string;
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
export default function CredentialsCard({
    credentials,
}: {
    credentials: Credentials;
}) {
    const [copied, setCopied] = useState<string | null>(null);
    const [copyFailed, setCopyFailed] = useState(false);

    async function copy(what: string, text: string) {
        // Clipboard API tidak ada pada origin yang bukan HTTPS maupun localhost, dan konsol ini
        // akan dibuka lewat alamat internal. Kegagalannya disebut apa adanya: operator tetap dapat
        // menyorot teksnya, asalkan ia tahu tombolnya memang tidak bekerja.
        try {
            await navigator.clipboard.writeText(text);
            setCopyFailed(false);
            setCopied(what);
            window.setTimeout(() => setCopied(null), 2500);
        } catch {
            setCopyFailed(true);
        }
    }

    return (
        <section className="rounded-lg border border-amber-300 bg-amber-50 p-5 text-amber-950 dark:border-amber-900/60 dark:bg-amber-950/40 dark:text-amber-50">
            <h2 className="text-base font-semibold">
                Akun admin untuk {credentials.name} sudah dibuat
            </h2>
            <p className="mt-1 text-sm">
                Sampaikan dua baris di bawah kepada admin tenant sekarang.{' '}
                <strong className="font-semibold">
                    Kata sandinya tidak akan muncul lagi
                </strong>{' '}
                — ia tidak disimpan di mana pun, dan menutup halaman ini
                menghilangkannya. Kalau telanjur hilang, tidak ada satu pun cara
                membacanya lagi dari konsol ini.
            </p>

            <dl className="mt-4 space-y-2">
                <div className="flex flex-wrap items-center justify-between gap-3 rounded-md border border-amber-200 bg-background px-4 py-3 dark:border-amber-900/60">
                    <div className="min-w-0">
                        <dt className="text-xs text-muted-foreground">
                            Email admin
                        </dt>
                        <dd className="truncate font-mono text-sm">
                            {credentials.email}
                        </dd>
                    </div>
                    <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        onClick={() => void copy('email', credentials.email)}
                    >
                        {copied === 'email' ? 'Tersalin' : 'Salin'}
                    </Button>
                </div>

                <div className="flex flex-wrap items-center justify-between gap-3 rounded-md border border-amber-200 bg-background px-4 py-3 dark:border-amber-900/60">
                    <div className="min-w-0">
                        <dt className="text-xs text-muted-foreground">
                            Kata sandi sementara
                        </dt>
                        <dd className="truncate font-mono text-lg font-semibold tracking-wide">
                            {credentials.password}
                        </dd>
                    </div>
                    <Button
                        type="button"
                        size="sm"
                        onClick={() =>
                            void copy('password', credentials.password)
                        }
                    >
                        {copied === 'password'
                            ? 'Tersalin'
                            : 'Salin kata sandi'}
                    </Button>
                </div>
            </dl>

            {copyFailed && (
                <p className="mt-3 text-sm font-medium">
                    Peramban menolak menyalin — biasanya karena halaman ini
                    dibuka lewat alamat yang bukan HTTPS. Sorot dan salin
                    teksnya dengan tangan.
                </p>
            )}

            <p className="mt-3 text-xs text-muted-foreground">
                Tenant {credentials.tenant}. Minta admin tenant menggantinya
                pada masuk pertama.
            </p>
        </section>
    );
}
