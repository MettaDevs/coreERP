import { useEffect, useState } from 'react';

import { AppContent } from '@/components/app-content';
import { AppShell } from '@/components/app-shell';
import { AppSidebar } from '@/components/app-sidebar';
import { AppSidebarHeader } from '@/components/app-sidebar-header';
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
                className="overflow-x-hidden bg-slate-100 dark:bg-slate-950 py-2 pr-2 pl-0 min-w-0"
            >
                <div className="flex min-h-[calc(100svh-1rem)] w-full flex-col rounded-2xl border border-slate-200/80 dark:border-slate-800/80 bg-white dark:bg-slate-900 shadow-sm overflow-hidden min-w-0">
                    <AppSidebarHeader isScrolled={isScrolled} />
                    <div className="min-w-0 flex-1 bg-transparent overflow-x-hidden">{children}</div>
                </div>
            </AppContent>
        </AppShell>
    );
}
