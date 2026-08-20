import { useEffect, useState } from 'react';
import { useForm, Head, Link } from '@inertiajs/react';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import { Button } from '@apperp/ui/button';
import { Checkbox } from '@apperp/ui/checkbox';
import { Input } from '@apperp/ui/input';
import { Label } from '@apperp/ui/label';
import { Spinner } from '@apperp/ui/spinner';
import { register } from '@/routes';
import { ArrowRight, Building2, Mail } from 'lucide-react';

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

    const [clientEmailError, setClientEmailError] = useState<
        string | undefined
    >(undefined);
    const [clientPasswordError, setClientPasswordError] = useState<
        string | undefined
    >(undefined);

    useEffect(() => {
        try {
            const isRemembered =
                localStorage.getItem('erp_remember_me') === 'true';
            const savedEmail = localStorage.getItem('erp_remember_email') || '';
            if (isRemembered && savedEmail) {
                setData((prev) => ({
                    ...prev,
                    email: savedEmail,
                    remember: true,
                }));
            }
        } catch {
            // Ignore localStorage access restrictions
        }
    }, []);

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

    const validatePasswordField = (
        passwordValue: string,
    ): string | undefined => {
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
        setData('email', normalized);

        if (clientEmailError) setClientEmailError(undefined);
        if (errors.email) clearErrors('email');
    };

    const handlePasswordChange = (val: string) => {
        setData('password', val);
        if (clientPasswordError) setClientPasswordError(undefined);
        if (errors.password) clearErrors('password');
    };

    const handleRememberChange = (checked: boolean) => {
        setData('remember', checked);
        if (!checked) {
            try {
                localStorage.removeItem('erp_remember_me');
                localStorage.removeItem('erp_remember_email');
            } catch {
                // Ignore
            }
        } else {
            try {
                localStorage.setItem('erp_remember_me', 'true');
                if (data.email) {
                    localStorage.setItem(
                        'erp_remember_email',
                        data.email.trim().toLowerCase(),
                    );
                }
            } catch {
                // Ignore
            }
        }
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
            try {
                localStorage.setItem('erp_remember_me', 'true');
                localStorage.setItem('erp_remember_email', normalizedEmail);
            } catch {
                // Ignore
            }
        } else {
            try {
                localStorage.removeItem('erp_remember_me');
                localStorage.removeItem('erp_remember_email');
            } catch {
                // Ignore
            }
        }

        post('/login');
    };

    const emailDisplayError = errors.email || clientEmailError;
    const passwordDisplayError = errors.password || clientPasswordError;

    return (
        <>
            <Head title="Masuk Akun" />

            {status && (
                <div className="rounded-2xl border border-emerald-200 bg-emerald-50 p-3.5 text-center text-xs font-semibold text-emerald-700 dark:border-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-300">
                    {status}
                </div>
            )}

            <form onSubmit={handleSubmit} className="space-y-4" noValidate>
                <div className="space-y-3.5">
                    {/* Alamat Email Input with Icon */}
                    <div className="space-y-1.5">
                        <Label
                            htmlFor="email"
                            className="text-xs font-bold text-slate-900 dark:text-slate-100"
                        >
                            Alamat Email
                        </Label>
                        <div className="relative">
                            <div className="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3.5 text-slate-400">
                                <Mail className="size-4" />
                            </div>
                            <Input
                                id="email"
                                type="email"
                                name="email"
                                value={data.email}
                                onChange={(e) =>
                                    handleEmailChange(e.target.value)
                                }
                                onBlur={handleEmailBlur}
                                aria-invalid={Boolean(emailDisplayError)}
                                required
                                autoFocus
                                tabIndex={1}
                                autoComplete="email"
                                placeholder="contoh@email.com"
                                className="h-11 rounded-xl border-slate-200 pl-10 text-xs transition-all focus:border-[#00AFC0] focus:ring-2 focus:ring-[#00AFC0]/20"
                            />
                        </div>
                        <InputError message={emailDisplayError} />
                    </div>

                    {/* Kata Sandi Input with Icon */}
                    <div className="space-y-1.5">
                        <div className="flex items-center justify-between">
                            <Label
                                htmlFor="password"
                                className="text-xs font-bold text-slate-900 dark:text-slate-100"
                            >
                                Kata Sandi
                            </Label>
                            {canResetPassword && (
                                <Link
                                    href={
                                        data.email.trim()
                                            ? `/forgot-password?email=${encodeURIComponent(data.email.trim())}`
                                            : '/forgot-password'
                                    }
                                    className="cursor-pointer text-xs font-semibold text-[#00AFC0] transition-colors hover:text-[#008B9B]"
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
                                onChange={(e) =>
                                    handlePasswordChange(e.target.value)
                                }
                                onBlur={handlePasswordBlur}
                                aria-invalid={Boolean(passwordDisplayError)}
                                required
                                tabIndex={2}
                                autoComplete="current-password"
                                placeholder="Masukkan kata sandi"
                                className="h-11 rounded-xl border-slate-200 text-xs transition-all focus:border-[#08BFC3] focus:ring-2 focus:ring-[#08BFC3]/20"
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
                            onCheckedChange={(checked) =>
                                handleRememberChange(checked === true)
                            }
                            tabIndex={3}
                            className="size-4 cursor-pointer rounded-md border-slate-300 data-[state=checked]:border-[#08BFC3] data-[state=checked]:bg-[#08BFC3] data-[state=checked]:text-white"
                        />
                        <Label
                            htmlFor="remember"
                            className="cursor-pointer text-xs font-medium text-slate-600 select-none dark:text-slate-400"
                        >
                            Ingat saya di perangkat ini
                        </Label>
                    </div>

                    {/* Primary Button: Masuk ke Akun */}
                    <Button
                        type="submit"
                        className="btn-gradient-primary mt-2 flex h-11 w-full cursor-pointer items-center justify-center gap-2 rounded-full border-none text-xs font-extrabold text-white transition-all active:scale-[0.99] sm:h-12 sm:text-sm"
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
                <div className="relative my-3.5 flex items-center justify-center">
                    <div className="w-full border-t border-slate-200/80 dark:border-slate-800" />
                    <span className="absolute shrink-0 rounded-full bg-white/95 px-3 text-[11px] font-semibold text-slate-400 dark:bg-[#071527] dark:text-slate-500">
                        atau
                    </span>
                </div>

                {/* Secondary Button: Daftar Bisnis Baru */}
                <Link href={register()} className="block">
                    <Button
                        type="button"
                        className="flex h-11 w-full cursor-pointer items-center justify-center gap-2 rounded-full border-2 border-[#08BFC3] bg-white text-xs font-extrabold text-[#007C89] shadow-xs transition-all hover:bg-[#C8F1F5]/60 active:scale-[0.99] sm:h-12 sm:text-sm dark:border-cyan-500/70 dark:bg-slate-800/90 dark:text-cyan-300 dark:hover:border-cyan-400 dark:hover:bg-cyan-950/70 dark:hover:text-cyan-200"
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
    description:
        'Masukkan email dan kata sandi untuk mengakses platform operasional ERP.',
};
