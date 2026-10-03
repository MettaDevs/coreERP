import type { Auth, EntitledProduct } from '@/types/auth';
import type { HostedNavigation } from '@/types/navigation';

declare module 'react' {
    // eslint-disable-next-line @typescript-eslint/no-unused-vars
    interface InputHTMLAttributes<T> {
        passwordrules?: string;
    }
}

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            name: string;
            auth: Auth;
            entitledProducts: EntitledProduct[];
            launchableProducts: EntitledProduct[];
            app?: {
                id: string;
                name: string;
                navigation: HostedNavigation;
            };
            sidebarOpen: boolean;
            /** Saklar sementara engine analitik; hanya menyaring menu, rutenya sendiri menjawab 404 saat mati. */
            analyticsEnabled: boolean;
            clock: { timezone: string; today: string } | null;
            workDate: {
                value: string | null;
                notice_dismissed: boolean;
            } | null;
            [key: string]: unknown;
        };
    }
}
