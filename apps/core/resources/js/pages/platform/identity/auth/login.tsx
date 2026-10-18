import { Button } from '@apperp/ui/button';
import { Checkbox } from '@apperp/ui/checkbox';
import { Input } from '@apperp/ui/input';
import { Label } from '@apperp/ui/label';
import { Spinner } from '@apperp/ui/spinner';
import { Form, Head } from '@inertiajs/react';
import { FieldError } from '@apperp/ui/field';
import PasswordInput from '@/components/password-input';
import TextLink from '@/components/text-link';
/* @chisel-registration */
import { register } from '@/routes';
/* @end-chisel-registration */
import { store } from '@/routes/login';
import { request } from '@/routes/password';

type Props = {
    status?: string;
    canResetPassword: boolean;
    /** Null di alamat yang tidak menawarkan SSO; tombolnya tidak tampil sama sekali. */
    ssoLoginUrl?: string | null;
    /** Kalimat untuk kode kegagalan yang dikenal server. Tidak pernah teks bebas dari alamat. */
    ssoError?: string | null;
};

export default function Login({
    status,
    canResetPassword,
    ssoLoginUrl,
    ssoError,
}: Props) {
    return (
        <>
            <Head title="Masuk" />

            {ssoError && (
                <div
                    role="alert"
                    className="mb-2 rounded-md border border-destructive/40 bg-destructive/5 px-3 py-2 text-sm text-destructive"
                >
                    {ssoError}
                </div>
            )}

            {ssoLoginUrl && (
                <div className="flex flex-col gap-3">
                    {/* Tautan biasa, bukan kunjungan Inertia: tujuannya alamat penyedia di domain lain. */}
                    <Button
                        asChild
                        variant="outline"
                        className="w-full"
                        data-test="sso-login-button"
                    >
                        <a href={ssoLoginUrl}>Masuk dengan SSO</a>
                    </Button>
                    <div className="text-center text-xs text-muted-foreground uppercase">
                        atau
                    </div>
                </div>
            )}

            <Form
                {...store.form()}
                resetOnSuccess={['password']}
                className="flex flex-col gap-6"
            >
                {({ processing, errors }) => (
                    <>
                        <div className="grid gap-6">
                            <div className="grid gap-2">
                                <Input
                                    id="email"
                                    label="Email"
                                    type="email"
                                    name="email"
                                    aria-invalid={Boolean(errors.email)}
                                    aria-describedby={
                                        errors.email ? 'email-error' : undefined
                                    }
                                    required
                                    autoFocus
                                    autoComplete="email"
                                />
                                <FieldError id="email-error">
                                    {errors.email}
                                </FieldError>
                            </div>

                            <div className="grid gap-2">
                                {canResetPassword && (
                                    <TextLink
                                        href={request()}
                                        className="justify-self-end text-sm"
                                    >
                                        Lupa kata sandi?
                                    </TextLink>
                                )}
                                <PasswordInput
                                    id="password"
                                    label="Kata sandi"
                                    name="password"
                                    aria-invalid={Boolean(errors.password)}
                                    aria-describedby={
                                        errors.password
                                            ? 'password-error'
                                            : undefined
                                    }
                                    required
                                    autoComplete="current-password"
                                />
                                <FieldError id="password-error">
                                    {errors.password}
                                </FieldError>
                            </div>

                            <div className="flex items-center space-x-3">
                                <Checkbox id="remember" name="remember" />
                                <Label htmlFor="remember">Tetap masuk</Label>
                            </div>

                            <Button
                                type="submit"
                                className="mt-4 w-full"
                                disabled={processing}
                                data-test="login-button"
                            >
                                {processing && <Spinner />}
                                Masuk
                            </Button>
                        </div>

                        {/* @chisel-registration */}
                        <div className="text-center text-sm text-muted-foreground">
                            Belum punya akun?{' '}
                            <TextLink href={register()}>Daftar</TextLink>
                        </div>
                        {/* @end-chisel-registration */}
                    </>
                )}
            </Form>

            {status && (
                <div role="status" className="mt-4 text-sm text-success">
                    {status}
                </div>
            )}
        </>
    );
}

Login.layout = {
    title: 'Masuk ke akun Anda',
    description: 'Gunakan email dan kata sandi akun Anda.',
};
