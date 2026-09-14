import { Button } from '@apperp/ui/button';
import { Input } from '@apperp/ui/input';
import { Label } from '@apperp/ui/label';
import { Head, useForm } from '@inertiajs/react';
import { useState } from 'react';
import type { FormEvent } from 'react';

type Props = {
    ssoAvailable: boolean;
    passwordLoginOpen: boolean;
    ssoError: string | null;
};

/**
 * Pintu masuk konsol: SSO lebih dulu, kata sandi di belakangnya.
 *
 * Saat pintu kata sandi ditutup (`CONSOLE_PASSWORD_LOGIN=false`), formulirnya tidak dibuang dari
 * halaman — hanya dilipat di balik satu tautan. Akun darurat tetap membutuhkannya pada hari
 * penyedia identitas mati, dan pada hari itu tidak ada waktu mencari alamat tersembunyi.
 */
export default function Login({
    ssoAvailable,
    passwordLoginOpen,
    ssoError,
}: Props) {
    const [showPassword, setShowPassword] = useState(
        !ssoAvailable || passwordLoginOpen,
    );
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
            <div className="w-full max-w-sm space-y-5 rounded-lg border bg-background p-8 shadow-sm">
                <div>
                    <h1 className="text-xl font-semibold">Pusat Admin</h1>
                    <p className="mt-1 text-sm text-muted-foreground">
                        Konsol operator. Masuk dengan akun Core Anda.
                    </p>
                </div>

                {ssoError && (
                    <p
                        className="rounded-md border border-destructive/40 bg-destructive/5 px-3 py-2 text-sm text-destructive"
                        data-test="sso-error"
                    >
                        {ssoError}
                    </p>
                )}

                {ssoAvailable && (
                    <Button asChild className="w-full">
                        {/* Tautan biasa, bukan Inertia: tujuannya domain penyedia. */}
                        <a href="/sso/masuk" data-test="sso-login-button">
                            Masuk dengan SSO
                        </a>
                    </Button>
                )}

                {ssoAvailable && !showPassword && (
                    <button
                        type="button"
                        className="w-full text-center text-xs text-muted-foreground underline-offset-4 hover:underline"
                        onClick={() => setShowPassword(true)}
                    >
                        Akun darurat
                    </button>
                )}

                {showPassword && (
                    <form onSubmit={submit} className="space-y-5">
                        {ssoAvailable && (
                            <div className="flex items-center gap-3 text-xs text-muted-foreground">
                                <span className="h-px flex-1 bg-border" />
                                {passwordLoginOpen ? 'atau' : 'akun darurat'}
                                <span className="h-px flex-1 bg-border" />
                            </div>
                        )}

                        <div className="space-y-2">
                            <Label htmlFor="email">Email</Label>
                            <Input
                                id="email"
                                type="email"
                                autoComplete="username"
                                value={data.email}
                                onChange={(e) =>
                                    setData('email', e.target.value)
                                }
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
                                onChange={(e) =>
                                    setData('password', e.target.value)
                                }
                            />
                        </div>

                        <Button
                            type="submit"
                            variant={ssoAvailable ? 'outline' : 'default'}
                            className="w-full"
                            disabled={processing}
                        >
                            Masuk dengan kata sandi
                        </Button>
                    </form>
                )}
            </div>
        </div>
    );
}
