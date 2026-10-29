import { Button } from '@apperp/ui/button';
import { Copy, Check } from 'lucide-react';
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
        <section className="rounded-lg border border-warning/30 bg-card p-5 text-card-foreground">
            <h2 className="text-sm font-semibold">
                Akun admin untuk {credentials.name} sudah dibuat
            </h2>
            <p className="mt-2 text-sm text-muted-foreground">
                Salin email dan kata sandi sementara sebelum meninggalkan
                halaman ini.{' '}
                <strong className="font-medium text-warning">
                    Kata sandi hanya ditampilkan sekali.
                </strong>{' '}
                Sampaikan keduanya kepada admin tenant.
            </p>

            <dl className="mt-4 grid gap-3 lg:grid-cols-2">
                <div className="flex flex-wrap items-center justify-between gap-3 rounded-md border border-warning/30 bg-background px-4 py-3">
                    <div className="min-w-0">
                        <dt className="text-xs text-muted-foreground">
                            Email admin
                        </dt>
                        <dd className="font-mono text-sm break-all">
                            {credentials.email}
                        </dd>
                    </div>
                    <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        onClick={() => void copy('email', credentials.email)}
                    >
                        {copied === 'email' ? (
                            <Check className="size-4" />
                        ) : (
                            <Copy className="size-4" />
                        )}
                        {copied === 'email' ? 'Tersalin' : 'Salin email'}
                    </Button>
                </div>

                <div className="flex flex-wrap items-center justify-between gap-3 rounded-md border border-warning/30 bg-background px-4 py-3">
                    <div className="min-w-0">
                        <dt className="text-xs text-muted-foreground">
                            Kata sandi sementara
                        </dt>
                        <dd className="font-mono text-base font-semibold tracking-wide break-all">
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
                        {copied === 'password' ? (
                            <Check className="size-4" />
                        ) : (
                            <Copy className="size-4" />
                        )}
                        {copied === 'password'
                            ? 'Tersalin'
                            : 'Salin kata sandi'}
                    </Button>
                </div>
            </dl>

            {copyFailed && (
                <p className="mt-3 text-sm font-medium">
                    Teks belum tersalin. Sorot email atau kata sandinya, lalu
                    salin secara manual.
                </p>
            )}

            <p className="mt-3 text-xs text-muted-foreground">
                Tenant {credentials.tenant}. Minta admin tenant menggantinya
                pada masuk pertama.
            </p>
        </section>
    );
}
