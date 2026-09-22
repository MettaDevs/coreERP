import { Sidebar } from '@apperp/ui/sidebar';
import { Tooltip, TooltipContent, TooltipTrigger } from '@apperp/ui/tooltip';
import { Link, usePage } from '@inertiajs/react';
import {
    Briefcase,
    Building2,
    CalendarDays,
    Clock,
    Coins,
    Contact,
    Database,
    FileOutput,
    FileText,
    KeyRound,
    LayoutDashboard,
    Hash,
    Map,
    MapPin,
    PhoneCall,
    Ruler,
    Package,
    Palette,
    ShieldCheck,
    UserRound,
    Users,
} from 'lucide-react';
import { useEffect, useLayoutEffect, useRef, useState } from 'react';

import { cn } from '@/lib/utils';

type NavigationItem = {
    label: string;
    icon?: typeof LayoutDashboard;
    href: string;
};
type PrimaryNavigationItem = NavigationItem & {
    icon: typeof LayoutDashboard;
    children: NavigationItem[];
};

function TruncatedLabel({ children }: { children: string }) {
    const label = useRef<HTMLSpanElement>(null);
    const [truncated, setTruncated] = useState(false);

    useLayoutEffect(() => {
        const update = () =>
            setTruncated(
                (label.current?.scrollWidth ?? 0) >
                    (label.current?.clientWidth ?? 0),
            );
        update();
        const observer = new ResizeObserver(update);

        if (label.current) {
            observer.observe(label.current);
        }

        return () => observer.disconnect();
    }, [children]);

    const content = (
        <span ref={label} className="truncate">
            {children}
        </span>
    );

    if (!truncated) {
        return content;
    }

    return (
        <Tooltip>
            <TooltipTrigger asChild>{content}</TooltipTrigger>
            <TooltipContent side="right">{children}</TooltipContent>
        </Tooltip>
    );
}

