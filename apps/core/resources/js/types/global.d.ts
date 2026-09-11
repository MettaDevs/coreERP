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
            [key: string]: unknown;
        };
    }
}
