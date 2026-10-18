import { Card, CardContent, CardHeader, CardTitle } from '@apperp/ui/card';
import { Link } from '@inertiajs/react';
import type { PropsWithChildren } from 'react';
import AppLogoIcon from '@/components/app-logo-icon';
import { home } from '@/routes';

export default function AuthCardLayout({
    children,
    title,
    description,
}: PropsWithChildren<{
    name?: string;
    title?: string;
    description?: string;
}>) {
    return (
        <div className="flex min-h-svh flex-col items-center justify-center gap-6 bg-muted p-6 md:p-10">
            <div className="flex w-full max-w-md flex-col gap-6">
                <Link
                    href={home()}
                    aria-label="Halaman utama"
                    className="flex items-center gap-2 self-center font-medium"
                >
                    <div className="flex items-center justify-center">
                        <AppLogoIcon className="size-12" />
                    </div>
                </Link>

                <div className="flex flex-col gap-6">
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-xl">{title}</CardTitle>
                            <p className="col-span-full text-sm text-muted-foreground">
                                {description}
                            </p>
                        </CardHeader>
                        <CardContent>{children}</CardContent>
                    </Card>
                </div>
            </div>
        </div>
    );
}
