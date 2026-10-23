import { Card, CardContent, CardHeader } from '@apperp/ui/card';
import { Link, usePage } from '@inertiajs/react';
import AppLogoIcon from '@/components/app-logo-icon';
import { cn } from '@/lib/utils';
import { home } from '@/routes';
import type { AuthLayoutProps } from '@/types';

export default function AuthSimpleLayout({
    children,
    title,
    description,
}: AuthLayoutProps) {
    const { component } = usePage();
    const isOnboarding = component === 'auth/register';

    return (
        <main className="flex min-h-svh items-center justify-center bg-background px-4 py-8 sm:px-6">
            <div
                className={cn(
                    'flex w-full flex-col gap-6',
                    isOnboarding ? 'max-w-3xl' : 'max-w-md',
                )}
            >
                <Link
                    href={home()}
                    aria-label="Halaman utama"
                    className="self-center rounded-xl bg-white p-3 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                >
                    <AppLogoIcon className="size-14" />
                </Link>
                <Card>
                    <CardHeader>
                        <div className="flex w-full flex-col gap-2 text-center">
                            <h1 className="text-xl font-semibold">{title}</h1>
                            {description && (
                                <p className="text-sm text-balance text-muted-foreground">
                                    {description}
                                </p>
                            )}
                        </div>
                    </CardHeader>
                    <CardContent>{children}</CardContent>
                </Card>
            </div>
        </main>
    );
}
