import { Button } from '@apperp/ui/button';
import { FieldError, FieldLabel } from '@apperp/ui/field';
import { Input } from '@apperp/ui/input';
import { InputOTP, InputOTPGroup, InputOTPSlot } from '@apperp/ui/input-otp';
import { Spinner } from '@apperp/ui/spinner';
import { Form, Head, setLayoutProps } from '@inertiajs/react';
import { REGEXP_ONLY_DIGITS } from 'input-otp';
import { useMemo, useState } from 'react';
import { FormErrorHeader } from '@/components/form-error-header';
import { useInertiaFeedback } from '@/hooks/use-inertia-feedback';
import { OTP_MAX_LENGTH } from '@/hooks/use-two-factor-auth';
import { store } from '@/routes/two-factor/login';

export default function TwoFactorChallenge() {
    const { wrap: wrapFormResponse1, summary: formResponse1Summary } =
        useInertiaFeedback();

    const [showRecoveryInput, setShowRecoveryInput] = useState<boolean>(false);
    const [code, setCode] = useState<string>('');

    const authConfigContent = useMemo<{
        title: string;
        description: string;
        toggleText: string;
    }>(() => {
        if (showRecoveryInput) {
            return {
                title: 'Kode pemulihan',
                description:
                    'Masukkan salah satu kode pemulihan yang Anda simpan saat mengaktifkan verifikasi dua langkah.',
                toggleText: 'Gunakan kode aplikasi autentikator',
            };
        }

        return {
            title: 'Kode autentikasi',
            description: 'Masukkan kode dari aplikasi autentikator Anda.',
            toggleText: 'Gunakan kode pemulihan',
        };
    }, [showRecoveryInput]);

    setLayoutProps({
        title: authConfigContent.title,
        description: authConfigContent.description,
    });

    const toggleRecoveryMode = (clearErrors: () => void): void => {
        setShowRecoveryInput(!showRecoveryInput);
        clearErrors();
        setCode('');
    };

    return (
        <>
            <Head title="Verifikasi dua langkah" />

            <div className="space-y-6">
                <Form
                    noValidate
                    {...store.form()}
                    className="space-y-4"
                    resetOnError
                    resetOnSuccess={!showRecoveryInput}
                    {...wrapFormResponse1({})}
                >
                    {({ errors, processing, clearErrors }) => (
                        <>
                            <>
                                {formResponse1Summary}
                                <FormErrorHeader errors={errors} />
                            </>

                            {showRecoveryInput ? (
                                <>
                                    <Input
                                        label="Kode pemulihan"
                                        name="recovery_code"
                                        aria-invalid={Boolean(
                                            errors.recovery_code,
                                        )}
                                        aria-describedby={
                                            errors.recovery_code
                                                ? 'recovery_code-error'
                                                : undefined
                                        }
                                        type="text"
                                        autoFocus={showRecoveryInput}
                                        required
                                    />
                                    <FieldError id="recovery_code-error">
                                        {errors.recovery_code}
                                    </FieldError>
                                </>
                            ) : (
                                <div
                                    className="flex flex-col items-center justify-center space-y-3 text-center"
                                    data-error-field={'code'}
                                >
                                    <FieldLabel htmlFor="code">
                                        Kode autentikasi
                                        <span
                                            aria-hidden="true"
                                            className="text-destructive"
                                        >
                                            *
                                        </span>
                                        <span className="sr-only">
                                            wajib diisi
                                        </span>
                                    </FieldLabel>
                                    <div className="flex w-full items-center justify-center">
                                        <InputOTP
                                            id="code"
                                            name="code"
                                            aria-invalid={Boolean(errors.code)}
                                            aria-describedby={
                                                errors.code
                                                    ? 'code-error'
                                                    : undefined
                                            }
                                            maxLength={OTP_MAX_LENGTH}
                                            value={code}
                                            onChange={(value) => setCode(value)}
                                            disabled={processing}
                                            pattern={REGEXP_ONLY_DIGITS}
                                            aria-label="Kode autentikasi"
                                            autoComplete="one-time-code"
                                            required
                                            autoFocus
                                        >
                                            <InputOTPGroup>
                                                {Array.from(
                                                    { length: OTP_MAX_LENGTH },
                                                    (_, index) => (
                                                        <InputOTPSlot
                                                            key={index}
                                                            index={index}
                                                        />
                                                    ),
                                                )}
                                            </InputOTPGroup>
                                        </InputOTP>
                                    </div>
                                    <FieldError id="code-error">
                                        {errors.code}
                                    </FieldError>
                                </div>
                            )}

                            <Button
                                type="submit"
                                className="w-full"
                                disabled={processing}
                            >
                                {processing && <Spinner />}
                                Lanjutkan
                            </Button>

                            <div className="text-center text-sm text-muted-foreground">
                                <Button
                                    type="button"
                                    variant="link"
                                    onClick={() =>
                                        toggleRecoveryMode(clearErrors)
                                    }
                                >
                                    {authConfigContent.toggleText}
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            </div>
        </>
    );
}
