import { Button } from '@apperp/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@apperp/ui/card';
import { Head, Link, usePage } from '@inertiajs/react';
import AppLogoIcon from '@/components/app-logo-icon';
import { dashboard, login } from '@/routes';

export default function Welcome() {
    const { auth, name } = usePage().props;

    return (
        <>
            <Head title="Selamat datang" />
            <main className="flex min-h-svh items-center justify-center bg-background p-6">
                <Card className="w-full max-w-md">
                    <CardHeader>
                        <AppLogoIcon className="mb-4 h-16 w-24" />
                        <CardTitle>{name}</CardTitle>
                        <CardDescription>
                            Masuk untuk melanjutkan pekerjaan Anda.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <Button asChild className="w-full">
                            <Link href={auth.user ? dashboard() : login()}>
                                {auth.user ? 'Buka beranda' : 'Masuk'}
                            </Link>
                        </Button>
                    </CardContent>
                </Card>
            </main>
        </>
    );
}
