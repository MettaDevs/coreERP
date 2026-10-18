import { Avatar, AvatarFallback, AvatarImage } from '@apperp/ui/avatar';
import { Button } from '@apperp/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuTrigger,
} from '@apperp/ui/dropdown-menu';
import { Kbd } from '@apperp/ui/kbd';
import { SidebarTrigger } from '@apperp/ui/sidebar';
import { Link, usePage } from '@inertiajs/react';
import { Command, Moon, Search, Sun } from 'lucide-react';
import { useEffect, useState } from 'react';
import { AppCommandPalette } from '@/components/app-command-palette';
import AppLogoIcon from '@/components/app-logo-icon';
import { ExportTray } from '@/components/export-tray';
import { NotificationBell } from '@/components/notification-bell';
import { PrintDialog } from '@/components/print-dialog';
import { UserMenuContent } from '@/components/user-menu-content';
import { WorkDateBadge } from '@/components/work-date-notice';
import { WorkspaceSwitcher } from '@/components/workspace-switcher';
import { useAppearance } from '@/hooks/use-appearance';
import { cn } from '@/lib/utils';
import { login } from '@/routes';

export function AppSidebarHeader({
    isScrolled = false,
}: {
    isScrolled?: boolean;
}) {
    const { auth } = usePage().props;
    const [commandOpen, setCommandOpen] = useState(false);
    const { resolvedAppearance, updateAppearance } = useAppearance();

    useEffect(() => {
        const onKeyDown = (event: KeyboardEvent) => {
            if ((event.metaKey || event.ctrlKey) && event.key === 'k') {
                event.preventDefault();
                setCommandOpen((open) => !open);
            }
        };

        window.addEventListener('keydown', onKeyDown);

        return () => window.removeEventListener('keydown', onKeyDown);
    }, []);

    return (
        <header
            className={cn(
                'sticky top-0 z-20 min-h-12 shrink-0 border-b bg-background transition-[border-radius,box-shadow] duration-200',
                isScrolled ? 'rounded-none shadow-sm' : 'rounded-t-2xl',
            )}
        >
            <div className="flex min-h-12 min-w-0 flex-wrap items-center gap-2 px-3 py-1.5 sm:gap-3 sm:px-4">
                <SidebarTrigger className="shrink-0 md:hidden" />
                <Button
                    variant="ghost"
                    size="icon-sm"
                    className="lg:hidden"
                    aria-label="Cari halaman atau aplikasi"
                    onClick={() => setCommandOpen(true)}
                >
                    <Search />
                </Button>
                <Link
                    href="/dashboard"
                    aria-label="Beranda"
                    className="flex size-8 shrink-0 items-center justify-center rounded-md bg-white"
                >
                    <AppLogoIcon className="size-7" />
                </Link>
                <WorkDateBadge />
                <div className="hidden h-6 w-px bg-border lg:block" />
                <Button
                    variant="outline"
                    size="sm"
                    aria-label="Cari halaman atau aplikasi"
                    aria-keyshortcuts="Control+K Meta+K"
                    className="hidden max-w-sm min-w-32 flex-1 justify-start font-normal text-muted-foreground lg:flex"
                    onClick={() => setCommandOpen(true)}
                >
                    <Search data-icon="inline-start" />
                    <span>Cari halaman…</span>
                    <span className="ml-auto hidden sm:block">
                        <Kbd>
                            <Command />K
                        </Kbd>
                    </span>
                </Button>

                <div className="ml-auto flex items-center gap-1">
                    <ExportTray />
                    <NotificationBell />
                    <Button
                        variant="ghost"
                        size="icon"
                        aria-label={
                            resolvedAppearance === 'dark'
                                ? 'Gunakan tema terang'
                                : 'Gunakan tema gelap'
                        }
                        title={
                            resolvedAppearance === 'dark'
                                ? 'Gunakan tema terang'
                                : 'Gunakan tema gelap'
                        }
                        onClick={() =>
                            updateAppearance(
                                resolvedAppearance === 'dark'
                                    ? 'light'
                                    : 'dark',
                            )
                        }
                    >
                        {resolvedAppearance === 'dark' ? <Sun /> : <Moon />}
                    </Button>
                    <WorkspaceSwitcher />
                    <div className="mx-2 hidden h-6 w-px bg-border sm:block" />
                    {auth.user ? (
                        <DropdownMenu>
                            <DropdownMenuTrigger asChild>
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    className="rounded-full"
                                    aria-label="Buka menu profil"
                                    data-test="header-profile-menu"
                                >
                                    <Avatar>
                                        <AvatarImage
                                            src={auth.user.avatar}
                                            alt={auth.user.name}
                                        />
                                        <AvatarFallback>
                                            {auth.user.name
                                                .slice(0, 2)
                                                .toUpperCase()}
                                        </AvatarFallback>
                                    </Avatar>
                                </Button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent
                                className="min-w-56 rounded-lg"
                                align="end"
                            >
                                <UserMenuContent user={auth.user} />
                            </DropdownMenuContent>
                        </DropdownMenu>
                    ) : (
                        <Button asChild size="sm">
                            <Link href={login()}>Masuk</Link>
                        </Button>
                    )}
                </div>
            </div>
            <AppCommandPalette
                open={commandOpen}
                onOpenChange={setCommandOpen}
            />
            <PrintDialog />
        </header>
    );
}
