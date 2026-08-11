import { useState } from 'react';
import { useForm, Head } from '@inertiajs/react';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import TextLink from '@/components/text-link';
import { Button } from '@apperp/ui/button';
import { Checkbox } from '@apperp/ui/checkbox';
import { Input } from '@apperp/ui/input';
import { Label } from '@apperp/ui/label';
import { Spinner } from '@apperp/ui/spinner';
import { register } from '@/routes';
import { request } from '@/routes/password';
import { LogIn, ArrowLeft } from 'lucide-react';

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

    const [isCheckingForgotPassword, setIsCheckingForgotPassword] = useState(false);

    const handleForgotPasswordClick = async (e: React.MouseEvent) => {
        e.preventDefault();
        const trimmedEmail = data.email.trim();

        if (!trimmedEmail) {
            setClientEmailError('Email wajib diisi untuk mereset kata sandi.');
            return;
        }

        if (!EMAIL_REGEX.test(trimmedEmail)) {
            setClientEmailError('Format email tidak valid.');
            return;
        }

        setIsCheckingForgotPassword(true);
        try {
            const response = await fetch('/check-email', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content || '',
                },
                body: JSON.stringify({ email: trimmedEmail }),
            });

            const result = await response.json();
            if (!result.exists) {
                setClientEmailError('Email tidak ditemukan.');
                setIsCheckingForgotPassword(false);
                return;
            }

            window.location.href = `/forgot-password?email=${encodeURIComponent(trimmedEmail)}`;
        } catch (err) {
            console.error('Error checking forgot password email:', err);
            setIsCheckingForgotPassword(false);
        }
    };

    const emailDisplayError = errors.email || clientEmailError;
    const passwordDisplayError = errors.password || clientPasswordError;

    return (
        <>
            <Head title="Masuk Akun" />

            {status && (
                <div className="p-3.5 rounded-xl bg-emerald-50 dark:bg-emerald-950/40 border border-emerald-200 dark:border-emerald-800 text-center text-xs font-medium text-emerald-700 dark:text-emerald-300">
                    {status}
                </div>
            )}

            <form onSubmit={handleSubmit} className="space-y-5" noValidate>
                <div className="space-y-4">
                    <div className="space-y-1.5">
                        <Input
                            id="email"
                            label="Alamat Email"
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
                            placeholder="nama@perusahaan.com"
                        />
                        <InputError message={emailDisplayError} />
                    </div>

                    <div className="space-y-1.5">
                        <Label htmlFor="password">Kata Sandi</Label>
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
                            placeholder="••••••••"
                        />
                        <InputError message={passwordDisplayError} />
                        {canResetPassword && (
                            <div className="text-right pt-1">
                                <button
                                    type="button"
                                    onClick={handleForgotPasswordClick}
                                    disabled={isCheckingForgotPassword}
                                    className="text-xs text-indigo-600 dark:text-indigo-400 hover:underline font-medium cursor-pointer disabled:opacity-50"
                                    tabIndex={5}
                                >
                                    {isCheckingForgotPassword ? 'Memeriksa email...' : 'Lupa kata sandi?'}
                                </button>
                            </div>
                        )}
                    </div>

                    <div className="flex items-center justify-between pt-1">
                        <div className="flex items-center space-x-2.5">
                            <Checkbox
                                id="remember"
                                name="remember"
                                checked={data.remember}
                                onCheckedChange={(checked) => setData('remember', checked === true)}
                                tabIndex={3}
                            />
                            <Label
                                htmlFor="remember"
                                className="text-xs font-normal text-slate-600 dark:text-slate-400 cursor-pointer"
                            >
                                Ingat saya di perangkat ini
                            </Label>
                        </div>
                    </div>

                    <Button
                        type="submit"
                        className="w-full h-11 bg-gradient-to-r from-indigo-600 to-blue-600 hover:from-indigo-500 hover:to-blue-500 text-white font-semibold rounded-xl shadow-lg shadow-indigo-500/25 hover:shadow-indigo-500/40 transition-all active:scale-[0.99] mt-2"
                        tabIndex={4}
                        disabled={processing}
                        data-test="login-button"
                    >
                        {processing ? (
                            <>
                                <Spinner className="mr-2" />
                                Memproses...
                            </>
                        ) : (
                            <>
                                <LogIn className="size-4 mr-2" />
                                Masuk ke Akun
                            </>
                        )}
                    </Button>
                </div>

                {/* Navigation Links */}
                <div className="pt-2 text-center text-xs text-slate-500 dark:text-slate-400 space-y-3">
                    <p>
                        Belum memiliki akun bisnis?{' '}
                        <TextLink href={register()} className="font-semibold text-indigo-600 dark:text-indigo-400 hover:underline" tabIndex={5}>
                            Daftar Bisnis Baru
                        </TextLink>
                    </p>
                    <div className="pt-2 border-t border-slate-100 dark:border-slate-800">
                        <TextLink
                            href="/"
                            className="inline-flex items-center gap-1.5 text-xs text-slate-500 hover:text-indigo-600 dark:hover:text-indigo-400 font-medium transition-colors"
                        >
                            <ArrowLeft className="size-3.5" />
                            Kembali ke Beranda Utama
                        </TextLink>
                    </div>
                </div>
            </form>
        </>
    );
}

Login.layout = {
    title: 'Masuk ke Akun Anda',
    description: 'Masukkan email dan kata sandi untuk mengakses platform operasional ERP.',
};
