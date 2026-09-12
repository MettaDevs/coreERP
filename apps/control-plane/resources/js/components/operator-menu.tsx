import { Avatar, AvatarFallback } from '@apperp/ui/avatar';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuRadioGroup,
    DropdownMenuRadioItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@apperp/ui/dropdown-menu';
import {
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    useSidebar,
} from '@apperp/ui/sidebar';
import { router, usePage } from '@inertiajs/react';
import { toTheme, useTheme } from '@/hooks/use-theme';

function initials(name: string): string {
    const words = name.trim().split(/\s+/u).filter(Boolean);

    if (words.length === 0) {
        return '?';
    }

    const first = words[0].charAt(0);
    const last = words.length > 1 ? words[words.length - 1].charAt(0) : '';

    return `${first}${last}`.toUpperCase();
}

/**
 * Kaki sidebar: siapa yang sedang masuk, pilihan tampilan, dan pintu keluar.
 *
 * Core menaruh pilihan tampilan di halaman Setelan dan hanya menautkannya dari menu ini. Konsol
 * operator tidak punya halaman setelan sama sekali — satu halaman yang isinya satu pilihan adalah
 * halaman yang tidak layak dibuat — jadi pilihannya berdiri langsung di dalam menu.
 */
export default function OperatorMenu() {
    const { operator } = usePage().props;
    const { isMobile, state } = useSidebar();
    const { theme, setTheme } = useTheme();

    if (!operator) {
        return null;
    }

    return (
        <SidebarMenu>
            <SidebarMenuItem>
                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                        <SidebarMenuButton
                            size="lg"
                            className="data-[state=open]:bg-sidebar-accent"
                        >
                            <Avatar className="size-8 rounded-full">
                                <AvatarFallback className="rounded-full text-xs">
                                    {initials(operator.name)}
                                </AvatarFallback>
                            </Avatar>
                            <span className="grid flex-1 text-left leading-tight">
                                <span className="truncate text-sm font-medium">
                                    {operator.name}
                                </span>
                                <span className="truncate text-xs text-muted-foreground">
                                    {operator.email}
                                </span>
                            </span>
                        </SidebarMenuButton>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent
                        className="w-(--radix-dropdown-menu-trigger-width) min-w-56 rounded-lg"
                        align="end"
                        side={
                            isMobile
                                ? 'bottom'
                                : state === 'collapsed'
                                  ? 'right'
                                  : 'top'
                        }
                    >
                        <DropdownMenuLabel className="font-normal">
                            <span className="block truncate text-sm font-medium">
                                {operator.name}
                            </span>
                            <span className="block truncate text-xs text-muted-foreground">
                                {operator.email}
                            </span>
                        </DropdownMenuLabel>
                        <DropdownMenuSeparator />
                        <DropdownMenuLabel className="text-xs font-normal text-muted-foreground">
                            Tampilan
                        </DropdownMenuLabel>
                        <DropdownMenuRadioGroup
                            value={theme}
                            onValueChange={(value) => setTheme(toTheme(value))}
                        >
                            <DropdownMenuRadioItem value="light">
                                Terang
                            </DropdownMenuRadioItem>
                            <DropdownMenuRadioItem value="dark">
                                Gelap
                            </DropdownMenuRadioItem>
                            <DropdownMenuRadioItem value="system">
                                Ikut sistem
                            </DropdownMenuRadioItem>
                        </DropdownMenuRadioGroup>
                        <DropdownMenuSeparator />
                        <DropdownMenuItem
                            className="cursor-pointer"
                            onSelect={() => router.post('/logout')}
                        >
                            Keluar
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
            </SidebarMenuItem>
        </SidebarMenu>
    );
}
