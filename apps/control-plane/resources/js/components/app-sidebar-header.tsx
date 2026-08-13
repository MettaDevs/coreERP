import { Link, usePage } from '@inertiajs/react';
import { Bell, Command, Moon, Palette, Search, Sun } from 'lucide-react';
import { useEffect, useState } from 'react';
import { useAppearance } from '@/hooks/use-appearance';

import { AppCommandPalette } from '@/components/app-command-palette';
import { ProductLauncher } from '@/components/product-launcher';
import { NotificationDropdown } from '@/components/notification-dropdown';
import { Avatar, AvatarFallback, AvatarImage } from '@apperp/ui/avatar';
import { Button } from '@apperp/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuTrigger,
} from '@apperp/ui/dropdown-menu';
import { Kbd } from '@apperp/ui/kbd';
import { SidebarTrigger } from '@apperp/ui/sidebar';
import { UserMenuContent } from '@/components/user-menu-content';
import { WorkspaceSwitcher } from '@/components/workspace-switcher';
import { cn } from '@/lib/utils';
import { login } from '@/routes';

export function AppSidebarHeader({
    isScrolled = false,
}: {
    isScrolled?: boolean;
}) {
    const { auth } = usePage<any>().props;
    const { appearance, updateAppearance } = useAppearance();
    const [commandOpen, setCommandOpen] = useState(false);

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
                'sticky top-0 z-20 h-16 shrink-0 border-b bg-background transition-[border-radius,box-shadow] duration-200',
                isScrolled ? 'rounded-none shadow-sm' : 'rounded-t-2xl',
            )}
        >
            <div className="flex h-full items-center gap-4 px-5">
                <SidebarTrigger className="shrink-0" />
                <div className="hidden h-6 w-px bg-border sm:block" />
                <WorkspaceSwitcher />
                <div className="hidden h-6 w-px bg-border lg:block" />
                <Button
                    variant="outline"
                    className="hidden h-10 w-full max-w-sm justify-start px-3 font-normal text-muted-foreground shadow-xs lg:flex"
                    onClick={() => setCommandOpen(true)}
                >
                    <Search data-icon="inline-start" />
                    <span>Search...</span>
                    <span className="ml-auto hidden sm:block">
                        <Kbd>
                            <Command />K
                        </Kbd>
                    </span>
                </Button>

                <div className="ml-auto flex items-center gap-1">
                    <ProductLauncher />
                    <NotificationDropdown />
                    <Button
                        variant="ghost"
                        size="icon"
                        aria-label="Toggle theme"
                        onClick={() => updateAppearance(appearance === 'dark' ? 'light' : 'dark')}
                        title={`Mode saat ini: ${appearance === 'dark' ? 'Dark' : 'Light'}. Klik untuk beralih.`}
                    >
                        {appearance === 'dark' ? <Sun className="size-4" /> : <Moon className="size-4" />}
                    </Button>
                    <Button
                        asChild
                        variant="ghost"
                        size="icon"
                        aria-label="Theme presets"
                        title="Pengaturan Tampilan"
                    >
                        <Link href="/settings/appearance">
                            <Palette className="size-4" />
                        </Link>
                    </Button>
                    <div className="mx-2 hidden h-6 w-px bg-border sm:block" />
                    {auth.user ? (
                        <DropdownMenu>
                            <DropdownMenuTrigger asChild>
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    className="rounded-full"
                                    aria-label="Open profile menu"
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
                            <Link href={login()}>Sign in</Link>
                        </Button>
                    )}
                </div>
            </div>
            <AppCommandPalette
                open={commandOpen}
                onOpenChange={setCommandOpen}
            />
        </header>
    );
}
