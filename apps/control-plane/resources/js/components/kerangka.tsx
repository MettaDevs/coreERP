import { SidebarInset } from '@apperp/ui/sidebar';
import { usePage } from '@inertiajs/react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import BilahSisi from '@/components/bilah-sisi';
import Cangkang from '@/components/cangkang';
import Kepala from '@/components/kepala';

/**
 * Bingkai setiap layar operator, bentuknya mengikuti `app-sidebar-layout.tsx` milik Core: sidebar
 * di kiri, isi halaman sebagai kartu membulat di dalam bidang yang lebih gelap.
 *
 * Satu hal dari Core sengaja tidak ikut: `min-w-[80rem]` pada kartunya. Lebar paksa itu ada untuk
 * tabel module yang berkolom belasan, dan konsol ini tidak punya satu pun — membawanya serta hanya
 * memaksa gulir mendatar pada layar yang sebenarnya cukup.
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
    const { pesan } = usePage().props;
    const [tergulir, setTergulir] = useState(false);

    return (
        <Cangkang>
            <BilahSisi />
            <SidebarInset className="bg-muted/30 h-svh min-w-0 overflow-hidden py-2 pr-2 pl-0">
                {/*
                    Bayangan header menyala dari gulir **wadah ini**, bukan dari `window.scrollY`.
                    Yang bergulir memang div ini — jendelanya tidak pernah bergerak sedikit pun,
                    karena tingginya dikunci `h-svh`.
                */}
                <div
                    className="min-h-0 min-w-0 flex-1 overflow-auto"
                    onScroll={(peristiwa) =>
                        setTergulir(peristiwa.currentTarget.scrollTop > 8)
                    }
                >
                    <div className="bg-background flex min-h-[calc(100svh-1rem)] w-full min-w-0 flex-col rounded-2xl border shadow-sm">
                        <Kepala judul={judul} tergulir={tergulir} />

                        <div className="bg-muted/30 min-w-0 flex-1 space-y-6 rounded-b-2xl px-6 py-6">
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
                                <div className="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900 dark:border-emerald-900/60 dark:bg-emerald-950/40 dark:text-emerald-100">
                                    {pesan}
                                </div>
                            )}

                            {children}
                        </div>
                    </div>
                </div>
            </SidebarInset>
        </Cangkang>
    );
}
