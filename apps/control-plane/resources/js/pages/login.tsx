import { Button } from '@apperp/ui/button';
import { Input } from '@apperp/ui/input';
import { Label } from '@apperp/ui/label';
import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';

export default function Login() {
    const { data, setData, post, processing, errors } = useForm({
        email: '',
        password: '',
    });

    function submit(e: FormEvent) {
        e.preventDefault();
        post('/login');
    }

    return (
        <div className="flex min-h-screen items-center justify-center bg-muted/30 px-6 text-foreground">
            <Head title="Masuk" />
            <form
                onSubmit={submit}
                className="w-full max-w-sm space-y-5 rounded-lg border bg-background p-8 shadow-sm"
            >
                <div>
                    <h1 className="text-xl font-semibold">Pusat Admin</h1>
                    <p className="mt-1 text-sm text-muted-foreground">
                        Konsol operator. Masuk dengan akun Core Anda.
                    </p>
                </div>

                <div className="space-y-2">
                    <Label htmlFor="email">Email</Label>
                    <Input
                        id="email"
                        type="email"
                        autoComplete="username"
                        value={data.email}
                        onChange={(e) => setData('email', e.target.value)}
                    />
                    {errors.email && (
                        <p className="text-sm text-destructive">
                            {errors.email}
                        </p>
                    )}
                </div>

                <div className="space-y-2">
                    <Label htmlFor="password">Kata sandi</Label>
                    <Input
                        id="password"
                        type="password"
                        autoComplete="current-password"
                        value={data.password}
                        onChange={(e) => setData('password', e.target.value)}
                    />
                </div>

                <Button type="submit" className="w-full" disabled={processing}>
                    Masuk
                </Button>
            </form>
        </div>
    );
}
