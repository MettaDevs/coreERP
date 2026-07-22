import { Link, usePage } from '@inertiajs/react';
import {
    Boxes,
    Building2,
    KeyRound,
    LayoutDashboard,
    Package,
    Palette,
    ShieldCheck,
    UserRound,
    Users,
} from 'lucide-react';

import { Button } from '@/components/ui/button';
import { Sidebar } from '@/components/ui/sidebar';
import { cn } from '@/lib/utils';

export function AppSidebar() {
    const { url, props } = usePage();
    const childItems = [
        {
            label: 'Dashboard',
            icon: LayoutDashboard,
            href: '/dashboard',
        },
        {
            label: 'Organization',
            icon: Building2,
            href: '/settings/organization',
        },
        {
            label: 'Identity & access',
            icon: KeyRound,
            href: '/settings/access',
        },
        {
            label: 'Profile',
            icon: UserRound,
            href: '/settings/profile',
        },
        {
            label: 'Security',
            icon: ShieldCheck,
            href: '/settings/security',
        },
        {
            label: 'Appearance',
            icon: Palette,
            href: '/settings/appearance',
        },
        ...(props.auth.provider_admin
            ? [
                  {
                      label: 'Identity monitor',
                      icon: Users,
                      href: '/control/identities',
                  },
                  {
                      label: 'Module catalog',
                      icon: Package,
                      href: '/control/modules',
                  },
              ]
            : []),
    ];

    return (
        <Sidebar
            collapsible="offcanvas"
            className="border-r border-sidebar-border bg-sidebar"
            style={{ '--sidebar-width': '20rem' } as React.CSSProperties}
        >
            <div className="flex h-full w-full">
                <aside className="flex w-16 shrink-0 flex-col items-center gap-3 border-r border-sidebar-border py-3">
                    <Link
                        href="/dashboard"
                        className="flex flex-col items-center gap-1 text-[10px] font-semibold text-sidebar-primary"
                    >
                        <span className="flex size-8 items-center justify-center rounded-md bg-sidebar-primary text-xs font-bold text-sidebar-primary-foreground">
                            CE
                        </span>
                        <span>Core</span>
                    </Link>
                    <nav aria-label="Modules" className="flex flex-col gap-1">
                        <Button
                            variant="ghost"
                            size="sm"
                            className="h-auto min-h-11 flex-col gap-0.5 bg-sidebar-accent px-1 py-1 text-[10px] text-sidebar-accent-foreground"
                            aria-label="Core"
                        >
                            <Boxes />
                            <span>Core</span>
                        </Button>
                    </nav>
                </aside>

                <nav className="min-w-0 flex-1" aria-label="CoreERP navigation">
                    <div className="border-b border-sidebar-border px-3 py-3">
                        <p className="text-[10px] font-medium tracking-wide text-muted-foreground uppercase">
                            Menu
                        </p>
                        <h2 className="text-base font-semibold">Core</h2>
                    </div>
                    <div className="flex flex-col gap-1 px-3 py-3">
                        {childItems.map((item) => (
                            <Link
                                key={item.label}
                                href={item.href}
                                className={cn(
                                    'flex h-9 items-center gap-3 rounded-md px-3 text-xs text-sidebar-foreground transition-colors hover:bg-sidebar-accent hover:text-sidebar-accent-foreground lg:h-10 lg:text-sm [&_svg]:size-4 lg:[&_svg]:size-5',
                                    url.startsWith(item.href) &&
                                        'bg-sidebar-accent font-semibold text-sidebar-accent-foreground',
                                )}
                            >
                                <item.icon />
                                <span className="truncate">{item.label}</span>
                            </Link>
                        ))}
                    </div>
                </nav>
            </div>
        </Sidebar>
    );
}
