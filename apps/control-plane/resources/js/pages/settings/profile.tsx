import { router, Form, Head, Link, usePage } from '@inertiajs/react';
import { useState, useRef } from 'react';
import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
import DeleteUser from '@/components/delete-user';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import { Button } from '@apperp/ui/button';
import { Input } from '@apperp/ui/input';
import { edit } from '@/routes/profile';
import type { Auth } from '@/types';
import {
    User,
    UserCheck,
    Loader2,
    CheckCircle2,
    Sparkles,
    Building2,
    MailCheck,
    AlertCircle,
    ArrowRight,
    Camera,
    X,
    Lock,
    Edit3,
    Phone,
    Clock,
} from 'lucide-react';
import { cn } from '@/lib/utils';
import PhoneInput from '@/components/phone-input';

/* @chisel-email-verification */
import { send } from '@/routes/verification';
/* @end-chisel-email-verification */

type PageProps = {
    auth: Auth;
};

export default function Profile(
    /* @chisel-email-verification */
    {
        mustVerifyEmail,
        status,
    }: {
        mustVerifyEmail: boolean;
        status?: string;
    },
    /* @end-chisel-email-verification */
) {
    const { auth } = usePage<PageProps>().props;
    const [toastMessage, setToastMessage] = useState<string | null>(null);
    const [showNameModal, setShowNameModal] = useState(false);
    const [showEmailModal, setShowEmailModal] = useState(false);

    // Phone Modal State
    const [showPhoneModal, setShowPhoneModal] = useState(false);
    const [phoneVal, setPhoneVal] = useState((auth.user as any).phone_number || '');
    const [phonePassword, setPhonePassword] = useState('');
    const [phoneError, setPhoneError] = useState<string | undefined>(undefined);
    const [phonePasswordError, setPhonePasswordError] = useState<string | undefined>(undefined);
    const [isSubmittingPhone, setIsSubmittingPhone] = useState(false);

    const handlePhoneSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        setPhoneError(undefined);
        setPhonePasswordError(undefined);

        if (!phonePassword) {
            setPhonePasswordError('Kata sandi wajib diisi untuk mengonfirmasi pengubahan nomor telepon.');
            return;
        }

        setIsSubmittingPhone(true);
        router.patch(
            '/settings/security/phone',
            {
                phone_number: phoneVal,
                current_password: phonePassword,
            },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setIsSubmittingPhone(false);
                    setShowPhoneModal(false);
                    setPhonePassword('');
                    showToast('Nomor telepon berhasil diperbarui.');
                },
                onError: (errs) => {
                    setIsSubmittingPhone(false);
                    if (errs.current_password) setPhonePasswordError(errs.current_password);
                    if (errs.phone_number) setPhoneError(errs.phone_number);
                },
            },
        );
    };

    const showToast = (msg: string) => {
        setToastMessage(msg);
        setTimeout(() => setToastMessage(null), 3500);
    };

    // User initials helper
    const userInitials = auth.user.name
        ? auth.user.name
              .split(' ')
              .map((n) => n[0])
              .join('')
              .toUpperCase()
              .slice(0, 2)
        : 'U';

    const formatRole = (role?: string) => {
        if (!role) return 'Owner';
        const trimmed = role.trim();
        if (trimmed.toLowerCase() === 'owner') return 'Owner';
        return trimmed.charAt(0).toUpperCase() + trimmed.slice(1).toLowerCase();
    };

    const currentTenantName = auth.membership?.tenant_name || 'PT Sanata System';
    const currentSystemRole = formatRole(auth.membership?.system_role);

    const fileInputRef = useRef<HTMLInputElement>(null);
    const [avatarUrl, setAvatarUrl] = useState<string | null>(null);

    const activeAvatar = avatarUrl || (auth.user as any).avatar_url;

    const handleAvatarChange = (e: React.ChangeEvent<HTMLInputElement>) => {
        const file = e.target.files?.[0];
        if (file) {
            const formData = new FormData();
            formData.append('avatar', file);

            const localPreview = URL.createObjectURL(file);
            setAvatarUrl(localPreview);

            router.post('/settings/profile/avatar', formData, {
                preserveScroll: true,
                onSuccess: () => {
                    showToast('Foto profil berhasil diperbarui & disimpan di database.');
                },
                onError: () => {
                    const reader = new FileReader();
                    reader.onloadend = () => {
                        const base64Url = reader.result as string;
                        setAvatarUrl(base64Url);
                        router.post(
                            '/settings/profile/avatar',
                            { avatar_url: base64Url },
                            {
                                preserveScroll: true,
                                onSuccess: () => {
                                    showToast('Foto profil berhasil diperbarui & disimpan di database.');
                                },
                            },
                        );
                    };
                    reader.readAsDataURL(file);
                },
            });
        }
    };

    return (
        <>
            <Head title="Profil Settings" />

            {/* Hidden File Input for Avatar */}
            <input
                type="file"
                ref={fileInputRef}
                onChange={handleAvatarChange}
                accept="image/*"
                className="hidden"
            />

            {/* --- FLOATING TOAST --- */}
            {toastMessage && (
                <div className="fixed bottom-6 right-6 z-50 flex items-center gap-3 px-4 py-3 bg-slate-900 text-white dark:bg-white dark:text-slate-900 rounded-2xl shadow-2xl border border-slate-800 dark:border-slate-200 animate-in fade-in slide-in-from-bottom-4 text-xs font-semibold">
                    <div className="p-1 rounded-full bg-emerald-500/20 text-emerald-400 dark:text-emerald-600">
                        <CheckCircle2 className="size-4 shrink-0" />
                    </div>
                    <span>{toastMessage}</span>
                    <button onClick={() => setToastMessage(null)} className="ml-2 p-1 text-slate-400 hover:text-white dark:hover:text-slate-900 transition-colors">
                        <X className="size-3.5" />
                    </button>
                </div>
            )}

            {/* =====================================================
                GLOBAL DYNAMIC BACKGROUND PT SANATA SYSTEM
            ====================================================== */}
            <div className="pointer-events-none fixed inset-0 overflow-hidden print:hidden z-0 bg-slate-50/50 dark:bg-slate-950" />

            <div className="relative z-10 flex flex-col gap-6 w-full max-w-[1400px] mx-auto p-4 sm:p-6 min-w-0">
                {/* --- 1. HEADER HALAMAN --- */}
                <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 pb-4 border-b border-slate-200/80 dark:border-slate-800/80">
                    <div className="flex items-start sm:items-center gap-3 min-w-0">
                        <User className="size-6 text-slate-700 dark:text-slate-200 shrink-0" />
                        <div className="min-w-0">
                            <div className="flex items-center gap-2 flex-wrap">
                                <h1 className="text-xl sm:text-2xl font-extrabold tracking-tight text-slate-900 dark:text-slate-100">
                                    Profil
                                </h1>
                            </div>
                            <p className="text-xs sm:text-sm text-slate-500 dark:text-slate-400 mt-1 truncate">
                                Kelola informasi pribadi dan akun Anda.
                            </p>
                        </div>
                    </div>
                </div>

                {/* --- 2. PROFILE SUMMARY CARD --- */}
                <div className="bg-white dark:bg-slate-900 rounded-2xl p-5 sm:p-7 border border-slate-200/80 dark:border-slate-800 shadow-xs flex flex-col sm:flex-row items-center sm:items-start justify-between gap-5">
                    <div className="flex flex-col sm:flex-row items-center sm:items-center gap-5 text-center sm:text-left min-w-0">
                        <div className="relative group">
                            {activeAvatar ? (
                                <img
                                    src={activeAvatar}
                                    alt={auth.user.name}
                                    className="size-20 sm:size-24 rounded-full object-cover shadow-lg ring-4 ring-white dark:ring-slate-900 shrink-0"
                                />
                            ) : (
                                <div className="size-20 sm:size-24 rounded-full bg-slate-800 text-white font-black text-2xl sm:text-3xl flex items-center justify-center shadow-lg ring-4 ring-white dark:ring-slate-900 shrink-0">
                                    {userInitials}
                                </div>
                            )}
                            <button
                                type="button"
                                onClick={() => fileInputRef.current?.click()}
                                className="absolute bottom-0 right-0 p-2 rounded-full bg-slate-900 text-white dark:bg-white dark:text-slate-900 shadow-md hover:scale-110 transition-all cursor-pointer"
                                title="Ubah Foto"
                            >
                                <Camera className="size-3.5" />
                            </button>
                        </div>

                        <div className="space-y-1 min-w-0">
                            <h2 className="text-xl font-extrabold text-slate-900 dark:text-slate-100 truncate">
                                {auth.user.name}
                            </h2>
                            <p className="text-xs font-medium text-slate-500 dark:text-slate-400 truncate">
                                {auth.user.email}
                            </p>
                            <div className="pt-1.5 flex items-center gap-2 justify-center sm:justify-start flex-wrap">
                                <span className="px-2.5 py-0.5 text-[10px] font-bold rounded-full bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 border border-slate-200 dark:border-slate-700">
                                    {currentSystemRole}
                                </span>
                                {auth.user.email_verified_at || !mustVerifyEmail ? (
                                    <span className="px-2.5 py-0.5 text-[10px] font-bold rounded-full bg-emerald-50 dark:bg-emerald-950/60 text-emerald-700 dark:text-emerald-300 border border-emerald-200/60 dark:border-emerald-800 flex items-center gap-1">
                                        <CheckCircle2 className="size-3" /> Email Terverifikasi (Aktif)
                                    </span>
                                ) : (
                                    <span className="px-2.5 py-0.5 text-[10px] font-bold rounded-full bg-amber-50 dark:bg-amber-950/60 text-amber-700 dark:text-amber-300 border border-amber-200/60 dark:border-amber-800 flex items-center gap-1">
                                        <AlertCircle className="size-3" /> Email Belum Terverifikasi
                                    </span>
                                )}
                            </div>
                        </div>
                    </div>
                </div>

                {/* --- 3 & 4. INFORMASI PRIBADI CARD --- */}
                <div className="bg-white dark:bg-slate-900 rounded-2xl p-5 sm:p-7 border border-slate-200/80 dark:border-slate-800 shadow-xs space-y-5">
                    <div className="flex items-start gap-3.5 pb-3 border-b border-slate-100 dark:border-slate-800">
                        <UserCheck className="size-5 text-slate-700 dark:text-slate-200 shrink-0 mt-0.5" />
                        <div>
                            <h3 className="text-base font-bold text-slate-900 dark:text-slate-100">
                                Informasi Pribadi
                            </h3>
                            <p className="text-xs text-slate-500 dark:text-slate-400 mt-0.5">
                                Lihat dan perbarui nama lengkap serta alamat email Anda.
                            </p>
                        </div>
                    </div>

                    <div className="grid grid-cols-1 md:grid-cols-3 gap-5 max-w-5xl items-start">
                        {/* Kolom 1: Nama Lengkap */}
                        <div className="space-y-1.5 flex flex-col justify-start">
                            <div className="flex items-center justify-between h-5">
                                <label htmlFor="name_display" className="text-xs font-semibold text-slate-700 dark:text-slate-300">
                                    Nama Lengkap
                                </label>
                                <button
                                    type="button"
                                    onClick={() => setShowNameModal(true)}
                                    className="text-[11px] font-bold text-slate-700 dark:text-slate-200 hover:underline flex items-center gap-1 cursor-pointer"
                                >
                                    <Edit3 className="size-3" /> Ubah Nama
                                </button>
                            </div>
                            <div className="relative">
                                <Input
                                    id="name_display"
                                    type="text"
                                    className="w-full rounded-xl bg-slate-100 dark:bg-slate-800/60 text-slate-700 dark:text-slate-300 font-semibold cursor-not-allowed pr-10"
                                    value={auth.user.name}
                                    readOnly
                                />
                                <Lock className="size-4 text-slate-400 absolute right-3 top-1/2 -translate-y-1/2" />
                            </div>
                            <p className="text-[11px] text-slate-400">
                                Nama ditampilkan pada seluruh profil dan aktivitas workspace Anda.
                            </p>
                        </div>

                        {/* Kolom 2: Alamat Email */}
                        <div className="space-y-1.5 flex flex-col justify-start">
                            <div className="flex items-center justify-between h-5">
                                <label htmlFor="email_display" className="text-xs font-semibold text-slate-700 dark:text-slate-300">
                                    Alamat Email
                                </label>
                                <button
                                    type="button"
                                    onClick={() => setShowEmailModal(true)}
                                    className="text-[11px] font-bold text-slate-700 dark:text-slate-200 hover:underline flex items-center gap-1 cursor-pointer"
                                >
                                    <Lock className="size-3" /> Ubah Email
                                </button>
                            </div>
                            <div className="relative">
                                <Input
                                    id="email_display"
                                    type="email"
                                    className="w-full rounded-xl bg-slate-100 dark:bg-slate-800/60 text-slate-700 dark:text-slate-300 font-semibold cursor-not-allowed pr-10"
                                    value={auth.user.email}
                                    readOnly
                                />
                                <Lock className="size-4 text-slate-400 absolute right-3 top-1/2 -translate-y-1/2" />
                            </div>
                            <p className="text-[11px] text-slate-400">
                                Email digunakan untuk autentikasi tunggal di seluruh workspace Anda.
                            </p>
                        </div>

                        {/* Kolom 3: Nomor Telepon Terdaftar */}
                        <div className="space-y-1.5 flex flex-col justify-start">
                            <div className="flex items-center justify-between h-5">
                                <label htmlFor="phone_display" className="text-xs font-semibold text-slate-700 dark:text-slate-300">
                                    Nomor Telepon
                                </label>
                                <button
                                    type="button"
                                    onClick={() => setShowPhoneModal(true)}
                                    className="text-[11px] font-bold text-slate-700 dark:text-slate-200 hover:underline flex items-center gap-1 cursor-pointer"
                                >
                                    <Phone className="size-3" /> Ubah Telepon
                                </button>
                            </div>
                            <div className="relative">
                                <Input
                                    id="phone_display"
                                    type="text"
                                    className="w-full rounded-xl bg-slate-100 dark:bg-slate-800/60 text-slate-700 dark:text-slate-300 font-semibold cursor-not-allowed pr-10"
                                    value={(auth.user as any).phone_number || 'Belum diatur'}
                                    readOnly
                                />
                                <Lock className="size-4 text-slate-400 absolute right-3 top-1/2 -translate-y-1/2" />
                            </div>
                            <p className="text-[11px] text-slate-400">
                                {(auth.user as any).phone_updated_at ? `Terakhir diperbarui: ${new Date((auth.user as any).phone_updated_at).toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' })}` : 'Nomor kontak terhubung untuk autentikasi & verifikasi.'}
                            </p>
                        </div>
                    </div>

                    {/* @chisel-email-verification */}
                    {mustVerifyEmail && auth.user.email_verified_at === null && (
                        <div className="p-3.5 rounded-xl bg-amber-50 dark:bg-amber-950/40 border border-amber-200 dark:border-amber-800 text-xs text-amber-800 dark:text-amber-300 max-w-3xl space-y-2">
                            <p className="font-medium">
                                Alamat email Anda belum diverifikasi.{' '}
                                <Link
                                    href={send()}
                                    as="button"
                                    className="underline font-semibold hover:text-amber-900 dark:hover:text-amber-100 cursor-pointer"
                                >
                                    Kirim Ulang Verifikasi
                                </Link>
                            </p>

                            {status === 'verification-link-sent' && (
                                <div className="text-xs font-semibold text-emerald-600 dark:text-emerald-400">
                                    Tautan verifikasi baru telah dikirim ke alamat email Anda.
                                </div>
                            )}
                        </div>
                    )}
                    {/* @end-chisel-email-verification */}
                </div>

                {/* MODAL 1: UBAH NAMA LENGKAP */}
                {showNameModal && (
                    <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-950/50 backdrop-blur-sm animate-in fade-in duration-200">
                        <div className="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6 max-w-md w-full shadow-2xl space-y-4 animate-in zoom-in-95 duration-150">
                            <div className="flex items-center justify-between pb-3 border-b border-slate-100 dark:border-slate-800">
                                <div className="flex items-center gap-2.5">
                                    <Edit3 className="size-4 text-slate-600 dark:text-slate-300" />
                                    <h3 className="text-base font-bold text-slate-900 dark:text-slate-100">
                                        Ubah Nama Lengkap
                                    </h3>
                                </div>
                                <button
                                    onClick={() => setShowNameModal(false)}
                                    className="text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 transition-colors"
                                >
                                    <X className="size-5" />
                                </button>
                            </div>

                            <p className="text-xs text-slate-500 dark:text-slate-400 leading-relaxed">
                                Masukkan nama lengkap baru Anda dan konfirmasikan dengan kata sandi saat ini.
                            </p>

                            <Form
                                {...ProfileController.update.form()}
                                options={{ preserveScroll: true }}
                                onSuccess={() => {
                                    setShowNameModal(false);
                                    showToast('Nama lengkap berhasil diperbarui.');
                                }}
                                className="space-y-4"
                            >
                                {({ errors, processing }) => (
                                    <>
                                        <input type="hidden" name="email" value={auth.user.email} />

                                        <div className="space-y-1">
                                            <Input
                                                id="new_name"
                                                label="Nama Lengkap Baru"
                                                type="text"
                                                name="name"
                                                required
                                                autoComplete="name"
                                                defaultValue={auth.user.name}
                                                className="w-full rounded-xl"
                                                autoFocus
                                            />
                                            <InputError message={errors.name} />
                                        </div>

                                        <div className="space-y-1">
                                            <PasswordInput
                                                id="name_current_password"
                                                label="Kata Sandi Saat Ini"
                                                name="current_password"
                                                required
                                                autoComplete="current-password"
                                                placeholder="••••••••"
                                                className="w-full rounded-xl"
                                            />
                                            <InputError message={errors.current_password} />
                                        </div>

                                        <div className="pt-2 flex justify-end gap-2 border-t border-slate-100 dark:border-slate-800">
                                            <Button
                                                type="button"
                                                variant="outline"
                                                size="sm"
                                                onClick={() => setShowNameModal(false)}
                                            >
                                                Batalkan
                                            </Button>
                                            <Button
                                                type="submit"
                                                variant="default"
                                                size="sm"
                                                disabled={processing}
                                                className="cursor-pointer"
                                            >
                                                {processing ? (
                                                    <>
                                                        <Loader2 className="size-4 animate-spin mr-1.5" />
                                                        <span>Memproses...</span>
                                                    </>
                                                ) : (
                                                    'Konfirmasi Perubahan Nama'
                                                )}
                                            </Button>
                                        </div>
                                    </>
                                )}
                            </Form>
                        </div>
                    </div>
                )}

                {/* MODAL 2: UBAH ALAMAT EMAIL */}
                {showEmailModal && (
                    <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-950/50 backdrop-blur-sm animate-in fade-in duration-200">
                        <div className="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6 max-w-md w-full shadow-2xl space-y-4 animate-in zoom-in-95 duration-150">
                            <div className="flex items-center justify-between pb-3 border-b border-slate-100 dark:border-slate-800">
                                <div className="flex items-center gap-2.5">
                                    <MailCheck className="size-4 text-slate-600 dark:text-slate-300" />
                                    <h3 className="text-base font-bold text-slate-900 dark:text-slate-100">
                                        Ubah Alamat Email
                                    </h3>
                                </div>
                                <button
                                    onClick={() => setShowEmailModal(false)}
                                    className="text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 transition-colors"
                                >
                                    <X className="size-5" />
                                </button>
                            </div>

                            <p className="text-xs text-slate-500 dark:text-slate-400 leading-relaxed">
                                Mengubah alamat email akan memperbarui identitas login Anda untuk seluruh bisnis terdaftar. Masukkan kata sandi saat ini untuk mengonfirmasi.
                            </p>

                            <Form
                                {...ProfileController.update.form()}
                                options={{ preserveScroll: true }}
                                onSuccess={() => {
                                    setShowEmailModal(false);
                                    showToast('Alamat email berhasil diperbarui.');
                                }}
                                className="space-y-4"
                            >
                                {({ errors, processing }) => (
                                    <>
                                        <input type="hidden" name="name" value={auth.user.name} />

                                        <div className="space-y-1">
                                            <Input
                                                id="new_email"
                                                label="Alamat Email Baru"
                                                type="email"
                                                name="email"
                                                required
                                                autoComplete="email"
                                                defaultValue={auth.user.email}
                                                className="w-full rounded-xl"
                                                autoFocus
                                            />
                                            <InputError message={errors.email} />
                                        </div>

                                        <div className="space-y-1">
                                            <PasswordInput
                                                id="email_current_password"
                                                label="Kata Sandi Saat Ini"
                                                name="current_password"
                                                required
                                                autoComplete="current-password"
                                                placeholder="••••••••"
                                                className="w-full rounded-xl"
                                            />
                                            <InputError message={errors.current_password} />
                                        </div>

                                        <div className="pt-2 flex justify-end gap-2 border-t border-slate-100 dark:border-slate-800">
                                            <Button
                                                type="button"
                                                variant="outline"
                                                size="sm"
                                                onClick={() => setShowEmailModal(false)}
                                            >
                                                Batalkan
                                            </Button>
                                            <Button
                                                type="submit"
                                                variant="default"
                                                size="sm"
                                                disabled={processing}
                                                className="cursor-pointer"
                                            >
                                                {processing ? (
                                                    <>
                                                        <Loader2 className="size-4 animate-spin mr-1.5" />
                                                        <span>Memproses...</span>
                                                    </>
                                                ) : (
                                                    'Konfirmasi Perubahan Email'
                                                )}
                                            </Button>
                                        </div>
                                    </>
                                )}
                            </Form>
                        </div>
                    </div>
                )}

                {/* MODAL 3: UBAH NOMOR TELEPON */}
                {showPhoneModal && (
                    <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-950/50 backdrop-blur-sm animate-in fade-in duration-200">
                        <div className="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6 max-w-md w-full shadow-2xl space-y-4 animate-in zoom-in-95 duration-150">
                            <div className="flex items-center justify-between pb-3 border-b border-slate-100 dark:border-slate-800">
                                <div className="flex items-center gap-2.5">
                                    <Phone className="size-4 text-slate-600 dark:text-slate-300" />
                                    <h3 className="text-base font-bold text-slate-900 dark:text-slate-100">
                                        Ubah Nomor Telepon
                                    </h3>
                                </div>
                                <button
                                    onClick={() => setShowPhoneModal(false)}
                                    className="text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 transition-colors"
                                >
                                    <X className="size-5" />
                                </button>
                            </div>

                            <p className="text-xs text-slate-500 dark:text-slate-400 leading-relaxed">
                                Pilih negara dan masukkan nomor telepon baru Anda. Konfirmasikan dengan kata sandi saat ini.
                            </p>

                            <form onSubmit={handlePhoneSubmit} className="space-y-4">
                                <div className="space-y-1.5">
                                    <label className="text-xs font-semibold text-slate-700 dark:text-slate-300">
                                        Nomor Telepon Baru
                                    </label>
                                    <PhoneInput
                                        id="phone_number_profile_edit"
                                        name="phone_number"
                                        value={phoneVal}
                                        onChange={(e164Val) => setPhoneVal(e164Val)}
                                        error={phoneError}
                                    />
                                </div>

                                <div className="space-y-1.5">
                                    <PasswordInput
                                        id="phone_current_password_profile"
                                        label="Kata Sandi Saat Ini"
                                        value={phonePassword}
                                        onChange={(e) => setPhonePassword(e.target.value)}
                                        required
                                        autoComplete="current-password"
                                        placeholder="••••••••"
                                        className="w-full rounded-xl"
                                    />
                                    <InputError message={phonePasswordError} />
                                </div>

                                <div className="pt-2 flex justify-end gap-2 border-t border-slate-100 dark:border-slate-800">
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        onClick={() => setShowPhoneModal(false)}
                                    >
                                        Batalkan
                                    </Button>
                                    <Button
                                        type="submit"
                                        variant="default"
                                        size="sm"
                                        disabled={isSubmittingPhone}
                                        className="cursor-pointer"
                                    >
                                        {isSubmittingPhone ? (
                                            <>
                                                <Loader2 className="size-4 animate-spin mr-1.5" />
                                                <span>Memproses...</span>
                                            </>
                                        ) : (
                                            'Konfirmasi No. Telepon'
                                        )}
                                    </Button>
                                </div>
                            </form>
                        </div>
                    </div>
                )}

                {/* --- 5. AKUN & WORKSPACE CARD --- */}
                <div className="bg-white dark:bg-slate-900 rounded-2xl p-5 sm:p-7 border border-slate-200/80 dark:border-slate-800 shadow-xs space-y-5">
                    <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 pb-3 border-b border-slate-100 dark:border-slate-800">
                        <div className="flex items-start gap-3.5">
                            <Building2 className="size-5 text-slate-700 dark:text-slate-200 shrink-0 mt-0.5" />
                            <div>
                                <h3 className="text-base font-bold text-slate-900 dark:text-slate-100">
                                    Akun &amp; Workspace
                                </h3>
                                <p className="text-xs text-slate-500 dark:text-slate-400 mt-0.5">
                                    Informasi status akun dan bisnis yang sedang aktif.
                                </p>
                            </div>
                        </div>

                        <Link
                            href="/settings/security"
                            className="inline-flex items-center gap-1.5 text-xs font-bold text-slate-700 dark:text-slate-200 hover:underline cursor-pointer self-start sm:self-center"
                        >
                            <span>Kelola Keamanan Akun</span>
                            <ArrowRight className="size-3.5" />
                        </Link>
                    </div>

                    <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                        <div className="p-4 rounded-xl bg-slate-50/70 dark:bg-slate-800/40 border border-slate-100 dark:border-slate-800 space-y-1">
                            <span className="text-[11px] font-semibold text-slate-400 uppercase tracking-wider block">
                                Email Akun
                            </span>
                            <span className="text-xs sm:text-sm font-bold text-slate-900 dark:text-slate-100 truncate block">
                                {auth.user.email}
                            </span>
                        </div>

                        <div className="p-4 rounded-xl bg-slate-50/70 dark:bg-slate-800/40 border border-slate-100 dark:border-slate-800 space-y-1">
                            <span className="text-[11px] font-semibold text-slate-400 uppercase tracking-wider block">
                                Peran / Role
                            </span>
                            <span className="text-xs sm:text-sm font-bold text-slate-900 dark:text-slate-100 truncate block">
                                {currentSystemRole}
                            </span>
                        </div>

                        <div className="p-4 rounded-xl bg-slate-50/70 dark:bg-slate-800/40 border border-slate-100 dark:border-slate-800 space-y-1">
                            <span className="text-[11px] font-semibold text-slate-400 uppercase tracking-wider block">
                                Bisnis Aktif
                            </span>
                            <span className="text-xs sm:text-sm font-bold text-slate-900 dark:text-slate-100 truncate block">
                                {currentTenantName}
                            </span>
                        </div>

                        <div className="p-4 rounded-xl bg-slate-50/70 dark:bg-slate-800/40 border border-slate-100 dark:border-slate-800 space-y-1">
                            <span className="text-[11px] font-semibold text-slate-400 uppercase tracking-wider block">
                                Status Akun
                            </span>
                            <span className="inline-flex items-center gap-1 text-xs font-bold text-emerald-700 dark:text-emerald-400">
                                <CheckCircle2 className="size-3.5 text-emerald-600" /> Aktif
                            </span>
                        </div>
                    </div>
                </div>

                {/* --- 6. DANGER ZONE --- */}
                <DeleteUser />
            </div>
        </>
    );
}

Profile.layout = {
    breadcrumbs: [
        {
            title: 'Profil',
            href: edit(),
        },
    ],
};
