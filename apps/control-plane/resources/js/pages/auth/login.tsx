import { useState } from 'react';
import { useForm, Head, Link } from '@inertiajs/react';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import { Button } from '@apperp/ui/button';
import { Checkbox } from '@apperp/ui/checkbox';
import { Input } from '@apperp/ui/input';
import { Label } from '@apperp/ui/label';
import { Spinner } from '@apperp/ui/spinner';
import { register } from '@/routes';
import { ArrowRight, Building2, Lock, Mail } from 'lucide-react';

type Props = {
    status?: string;
    canResetPassword: boolean;
};

// Standard email validation regex requiring valid format (name@domain.ext)
const EMAIL_REGEX = /^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/;

export default function Login({ status, canResetPassword }: Props) {
    const { data, setData, post, processing, errors, clearErrors } = useForm({
        email: '',
        password: '',
        remember: false,
    });

    const [clientEmailError, setClientEmailError] = useState<string | undefined>(undefined);
    const [clientPasswordError, setClientPasswordError] = useState<string | undefined>(undefined);

    const validateEmailField = (emailValue: string): string | undefined => {
        const trimmed = emailValue.trim();
        if (!trimmed) {
            return 'Email wajib diisi.';
        }
        if (!EMAIL_REGEX.test(trimmed)) {
            return 'Format email tidak valid.';
        }
        return undefined;
    };

    const validatePasswordField = (passwordValue: string): string | undefined => {
        if (!passwordValue) {
            return 'Password wajib diisi.';
        }
        if (passwordValue.length < 8) {
            return 'Password minimal 8 karakter.';
        }
        return undefined;
    };

    const handleEmailBlur = () => {
        if (data.email.trim() !== '') {
            if (!EMAIL_REGEX.test(data.email.trim())) {
                setClientEmailError('Format email tidak valid.');
            }
        }
    };

    const handlePasswordBlur = () => {
        if (data.password !== '' && data.password.length < 8) {
            setClientPasswordError('Password minimal 8 karakter.');
        }
    };

    const handleEmailChange = (rawVal: string) => {
        const normalized = rawVal.trimStart();
        setData((prev) => {
            const savedRemember = localStorage.getItem('erp_remember_me') === 'true';
            const savedEmail = localStorage.getItem('erp_remember_email') || '';
            const savedPassword = localStorage.getItem('erp_remember_password') || '';

            if (savedRemember && savedEmail && normalized.trim().toLowerCase() === savedEmail.trim().toLowerCase()) {
                return {
                    ...prev,
                    email: normalized.trim().toLowerCase(),
                    password: savedPassword,
                    remember: true,
                };
            }

            return {
                ...prev,
                email: normalized,
                password: prev.password === savedPassword ? '' : prev.password,
            };
        });

        if (clientEmailError) setClientEmailError(undefined);
        if (errors.email) clearErrors('email');
    };

    const handlePasswordChange = (val: string) => {
        setData('password', val);
        if (clientPasswordError) setClientPasswordError(undefined);
        if (errors.password) clearErrors('password');
    };

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();

        const emailErr = validateEmailField(data.email);
        const passErr = validatePasswordField(data.password);

        setClientEmailError(emailErr);
        setClientPasswordError(passErr);

        if (emailErr || passErr) {
            return;
        }

        const normalizedEmail = data.email.trim().toLowerCase();
        data.email = normalizedEmail;

        if (data.remember) {
            localStorage.setItem('erp_remember_me', 'true');
            localStorage.setItem('erp_remember_email', normalizedEmail);
            localStorage.setItem('erp_remember_password', data.password);
        } else {
            localStorage.removeItem('erp_remember_me');
            localStorage.removeItem('erp_remember_email');
            localStorage.removeItem('erp_remember_password');
        }

        post('/login');
    };

    const emailDisplayError = errors.email || clientEmailError;
    const passwordDisplayError = errors.password || clientPasswordError;

    return (
        <>
            <Head title="Masuk Akun" />

            {status && (
                <div className="p-3.5 rounded-2xl bg-emerald-50 dark:bg-emerald-950/40 border border-emerald-200 dark:border-emerald-800 text-center text-xs font-semibold text-emerald-700 dark:text-emerald-300">
                    {status}
                </div>
            )}

            <form onSubmit={handleSubmit} className="space-y-4" noValidate>
                <div className="space-y-3.5">
                    {/* Alamat Email Input with Icon */}
                    <div className="space-y-1.5">
                        <Label htmlFor="email" className="text-xs font-bold text-slate-900 dark:text-slate-100">
                            Alamat Email
                        </Label>
                        <div className="relative">
                            <div className="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-slate-400">
                                <Mail className="size-4" />
                            </div>
                            <Input
                                id="email"
                                type="email"
                                name="email"
                                value={data.email}
                                onChange={(e) => handleEmailChange(e.target.value)}
                                onBlur={handleEmailBlur}
                                aria-invalid={Boolean(emailDisplayError)}
                                required
                                autoFocus
                                tabIndex={1}
                                autoComplete="email"
                                placeholder="contoh@email.com"
                                className="pl-10 h-11 rounded-xl border-slate-200 focus:border-[#00AFC0] focus:ring-2 focus:ring-[#00AFC0]/20 transition-all text-xs"
                            />
                        </div>
                        <InputError message={emailDisplayError} />
                    </div>

                    {/* Kata Sandi Input with Icon */}
                    <div className="space-y-1.5">
                        <div className="flex items-center justify-between">
                            <Label htmlFor="password" className="text-xs font-bold text-slate-900 dark:text-slate-100">
                                Kata Sandi
                            </Label>
                            {canResetPassword && (
                                <Link
                                    href={data.email.trim() ? `/forgot-password?email=${encodeURIComponent(data.email.trim())}` : '/forgot-password'}
                                    className="text-xs text-[#00AFC0] hover:text-[#008B9B] font-semibold cursor-pointer transition-colors"
                                    tabIndex={5}
                                >
                                    Lupa kata sandi?
                                </Link>
                            )}
                        </div>
                        <div className="relative">
                            <PasswordInput
                                id="password"
                                name="password"
                                value={data.password}
                                onChange={(e) => handlePasswordChange(e.target.value)}
                                onBlur={handlePasswordBlur}
                                aria-invalid={Boolean(passwordDisplayError)}
                                required
                                tabIndex={2}
                                autoComplete="current-password"
                                placeholder="Masukkan kata sandi"
                                className="h-11 rounded-xl border-slate-200 focus:border-[#08BFC3] focus:ring-2 focus:ring-[#08BFC3]/20 transition-all text-xs"
                            />
                        </div>
                        <InputError message={passwordDisplayError} />
                    </div>

                    {/* Checkbox Ingat Saya */}
                    <div className="flex items-center space-x-2.5 pt-0.5">
                        <Checkbox
                            id="remember"
                            name="remember"
                            checked={data.remember}
                            onCheckedChange={(checked) => setData('remember', checked === true)}
                            tabIndex={3}
                            className="rounded-md border-slate-300 data-[state=checked]:bg-[#08BFC3] data-[state=checked]:border-[#08BFC3]"
                        />
                        <Label
                            htmlFor="remember"
                            className="text-xs font-medium text-slate-600 dark:text-slate-400 cursor-pointer select-none"
                        >
                            Ingat saya di perangkat ini
                        </Label>
                    </div>

                    {/* Primary Button: Masuk ke Akun */}
                    <Button
                        type="submit"
                        className="w-full h-11 sm:h-12 rounded-full btn-gradient-primary text-white font-extrabold text-xs sm:text-sm cursor-pointer active:scale-[0.99] mt-2 flex items-center justify-center gap-2 transition-all border-none"
                        tabIndex={4}
                        disabled={processing}
                        data-test="login-button"
                    >
                        {processing ? (
                            <>
                                <Spinner className="mr-2 text-white" />
                                Memproses...
                            </>
                        ) : (
                            <>
                                <ArrowRight className="size-4 text-white" />
                                <span>Masuk ke Akun</span>
                            </>
                        )}
                    </Button>
                </div>

                {/* Divider 'atau' */}
                <div className="relative flex items-center justify-center my-3.5">
                    <div className="border-t border-slate-200/80 dark:border-slate-800 w-full" />
                    <span className="bg-white/95 dark:bg-[#071527] px-3 text-[11px] font-semibold text-slate-400 dark:text-slate-500 rounded-full shrink-0 absolute">
                        atau
                    </span>
                </div>

                {/* Secondary Button: Daftar Bisnis Baru */}
                <Link href={register()} className="block">
                    <Button
                        type="button"
                        className="w-full h-11 sm:h-12 rounded-full border-2 border-[#08BFC3] dark:border-cyan-500/70 text-[#007C89] dark:text-cyan-300 hover:bg-[#C8F1F5]/60 dark:hover:bg-cyan-950/70 dark:hover:border-cyan-400 dark:hover:text-cyan-200 font-extrabold text-xs sm:text-sm bg-white dark:bg-slate-800/90 cursor-pointer active:scale-[0.99] flex items-center justify-center gap-2 transition-all shadow-xs"
                    >
                        <Building2 className="size-4 text-[#007C89] dark:text-cyan-400" />
                        <span>Daftar Bisnis Baru</span>
                    </Button>
                </Link>
            </form>
        </>
    );
}

Login.layout = {
    title: 'Selamat Datang!',
    description: 'Masukkan email dan kata sandi untuk mengakses platform operasional ERP.',
};
