import { Head, useForm } from '@inertiajs/react';
import { Building2, Package, ShieldCheck, ArrowRight, ArrowLeft, CheckCircle2 } from 'lucide-react';
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
    const [isExistingEmail, setIsExistingEmail] = useState(false);

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

            const result = await response.json();
            if (result.maxReached) {
                setClientErrors((prev) => ({
                    ...prev,
                    email: result.message || 'Email ini telah terdaftar untuk 3 bisnis (batas maksimal). Silakan gunakan email lain atau login.',
                }));
                hasError = true;
                return;
            }

            if (result.exists) {
                setIsExistingEmail(true);
            } else {
                setIsExistingEmail(false);
            }
        } catch (e) {
            console.error('Error checking duplicate email:', e);
            hasError = true;
        } finally {
            setIsCheckingEmail(false);
        }

        if (!hasError) {
            setStep('products');
        }
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
        form.data.email.trim() !== '' &&
        !getFieldError('name') &&
        !getFieldError('business_name') &&
        !getFieldError('email') &&
        !isCheckingEmail;

    return (
        <>
            <Head title="Pendaftaran Bisnis Baru" />

            <Tabs
                value={step}
                onValueChange={(value) => setStep(value as Step)}
                className="space-y-3"
            >
                {/* Modern Step Navigation Tabs Header (Matching Reference Image) */}
                <TabsList className="grid w-full grid-cols-3 h-9.5 bg-slate-100/90 dark:bg-slate-800/80 p-1 rounded-full border-none outline-none ring-0 shadow-none">
                    <TabsTrigger
                        value="business"
                        className="rounded-full text-[11px] font-bold gap-1.5 transition-all cursor-pointer border-none outline-none ring-0 focus-visible:ring-0 focus-visible:outline-none focus:outline-none shadow-none data-[state=active]:bg-gradient-to-r data-[state=active]:from-[#005F73] data-[state=active]:via-[#00A8B5] data-[state=active]:to-[#00C9C8] data-[state=active]:text-white data-[state=active]:shadow-md data-[state=active]:shadow-cyan-600/30 data-[state=inactive]:text-slate-500 dark:data-[state=inactive]:text-slate-400"
                    >
                        <Building2 className="size-3.5" />
                        <span className="hidden sm:inline">1. Bisnis</span>
                        <span className="sm:hidden">1</span>
                    </TabsTrigger>
                    <TabsTrigger
                        value="products"
                        disabled={!isStep1Valid}
                        className="rounded-full text-[11px] font-bold gap-1.5 transition-all cursor-pointer border-none outline-none ring-0 focus-visible:ring-0 focus-visible:outline-none focus:outline-none shadow-none data-[state=active]:bg-gradient-to-r data-[state=active]:from-[#005F73] data-[state=active]:via-[#00A8B5] data-[state=active]:to-[#00C9C8] data-[state=active]:text-white data-[state=active]:shadow-md data-[state=active]:shadow-cyan-600/30 data-[state=inactive]:text-slate-500 dark:data-[state=inactive]:text-slate-400 disabled:opacity-40"
                    >
                        <Package className="size-3.5" />
                        <span className="hidden sm:inline">2. Produk</span>
                        <span className="sm:hidden">2</span>
                    </TabsTrigger>
                    <TabsTrigger
                        value="security"
                        disabled={!isStep1Valid || form.data.app_ids.length === 0}
                        className="rounded-full text-[11px] font-bold gap-1.5 transition-all cursor-pointer border-none outline-none ring-0 focus-visible:ring-0 focus-visible:outline-none focus:outline-none shadow-none data-[state=active]:bg-gradient-to-r data-[state=active]:from-[#005F73] data-[state=active]:via-[#00A8B5] data-[state=active]:to-[#00C9C8] data-[state=active]:text-white data-[state=active]:shadow-md data-[state=active]:shadow-cyan-600/30 data-[state=inactive]:text-slate-500 dark:data-[state=inactive]:text-slate-400 disabled:opacity-40"
                    >
                        <ShieldCheck className="size-3.5" />
                        <span className="hidden sm:inline">3. Keamanan</span>
                        <span className="sm:hidden">3</span>
                    </TabsTrigger>
                </TabsList>

                {/* STEP 1: BUSINESS IDENTIFICATION */}
                <TabsContent value="business" className="space-y-3 focus:outline-none">
                    <div className="space-y-0.5 border-b border-slate-100 dark:border-slate-800 pb-1.5">
                        <h3 className="text-xs sm:text-sm font-bold text-slate-900 dark:text-white flex items-center gap-1.5">
                            <Building2 className="size-3.5 text-[#00AFC0]" />
                            Identitas Pemilik &amp; Perusahaan
                        </h3>
                        <p className="text-[10.5px] text-slate-500 dark:text-slate-400 leading-snug">
                            Lengkapi identitas Anda sebagai pemilik akun utama serta nama unit bisnis.
                        </p>
                    </div>

                    <div className="space-y-2.5">
                        <Field className="!gap-1" data-invalid={Boolean(getFieldError('name'))}>
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
                                className="w-full h-9.5 rounded-xl border-slate-200 focus:border-[#00AFC0] focus:ring-2 focus:ring-[#00AFC0]/20 text-xs transition-all"
                            />
                            <FieldError>{getFieldError('name')}</FieldError>
                        </Field>

                        <Field className="!gap-1" data-invalid={Boolean(getFieldError('business_name'))}>
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
                                className="w-full h-9.5 rounded-xl border-slate-200 focus:border-[#00AFC0] focus:ring-2 focus:ring-[#00AFC0]/20 text-xs transition-all"
                            />
                            <FieldError>{getFieldError('business_name')}</FieldError>
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
                                placeholder="pemilik@perusahaan.com"
                                className="w-full h-9.5 rounded-xl border-slate-200 focus:border-[#00AFC0] focus:ring-2 focus:ring-[#00AFC0]/20 text-xs transition-all"
                            />
                            <FieldError>{getFieldError('email')}</FieldError>
                        </Field>
                    </div>

                    <div className="pt-1">
                        <Button
                            type="button"
                            disabled={!isStep1Valid || isCheckingEmail}
                            onClick={handleNextToProducts}
                            className="w-full h-10 rounded-full btn-gradient-primary cursor-pointer active:scale-[0.99] gap-2 text-xs sm:text-sm font-bold flex items-center justify-center border-none shadow-md shadow-cyan-600/25"
                        >
                            {isCheckingEmail ? (
                                <>
                                    <Spinner className="mr-1.5 text-white" />
                                    Memeriksa...
                                </>
                            ) : (
                                <>
                                    <span>Lanjutkan ke Produk</span>
                                    <ArrowRight className="size-4" />
                                </>
                            )}
                        </Button>
                    </div>
                </TabsContent>

                {/* STEP 2: PRODUCT SELECTION */}
                <TabsContent value="products" className="space-y-3 focus:outline-none">
                    <div className="space-y-0.5 border-b border-slate-100 dark:border-slate-800 pb-1.5">
                        <h3 className="text-xs sm:text-sm font-bold text-slate-900 dark:text-white flex items-center gap-1.5">
                            <Package className="size-3.5 text-[#00AFC0]" />
                            Pilih Modul Aplikasi ERP
                        </h3>
                        <p className="text-[10.5px] text-slate-500 dark:text-slate-400 leading-snug">
                            Pilih minimal 1 produk awal yang dibutuhkan oleh operasional bisnis Anda.
                        </p>
                    </div>

                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-2">
                        {apps.map((app) => {
                            const isSelected = form.data.app_ids.includes(app.id);
                            return (
                                <div
                                    key={app.id}
                                    onClick={() => toggleApp(app.id)}
                                    className={`p-2.5 rounded-xl border transition-all cursor-pointer flex flex-col justify-between space-y-1 relative group ${
                                        isSelected
                                            ? 'bg-cyan-50/60 dark:bg-cyan-950/40 border-[#00AFC0] dark:border-[#00AFC0] ring-2 ring-[#00AFC0]/20'
                                            : 'bg-white dark:bg-slate-900 border-slate-200 dark:border-slate-800 hover:border-slate-300 dark:hover:border-slate-700'
                                    }`}
                                >
                                    <div className="flex items-start justify-between">
                                        <div className="size-7 rounded-lg bg-slate-100 dark:bg-slate-800 flex items-center justify-center text-slate-700 dark:text-slate-200 group-hover:scale-105 transition-transform">
                                            <Package className="size-3.5 text-[#00AFC0]" />
                                        </div>
                                        <div
                                            className={`size-3.5 rounded-full flex items-center justify-center transition-all ${
                                                isSelected
                                                    ? 'bg-[#00AFC0] text-white'
                                                    : 'border border-slate-300 dark:border-slate-700'
                                            }`}
                                        >
                                            {isSelected && <CheckCircle2 className="size-2 stroke-[3]" />}
                                        </div>
                                    </div>

                                    <div>
                                        <h4 className="text-xs font-bold text-slate-900 dark:text-white">
                                            {app.name}
                                        </h4>
                                        <p className="text-[10px] text-slate-500 dark:text-slate-400 line-clamp-2 mt-0.5">
                                            {app.description || 'Sistem manajemen operasional terpadu.'}
                                        </p>
                                    </div>
                                </div>
                            );
                        })}
                    </div>

                    {form.errors.app_ids && (
                        <p className="text-xs font-medium text-red-500 mt-0.5">
                            {form.errors.app_ids}
                        </p>
                    )}

                    <div className="pt-1 flex items-center justify-between gap-2.5">
                        <Button
                            type="button"
                            onClick={() => setStep('business')}
                            className="h-10 px-4 rounded-full btn-outline-turquoise cursor-pointer gap-2 text-xs font-bold"
                        >
                            <ArrowLeft className="size-3.5" />
                            <span>Kembali</span>
                        </Button>
                        <Button
                            type="button"
                            disabled={form.data.app_ids.length === 0}
                            onClick={handleNextToSecurity}
                            className="h-10 px-5 rounded-full btn-gradient-primary cursor-pointer active:scale-[0.99] gap-2 text-xs font-bold flex-1 flex items-center justify-center border-none shadow-md shadow-cyan-600/25"
                        >
                            <span>Lanjutkan ke Keamanan</span>
                            <ArrowRight className="size-3.5" />
                        </Button>
                    </div>
                </TabsContent>

                {/* STEP 3: SECURITY & CONFIRMATION */}
                <TabsContent value="security" className="space-y-3 focus:outline-none">
                    <form onSubmit={submit} className="space-y-3" noValidate>
                        <div className="space-y-0.5 border-b border-slate-100 dark:border-slate-800 pb-1.5">
                            <h3 className="text-xs sm:text-sm font-bold text-slate-900 dark:text-white flex items-center gap-1.5">
                                <ShieldCheck className="size-3.5 text-[#00AFC0]" />
                                Keamanan &amp; Kata Sandi
                            </h3>
                            <p className="text-[10.5px] text-slate-500 dark:text-slate-400 leading-snug">
                                Buat kata sandi aman untuk akun pemilik tenant bisnis.
                            </p>
                        </div>

                        <div className="space-y-2.5">
                            {isExistingEmail ? (
                                <Alert className="bg-amber-50/70 dark:bg-amber-950/40 border-amber-200 dark:border-amber-800 py-1.5">
                                    <CheckCircle2 className="size-3.5 text-amber-600 dark:text-amber-400" />
                                    <AlertTitle className="text-xs font-semibold text-amber-950 dark:text-amber-200">
                                        Akun Terdaftar Ditemukan ({form.data.email})
                                    </AlertTitle>
                                    <AlertDescription className="text-[10.5px] text-amber-800 dark:text-amber-300">
                                        Email ini sudah terdaftar. Bisnis <strong>{form.data.business_name}</strong> akan ditambahkan ke akun Anda yang ada. Masukkan kata sandi akun Anda untuk mengonfirmasi.
                                    </AlertDescription>
                                </Alert>
                            ) : (
                                <Alert className="bg-[#EAFBFC]/80 dark:bg-cyan-950/40 border-[#00AFC0]/30 py-1.5">
                                    <Building2 className="size-3.5 text-[#00AFC0]" />
                                    <AlertTitle className="text-xs font-semibold text-cyan-950 dark:text-cyan-200">
                                        Perusahaan: {form.data.business_name}
                                    </AlertTitle>
                                    <AlertDescription className="text-[10.5px] text-cyan-800 dark:text-cyan-300">
                                        {form.data.app_ids.length} modul aplikasi terpilih. Tenant dan database akan disiapkan secara otomatis setelah pendaftaran selesai.
                                    </AlertDescription>
                                </Alert>
                            )}

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
                                onClick={() => setStep('products')}
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
                Sudah memiliki akun bisnis?{' '}
                <TextLink href={login()} className="font-semibold text-[#00AFC0] hover:text-[#008B9B] hover:underline">
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

