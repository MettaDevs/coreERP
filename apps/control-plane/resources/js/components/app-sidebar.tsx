import { Link, usePage } from '@inertiajs/react';
import { useLayoutEffect, useRef, useState, useEffect } from 'react';
import {
    Building2,
    Database,
    KeyRound,
    LayoutDashboard,
    Hash,
    Ruler,
    Package,
    Palette,
    ShieldCheck,
    UserRound,
    Users,
    UserPlus,
    GitFork,
    ChevronRight,
    ChevronDown,
    CheckCircle2,
    Layers,
    PanelLeftClose,
    PanelLeftOpen,
} from 'lucide-react';

import { Sidebar } from '@apperp/ui/sidebar';
import { Tooltip, TooltipContent, TooltipTrigger } from '@apperp/ui/tooltip';
import { cn } from '@/lib/utils';
import AppLogoIcon from '@/components/app-logo-icon';

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
                          {
                              label: 'Operating Units',
                              icon: Building2,
                              href: '/settings/organization?section=units',
                          },
                          {
                              label: 'Hierarchy',
                              icon: Layers,
                              href: '/settings/organization?section=hierarchy',
                          },
                      ],
                  },
                  {
                      label: 'Identity & Access',
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
                              icon: UserPlus,
                              href: '/settings/access?section=invitations',
                          },
                          {
                              label: 'Konfigurasi Keamanan',
                              icon: ShieldCheck,
                              href: '/settings/security-configuration',
                          },
                          {
                              label: 'Workflow',
                              icon: GitFork,
                              href: '/settings/workflows',
                          },
                      ],
                  },
                  ...(props.auth.membership &&
                  ['owner', 'admin'].includes(props.auth.membership.system_role)
                      ? [
                            {
                                label: 'Data Referensi',
                                icon: Ruler,
                                href: '/settings/units-of-measure',
                                children: [
                                    {
                                        label: 'Satuan',
                                        icon: Ruler,
                                        href: '/settings/units-of-measure',
                                    },
                                ],
                            },
                        ]
                      : []),
                  ...(props.auth.membership &&
                  ['owner', 'admin'].includes(props.auth.membership.system_role)
                      ? [
                            {
                                label: 'Nomor Dokumen',
                                icon: Hash,
                                href: '/settings/number-sequences',
                                children: [
                                    {
                                        label: 'Atur Nomor',
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
                    label: 'Keamanan Akun',
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
                              label: 'Identity Monitor',
                              icon: Users,
                              href: '/control/identities',
                          },
                          {
                              label: 'Katalog Aplikasi',
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
        if (url === item.href) return true;
        const [itemPath, itemQuery] = item.href.split('?');
        if (path === itemPath) {
            if (!itemQuery) return !url.includes('?section=');
            return url.includes(itemQuery);
        }
        return false;
    };

    // Dynamic Sidebar Collapsed state from Appearance Settings & Context
    const [isCollapsed, setIsCollapsed] = useState<boolean>(() => {
        if (typeof window !== 'undefined') {
            return localStorage.getItem('sidebar_mode') === 'collapsed';
        }
        return false;
    });

    const toggleSidebarCollapse = () => {
        const nextMode = !isCollapsed ? 'collapsed' : 'expanded';
        setIsCollapsed(!isCollapsed);
        if (typeof window !== 'undefined') {
            localStorage.setItem('sidebar_mode', nextMode);
            window.dispatchEvent(new Event('sidebar_mode_change'));
        }
    };

    // Expandable accordion submenus (supports closing active items)
    const [openModules, setOpenModules] = useState<Record<string, boolean>>({});

    const toggleModule = (label: string) => {
        setOpenModules((prev) => {
            const currentlyOpen = prev[label] ?? (selectedItem?.label === label);
            return {
                ...prev,
                [label]: !currentlyOpen,
            };
        });
    };

    useEffect(() => {
        const handleSidebarModeChange = () => {
            setIsCollapsed(localStorage.getItem('sidebar_mode') === 'collapsed');
        };
        window.addEventListener('storage', handleSidebarModeChange);
        window.addEventListener('sidebar_mode_change', handleSidebarModeChange);
        return () => {
            window.removeEventListener('storage', handleSidebarModeChange);
            window.removeEventListener('sidebar_mode_change', handleSidebarModeChange);
        };
    }, []);

    return (
        <Sidebar
            collapsible="offcanvas"
            className="border-r border-[#DCE8F0] dark:border-slate-800 bg-[#F4F9FC] dark:bg-slate-950 shadow-xs transition-all duration-300"
            style={{ '--sidebar-width': isCollapsed ? '4.75rem' : '17.5rem' } as React.CSSProperties}
        >
            <div className="flex h-full w-full flex-col overflow-hidden bg-[#F4F9FC] dark:bg-slate-950 text-slate-800 dark:text-slate-100">
                {/* --- 1. BRANDING TOP HEADER WITH TOGGLE BUTTON --- */}
                <div className={cn("flex items-center justify-between border-b border-[#DCE8F0] dark:border-slate-800 p-3 bg-white/80 dark:bg-slate-900/80 shrink-0", isCollapsed && "flex-col gap-2 p-2.5")}>
                    {isCollapsed ? (
                        <>
                            <button
                                type="button"
                                onClick={toggleSidebarCollapse}
                                title="Buka Sidebar Navigation"
                                className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-white dark:bg-slate-800 p-1.5 shadow-xs border border-[#DCE8F0] dark:border-slate-700 hover:border-[#00AFC0] hover:bg-[#EAFBFC] transition-all cursor-pointer group"
                            >
                                <AppLogoIcon className="size-full group-hover:scale-110 transition-transform" />
                            </button>
                            <button
                                type="button"
                                onClick={toggleSidebarCollapse}
                                title="Buka Sidebar Navigation"
                                className="flex items-center justify-center p-1 rounded-lg text-[#00AFC0] hover:bg-[#EAFBFC] dark:hover:bg-slate-800 transition-all cursor-pointer"
                            >
                                <PanelLeftOpen className="size-4" />
                            </button>
                        </>
                    ) : (
                        <>
                            <Link href="/dashboard" className="flex items-center gap-3 min-w-0 group">
                                <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-white dark:bg-slate-800 p-1.5 shadow-xs border border-[#DCE8F0] dark:border-slate-700 group-hover:scale-105 transition-transform">
                                    <AppLogoIcon className="size-full" />
                                </div>

                                <div className="min-w-0 flex-1">
                                    <h1 className="text-xs font-black tracking-tight text-[#0B2040] dark:text-slate-100 truncate uppercase">
                                        PT SANATA SYSTEM
                                    </h1>
                                    <p className="text-[9px] font-extrabold tracking-wider text-[#00B8C8] uppercase truncate mt-0.5">
                                        IT SOLUTIONS &amp; ENTERPRISE SYSTEM
                                    </p>
                                </div>
                            </Link>

                            <button
                                type="button"
                                onClick={toggleSidebarCollapse}
                                title="Tutup Sidebar"
                                className="p-1.5 rounded-lg text-[#7890A8] hover:bg-slate-100 dark:hover:bg-slate-800 hover:text-[#00AFC0] transition-colors focus:outline-none focus:ring-0 focus-visible:outline-none outline-none shrink-0 cursor-pointer"
                            >
                                <PanelLeftClose className="size-4" />
                            </button>
                        </>
                    )}
                </div>

                {/* --- 2. MIDDLE SCROLLABLE MENU AREA WITH CUSTOM SUBTLE SCROLLBAR --- */}
                <div className="flex-1 overflow-y-auto px-3 py-3.5 space-y-3 select-none [scrollbar-width:thin] [::-webkit-scrollbar]:w-1.5 [::-webkit-scrollbar-thumb]:bg-slate-300 dark:[::-webkit-scrollbar-thumb]:bg-slate-700 [::-webkit-scrollbar-track]:bg-transparent">
                    <div className="px-1 flex items-center justify-between">
                        <div>
                            <p className="text-[10px] font-bold uppercase tracking-wider text-[#00B8C8] dark:text-cyan-400">
                                MENU
                            </p>
                            {!isCollapsed && (
                                <h2 className="text-sm font-black text-[#0B2040] dark:text-slate-100 mt-0.5">
                                    {selectedItem?.label || 'Dashboard'}
                                </h2>
                            )}
                        </div>
                    </div>

                    <nav aria-label="Navigasi Core ERP" className="space-y-1">
                        {primaryItems.map((module) => {
                            const isModuleSelected = selectedItem?.label === module.label;
                            const isOpen = openModules[module.label] ?? isModuleSelected;
                            const hasChildren = module.children && module.children.length > 0;

                            if (isCollapsed) {
                                return (
                                    <Tooltip key={module.label}>
                                        <TooltipTrigger asChild>
                                            <Link
                                                href={module.href}
                                                className={cn(
                                                    'group relative flex h-11 w-full items-center justify-center rounded-xl transition-all duration-150 cursor-pointer overflow-hidden focus:outline-none focus:ring-0 focus-visible:outline-none outline-none',
                                                    isModuleSelected
                                                        ? 'bg-[#E6F9FC] dark:bg-cyan-950/60 text-[#0097A7] dark:text-cyan-300 font-extrabold border border-[#B8EEF2] dark:border-cyan-900/60 shadow-xs'
                                                        : 'text-[#7890A8] dark:text-slate-400 hover:bg-[#F0FAFC] dark:hover:bg-slate-800/60 hover:text-[#008FA0]',
                                                )}
                                            >
                                                {isModuleSelected && (
                                                    <span className="absolute left-0 top-1/2 -translate-y-1/2 h-5 w-1 rounded-r-full bg-gradient-to-b from-[#1677FF] to-[#00B8C8]" />
                                                )}
                                                <module.icon className={cn('size-5', isModuleSelected ? 'text-[#00AFC0]' : 'text-[#7890A8]')} />
                                            </Link>
                                        </TooltipTrigger>
                                        <TooltipContent side="right" className="font-semibold text-xs">
                                            {module.label}
                                        </TooltipContent>
                                    </Tooltip>
                                );
                            }

                            return (
                                <div key={module.label} className="space-y-1">
                                    <div
                                        className={cn(
                                            'group relative flex w-full items-center justify-between gap-2 rounded-xl px-3 py-2 text-xs font-bold transition-all duration-150 overflow-hidden focus:outline-none focus:ring-0 outline-none select-none border border-transparent',
                                            isModuleSelected
                                                ? 'bg-[#E6F9FC] dark:bg-cyan-950/60 text-[#0097A7] dark:text-cyan-300 border-[#B8EEF2] dark:border-cyan-900/60 shadow-xs'
                                                : 'text-[#334155] dark:text-slate-300 hover:bg-[#F0FAFC] dark:hover:bg-slate-800/60 hover:text-[#008FA0]',
                                        )}
                                    >
                                        {isModuleSelected && (
                                            <span className="absolute left-0 top-1/2 -translate-y-1/2 h-5 w-1 rounded-r-full bg-gradient-to-b from-[#1677FF] to-[#00B8C8]" />
                                        )}
                                        
                                        {/* CLICKABLE LINK THAT REDIRECTS TO MODULE ROUTE */}
                                        <Link
                                            href={module.href}
                                            className="flex items-center gap-3 min-w-0 flex-1 py-0.5 cursor-pointer focus:outline-none focus:ring-0 outline-none"
                                        >
                                            <module.icon className={cn('size-4 shrink-0 transition-colors', isModuleSelected ? 'text-[#00AFC0]' : 'text-[#7890A8] group-hover:text-[#00AFC0]')} />
                                            <span className="truncate">{module.label}</span>
                                        </Link>

                                        {/* ACCORDION TOGGLE CHEVRON (ALLOWS CLOSING ACTIVE SUBMENU) */}
                                        {hasChildren && (
                                            <button
                                                type="button"
                                                onClick={(e) => {
                                                    e.preventDefault();
                                                    e.stopPropagation();
                                                    toggleModule(module.label);
                                                }}
                                                title={isOpen ? 'Tutup Submenu' : 'Buka Submenu'}
                                                className="p-1 rounded-lg text-slate-400 hover:text-[#00AFC0] hover:bg-slate-200/50 dark:hover:bg-slate-800 shrink-0 transition-all focus:outline-none focus:ring-0 outline-none cursor-pointer"
                                            >
                                                {isOpen ? <ChevronDown className="size-3.5" /> : <ChevronRight className="size-3.5" />}
                                            </button>
                                        )}
                                    </div>

                                    {/* SUBMENU ITEMS ACCORDION */}
                                    {isOpen && hasChildren && (
                                        <div className="ml-4 pl-2.5 border-l-2 border-[#DCE8F0] dark:border-slate-800 space-y-1 pt-0.5 animate-in fade-in slide-in-from-top-1 duration-150">
                                            {module.children.map((child) => {
                                                const active = isChildActive(child);
                                                return (
                                                    <Link
                                                        key={child.label}
                                                        href={child.href}
                                                        className={cn(
                                                            'flex h-8 items-center gap-2 rounded-lg px-2.5 text-[11px] font-semibold transition-all duration-150 cursor-pointer focus:outline-none focus:ring-0 focus-visible:outline-none outline-none',
                                                            active
                                                                ? 'bg-[#E6F9FC] dark:bg-cyan-950/80 text-[#0097A7] dark:text-cyan-300 font-extrabold border border-[#B8EEF2]/80 dark:border-cyan-900/60'
                                                                : 'text-slate-600 dark:text-slate-400 hover:bg-[#F0FAFC] dark:hover:bg-slate-800/40 hover:text-[#008FA0]',
                                                        )}
                                                    >
                                                        {child.icon ? (
                                                            <child.icon className={cn('size-3.5 shrink-0', active ? 'text-[#00AFC0]' : 'text-[#7890A8]')} />
                                                        ) : (
                                                            <span className={cn('size-1.5 rounded-full shrink-0', active ? 'bg-[#00AFC0]' : 'bg-slate-300 dark:bg-slate-600')} />
                                                        )}
                                                        <TruncatedLabel>{child.label}</TruncatedLabel>
                                                    </Link>
                                                );
                                            })}
                                        </div>
                                    )}
                                </div>
                            );
                        })}
                    </nav>
                </div>

                {/* --- 3. FOOTER SIDEBAR (SYSTEM READY) --- */}
                <div className="border-t border-[#DCE8F0] dark:border-slate-800 p-3 bg-white/70 dark:bg-slate-900/70 shrink-0">
                    <div className={cn('flex items-center gap-2.5 rounded-xl border border-[#DCE8F0] dark:border-slate-800 bg-white dark:bg-slate-900 p-2.5 shadow-xs', isCollapsed && 'justify-center p-2')}>
                        <div className="relative flex h-7 w-7 shrink-0 items-center justify-center rounded-lg bg-emerald-50 dark:bg-emerald-950/60 text-emerald-600 dark:text-emerald-400 border border-emerald-200/80 dark:border-emerald-800">
                            <CheckCircle2 className="size-4" />
                            <span className="absolute -top-0.5 -right-0.5 size-2 rounded-full bg-emerald-500 ring-2 ring-white animate-pulse" />
                        </div>

                        {!isCollapsed && (
                            <div className="min-w-0 flex-1">
                                <p className="text-xs font-bold text-[#0B2040] dark:text-slate-100 flex items-center gap-1">
                                    System Ready
                                </p>
                                <p className="text-[10px] text-slate-500 dark:text-slate-400 truncate mt-0.5">
                                    Semua sistem berjalan normal
                                </p>
                            </div>
                        )}
                    </div>
                </div>
            </div>
        </Sidebar>
    );
}
