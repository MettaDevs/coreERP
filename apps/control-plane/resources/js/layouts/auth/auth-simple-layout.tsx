import { Link, usePage } from '@inertiajs/react';
import AppLogoIcon from '@/components/app-logo-icon';
import { home } from '@/routes';
import type { AuthLayoutProps } from '@/types';
import { cn } from '@/lib/utils';

export default function AuthSimpleLayout({
    children,
    title,
    description,
}: AuthLayoutProps) {
    const { component } = usePage();
    const isOnboarding = component === 'auth/register';

    return (
        <div className="flex min-h-svh flex-col items-center justify-center gap-6 bg-background p-6 md:p-10">
            <div
                className={cn(
                    'w-full',
                    isOnboarding ? 'max-w-3xl' : 'max-w-sm',
                )}
            >
                <div className="flex flex-col gap-8">
                    <div className="flex flex-col items-center gap-4">
                        <Link
                            href={home()}
                            className="flex flex-col items-center gap-2 font-medium"
                        >
                            <div className="mb-1 flex aspect-square size-10 items-center justify-center rounded-xl bg-white dark:bg-slate-800 p-2 shadow-sm border border-slate-200 dark:border-slate-700 overflow-hidden shrink-0">
                                <AppLogoIcon className="size-full" />
                            </div>
                            <span className="sr-only">{title}</span>
                        </Link>

                        <div className="flex flex-col gap-2 text-center">
                            <h1 className="text-xl font-medium">{title}</h1>
                            <p className="text-center text-sm text-muted-foreground">
                                {description}
                            </p>
                        </div>
                    </div>
                    {children}
                </div>
            </div>
        </div>
    );
}