export function AppSidebar() {
    const { url, props } = usePage();
    const path = url.split('?')[0];
    const [realtimeSection, setRealtimeSection] = useState<string | null>(null);
    const [prevUrl, setPrevUrl] = useState(url);

    if (prevUrl !== url) {
        setPrevUrl(url);
        setRealtimeSection(null);
    }

    useEffect(() => {
        const handleSectionChange = (event: Event) => {
            const customEvent = event as CustomEvent<{ section: string }>;

            if (customEvent.detail?.section) {
                setRealtimeSection(customEvent.detail.section);
            }
        };

        window.addEventListener('coreerp:section-change', handleSectionChange);

        return () => {
            window.removeEventListener(
                'coreerp:section-change',
                handleSectionChange,
            );
        };
    }, []);

    const coreItems: PrimaryNavigationItem[] = [
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
                ...(props.auth.membership
                    ? [
                          {
                              label: 'Ekspor laporan',
                              icon: FileOutput,
                              href: '/reports/exports',
                          },
                      ]
                    : []),
            ],
        },
        {
            label: 'Setup Address',
            icon: Map,
            href: '/settings/address-setup',
            children: [
                {
                    label: 'Pengaturan',
                    icon: Map,
                    href: '/settings/address-setup',
                },
            ],
        },
        ...(props.auth.membership
            ? [
                  ...(props.auth.membership &&
                  ['owner', 'admin'].includes(props.auth.membership.system_role)
                      ? [
                            {
                                label: 'Kalender',
                                icon: CalendarDays,
                                href: '/settings/working-time-templates',
                                children: [
                                    {
                                        label: 'Pola jam kerja',
                                        icon: Clock,
                                        href: '/settings/working-time-templates',
                                    },
                                ],
                            },
                        ]
                      : []),
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
                      label: 'Buku alamat',
                      icon: Contact,
                      href: '/settings/global-address-book',
                      children: [
                          {
                              label: 'General',
                              icon: UserRound,
                              href: '/settings/global-address-book?section=general',
                          },
                          {
                              label: 'Addresses',
                              icon: MapPin,
                              href: '/settings/global-address-book?section=addresses',
                          },
                          {
                              label: 'Relationships',
                              icon: Users,
                              href: '/settings/global-address-book?section=relationships',
                          },
                          {
                              label: 'Contact Information',
                              icon: PhoneCall,
                              href: '/settings/global-address-book?section=contacts',
                          },
                          {
                              label: 'Roles',
                              icon: Briefcase,
                              href: '/settings/global-address-book?section=roles',
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
                              label: 'Undangan',
                              icon: Users,
                              href: '/settings/access?section=invitations',
                          },
                          {
                              label: 'Konfigurasi keamanan',
                              icon: ShieldCheck,
                              href: '/settings/security-configuration',
                          },
                          {
                              label: 'Workflow',
                              icon: ShieldCheck,
                              href: '/settings/workflows',
                          },
                      ],
                  },
                  ...(props.auth.membership &&
                  ['owner', 'admin'].includes(props.auth.membership.system_role)
                      ? [
                            {
                                label: 'Data referensi',
                                icon: Ruler,
                                href: '/settings/units-of-measure',
                                children: [
                                    {
                                        label: 'Satuan',
                                        icon: Ruler,
                                        href: '/settings/units-of-measure',
                                    },
                                    {
                                        label: 'Mata uang',
                                        icon: Coins,
                                        href: '/settings/currencies',
                                    },
                                    {
                                        label: 'Setup Address',
                                        icon: Map,
                                        href: '/settings/address-setup',
                                    },
                                    {
                                        label: 'Layout laporan',
                                        icon: FileText,
                                        href: '/settings/report-layouts',
                                    },
                                ],
                            },
                        ]
                      : []),
                  ...(props.auth.membership &&
                  ['owner', 'admin'].includes(props.auth.membership.system_role)
                      ? [
                            {
                                label: 'Nomor dokumen',
                                icon: Hash,
                                href: '/settings/number-sequences',
                                children: [
                                    {
                                        label: 'Atur nomor',
                                        icon: Hash,
                                        href: '/settings/number-sequences',
                                    },
                                ],
                            },
                        ]
                      : []),
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
    const hostedItems: PrimaryNavigationItem[] =
        props.app?.navigation.rails.map((rail) => ({
            label: rail.label,
            icon: Database,
            href: rail.href,
            children: rail.items.map((item) => ({
                label: item.label,
                href: item.href,
            })),
        })) ?? [];
    const isHostedApp = props.app !== undefined;
    const primaryItems = isHostedApp ? hostedItems : coreItems;
    const selectedItem =
        primaryItems.find((item) =>
            item.children.some((child) =>
                isHostedApp
                    ? url === child.href
                    : path === child.href.split('?')[0],
            ),
        ) ?? primaryItems[0];
    const isChildActive = (item: NavigationItem) => {
        if (realtimeSection && path === '/settings/global-address-book') {
            return (
                item.href ===
                `/settings/global-address-book?section=${realtimeSection}`
            );
        }

        return (
            url === item.href ||
            (item.href === '/settings/global-address-book?section=general' &&
                path === '/settings/global-address-book' &&
                !url.includes('?section=')) ||
            (item.href === '/settings/access?section=members' &&
                path === '/settings/access' &&
                !url.includes('?section='))
        );
    };

    return (
        <Sidebar
            collapsible="offcanvas"
            className="border-r border-sidebar-border bg-sidebar"
            style={{ '--sidebar-width': '16rem' } as React.CSSProperties}
        >
            <div className="flex h-full w-full">
                <aside className="flex w-14 shrink-0 flex-col items-center border-r border-sidebar-border px-1 py-1">
                    <nav
                        aria-label="Menu utama"
                        className="flex w-full flex-col gap-1"
                    >
                        {primaryItems.map((item) => (
                            <Link
                                key={item.label}
                                href={item.href}
                                className={cn(
                                    'flex min-h-11 flex-col items-center justify-center gap-0.5 rounded-md px-1 py-1 text-center text-[10px] text-sidebar-foreground hover:bg-sidebar-accent hover:text-sidebar-accent-foreground [&_svg]:size-4',
                                    selectedItem?.label === item.label &&
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

                {selectedItem && (
                    <nav
                        className="min-w-0 flex-1"
                        aria-label={`${selectedItem.label} navigation`}
                    >
                        <div className="border-b border-sidebar-border px-1 py-1">
                            <p className="text-[10px] font-medium tracking-wide text-muted-foreground uppercase">
                                Menu
                            </p>
                            <h2 className="text-sm font-semibold">
                                {selectedItem.label}
                            </h2>
                        </div>
                        <div className="flex flex-col gap-1 px-1 py-1">
                            {selectedItem.children.map((item) => (
                                <Link
                                    key={item.label}
                                    href={item.href}
                                    className={cn(
                                        'flex h-7 items-center gap-1 rounded-md px-1 text-[10px] text-sidebar-foreground transition-colors hover:bg-sidebar-accent hover:text-sidebar-accent-foreground [&_svg]:size-3.5',
                                        isChildActive(item) &&
                                            'bg-sidebar-accent font-semibold text-sidebar-accent-foreground',
                                    )}
                                >
                                    {item.icon && <item.icon />}
                                    <TruncatedLabel>
                                        {item.label}
                                    </TruncatedLabel>
                                </Link>
                            ))}
                        </div>
                    </nav>
                )}
            </div>
        </Sidebar>
    );
}
