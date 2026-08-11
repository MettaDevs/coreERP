import { Form, Head, router } from '@inertiajs/react';
import { Info, KeyRound, ShieldCheck } from 'lucide-react';
import { useRef } from 'react';
import SecurityController from '@/actions/App/Http/Controllers/Settings/SecurityController';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import PasskeyRegistration from '@/components/passkey-register';
import { Button } from '@apperp/ui/button';
import {
    Card,
    CardContent,
    CardHeader,
    CardTitle,
} from '@apperp/ui/card';
import { edit } from '@/routes/security';
import { enable } from '@/routes/two-factor';

/* @chisel-passkeys */
import type { Props as ManagePasskeysProps } from '@/components/manage-passkeys';
import ManagePasskeys from '@/components/manage-passkeys';
/* @end-chisel-passkeys */

/* @chisel-2fa */
import type { Props as ManageTwoFactorProps } from '@/components/manage-two-factor';
import ManageTwoFactor from '@/components/manage-two-factor';
/* @end-chisel-2fa */

type Props = {
    passwordRules?: string;
} /* @chisel-passkeys */ & ManagePasskeysProps /* @end-chisel-passkeys */ /* @chisel-2fa */ &
    ManageTwoFactorProps /* @end-chisel-2fa */;

export default function Security(props: Props) {
    const passwordInput = useRef<HTMLInputElement>(null);
    const currentPasswordInput = useRef<HTMLInputElement>(null);

    const twoFactorEnabled = props.twoFactorEnabled ?? false;
    const passkeys = props.passkeys ?? [];

    return (
        <>
            <Head title="Security Settings" />

            <main className="mx-auto flex w-full max-w-6xl flex-col gap-8 p-6">
                <div>
                    <h1 className="text-2xl font-semibold tracking-tight">Security Settings</h1>
                    <p className="mt-1 text-sm text-muted-foreground">
                        Kelola kata sandi akun dan opsi autentikasi tambahan Anda.
                    </p>
                </div>

                {/* ─────────────── TATA LETAK 2 KOLOM (BESAMPINGAN) ─────────────── */}
                <div className="grid gap-6 items-start lg:grid-cols-2">
                    
                    {/* KOLOM KIRI: UPDATE PASSWORD */}
                    <Card className="h-full">
                        <CardHeader>
                            <CardTitle>Update Password</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <Form
                                {...SecurityController.update.form()}
                                options={{
                                    preserveScroll: true,
                                }}
                                resetOnError={[
                                    'password',
                                    'password_confirmation',
                                    'current_password',
                                ]}
                                resetOnSuccess
                                onError={(errors) => {
                                    if (errors.password) {
                                        passwordInput.current?.focus();
                                    }

                                    if (errors.current_password) {
                                        currentPasswordInput.current?.focus();
                                    }
                                }}
                                className="space-y-4"
                            >
                                {({ errors, processing }) => (
                                    <>
                                        <div className="space-y-1.5">
                                            <label
                                                htmlFor="current_password"
                                                className="text-xs font-medium text-foreground"
                                            >
                                                Current Password
                                            </label>
                                            <PasswordInput
                                                id="current_password"
                                                ref={currentPasswordInput}
                                                name="current_password"
                                                autoComplete="current-password"
                                            />
                                            <InputError message={errors.current_password} />
                                        </div>

                                        <div className="space-y-1.5">
                                            <label
                                                htmlFor="password"
                                                className="text-xs font-medium text-foreground"
                                            >
                                                New Password
                                            </label>
                                            <PasswordInput
                                                id="password"
                                                ref={passwordInput}
                                                name="password"
                                                autoComplete="new-password"
                                                passwordrules={props.passwordRules}
                                            />
                                            <InputError message={errors.password} />
                                        </div>

                                        <div className="space-y-1.5">
                                            <label
                                                htmlFor="password_confirmation"
                                                className="text-xs font-medium text-foreground"
                                            >
                                                Confirm Password
                                            </label>
                                            <PasswordInput
                                                id="password_confirmation"
                                                name="password_confirmation"
                                                autoComplete="new-password"
                                                passwordrules={props.passwordRules}
                                            />
                                            <InputError message={errors.password_confirmation} />
                                        </div>

                                        {/* Password Tips Box */}
                                        <div className="rounded-lg border border-border/60 bg-muted/40 p-4 text-xs text-muted-foreground">
                                            <div className="flex items-start gap-2.5">
                                                <Info className="mt-0.5 size-4 shrink-0 text-muted-foreground" />
                                                <div className="space-y-1.5">
                                                    <p className="font-medium text-foreground">
                                                        Saran pembuatan kata sandi:
                                                    </p>
                                                    <ul className="list-inside list-disc space-y-1">
                                                        <li>Gunakan 12+ karakter</li>
                                                        <li>Kombinasi huruf besar &amp; kecil</li>
                                                        <li>Tambahkan angka &amp; simbol</li>
                                                    </ul>
                                                </div>
                                            </div>
                                        </div>

                                        <div className="pt-1">
                                            <Button
                                                type="submit"
                                                disabled={processing}
                                                data-test="update-password-button"
                                            >
                                                Save Password
                                            </Button>
                                        </div>
                                    </>
                                )}
                            </Form>
                        </CardContent>
                    </Card>

                    {/* KOLOM KANAN: TWO-FACTOR & PASSKEYS */}
                    <div className="flex flex-col gap-6">
                        {/* 2FA Card */}
                        <Card>
                            <CardHeader>
                                <CardTitle>Two-Factor Authentication</CardTitle>
                            </CardHeader>
                            <CardContent>
                                {twoFactorEnabled ? (
                                    <ManageTwoFactor
                                        canManageTwoFactor={props.canManageTwoFactor}
                                        requiresConfirmation={props.requiresConfirmation}
                                        twoFactorEnabled={props.twoFactorEnabled}
                                    />
                                ) : (
                                    <div className="flex flex-col items-start justify-between gap-4 sm:flex-row sm:items-center">
                                        <p className="text-sm leading-relaxed text-muted-foreground">
                                            Amankan akun Anda dengan verifikasi dua langkah menggunakan kode acak dari aplikasi autentikator (TOTP) saat masuk.
                                        </p>

                                        <div className="shrink-0">
                                            {/* @chisel-2fa */}
                                            <Form {...enable.form()}>
                                                {({ processing }) => (
                                                    <Button type="submit" disabled={processing}>
                                                        <ShieldCheck className="mr-1.5 size-4" />
                                                        Enable 2FA
                                                    </Button>
                                                )}
                                            </Form>
                                            {/* @end-chisel-2fa */}
                                        </div>
                                    </div>
                                )}
                            </CardContent>
                        </Card>

                        {/* Passkeys Card */}
                        <Card>
                            <CardHeader>
                                <CardTitle>Passkeys</CardTitle>
                            </CardHeader>
                            <CardContent>
                                {passkeys.length > 0 ? (
                                    <ManagePasskeys
                                        canManagePasskeys={props.canManagePasskeys}
                                        passkeys={props.passkeys}
                                    />
                                ) : (
                                    <div className="flex flex-col items-center justify-center rounded-xl border border-dashed border-border bg-muted/40 p-6 text-center">
                                        <div className="mb-3 flex size-12 items-center justify-center rounded-full bg-muted">
                                            <KeyRound className="size-6 text-muted-foreground" />
                                        </div>
                                        <p className="text-sm font-medium text-foreground">No passkeys yet</p>
                                        <p className="mt-1 mb-4 text-xs text-muted-foreground">
                                            Tambahkan passkey untuk masuk tanpa mengetik kata sandi
                                        </p>
                                        {/* @chisel-passkeys */}
                                        <PasskeyRegistration onSuccess={() => router.reload()} />
                                        {/* @end-chisel-passkeys */}
                                    </div>
                                )}
                            </CardContent>
                        </Card>
                    </div>

                </div>
            </main>
        </>
    );
}

Security.layout = {
    breadcrumbs: [
        {
            title: 'Security settings',
            href: edit(),
        },
    ],
};



