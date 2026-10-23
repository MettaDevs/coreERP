import AppShell from '@/components/app-shell';
import { FormErrorHeader } from '@/components/form-error-header';
import { RecordActionBar } from '@apperp/ui/record-action-bar';
import { SidebarInset } from '@apperp/ui/sidebar';
import { usePage } from '@inertiajs/react';
import { CircleCheck } from 'lucide-react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import Header from '@/components/header';
import AppSidebar from '@/components/sidebar';

/**
 * Bingkai setiap layar operator, bentuknya mengikuti `app-sidebar-layout.tsx` milik Core: sidebar
 * di kiri, isi halaman sebagai kartu membulat di dalam bidang yang lebih gelap.
 *
 * Satu hal dari Core sengaja tidak ikut: `min-w-[80rem]` pada kartunya. Lebar paksa itu ada untuk
 * tabel module yang berkolom belasan, dan konsol ini tidak punya satu pun — membawanya serta hanya
 * memaksa gulir mendatar pada layar yang sebenarnya cukup.
 */
export default function Shell({
    title,
    description,
    actions,
    children,
}: {
    title: string;
    description?: string;
    actions?: ReactNode;
    children: ReactNode;
}) {
    const { message, errors } = usePage().props;
    const [scrolled, setScrolled] = useState(false);

    return (
        <AppShell>
            <AppSidebar />
            <SidebarInset className="h-svh min-w-0 overflow-hidden bg-muted/30 py-2 pr-2 pl-0">
                <div className="flex h-full min-h-0 min-w-0 flex-col overflow-hidden rounded-2xl border bg-background shadow-sm">
                    <Header title={title} scrolled={scrolled} />
                    <div
                        data-shell-content=""
                        className="relative isolate min-h-0 min-w-0 flex-1 overflow-hidden"
                    >
                        <div
                            data-shell-page-content=""
                            scroll-region=""
                            className="relative isolate z-0 h-full min-w-0 overflow-auto"
                            onScroll={(event) =>
                                setScrolled(event.currentTarget.scrollTop > 8)
                            }
                        >
                            <RecordActionBar className="top-0 z-10 px-4 sm:px-6">
                                <h1 className="mr-3 min-w-0 text-sm font-semibold break-words">
                                    {title}
                                </h1>
                                {actions}
                            </RecordActionBar>
                            <main
                                id="main-content"
                                className="min-w-0 flex-1 space-y-5 rounded-b-2xl px-4 py-5 sm:px-6"
                            >
                                {description && (
                                    <p className="max-w-3xl text-sm leading-relaxed text-muted-foreground">
                                        {description}
                                    </p>
                                )}

                                {message && (
                                    <div className="rounded-md border border-success/30 bg-success/10 px-4 py-3 text-sm text-success">
                                        <span
                                            role="status"
                                            className="flex items-start gap-2"
                                        >
                                            <CircleCheck
                                                aria-hidden="true"
                                                className="mt-0.5 size-4 shrink-0"
                                            />
                                            {message}
                                        </span>
                                    </div>
                                )}

                                <FormErrorHeader
                                    message={Object.values(errors ?? {}).join(
                                        ' ',
                                    )}
                                    operation="Tindakan belum berhasil"
                                />
                                {children}
                            </main>
                        </div>
                        <div
                            data-shell-overlay-host=""
                            className="pointer-events-none absolute inset-0 z-10"
                        />
                    </div>
                </div>
            </SidebarInset>
        </AppShell>
    );
}
