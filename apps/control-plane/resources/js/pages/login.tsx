import { FormErrorHeader } from '@/components/form-error-header';
import { useInertiaFeedback } from '@/hooks/use-inertia-feedback';
import { Button } from '@apperp/ui/button';
import { Input } from '@apperp/ui/input';
import { Head, useForm } from '@inertiajs/react';
import { Eye, EyeOff, LogIn } from 'lucide-react';
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
    const { wrap: wrapLocalForm0Request, summary: localForm0RequestSummary } =
        useInertiaFeedback();

    const [showPassword, setShowPassword] = useState(
        !ssoAvailable || passwordLoginOpen,
    );
    const [passwordVisible, setPasswordVisible] = useState(false);
    const { data, setData, post, processing, errors } = useForm({
        email: '',
        password: '',
    });

    function submit(e: FormEvent) {
        e.preventDefault();
        post('/login', wrapLocalForm0Request());
    }

    return (
        <main className="flex min-h-svh items-center justify-center bg-muted/30 px-4 py-8 text-foreground">
            <Head title="Masuk" />
            <div className="w-full max-w-md space-y-6 rounded-xl border bg-card p-6 shadow-sm sm:p-8">
                <div>
                    <LogIn
                        aria-hidden="true"
                        className="mb-4 size-6 text-primary"
                    />
                    <h1 className="text-xl font-semibold">Pusat Admin</h1>
                    <p className="mt-1 text-sm text-muted-foreground">
                        Masuk dengan akun operator Anda untuk mengelola tenant,
                        lingkungan, dan server klien.
                    </p>
                </div>

                {ssoError && (
                    <p
                        role="alert"
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
                        Gunakan akun darurat dengan kata sandi
                    </button>
                )}

                {showPassword && (
                    <form noValidate onSubmit={submit} className="space-y-5">
                        <>
                            {localForm0RequestSummary}
                            <FormErrorHeader errors={errors} />
                        </>

                        {ssoAvailable && (
                            <div className="flex items-center gap-3 text-xs text-muted-foreground">
                                <span className="h-px flex-1 bg-border" />
                                {passwordLoginOpen ? 'atau' : 'akun darurat'}
                                <span className="h-px flex-1 bg-border" />
                            </div>
                        )}

                        <div className="space-y-2">
                            <Input
                                id="email"
                                label="Email"
                                required
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
                            <Input
                                id="password"
                                label="Kata sandi"
                                required
                                type={passwordVisible ? 'text' : 'password'}
                                autoComplete="current-password"
                                value={data.password}
                                onChange={(e) =>
                                    setData('password', e.target.value)
                                }
                            />
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                aria-pressed={passwordVisible}
                                onClick={() =>
                                    setPasswordVisible((visible) => !visible)
                                }
                            >
                                {passwordVisible ? (
                                    <EyeOff aria-hidden="true" />
                                ) : (
                                    <Eye aria-hidden="true" />
                                )}
                                {passwordVisible
                                    ? 'Sembunyikan kata sandi'
                                    : 'Tampilkan kata sandi'}
                            </Button>
                            {errors.password && (
                                <p
                                    role="alert"
                                    className="text-sm text-destructive"
                                >
                                    {errors.password}
                                </p>
                            )}
                        </div>

                        <Button
                            type="submit"
                            variant={ssoAvailable ? 'outline' : 'default'}
                            className="w-full"
                            disabled={processing}
                            aria-busy={processing}
                        >
                            {processing
                                ? 'Sedang masuk…'
                                : 'Masuk dengan kata sandi'}
                        </Button>
                    </form>
                )}
            </div>
        </main>
    );
}
