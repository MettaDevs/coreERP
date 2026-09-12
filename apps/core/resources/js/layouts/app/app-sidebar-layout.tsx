import { useEffect, useState } from 'react';

import { AppContent } from '@/components/app-content';
import { AppShell } from '@/components/app-shell';
import { AppSidebar } from '@/components/app-sidebar';
import { AppSidebarHeader } from '@/components/app-sidebar-header';
import EnvironmentBanner from '@/components/environment-banner';
import type { AppLayoutProps } from '@/types';

export default function AppSidebarLayout({ children }: AppLayoutProps) {
    const [isScrolled, setIsScrolled] = useState(false);

    useEffect(() => {
        const updateScrolled = () => setIsScrolled(window.scrollY > 8);

        updateScrolled();
        window.addEventListener('scroll', updateScrolled, { passive: true });

        return () => window.removeEventListener('scroll', updateScrolled);
    }, []);

    return (
        <AppShell variant="sidebar">
            <AppSidebar />
            <AppContent
                variant="sidebar"
                className="h-svh min-w-0 overflow-hidden bg-muted/30 py-2 pr-2 pl-0"
            >
                <div className="min-h-0 min-w-0 flex-1 overflow-auto">
                    <div className="flex min-h-[calc(100svh-1rem)] w-full min-w-[80rem] flex-col rounded-2xl border bg-background shadow-sm xl:min-w-0">
                        <AppSidebarHeader isScrolled={isScrolled} />
                        {/*
                            Di bawah header, di atas isi halaman — dan tidak dapat ditutup.
                            Spanduk yang bisa dibuang adalah spanduk yang dibuang orang pada hari
                            pertama, lalu tidak pernah terlihat lagi justru ketika ia dibutuhkan.
                        */}
                        <EnvironmentBanner />
                        <div className="min-w-0 flex-1 bg-muted/30">
                            {children}
                        </div>
                    </div>
                </div>
            </AppContent>
        </AppShell>
    );
}
