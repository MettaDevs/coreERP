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
import { keTema, useTema } from '@/hooks/tema';

function inisial(nama: string): string {
    const kata = nama.trim().split(/\s+/u).filter(Boolean);

    if (kata.length === 0) {
        return '?';
    }

    const depan = kata[0].charAt(0);
    const belakang = kata.length > 1 ? kata[kata.length - 1].charAt(0) : '';

    return `${depan}${belakang}`.toUpperCase();
}

/**
 * Kaki sidebar: siapa yang sedang masuk, pilihan tampilan, dan pintu keluar.
 *
 * Core menaruh pilihan tampilan di halaman Setelan dan hanya menautkannya dari menu ini. Konsol
 * operator tidak punya halaman setelan sama sekali — satu halaman yang isinya satu pilihan adalah
 * halaman yang tidak layak dibuat — jadi pilihannya berdiri langsung di dalam menu.
 */
export default function MenuOperator() {
    const { operator } = usePage().props;
    const { isMobile, state } = useSidebar();
    const { tema, setel } = useTema();

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
                                    {inisial(operator.nama)}
                                </AvatarFallback>
                            </Avatar>
                            <span className="grid flex-1 text-left leading-tight">
                                <span className="truncate text-sm font-medium">
                                    {operator.nama}
                                </span>
                                <span className="text-muted-foreground truncate text-xs">
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
                                {operator.nama}
                            </span>
                            <span className="text-muted-foreground block truncate text-xs">
                                {operator.email}
                            </span>
                        </DropdownMenuLabel>
                        <DropdownMenuSeparator />
                        <DropdownMenuLabel className="text-muted-foreground text-xs font-normal">
                            Tampilan
                        </DropdownMenuLabel>
                        <DropdownMenuRadioGroup
                            value={tema}
                            onValueChange={(nilai) => setel(keTema(nilai))}
                        >
                            <DropdownMenuRadioItem value="terang">
                                Terang
                            </DropdownMenuRadioItem>
                            <DropdownMenuRadioItem value="gelap">
                                Gelap
                            </DropdownMenuRadioItem>
                            <DropdownMenuRadioItem value="sistem">
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
