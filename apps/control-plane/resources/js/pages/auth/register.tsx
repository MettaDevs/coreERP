import { Head, useForm } from '@inertiajs/react';
import { User, ShieldCheck, ArrowRight, ArrowLeft, CheckCircle2 } from 'lucide-react';
import { useState } from 'react';

import PasswordInput from '@/components/password-input';
import TextLink from '@/components/text-link';
import { Alert, AlertDescription, AlertTitle } from '@apperp/ui/alert';
import { Button } from '@apperp/ui/button';
import {
    Field,
    FieldError,
} from '@apperp/ui/field';
import { Input } from '@apperp/ui/input';
import { Spinner } from '@apperp/ui/spinner';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@apperp/ui/tabs';
import { login } from '@/routes';

type Props = { passwordRules?: string };
type Step = 'account' | 'security';

const EMAIL_REGEX = /^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/;

export default function Register({ passwordRules = '' }: Props) {
    const [step, setStep] = useState<Step>('account');
    const form = useForm({
        name: '',
        email: '',
        password: '',
        password_confirmation: '',
    });

    const [clientErrors, setClientErrors] = useState<Record<string, string | undefined>>({});
    const [isCheckingEmail, setIsCheckingEmail] = useState(false);

    const validateName = (val: string) => {
        if (!val.trim()) return 'Nama lengkap wajib diisi.';
        return undefined;
    };

    const validateEmail = (val: string) => {
        const trimmed = val.trim();
        if (!trimmed) return 'Email wajib diisi.';
        if (!EMAIL_REGEX.test(trimmed)) return 'Format email tidak valid.';
        return undefined;
    };

    const validatePassword = (val: string) => {
        if (!val) return 'Password wajib diisi.';
        if (val.length < 8) return 'Password minimal 8 karakter.';
        return undefined;
    };

    const validatePasswordConfirmation = (confVal: string, passVal: string) => {
        if (!confVal) return 'Konfirmasi password wajib diisi.';
        if (confVal !== passVal) return 'Konfirmasi password tidak cocok.';
        return undefined;
    };

    const handleFieldChange = (field: keyof typeof form.data, value: string) => {
        let finalVal = value;
        if (field === 'email') {
            finalVal = value.trimStart();
        }
        form.setData(field, finalVal);

        if (clientErrors[field]) {
            setClientErrors((prev) => ({ ...prev, [field]: undefined }));
        }
        if (form.errors[field as keyof typeof form.errors]) {
            form.clearErrors(field as keyof typeof form.errors);
        }
    };

    const handleBlur = (field: string) => {
        let err: string | undefined = undefined;

        if (field === 'email' && form.data.email.trim() !== '') {
            if (!EMAIL_REGEX.test(form.data.email.trim())) {
                err = 'Format email tidak valid.';
            }
        }
        if (field === 'password' && form.data.password !== '') {
            if (form.data.password.length < 8) {
                err = 'Password minimal 8 karakter.';
            }
        }
        if (field === 'password_confirmation' && form.data.password_confirmation !== '') {
            if (form.data.password_confirmation !== form.data.password) {
                err = 'Konfirmasi password tidak cocok.';
            }
        }

        if (err !== undefined) {
            setClientErrors((prev) => ({ ...prev, [field]: err }));
        }
    };

    const handleNextToSecurity = async () => {
        const nameErr = validateName(form.data.name);
        const emailErr = validateEmail(form.data.email);

        setClientErrors((prev) => ({
            ...prev,
            name: nameErr,
            email: emailErr,
        }));

        if (nameErr || emailErr) {
            return;
        }

        setIsCheckingEmail(true);
        let hasError = false;
        try {
            const response = await fetch('/check-email', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content || '',
                },
                body: JSON.stringify({ email: form.data.email.trim() }),
            });

            if (response.ok) {
                const data = await response.json();
                if (!data.available) {
                    hasError = true;
                    setClientErrors((prev) => ({
                        ...prev,
                        email: data.message || 'Email sudah terdaftar. Silakan gunakan email lain atau login.',
                    }));
                }
            }
        } catch {
            // Ignore fetch network issues and let backend validate
        } finally {
            setIsCheckingEmail(false);
        }

        if (!hasError) {
            setStep('security');
        }
    };

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();

        const passErr = validatePassword(form.data.password);
        const confErr = validatePasswordConfirmation(
            form.data.password_confirmation,
            form.data.password
        );

        setClientErrors((prev) => ({
            ...prev,
            password: passErr,
            password_confirmation: confErr,
        }));

        if (passErr || confErr) {
            return;
        }

        form.data.email = form.data.email.trim().toLowerCase();

        form.post('/register', {
            onError: (errors) => {
                if (errors.name || errors.email) {
                    setStep('account');
                } else {
                    setStep('security');
                }
            },
        });
    };

    const getFieldError = (field: string) => {
        return form.errors[field as keyof typeof form.errors] || clientErrors[field];
    };

    const isStep1Valid =
        form.data.name.trim() !== '' &&
        form.data.email.trim() !== '' &&
        !getFieldError('name') &&
        !getFieldError('email') &&
        !isCheckingEmail;

    return (
        <>
            <Head title="Pendaftaran Akun Baru" />

            <Tabs
                value={step}
                onValueChange={(value) => {
                    if (value === 'account') setStep('account');
                    else if (value === 'security' && isStep1Valid) handleNextToSecurity();
                }}
                className="space-y-3"
            >
                {/* 2-Step Modern Tab Header */}
                <TabsList className="grid w-full grid-cols-2 h-9.5 bg-slate-100/90 dark:bg-slate-800/80 p-1 rounded-full border-none outline-none ring-0 shadow-none">
                    <TabsTrigger
                        value="account"
                        className="rounded-full text-[11px] font-bold gap-1.5 transition-all cursor-pointer border-none outline-none ring-0 focus-visible:ring-0 focus-visible:outline-none focus:outline-none shadow-none data-[state=active]:bg-gradient-to-r data-[state=active]:from-[#005F73] data-[state=active]:via-[#00A8B5] data-[state=active]:to-[#00C9C8] data-[state=active]:text-white data-[state=active]:shadow-md data-[state=active]:shadow-cyan-600/30 data-[state=inactive]:text-slate-500 dark:data-[state=inactive]:text-slate-400"
                    >
                        <User className="size-3.5" />
                        <span>1. Akun</span>
                    </TabsTrigger>
                    <TabsTrigger
                        value="security"
                        disabled={!isStep1Valid || isCheckingEmail}
                        className="rounded-full text-[11px] font-bold gap-1.5 transition-all cursor-pointer border-none outline-none ring-0 focus-visible:ring-0 focus-visible:outline-none focus:outline-none shadow-none data-[state=active]:bg-gradient-to-r data-[state=active]:from-[#005F73] data-[state=active]:via-[#00A8B5] data-[state=active]:to-[#00C9C8] data-[state=active]:text-white data-[state=active]:shadow-md data-[state=active]:shadow-cyan-600/30 data-[state=inactive]:text-slate-500 dark:data-[state=inactive]:text-slate-400 disabled:opacity-40 disabled:pointer-events-none"
                    >
                        <ShieldCheck className="size-3.5" />
                        <span>2. Keamanan</span>
                    </TabsTrigger>
                </TabsList>

                {/* STEP 1: ACCOUNT IDENTIFICATION */}
                <TabsContent value="account" className="space-y-3 focus:outline-none">
                    <div className="space-y-0.5 border-b border-slate-100 dark:border-slate-800 pb-1.5">
                        <h3 className="text-xs sm:text-sm font-bold text-slate-900 dark:text-white flex items-center gap-1.5">
                            <User className="size-3.5 text-[#00AFC0]" />
                            Identitas Pemilik Akun
                        </h3>
                        <p className="text-[10.5px] text-slate-500 dark:text-slate-400 leading-snug">
                            Lengkapi identitas diri Anda dan alamat email utama untuk pembuatan akun.
                        </p>
                    </div>

                    <div className="space-y-2.5">
                        <Field className="!gap-1" data-invalid={Boolean(getFieldError('name'))}>
                            <Input
                                id="name"
                                name="name"
                                label="Nama Lengkap"
                                value={form.data.name}
                                onChange={(event) => handleFieldChange('name', event.target.value)}
                                onBlur={() => handleBlur('name')}
                                aria-invalid={Boolean(getFieldError('name'))}
                                autoComplete="name"
                                autoFocus
                                placeholder="Nama lengkap Anda"
                                className="w-full h-9.5 rounded-xl border-slate-200 focus:border-[#00AFC0] focus:ring-2 focus:ring-[#00AFC0]/20 text-xs transition-all"
                            />
                            <FieldError>{getFieldError('name')}</FieldError>
                        </Field>

                        <Field className="!gap-1" data-invalid={Boolean(getFieldError('email'))}>
                            <Input
                                id="email"
                                name="email"
                                label="Alamat Email Utama"
                                type="email"
                                value={form.data.email}
                                onChange={(event) => handleFieldChange('email', event.target.value)}
                                onBlur={() => handleBlur('email')}
                                aria-invalid={Boolean(getFieldError('email'))}
                                autoComplete="email"
                                placeholder="nama@email.com"
                                className="w-full h-9.5 rounded-xl border-slate-200 focus:border-[#00AFC0] focus:ring-2 focus:ring-[#00AFC0]/20 text-xs transition-all"
                            />
                            <FieldError>{getFieldError('email')}</FieldError>
                        </Field>
                    </div>

                    <div className="pt-1">
                        <Button
                            type="button"
                            disabled={!isStep1Valid || isCheckingEmail}
                            onClick={handleNextToSecurity}
                            className="w-full h-10 rounded-full btn-gradient-primary cursor-pointer active:scale-[0.99] gap-2 text-xs sm:text-sm font-bold flex items-center justify-center border-none shadow-md shadow-cyan-600/25"
                        >
                            {isCheckingEmail ? (
                                <>
                                    <Spinner className="mr-1.5 text-white" />
                                    Memeriksa...
                                </>
                            ) : (
                                <>
                                    <span>Lanjutkan ke Keamanan</span>
                                    <ArrowRight className="size-4" />
                                </>
                            )}
                        </Button>
                    </div>
                </TabsContent>

                {/* STEP 2: SECURITY & CONFIRMATION */}
                <TabsContent value="security" className="space-y-3 focus:outline-none">
                    <form onSubmit={handleSubmit} className="space-y-3" noValidate>
                        <div className="space-y-0.5 border-b border-slate-100 dark:border-slate-800 pb-1.5">
                            <h3 className="text-xs sm:text-sm font-bold text-slate-900 dark:text-white flex items-center gap-1.5">
                                <ShieldCheck className="size-3.5 text-[#00AFC0]" />
                                Keamanan &amp; Kata Sandi
                            </h3>
                            <p className="text-[10.5px] text-slate-500 dark:text-slate-400 leading-snug">
                                Buat kata sandi aman untuk masuk ke akun Anda.
                            </p>
                        </div>

                        <div className="space-y-2.5">
                            <Alert className="bg-[#EAFBFC]/80 dark:bg-cyan-950/40 border-[#00AFC0]/30 py-1.5">
                                <CheckCircle2 className="size-3.5 text-[#00AFC0]" />
                                <AlertTitle className="text-xs font-semibold text-cyan-950 dark:text-cyan-200">
                                    Pendaftaran Akun Baru
                                </AlertTitle>
                                <AlertDescription className="text-[10.5px] text-cyan-800 dark:text-cyan-300">
                                    Setelah akun terdaftar, Anda dapat langsung login dan mendaftarkan unit bisnis serta modul aplikasi ERP Anda.
                                </AlertDescription>
                            </Alert>

                            <Field className="!gap-1" data-invalid={Boolean(getFieldError('password'))}>
                                <PasswordInput
                                    id="password"
                                    name="password"
                                    label="Kata Sandi Utama"
                                    value={form.data.password}
                                    onChange={(event) => handleFieldChange('password', event.target.value)}
                                    onBlur={() => handleBlur('password')}
                                    aria-invalid={Boolean(getFieldError('password'))}
                                    autoComplete="new-password"
                                    passwordrules={passwordRules}
                                    placeholder="••••••••"
                                    className="w-full h-9.5 rounded-xl border-slate-200 focus:border-[#00AFC0] focus:ring-2 focus:ring-[#00AFC0]/20 text-xs transition-all"
                                />
                                <FieldError>{getFieldError('password')}</FieldError>
                            </Field>

                            <Field className="!gap-1" data-invalid={Boolean(getFieldError('password_confirmation'))}>
                                <PasswordInput
                                    id="password_confirmation"
                                    name="password_confirmation"
                                    label="Konfirmasi Kata Sandi"
                                    value={form.data.password_confirmation}
                                    onChange={(event) => handleFieldChange('password_confirmation', event.target.value)}
                                    onBlur={() => handleBlur('password_confirmation')}
                                    aria-invalid={Boolean(getFieldError('password_confirmation'))}
                                    autoComplete="new-password"
                                    passwordrules={passwordRules}
                                    placeholder="••••••••"
                                    className="w-full h-9.5 rounded-xl border-slate-200 focus:border-[#00AFC0] focus:ring-2 focus:ring-[#00AFC0]/20 text-xs transition-all"
                                />
                                <FieldError>{getFieldError('password_confirmation')}</FieldError>
                            </Field>
                        </div>

                        <div className="pt-1 flex items-center justify-between gap-2.5">
                            <Button
                                type="button"
                                onClick={() => setStep('account')}
                                className="h-10 px-4 rounded-full btn-outline-turquoise cursor-pointer gap-2 text-xs font-bold"
                            >
                                <ArrowLeft className="size-3.5" />
                                <span>Kembali</span>
                            </Button>
                            <Button
                                type="submit"
                                disabled={form.processing}
                                className="h-10 px-5 rounded-full btn-gradient-primary cursor-pointer active:scale-[0.99] gap-2 text-xs font-bold flex-1 flex items-center justify-center border-none shadow-md shadow-cyan-600/25"
                            >
                                {form.processing ? (
                                    <>
                                        <Spinner className="mr-1.5 text-white" />
                                        Mendaftarkan...
                                    </>
                                ) : (
                                    <>
                                        <span>Selesaikan Pendaftaran</span>
                                        <CheckCircle2 className="size-4" />
                                    </>
                                )}
                            </Button>
                        </div>
                    </form>
                </TabsContent>
            </Tabs>

            <div className="pt-2 text-center text-xs text-slate-500 dark:text-slate-400 border-t border-slate-100 dark:border-slate-800 mt-1">
                Sudah memiliki akun?{' '}
                <TextLink href={login()} className="font-semibold text-[#00AFC0] hover:text-[#008B9B] hover:underline">
                    Masuk ke Akun
                </TextLink>
            </div>
        </>
    );
}

Register.layout = {
    title: 'Pendaftaran Akun Baru',
    description: 'Isi data identitas diri untuk membuat akun ERP baru.',
};
