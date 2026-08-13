import { Link, usePage } from '@inertiajs/react';
import type { InertiaLinkProps } from '@inertiajs/react';
import type { PropsWithChildren } from 'react';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { cn, toUrl } from '@/lib/utils';
import { edit as editAppearance } from '@/routes/appearance';
import { edit } from '@/routes/profile';
import { edit as editSecurity } from '@/routes/security';
import {
    Settings,
    CheckSquare,
    Building2,
    KeyRound,
    Sliders,
    UserRound,
    ShieldCheck,
    Palette,
} from 'lucide-react';
import type { ComponentType } from 'react';

type NavItemWithIcon = {
    title: string;
    href: NonNullable<InertiaLinkProps['href']>;
    icon: ComponentType<{ className?: string }>;
};

const sidebarNavItems: NavItemWithIcon[] = [
    {
        title: 'Persetujuan saya',
        href: '/workflow-inbox',
        icon: CheckSquare,
    },
    {
        title: 'Organization',
        href: '/settings/organization',
        icon: Building2,
    },
    {
        title: 'Identity & access',
        href: '/settings/access',
        icon: KeyRound,
    },
    {
        title: 'Konfigurasi workflow',
        href: '/settings/workflows',
        icon: Sliders,
    },
    {
        title: 'Profile',
        href: edit(),
        icon: UserRound,
    },
    {
        title: 'Security',
        href: editSecurity(),
        icon: ShieldCheck,
    },
    {
        title: 'Appearance',
        href: editAppearance(),
        icon: Palette,
    },
];

export default function SettingsLayout({ children }: PropsWithChildren) {
    const { isCurrentOrParentUrl } = useCurrentUrl();
    const { url } = usePage();
    const isWidePage =
        url.startsWith('/settings/access') ||
        url.startsWith('/settings/organization');

    return (
        <div className="flex flex-col gap-6 px-4 py-6 md:px-8 max-w-7xl mx-auto">
            {/* Header Banner */}
            <div className="flex flex-col md:flex-row md:items-center justify-between gap-4 p-6 bg-white dark:bg-slate-900 rounded-2xl border border-slate-200/80 dark:border-slate-800 shadow-sm relative overflow-hidden">
                <div className="absolute top-0 right-0 w-64 h-64 bg-blue-500/10 rounded-full blur-3xl pointer-events-none -mr-20 -mt-20" />
                <div className="flex items-center gap-4 relative z-10">
                    <div className="flex size-12 items-center justify-center rounded-2xl bg-blue-600/10 dark:bg-blue-500/20 text-blue-600 dark:text-blue-400 border border-blue-200/50 dark:border-blue-800/50 shrink-0">
                        <Settings className="size-6" />
                    </div>
                    <div>
                        <h1 className="text-xl sm:text-2xl font-extrabold text-slate-900 dark:text-slate-100 tracking-tight">
                            Pengaturan &amp; Konfigurasi
                        </h1>
                        <p className="text-xs sm:text-sm text-slate-500 dark:text-slate-400">
                            Kelola profil pengguna, keamanan akun, hak akses, dan preferensi sistem Anda.
                        </p>
                    </div>
                </div>
            </div>

            {/* Layout Body */}
            <div className="flex flex-col gap-6 lg:flex-row lg:items-start">
                <aside className="w-full lg:w-64 shrink-0">
                    <div className="bg-white dark:bg-slate-900 p-3 rounded-2xl border border-slate-200/80 dark:border-slate-800 shadow-sm">
                        <div className="px-3 py-2 text-[11px] font-bold text-slate-400 uppercase tracking-wider">
                            Menu Navigasi
                        </div>
                        <nav className="flex flex-col gap-1 mt-1" aria-label="Settings">
                            {sidebarNavItems.map((item, index) => {
                                const active = isCurrentOrParentUrl(item.href);
                                const Icon = item.icon;
                                return (
                                    <Link
                                        key={`${toUrl(item.href)}-${index}`}
                                        href={item.href}
                                        className={cn(
                                            'flex items-center gap-3 px-3.5 py-2.5 rounded-xl text-xs font-medium transition-all duration-150',
                                            active
                                                ? 'bg-blue-600 text-white font-semibold shadow-md shadow-blue-500/25 border border-blue-500/30'
                                                : 'text-slate-600 dark:text-slate-300 hover:bg-blue-50/80 dark:hover:bg-blue-950/40 hover:text-blue-600 dark:hover:text-blue-400'
                                        )}
                                    >
                                        <Icon className={cn('size-4 shrink-0', active ? 'text-white' : 'text-slate-400 dark:text-slate-400 group-hover:text-blue-600')} />
                                        <span>{item.title}</span>
                                    </Link>
                                );
                            })}
                        </nav>
                    </div>
                </aside>

                <div className={cn('min-w-0 flex-1', !isWidePage && 'max-w-2xl')}>
                    <section className="flex min-w-0 flex-col gap-6">
                        {children}
                    </section>
                </div>
            </div>
        </div>
    );
}

