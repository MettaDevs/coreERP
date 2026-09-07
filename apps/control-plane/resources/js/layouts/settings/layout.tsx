import { Button } from '@apperp/ui/button';
import { Separator } from '@apperp/ui/separator';
import { Link, usePage } from '@inertiajs/react';
import type { PropsWithChildren } from 'react';
import Heading from '@/components/heading';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { cn, toUrl } from '@/lib/utils';
import { edit as editAppearance } from '@/routes/appearance';
import { edit } from '@/routes/profile';
import { edit as editSecurity } from '@/routes/security';
import type { NavItem } from '@/types';

const sidebarNavItems: NavItem[] = [
    {
        title: 'Persetujuan saya',
        href: '/workflow-inbox',
        icon: null,
    },
    {
        title: 'Organization',
        href: '/settings/organization',
        icon: null,
    },
    {
        title: 'Identity & access',
        href: '/settings/access',
        icon: null,
    },
    {
        title: 'Konfigurasi workflow',
        href: '/settings/workflows',
        icon: null,
    },
    {
        title: 'Layout laporan',
        href: '/settings/report-layouts',
        icon: null,
    },
    {
        title: 'Ekspor laporan',
        href: '/reports/exports',
        icon: null,
    },
    {
        title: 'Profile',
        href: edit(),
        icon: null,
    },
    {
        title: 'Security',
        href: editSecurity(),
        icon: null,
    },
    {
        title: 'Appearance',
        href: editAppearance(),
        icon: null,
    },
];

export default function SettingsLayout({ children }: PropsWithChildren) {
    const { isCurrentOrParentUrl } = useCurrentUrl();
    const { url } = usePage();
    const isWidePage =
        url.startsWith('/settings/access') ||
        url.startsWith('/settings/organization');

    return (
        <div className="px-4 py-6">
            <Heading
                title="Settings"
                description="Manage your profile and account settings"
            />

            <div className="flex flex-col gap-8 lg:flex-row lg:gap-12">
                <aside className="w-full max-w-xl lg:w-48">
                    <nav className="flex flex-col gap-1" aria-label="Settings">
                        {sidebarNavItems.map((item, index) => (
                            <Button
                                key={`${toUrl(item.href)}-${index}`}
                                size="sm"
                                variant="ghost"
                                asChild
                                className={cn('w-full justify-start', {
                                    'bg-muted': isCurrentOrParentUrl(item.href),
                                })}
                            >
                                <Link href={item.href}>
                                    {item.icon && (
                                        <item.icon className="h-4 w-4" />
                                    )}
                                    {item.title}
                                </Link>
                            </Button>
                        ))}
                    </nav>
                </aside>

                <Separator className="my-6 lg:hidden" />

                <div
                    className={cn(
                        'min-w-0 flex-1',
                        !isWidePage && 'md:max-w-2xl',
                    )}
                >
                    <section
                        className={cn(
                            'flex min-w-0 flex-col gap-12',
                            !isWidePage && 'max-w-xl',
                        )}
                    >
                        {children}
                    </section>
                </div>
            </div>
        </div>
    );
}
