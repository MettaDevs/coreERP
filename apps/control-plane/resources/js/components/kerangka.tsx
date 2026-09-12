import { Button } from '@apperp/ui/button';
import { Link, router, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';

type Bersama = {
    operator: { nama: string; email: string } | null;
    pesan: string | null;
};

/**
 * Bingkai setiap layar operator.
 *
 * Sengaja satu berkas dan tanpa sidebar. Konsol ini punya tiga layar; navigasi yang dapat runtuh,
 * mengingat keadaannya, dan ikut diuji adalah ongkos yang belum dibeli apa pun.
 */
export default function Kerangka({
    judul,
    keterangan,
    aksi,
    children,
}: {
    judul: string;
    keterangan?: string;
    aksi?: ReactNode;
    children: ReactNode;
}) {
    const { operator, pesan } = usePage<Bersama>().props;

    return (
        <div className="text-foreground min-h-screen bg-[hsl(210_30%_96%)]">
            <header className="bg-background border-b">
                <div className="mx-auto flex max-w-6xl items-center justify-between gap-4 px-6 py-3">
                    <Link href="/lingkungan" className="text-sm font-semibold">
                        Pusat Admin
                    </Link>
                    {operator && (
                        <div className="text-muted-foreground flex items-center gap-3 text-sm">
                            <span>{operator.nama}</span>
                            <Button
                                size="sm"
                                variant="ghost"
                                onClick={() => router.post('/logout')}
                            >
                                Keluar
                            </Button>
                        </div>
                    )}
                </div>
            </header>

            <main className="mx-auto max-w-6xl space-y-6 px-6 py-8">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">
                            {judul}
                        </h1>
                        {keterangan && (
                            <p className="text-muted-foreground mt-1 max-w-2xl text-sm">
                                {keterangan}
                            </p>
                        )}
                    </div>
                    {aksi}
                </div>

                {pesan && (
                    <div className="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900">
                        {pesan}
                    </div>
                )}

                {children}
            </main>
        </div>
    );
}
