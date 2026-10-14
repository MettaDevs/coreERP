import { Button } from '@apperp/ui/button';
import { Sidebar, useSidebar } from '@apperp/ui/sidebar';
import { Tooltip, TooltipContent, TooltipTrigger } from '@apperp/ui/tooltip';
import { Link, usePage } from '@inertiajs/react';
import { ChevronsLeft, ChevronsRight } from 'lucide-react';
import { useEffect, useLayoutEffect, useRef, useState } from 'react';

import { ProductLauncher } from '@/components/product-launcher';
import { useShellNavigation } from '@/hooks/use-shell-navigation';
import type { NavigationItem } from '@/hooks/use-shell-navigation';
import { cn } from '@/lib/utils';

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
    const { open, setOpen, isMobile, setOpenMobile } = useSidebar();
    const { url, props } = usePage();
    const [previewLabel, setPreviewLabel] = useState<string | null>(null);
    const path = url.split('?')[0];
    const [realtimeSection, setRealtimeSection] = useState<string | null>(null);
    const [prevUrl, setPrevUrl] = useState(url);

    if (prevUrl !== url) {
        setPrevUrl(url);
        setRealtimeSection(null);
        setPreviewLabel(null);
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

    const { primaryItems } = useShellNavigation();
    const isHostedApp = props.app !== undefined;
    const selectedItem =
        primaryItems.find((item) =>
            item.children.some((child) =>
                isHostedApp
                    ? path === child.href || path.startsWith(`${child.href}/`)
                    : path === child.href.split('?')[0],
            ),
        ) ?? primaryItems[0];
    const isChildActive = (item: NavigationItem) => {
        if (
            path === '/settings/global-address-book' &&
            item.href.startsWith('/settings/global-address-book?')
        ) {
            const currentSection =
                new URLSearchParams(url.split('?')[1] ?? '').get('section') ??
                'general';

            return (
                new URLSearchParams(item.href.split('?')[1] ?? '').get(
                    'section',
                ) === currentSection
            );
        }

        if (realtimeSection && path === '/settings/global-address-book') {
            return (
                item.href ===
                `/settings/global-address-book?section=${realtimeSection}`
            );
        }

        return (
            url === item.href ||
            (isHostedApp && path.startsWith(`${item.href}/`)) ||
            (item.href === '/settings/global-address-book?section=general' &&
                path === '/settings/global-address-book' &&
                !url.includes('?section=')) ||
            (item.href === '/settings/access?section=members' &&
                path === '/settings/access' &&
                !url.includes('?section='))
        );
    };
    const displayedItem =
        primaryItems.find((item) => item.label === previewLabel) ??
        selectedItem;
    const showChildren = isMobile || open || previewLabel !== null;

    return (
        <Sidebar
            collapsible="icon"
            className="z-30 border-r border-sidebar-border bg-sidebar"
            onMouseLeave={() => setPreviewLabel(null)}
            onBlur={(event) => {
                if (!event.currentTarget.contains(event.relatedTarget)) {
                    setPreviewLabel(null);
                }
            }}
            onKeyDown={(event) => {
                if (
                    event.key === 'Escape' &&
                    event.currentTarget.contains(event.target as Node)
                ) {
                    setPreviewLabel(null);

                    if (isMobile) {
                        setOpenMobile(false);
                    } else {
                        setOpen(false);
                    }
                }
            }}
        >
            <div className="relative flex h-full w-full">
                <aside className="flex h-full w-18 shrink-0 flex-col items-center border-r border-sidebar-border">
                    <div className="flex h-12 w-full shrink-0 items-center justify-center border-b border-sidebar-border">
                        <ProductLauncher />
                    </div>
                    <nav
                        aria-label="Menu utama"
                        className="flex min-h-0 w-full flex-1 flex-col gap-1 overflow-y-auto px-0.5 py-2"
                    >
                        {primaryItems.map((item) => (
                            <Link
                                key={item.label}
                                href={item.href}
                                onMouseEnter={() => setPreviewLabel(item.label)}
                                onFocus={() => setPreviewLabel(item.label)}
                                onClick={() => {
                                    if (isMobile) {
                                        setOpenMobile(false);
                                    }
                                }}
                                aria-current={
                                    selectedItem?.label === item.label
                                        ? 'page'
                                        : undefined
                                }
                                className={cn(
                                    'flex min-h-11 flex-col items-center justify-center gap-0.5 rounded-md py-1 text-center text-[10px] text-muted-foreground transition-colors hover:bg-sidebar-accent hover:text-sidebar-accent-foreground focus-visible:outline-2 focus-visible:outline-offset-[-2px] focus-visible:outline-ring [&_svg]:size-4',
                                    selectedItem?.label === item.label &&
                                        'font-semibold text-sidebar-foreground',
                                    previewLabel === item.label &&
                                        selectedItem?.label !== item.label &&
                                        'bg-sidebar-accent text-sidebar-accent-foreground',
                                )}
                            >
                                <item.icon
                                    className={cn(
                                        'box-content rounded-md border border-transparent p-1',
                                        selectedItem?.label === item.label &&
                                            'border-sidebar-primary bg-sidebar-primary text-sidebar-primary-foreground',
                                        previewLabel === item.label &&
                                            selectedItem?.label !==
                                                item.label &&
                                            'border-sidebar-accent-foreground/30',
                                    )}
                                />
                                <span className="w-full leading-tight break-words whitespace-normal">
                                    {item.label}
                                </span>
                            </Link>
                        ))}
                    </nav>
                    <div
                        className="w-full shrink-0 border-t border-sidebar-border px-1 py-2 text-center text-[10px] leading-tight text-muted-foreground"
                        aria-label={
                            props.productVersion
                                ? `Versi produk ${props.productVersion}`
                                : 'Versi produk belum tersedia'
                        }
                        title={
                            props.productVersion
                                ? `Versi produk ${props.productVersion}`
                                : 'Versi produk belum tersedia'
                        }
                    >
                        <span className="block">Versi</span>
                        <span className="block font-medium break-all">
                            {props.productVersion ?? '—'}
                        </span>
                    </div>
                </aside>

                {displayedItem && showChildren && (
                    <nav
                        className={cn(
                            'flex min-h-0 min-w-0 flex-1 flex-col bg-sidebar',
                            !isMobile &&
                                !open &&
                                'absolute inset-y-0 left-18 w-[calc(var(--sidebar-width)-var(--sidebar-width-icon))] border-r border-sidebar-border shadow-lg',
                        )}
                        aria-label={`Menu ${displayedItem.label}`}
                    >
                        <div className="flex h-12 shrink-0 items-center justify-between gap-2 border-b border-sidebar-border px-3">
                            <h2 className="min-w-0 truncate text-[13px] font-semibold">
                                {displayedItem.label}
                            </h2>
                            <Button
                                variant={isMobile || open ? 'ghost' : 'default'}
                                size="icon-xs"
                                aria-label={
                                    isMobile
                                        ? 'Tutup menu'
                                        : open
                                          ? 'Ciutkan menu'
                                          : 'Sematkan menu'
                                }
                                aria-expanded={isMobile || open}
                                onClick={() => {
                                    setPreviewLabel(
                                        open ? displayedItem.label : null,
                                    );

                                    if (isMobile) {
                                        setOpenMobile(false);
                                    } else {
                                        setOpen(!open);
                                    }
                                }}
                            >
                                {isMobile || open ? (
                                    <ChevronsLeft />
                                ) : (
                                    <ChevronsRight />
                                )}
                            </Button>
                        </div>
                        <div className="flex min-h-0 flex-1 flex-col gap-1 overflow-y-auto px-2 py-2">
                            {displayedItem.children.map((item) => (
                                <Link
                                    key={item.label}
                                    href={
                                        path ===
                                            '/settings/global-address-book' &&
                                        item.href.startsWith(
                                            '/settings/global-address-book?',
                                        ) &&
                                        new URLSearchParams(
                                            url.split('?')[1] ?? '',
                                        ).has('party')
                                            ? `${item.href}&party=${new URLSearchParams(url.split('?')[1] ?? '').get('party')}`
                                            : item.href
                                    }
                                    onClick={() => {
                                        setPreviewLabel(null);

                                        if (isMobile) {
                                            setOpenMobile(false);
                                        }
                                    }}
                                    aria-current={
                                        isChildActive(item) ? 'page' : undefined
                                    }
                                    className={cn(
                                        'flex min-h-8 shrink-0 items-center gap-2 rounded-md px-2 text-[13px] text-sidebar-foreground transition-colors hover:bg-sidebar-accent hover:text-sidebar-accent-foreground focus-visible:outline-2 focus-visible:outline-ring [&_svg]:size-3.5 [&_svg]:shrink-0',
                                        isChildActive(item) &&
                                            'bg-sidebar-primary/15 font-semibold text-sidebar-primary ring-1 ring-sidebar-primary/30 ring-inset hover:bg-sidebar-primary/20 hover:text-sidebar-primary',
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
