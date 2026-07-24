import { Link, usePage } from '@inertiajs/react';
import {
    Building2,
    KeyRound,
    LayoutDashboard,
    Package,
    Palette,
    ShieldCheck,
    UserRound,
    Users,
} from 'lucide-react';

import { Sidebar } from '@/components/ui/sidebar';
import { cn } from '@/lib/utils';

type NavigationItem = {
    label: string;
    icon: typeof LayoutDashboard;
    href: string;
};
type PrimaryNavigationItem = NavigationItem & {
    children: NavigationItem[];
};

export function AppSidebar() {
    const { url, props } = usePage();
    const path = url.split('?')[0];
    const primaryItems: PrimaryNavigationItem[] = [
        {
            label: 'Dashboard',
            icon: LayoutDashboard,
            href: '/dashboard',
            children: [
                {
                    label: 'Ringkasan',
                    icon: LayoutDashboard,
                    href: '/dashboard',
                },
            ],
        },
        ...(props.auth.membership
            ? [
                  {
                      label: 'Organization',
                      icon: Building2,
                      href: '/settings/organization',
                      children: [
                          {
                              label: 'Organisasi',
                              icon: Building2,
                              href: '/settings/organization',
                          },
                      ],
                  },
                  {
                      label: 'Identity & access',
                      icon: KeyRound,
                      href: '/settings/access?section=members',
                      children: [
                          {
                              label: 'Anggota',
                              icon: Users,
                              href: '/settings/access?section=members',
                          },
                          {
                              label: 'Role',
                              icon: KeyRound,
                              href: '/settings/access?section=roles',
                          },
                          {
                              label: 'Undangan',
                              icon: Users,
                              href: '/settings/access?section=invitations',
                          },
                      ],
                  },
              ]
            : []),
        {
            label: 'Profile',
            icon: UserRound,
            href: '/settings/profile',
            children: [
                {
                    label: 'Profil',
                    icon: UserRound,
                    href: '/settings/profile',
                },
            ],
        },
        {
            label: 'Security',
            icon: ShieldCheck,
            href: '/settings/security',
            children: [
                {
                    label: 'Keamanan akun',
                    icon: ShieldCheck,
                    href: '/settings/security',
                },
            ],
        },
        {
            label: 'Appearance',
            icon: Palette,
            href: '/settings/appearance',
            children: [
                {
                    label: 'Tampilan',
                    icon: Palette,
                    href: '/settings/appearance',
                },
            ],
        },
        ...(props.auth.provider_admin
            ? [
                  {
                      label: 'Operations',
                      icon: Package,
                      href: '/control/identities',
                      children: [
                          {
                              label: 'Identity monitor',
                              icon: Users,
                              href: '/control/identities',
                          },
                          {
                              label: 'Katalog aplikasi',
                              icon: Package,
                              href: '/control/apps',
                          },
                      ],
                  },
              ]
            : []),
    ];
    const selectedItem =
        primaryItems.find((item) =>
            item.children.some((child) => path === child.href.split('?')[0]),
        ) ?? primaryItems[0];
    const isChildActive = (item: NavigationItem) =>
        url === item.href ||
        (item.href === '/settings/access?section=members' &&
            path === '/settings/access' &&
            !url.includes('?section='));

    return (
        <Sidebar
            collapsible="offcanvas"
            className="border-r border-sidebar-border bg-sidebar"
            style={{ '--sidebar-width': '20rem' } as React.CSSProperties}
        >
            <div className="flex h-full w-full">
                <aside className="flex w-24 shrink-0 flex-col items-center gap-3 border-r border-sidebar-border py-3">
                    <Link
                        href="/dashboard"
                        className="flex size-8 items-center justify-center rounded-md bg-sidebar-primary text-xs font-bold text-sidebar-primary-foreground"
                        aria-label="Beranda"
                    >
                        CE
                    </Link>
                    <nav
                        aria-label="Menu utama"
                        className="flex flex-col gap-1"
                    >
                        {primaryItems.map((item) => (
                            <Link
                                key={item.label}
                                href={item.href}
                                className={cn(
                                    'flex min-h-14 flex-col items-center justify-center gap-0.5 rounded-md px-1 py-1 text-center text-[10px] text-sidebar-foreground hover:bg-sidebar-accent hover:text-sidebar-accent-foreground [&_svg]:size-4',
                                    selectedItem.label === item.label &&
                                        'bg-sidebar-accent font-semibold text-sidebar-accent-foreground',
                                )}
                            >
                                <item.icon />
                                <span className="w-full leading-tight break-words whitespace-normal">
                                    {item.label}
                                </span>
                            </Link>
                        ))}
                    </nav>
                </aside>

                <nav
                    className="min-w-0 flex-1"
                    aria-label={`${selectedItem.label} navigation`}
                >
                    <div className="border-b border-sidebar-border px-3 py-3">
                        <p className="text-[10px] font-medium tracking-wide text-muted-foreground uppercase">
                            Menu
                        </p>
                        <h2 className="text-base font-semibold">
                            {selectedItem.label}
                        </h2>
                    </div>
                    <div className="flex flex-col gap-1 px-3 py-3">
                        {selectedItem.children.map((item) => (
                            <Link
                                key={item.label}
                                href={item.href}
                                className={cn(
                                    'flex h-9 items-center gap-3 rounded-md px-3 text-xs text-sidebar-foreground transition-colors hover:bg-sidebar-accent hover:text-sidebar-accent-foreground lg:h-10 lg:text-sm [&_svg]:size-4 lg:[&_svg]:size-5',
                                    isChildActive(item) &&
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
