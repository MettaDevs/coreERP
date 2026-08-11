import { Head, useForm } from '@inertiajs/react';
import { Building2, KeyRound, Package, ShieldCheck, ArrowRight, ArrowLeft, CheckCircle2 } from 'lucide-react';
import { useState } from 'react';

import PasswordInput from '@/components/password-input';
import TextLink from '@/components/text-link';
import { Alert, AlertDescription, AlertTitle } from '@apperp/ui/alert';
import { Button } from '@apperp/ui/button';
import {
    Field,
    FieldError,
    FieldGroup,
} from '@apperp/ui/field';
import { Input } from '@apperp/ui/input';
import { Spinner } from '@apperp/ui/spinner';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@apperp/ui/tabs';
import { login } from '@/routes';

type AppOption = { id: string; name: string; description: string };
type Props = { passwordRules: string; apps: AppOption[] };
type Step = 'business' | 'products' | 'security';

const EMAIL_REGEX = /^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/;

export default function Register({ passwordRules, apps }: Props) {
    const [step, setStep] = useState<Step>('business');
    const form = useForm({
        name: '',
        business_name: '',
        email: '',
        app_ids: [] as string[],
        password: '',
        password_confirmation: '',
    });

    const [clientErrors, setClientErrors] = useState<Record<string, string | undefined>>({});

    const validateName = (val: string) => {
        if (!val.trim()) return 'Nama pemilik akun wajib diisi.';
        return undefined;
    };

    const validateBusinessName = (val: string) => {
        if (!val.trim()) return 'Nama bisnis / perusahaan wajib diisi.';
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

    const handleFieldChange = (field: keyof typeof form.data, value: any) => {
        let finalVal = value;
        if (field === 'email' && typeof value === 'string') {
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

    const [isCheckingEmail, setIsCheckingEmail] = useState(false);

    const handleNextToProducts = async () => {
        const nameErr = validateName(form.data.name);
        const bizErr = validateBusinessName(form.data.business_name);
        const emailErr = validateEmail(form.data.email);

        setClientErrors((prev) => ({
            ...prev,
            name: nameErr,
            business_name: bizErr,
            email: emailErr,
        }));

        if (nameErr || bizErr || emailErr) {
            return;
        }

        setIsCheckingEmail(true);
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

            const result = await response.json();
            if (result.maxReached) {
                setClientErrors((prev) => ({
                    ...prev,
                    email: result.message || 'Email ini telah terdaftar untuk 3 bisnis (batas maksimal). Silakan gunakan email lain atau login.',
                }));
                setIsCheckingEmail(false);
                return;
            }
        } catch (e) {
            console.error('Error checking duplicate email:', e);
        } finally {
            setIsCheckingEmail(false);
        }

        setStep('products');
    };

    const handleNextToSecurity = () => {
        if (form.data.app_ids.length > 0) {
            setStep('security');
        }
    };

    const toggleApp = (id: string) => {
        const current = form.data.app_ids;
        if (current.includes(id)) {
            form.setData('app_ids', current.filter((item) => item !== id));
        } else {
            form.setData('app_ids', [...current, id]);
        }
    };

    const submit = (e: React.FormEvent) => {
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
                if (errors.name || errors.business_name || errors.email) {
                    setStep('business');
                } else if (errors.app_ids) {
                    setStep('products');
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
        form.data.business_name.trim() !== '' &&
        form.data.email.trim() !== '';

    return (
        <>
            <Head title="Pendaftaran Bisnis Baru" />

            <Tabs
                value={step}
                onValueChange={(value) => setStep(value as Step)}
                className="space-y-6"
            >
                {/* Modern Step Navigation Tabs Header */}
                <TabsList className="grid w-full grid-cols-3 h-12 bg-slate-100 dark:bg-slate-800/60 p-1 rounded-xl">
                    <TabsTrigger
                        value="business"
                        className="rounded-lg text-xs font-semibold gap-1.5 data-[state=active]:bg-white dark:data-[state=active]:bg-slate-900 data-[state=active]:shadow-sm"
                    >
                        <Building2 className="size-3.5" />
                        <span className="hidden sm:inline">1. Bisnis</span>
                        <span className="sm:hidden">1</span>
                    </TabsTrigger>
                    <TabsTrigger
                        value="products"
                        disabled={!isStep1Valid}
                        className="rounded-lg text-xs font-semibold gap-1.5 data-[state=active]:bg-white dark:data-[state=active]:bg-slate-900 data-[state=active]:shadow-sm"
                    >
                        <Package className="size-3.5" />
                        <span className="hidden sm:inline">2. Produk</span>
                        <span className="sm:hidden">2</span>
                    </TabsTrigger>
                    <TabsTrigger
                        value="security"
                        disabled={!isStep1Valid || form.data.app_ids.length === 0}
                        className="rounded-lg text-xs font-semibold gap-1.5 data-[state=active]:bg-white dark:data-[state=active]:bg-slate-900 data-[state=active]:shadow-sm"
                    >
                        <ShieldCheck className="size-3.5" />
                        <span className="hidden sm:inline">3. Keamanan</span>
                        <span className="sm:hidden">3</span>
                    </TabsTrigger>
                </TabsList>

                {/* STEP 1: BUSINESS IDENTIFICATION */}
                <TabsContent value="business" className="space-y-5 focus:outline-none">
                    <div className="space-y-1 border-b border-slate-100 dark:border-slate-800 pb-3">
                        <h3 className="text-base font-semibold text-slate-900 dark:text-white flex items-center gap-2">
                            <Building2 className="size-4 text-indigo-500" />
                            Identitas Pemilik & Perusahaan
                        </h3>
                        <p className="text-xs text-slate-500 dark:text-slate-400">
                            Lengkapi identitas Anda sebagai pemilik akun utama serta nama unit bisnis.
                        </p>
                    </div>

                    <FieldGroup className="space-y-4">
                        <Field data-invalid={Boolean(getFieldError('name'))}>
                            <Input
                                id="name"
                                name="name"
                                label="Nama Pemilik Akun"
                                value={form.data.name}
                                onChange={(event) => handleFieldChange('name', event.target.value)}
                                onBlur={() => handleBlur('name')}
                                aria-invalid={Boolean(getFieldError('name'))}
                                autoComplete="name"
                                autoFocus
                                placeholder="Nama lengkap Anda"
                            />
                            <FieldError>{getFieldError('name')}</FieldError>
                        </Field>

                        <Field data-invalid={Boolean(getFieldError('business_name'))}>
                            <Input
                                id="business_name"
                                name="business_name"
                                label="Nama Bisnis / Perusahaan"
                                value={form.data.business_name}
                                onChange={(event) => handleFieldChange('business_name', event.target.value)}
                                onBlur={() => handleBlur('business_name')}
                                aria-invalid={Boolean(getFieldError('business_name'))}
                                autoComplete="organization"
                                placeholder="Contoh: PT. Sanata System"
                            />
                            <FieldError>{getFieldError('business_name')}</FieldError>
                        </Field>

                        <Field data-invalid={Boolean(getFieldError('email'))}>
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
                                placeholder="pemilik@perusahaan.com"
                            />
                            <FieldError>{getFieldError('email')}</FieldError>
                        </Field>
                    </FieldGroup>

                    <div className="pt-4 flex justify-end">
                        <Button
                            type="button"
                            disabled={!isStep1Valid || isCheckingEmail}
                            onClick={handleNextToProducts}
                            className="h-10 px-5 bg-indigo-600 hover:bg-indigo-500 text-white font-medium rounded-xl shadow-md transition-all gap-2 text-xs"
                        >
                            {isCheckingEmail ? (
                                <>
                                    <Spinner className="mr-1.5" />
                                    Memeriksa...
                                </>
                            ) : (
                                <>
                                    Lanjutkan ke Produk
                                    <ArrowRight className="size-3.5" />
                                </>
                            )}
                        </Button>
                    </div>
                </TabsContent>

                {/* STEP 2: PRODUCT SELECTION */}
                <TabsContent value="products" className="space-y-5 focus:outline-none">
                    <div className="space-y-1 border-b border-slate-100 dark:border-slate-800 pb-3">
                        <h3 className="text-base font-semibold text-slate-900 dark:text-white flex items-center gap-2">
                            <Package className="size-4 text-indigo-500" />
                            Pilih Modul Aplikasi ERP
                        </h3>
                        <p className="text-xs text-slate-500 dark:text-slate-400">
                            Pilih minimal 1 produk awal yang dibutuhkan oleh operasional bisnis Anda.
                        </p>
                    </div>

                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-3.5">
                        {apps.map((app) => {
                            const isSelected = form.data.app_ids.includes(app.id);
                            return (
                                <div
                                    key={app.id}
                                    onClick={() => toggleApp(app.id)}
                                    className={`p-4 rounded-2xl border transition-all cursor-pointer flex flex-col justify-between space-y-3 relative group ${
                                        isSelected
                                            ? 'bg-indigo-50/60 dark:bg-indigo-950/40 border-indigo-500 dark:border-indigo-500 ring-2 ring-indigo-500/20'
                                            : 'bg-white dark:bg-slate-900 border-slate-200 dark:border-slate-800 hover:border-slate-300 dark:hover:border-slate-700'
                                    }`}
                                >
                                    <div className="flex items-start justify-between">
                                        <div className="size-9 rounded-xl bg-slate-100 dark:bg-slate-800 flex items-center justify-center text-slate-700 dark:text-slate-200 group-hover:scale-105 transition-transform">
                                            <Package className="size-5 text-indigo-600 dark:text-indigo-400" />
                                        </div>
                                        <div
                                            className={`size-5 rounded-full flex items-center justify-center transition-all ${
                                                isSelected
                                                    ? 'bg-indigo-600 text-white'
                                                    : 'border border-slate-300 dark:border-slate-700'
                                            }`}
                                        >
                                            {isSelected && <CheckCircle2 className="size-3.5 stroke-[3]" />}
                                        </div>
                                    </div>

                                    <div>
                                        <h4 className="text-xs font-bold text-slate-900 dark:text-white">
                                            {app.name}
                                        </h4>
                                        <p className="text-[11px] text-slate-500 dark:text-slate-400 line-clamp-2 mt-0.5">
                                            {app.description || 'Sistem manajemen operasional terpadu.'}
                                        </p>
                                    </div>
                                </div>
                            );
                        })}
                    </div>

                    {form.errors.app_ids && (
                        <p className="text-xs font-medium text-red-500 mt-1">
                            {form.errors.app_ids}
                        </p>
                    )}

                    <div className="pt-4 flex items-center justify-between">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => setStep('business')}
                            className="h-10 px-4 rounded-xl gap-2 text-xs"
                        >
                            <ArrowLeft className="size-3.5" />
                            Kembali
                        </Button>
                        <Button
                            type="button"
                            disabled={form.data.app_ids.length === 0}
                            onClick={handleNextToSecurity}
                            className="h-10 px-5 bg-indigo-600 hover:bg-indigo-500 text-white font-medium rounded-xl shadow-md transition-all gap-2 text-xs"
                        >
                            Lanjutkan ke Keamanan
                            <ArrowRight className="size-3.5" />
                        </Button>
                    </div>
                </TabsContent>

                {/* STEP 3: SECURITY & CONFIRMATION */}
                <TabsContent value="security" className="space-y-5 focus:outline-none">
                    <form onSubmit={submit} className="space-y-5" noValidate>
                        <div className="space-y-1 border-b border-slate-100 dark:border-slate-800 pb-3">
                            <h3 className="text-base font-semibold text-slate-900 dark:text-white flex items-center gap-2">
                                <ShieldCheck className="size-4 text-indigo-500" />
                                Keamanan & Kata Sandi
                            </h3>
                            <p className="text-xs text-slate-500 dark:text-slate-400">
                                Buat kata sandi aman untuk akun pemilik tenant bisnis.
                            </p>
                        </div>

                        <FieldGroup className="space-y-4">
                            <Alert className="bg-indigo-50/70 dark:bg-indigo-950/40 border-indigo-200 dark:border-indigo-800">
                                <Building2 className="size-4 text-indigo-600 dark:text-indigo-400" />
                                <AlertTitle className="text-xs font-semibold text-indigo-950 dark:text-indigo-200">
                                    Perusahaan: {form.data.business_name}
                                </AlertTitle>
                                <AlertDescription className="text-xs text-indigo-800 dark:text-indigo-300">
                                    {form.data.app_ids.length} modul aplikasi terpilih. Tenant dan database akan disiapkan secara otomatis setelah pendaftaran selesai.
                                </AlertDescription>
                            </Alert>

                            <Field data-invalid={Boolean(getFieldError('password'))}>
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
                                />
                                <FieldError>{getFieldError('password')}</FieldError>
                            </Field>

                            <Field data-invalid={Boolean(getFieldError('password_confirmation'))}>
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
                                />
                                <FieldError>{getFieldError('password_confirmation')}</FieldError>
                            </Field>
                        </FieldGroup>

                        <div className="pt-4 flex items-center justify-between">
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => setStep('products')}
                                className="h-10 px-4 rounded-xl gap-2 text-xs"
                            >
                                <ArrowLeft className="size-3.5" />
                                Kembali
                            </Button>
                            <Button
                                type="submit"
                                disabled={form.processing}
                                className="h-10 px-6 bg-gradient-to-r from-indigo-600 to-blue-600 hover:from-indigo-500 hover:to-blue-500 text-white font-semibold rounded-xl shadow-lg shadow-indigo-500/25 transition-all"
                            >
                                {form.processing ? (
                                    <>
                                        <Spinner className="mr-2" />
                                        Memproses...
                                    </>
                                ) : (
                                    'Daftar Business Account'
                                )}
                            </Button>
                        </div>
                    </form>
                </TabsContent>
            </Tabs>

            <div className="pt-4 text-center text-xs text-slate-500 dark:text-slate-400 border-t border-slate-100 dark:border-slate-800 mt-4">
                Sudah memiliki akun bisnis?{' '}
                <TextLink href={login()} className="font-semibold text-indigo-600 dark:text-indigo-400 hover:underline">
                    Masuk ke Akun
                </TextLink>
            </div>
        </>
    );
}

Register.layout = {
    title: 'Pendaftaran Bisnis Baru',
    description: 'Isi data pemilik akun dan pilih produk awal untuk bisnis Anda.',
};
