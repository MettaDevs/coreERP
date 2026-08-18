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
    FieldLegend,
    FieldSet,
} from '@apperp/ui/field';
import { Input } from '@apperp/ui/input';
import { Spinner } from '@apperp/ui/spinner';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@apperp/ui/tabs';

type AppOption = { id: string; name: string; description: string };
type Props = { passwordRules: string; apps: AppOption[] };
type Step = 'business' | 'products' | 'security';

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

    const businessComplete =
        form.data.name.trim() !== '' &&
        form.data.business_name.trim() !== '' &&
        form.data.email.trim() !== '';
    const productsComplete = form.data.app_ids.length > 0;

    const toggleApp = (id: string) => {
        const current = form.data.app_ids;
        if (current.includes(id)) {
            form.setData('app_ids', current.filter((item) => item !== id));
        } else {
            form.setData('app_ids', [...current, id]);
        }
    };

    const submit = () => {
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
                        disabled={!businessComplete}
                        className="rounded-lg text-xs font-semibold gap-1.5 data-[state=active]:bg-white dark:data-[state=active]:bg-slate-900 data-[state=active]:shadow-sm"
                    >
                        <Package className="size-3.5" />
                        <span className="hidden sm:inline">2. Produk</span>
                        <span className="sm:hidden">2</span>
                    </TabsTrigger>
                    <TabsTrigger
                        value="security"
                        disabled={!businessComplete || !productsComplete}
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
                        <Field data-invalid={Boolean(form.errors.name)}>
                            <Input
                                label="Nama Pemilik Akun"
                                value={form.data.name}
                                onChange={(event) => form.setData('name', event.target.value)}
                                aria-invalid={Boolean(form.errors.name)}
                                autoComplete="name"
                                autoFocus
                                placeholder="Nama lengkap Anda"
                            />
                            <FieldError>{form.errors.name}</FieldError>
                        </Field>

                        <Field data-invalid={Boolean(form.errors.business_name)}>
                            <Input
                                label="Nama Bisnis / Perusahaan"
                                value={form.data.business_name}
                                onChange={(event) => form.setData('business_name', event.target.value)}
                                aria-invalid={Boolean(form.errors.business_name)}
                                autoComplete="organization"
                                placeholder="Contoh: PT. Sanata System"
                            />
                            <FieldError>{form.errors.business_name}</FieldError>
                        </Field>

                        <Field data-invalid={Boolean(form.errors.email)}>
                            <Input
                                label="Alamat Email Utama"
                                type="email"
                                value={form.data.email}
                                onChange={(event) => form.setData('email', event.target.value)}
                                aria-invalid={Boolean(form.errors.email)}
                                autoComplete="email"
                                placeholder="pemilik@perusahaan.com"
                            />
                            <FieldError>{form.errors.email}</FieldError>
                        </Field>
                    </FieldGroup>

                    <div className="pt-4 flex justify-end">
                        <Button
                            type="button"
                            disabled={!businessComplete}
                            onClick={() => setStep('products')}
                            className="h-10 px-5 bg-indigo-600 hover:bg-indigo-500 text-white font-medium rounded-xl shadow-md transition-all gap-2 text-xs"
                        >
                            Lanjutkan ke Produk
                            <ArrowRight className="size-3.5" />
                        </Button>
                    </div>
                </TabsContent>

                {/* STEP 2: PRODUCT SELECTION */}
                <TabsContent value="products" className="space-y-5 focus:outline-none">
                    <div className="space-y-1 border-b border-slate-100 dark:border-slate-800 pb-3">
                        <h3 className="text-base font-semibold text-slate-900 dark:text-white flex items-center gap-2">
                            <Package className="size-4 text-indigo-500" />
                            Pilih Produk Awal
                        </h3>
                        <p className="text-xs text-slate-500 dark:text-slate-400">
                            Pilih modul aplikasi yang ingin diaktifkan untuk tenant bisnis Anda.
                        </p>
                    </div>

                    <FieldSet data-invalid={Boolean(form.errors.app_ids)} className="space-y-3">
                        <FieldLegend hint="Pilih minimal 1 aplikasi untuk melanjutkan pendaftaran.">
                            Aplikasi Terinstal
                        </FieldLegend>

                        <div className="grid gap-3 sm:grid-cols-1">
                            {apps.map((app) => {
                                const selected = form.data.app_ids.includes(app.id);
                                return (
                                    <div
                                        key={app.id}
                                        onClick={() => toggleApp(app.id)}
                                        className={`p-4 rounded-xl border transition-all cursor-pointer flex items-start gap-3.5 ${
                                            selected
                                                ? 'bg-indigo-50/70 dark:bg-indigo-950/40 border-indigo-500 dark:border-indigo-600 ring-2 ring-indigo-500/20 shadow-md'
                                                : 'bg-white dark:bg-slate-900/60 border-slate-200 dark:border-slate-800 hover:border-slate-300 dark:hover:border-slate-700'
                                        }`}
                                    >
                                        <div className={`p-2.5 rounded-lg shrink-0 ${selected ? 'bg-indigo-600 text-white' : 'bg-slate-100 dark:bg-slate-800 text-slate-500'}`}>
                                            <Package className="size-4" />
                                        </div>
                                        <div className="flex-1 space-y-1">
                                            <div className="flex items-center justify-between">
                                                <h4 className="text-sm font-semibold text-slate-900 dark:text-white">
                                                    {app.name}
                                                </h4>
                                                {selected && (
                                                    <span className="flex items-center gap-1 text-xs font-medium text-indigo-600 dark:text-indigo-400 bg-indigo-100 dark:bg-indigo-900/50 px-2 py-0.5 rounded-full">
                                                        <CheckCircle2 className="size-3" />
                                                        Terpilih
                                                    </span>
                                                )}
                                            </div>
                                            <p className="text-xs text-slate-500 dark:text-slate-400 leading-relaxed">
                                                {app.description}
                                            </p>
                                        </div>
                                    </div>
                                );
                            })}
                        </div>
                        <FieldError>{form.errors.app_ids}</FieldError>
                    </FieldSet>

                    <div className="pt-4 flex items-center justify-between">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => setStep('business')}
                            className="h-10 text-xs rounded-xl gap-2"
                        >
                            <ArrowLeft className="size-3.5" />
                            Kembali
                        </Button>
                        <Button
                            type="button"
                            disabled={!productsComplete}
                            onClick={() => setStep('security')}
                            className="h-10 px-5 bg-indigo-600 hover:bg-indigo-500 text-white font-medium rounded-xl shadow-md transition-all gap-2 text-xs"
                        >
                            Lanjutkan ke Keamanan
                            <ArrowRight className="size-3.5" />
                        </Button>
                    </div>
                </TabsContent>

                {/* STEP 3: SECURITY & CONFIRMATION */}
                <TabsContent value="security" className="space-y-5 focus:outline-none">
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

                        <Field data-invalid={Boolean(form.errors.password)}>
                            <PasswordInput
                                label="Kata Sandi Utama"
                                value={form.data.password}
                                onChange={(event) => form.setData('password', event.target.value)}
                                aria-invalid={Boolean(form.errors.password)}
                                autoComplete="new-password"
                                passwordrules={passwordRules}
                                placeholder="••••••••"
                            />
                            <FieldError>{form.errors.password}</FieldError>
                        </Field>

                        <Field data-invalid={Boolean(form.errors.password_confirmation)}>
                            <PasswordInput
                                label="Konfirmasi Kata Sandi"
                                value={form.data.password_confirmation}
                                onChange={(event) => form.setData('password_confirmation', event.target.value)}
                                aria-invalid={Boolean(form.errors.password_confirmation)}
                                autoComplete="new-password"
                                passwordrules={passwordRules}
                                placeholder="••••••••"
                            />
                            <FieldError>{form.errors.password_confirmation}</FieldError>
                        </Field>
                    </FieldGroup>

                    <div className="pt-4 flex items-center justify-between">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => setStep('products')}
                            className="h-10 text-xs rounded-xl gap-2"
                        >
                            <ArrowLeft className="size-3.5" />
                            Kembali
                        </Button>
                        <Button
                            type="button"
                            disabled={
                                form.processing ||
                                form.data.password === '' ||
                                form.data.password_confirmation === ''
                            }
                            onClick={submit}
                            className="h-11 px-6 bg-gradient-to-r from-indigo-600 to-blue-600 hover:from-indigo-500 hover:to-blue-500 text-white font-semibold rounded-xl shadow-lg shadow-indigo-500/25 transition-all text-xs gap-2"
                        >
                            {form.processing ? <Spinner /> : <KeyRound className="size-4" />}
                            Aktifkan & Buat Tenant
                        </Button>
                    </div>
                </TabsContent>
            </Tabs>

            <div className="pt-2 text-center text-xs text-slate-500 dark:text-slate-400 space-y-1.5 border-t border-slate-100 dark:border-slate-800">
                <p>
                    Punya kode akses dari admin?{' '}
                    <TextLink href="/join" className="font-semibold text-indigo-600 dark:text-indigo-400 hover:underline">
                        Daftar sebagai Anggota
                    </TextLink>
                </p>
                <p>
                    Sudah memiliki akun?{' '}
                    <TextLink href="/login" className="font-medium text-slate-700 dark:text-slate-300 hover:underline">
                        Masuk ke Akun
                    </TextLink>
                </p>
            </div>
        </>
    );
}

Register.layout = {
    title: 'Pendaftaran Bisnis Baru',
    description: 'Isi data pemilik akun dan pilih produk awal untuk bisnis Anda.',
};
