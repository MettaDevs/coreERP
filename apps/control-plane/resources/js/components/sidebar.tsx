import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarGroup,
    SidebarGroupContent,
    SidebarGroupLabel,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@apperp/ui/sidebar';
import { Link, usePage } from '@inertiajs/react';
import { Building2, Layers, RefreshCw, Server, Settings } from 'lucide-react';
import OperatorMenu from '@/components/operator-menu';
import { activeItem, navigation } from '@/lib/navigation';

const navigationIcons = {
    '/produk/tenant': Building2,
    '/tenant': Building2,
    '/lingkungan': Layers,
    '/pembaruan': RefreshCw,
    '/situs': Server,
    '/pengaturan': Settings,
};
function navigationIcon(href: string) {
    return Object.entries(navigationIcons).find(([path]) => path === href)?.[1];
}

export default function AppSidebar() {
    const { url, props } = usePage();
    const active = activeItem(url);

    return (
        <Sidebar
            collapsible="offcanvas"
            className="border-r border-sidebar-border"
        >
            <SidebarHeader className="gap-0 px-3 py-4">
                <Link href="/lingkungan" className="text-sm font-semibold">
                    Pusat Admin
                </Link>
                <span className="text-xs text-sidebar-foreground/70">
                    Konsol operator
                </span>
            </SidebarHeader>

            <SidebarContent>
                <SidebarGroup>
                    <SidebarGroupLabel>Menu</SidebarGroupLabel>
                    <SidebarGroupContent>
                        <SidebarMenu>
                            {navigation.map((item) => {
                                const Icon = navigationIcon(item.href);

                                return (
                                    <SidebarMenuItem key={item.href}>
                                        <SidebarMenuButton
                                            asChild
                                            isActive={
                                                active?.href === item.href
                                            }
                                            className="h-auto min-h-9 py-2 font-normal whitespace-normal data-[active=true]:font-normal"
                                        >
                                            <Link
                                                href={item.href}
                                                aria-current={
                                                    active?.href === item.href
                                                        ? 'page'
                                                        : undefined
                                                }
                                            >
                                                {Icon && (
                                                    <Icon aria-hidden="true" />
                                                )}
                                                <span className="break-words whitespace-normal">
                                                    {item.title}
                                                </span>
                                            </Link>
                                        </SidebarMenuButton>
                                    </SidebarMenuItem>
                                );
                            })}
                        </SidebarMenu>
                    </SidebarGroupContent>
                </SidebarGroup>
            </SidebarContent>

            {props.operator && (
                <SidebarFooter>
                    <OperatorMenu />
                </SidebarFooter>
            )}
        </Sidebar>
    );
}
