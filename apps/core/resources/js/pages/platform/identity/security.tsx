import { useInertiaFeedback } from '@/hooks/use-inertia-feedback';
import { FormErrorHeader } from '@/components/form-error-header';
import { RecordActionBar } from '@apperp/ui/record-action-bar';
import { ActionButton } from '@apperp/ui/action-button';
import { Button } from '@apperp/ui/button';
import { Form, Head, router } from '@inertiajs/react';
import { useRef } from 'react';
import SecurityController from '@/actions/App/Platform/Identity/Http/Controllers/SecurityController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
/* @chisel-2fa */
import type { Props as ManageTwoFactorProps } from '@/components/manage-two-factor';
import ManageTwoFactor from '@/components/manage-two-factor';
import PasswordInput from '@/components/password-input';
import { edit } from '@/routes/security';
/* @end-chisel-2fa */

type SsoState = {
    linked: boolean;
    emailAtLink: string | null;
    linkedAt: string | null;
    /** Benar hanya di alamat tenant yang memilih SSO; upacaranya harus kembali ke sana. */
    canConnect: boolean;
};

type Props = {
    passwordRules: string;
    /** Null bila penempatan ini tidak menyetel penyedia identitas; bagiannya tidak tampil. */
    sso: SsoState | null;
    ssoError: string | null;
} /* @chisel-2fa */ & ManageTwoFactorProps /* @end-chisel-2fa */;

export default function Security(props: Props) {
    const { wrapRouter: wrapRouterRequest, summary: routerRequestSummary } =
        useInertiaFeedback();

    const { wrap: wrapFormResponse1, summary: formResponse1Summary } =
        useInertiaFeedback();

    const passwordInput = useRef<HTMLInputElement>(null);
    const currentPasswordInput = useRef<HTMLInputElement>(null);

    return (
        <>
            <Head title="Security settings" />

            <main className="mx-auto flex w-full max-w-3xl flex-col gap-12 p-6">
                {routerRequestSummary}
                <h1 className="sr-only">Security settings</h1>

                <div className="flex flex-col gap-6">
                    <Heading
                        variant="small"
                        title="Update password"
                        description="Ensure your account is using a long, random password to stay secure"
                    />

                    <Form
                        noValidate
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
                        className="space-y-6"
                        {...wrapFormResponse1({})}
                    >
                        {({ errors, processing }) => (
                            <>
                                <RecordActionBar>
                                    <ActionButton
                                        action="save"
                                        type="submit"
                                        pending={processing}
                                        disabled={processing}
                                        data-test="update-password-button"
                                    />
                                </RecordActionBar>
                                <>
                                    {formResponse1Summary}
                                    <FormErrorHeader errors={errors} />
                                </>

                                <div
                                    className="grid gap-2"
                                    data-error-field={'current_password'}
                                >
                                    <PasswordInput
                                        id="current_password"
                                        label="Current password"
                                        ref={currentPasswordInput}
                                        name="current_password"
                                        className="mt-1 block w-full"
                                        autoComplete="current-password"
                                    />

                                    <InputError
                                        message={errors.current_password}
                                    />
                                </div>

                                <div
                                    className="grid gap-2"
                                    data-error-field={'password'}
                                >
                                    <PasswordInput
                                        id="password"
                                        label="New password"
                                        ref={passwordInput}
                                        name="password"
                                        className="mt-1 block w-full"
                                        autoComplete="new-password"
                                        passwordrules={props.passwordRules}
                                    />

                                    <InputError message={errors.password} />
                                </div>

                                <div
                                    className="grid gap-2"
                                    data-error-field={'password_confirmation'}
                                >
                                    <PasswordInput
                                        id="password_confirmation"
                                        label="Confirm password"
                                        name="password_confirmation"
                                        className="mt-1 block w-full"
                                        autoComplete="new-password"
                                        passwordrules={props.passwordRules}
                                    />

                                    <InputError
                                        message={errors.password_confirmation}
                                    />
                                </div>
                            </>
                        )}
                    </Form>
                </div>

                {/* @chisel-2fa */}
                <ManageTwoFactor
                    canManageTwoFactor={props.canManageTwoFactor}
                    requiresConfirmation={props.requiresConfirmation}
                    twoFactorEnabled={props.twoFactorEnabled}
                />
                {/* @end-chisel-2fa */}

                {props.sso && (
                    <div className="flex flex-col gap-6">
                        <Heading
                            variant="small"
                            title="Akun SSO"
                            description="Hubungkan akun SSO supaya berikutnya Anda dapat masuk lewat SSO. Hanya akun yang Anda hubungkan sendiri dari sini yang dapat dipakai."
                        />

                        {props.ssoError && (
                            <div
                                role="alert"
                                className="rounded-md border border-destructive/40 bg-destructive/5 px-3 py-2 text-sm text-destructive"
                            >
                                {props.ssoError}
                            </div>
                        )}

                        {props.sso.linked ? (
                            <div className="flex flex-wrap items-center justify-between gap-4">
                                <p className="text-sm text-muted-foreground">
                                    Terhubung
                                    {props.sso.emailAtLink
                                        ? ` sebagai ${props.sso.emailAtLink}`
                                        : ''}
                                    {props.sso.linkedAt
                                        ? `, ${props.sso.linkedAt}`
                                        : ''}
                                    .
                                </p>
                                <Button
                                    variant="outline"
                                    data-test="sso-disconnect-button"
                                    onClick={() =>
                                        router.delete(
                                            '/sso/hubungkan',
                                            wrapRouterRequest({
                                                preserveScroll: true,
                                            }),
                                        )
                                    }
                                >
                                    Putuskan
                                </Button>
                            </div>
                        ) : props.sso.canConnect ? (
                            <div>
                                <Button
                                    data-test="sso-connect-button"
                                    onClick={() =>
                                        router.post(
                                            '/sso/hubungkan',
                                            {},
                                            wrapRouterRequest(),
                                        )
                                    }
                                >
                                    Hubungkan SSO
                                </Button>
                            </div>
                        ) : (
                            <p className="text-sm text-muted-foreground">
                                Buka pengaturan ini dari alamat tenant yang
                                memakai SSO untuk menghubungkan akun.
                            </p>
                        )}
                    </div>
                )}
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
