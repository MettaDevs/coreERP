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
import OperatorMenu from '@/components/operator-menu';
import { activeItem, navigation } from '@/lib/navigation';

/**
 * Sepadan dengan `app-sidebar.tsx` milik Core, tetapi satu kolom dan tanpa ikon.
 *
 * Core memakai rel ikon di kiri karena navigasinya bertingkat: belasan bagian, masing-masing
 * dengan anak-anaknya sendiri, dan module yang menyumbang lagi. Konsol ini punya dua butir yang
 * keduanya muat sebagai satu kata. Rel ikon untuk dua kata hanya menambah satu lapis yang harus
 * ditebak artinya, dan ikonnya pun harus datang dari dependensi yang belum diminta konsol ini.
 */
export default function AppSidebar() {
    const { url, props } = usePage();
    const active = activeItem(url);

    return (
        <Sidebar
            collapsible="offcanvas"
            className="border-sidebar-border border-r"
        >
            <SidebarHeader className="gap-0 px-3 py-4">
                <Link href="/lingkungan" className="text-sm font-semibold">
                    Pusat Admin
                </Link>
                <span className="text-sidebar-foreground/70 text-xs">
                    Konsol operator
                </span>
            </SidebarHeader>

            <SidebarContent>
                <SidebarGroup>
                    <SidebarGroupLabel>Menu</SidebarGroupLabel>
                    <SidebarGroupContent>
                        <SidebarMenu>
                            {navigation.map((item) => (
                                <SidebarMenuItem key={item.href}>
                                    <SidebarMenuButton
                                        asChild
                                        isActive={active?.href === item.href}
                                    >
                                        <Link href={item.href}>
                                            {item.title}
                                        </Link>
                                    </SidebarMenuButton>
                                </SidebarMenuItem>
                            ))}
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
