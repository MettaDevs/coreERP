import { Form, Head, router, usePage } from '@inertiajs/react';
import { useRef, useState } from 'react';
import SecurityController from '@/actions/App/Http/Controllers/Settings/SecurityController';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import { Button } from '@apperp/ui/button';
import {
    Dialog,
    DialogAction,
    DialogCancel,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@apperp/ui/dialog';
import { edit } from '@/routes/security';
import {
    Shield,
    ShieldCheck,
    KeyRound,
    Smartphone,
    Lock,
    Save,
    Loader2,
    CheckCircle2,
    Sparkles,
    AlertTriangle,
    X,
    Laptop,
    LogOut,
    Check,
    Plus,
    Trash2,
    History,
    ChevronRight,
    Clock,
    AlertCircle,
} from 'lucide-react';
import { cn } from '@/lib/utils';

/* @chisel-passkeys */
import type { Props as ManagePasskeysProps } from '@/components/manage-passkeys';
import ManagePasskeys from '@/components/manage-passkeys';
/* @end-chisel-passkeys */
/* @chisel-2fa */
import type { Props as ManageTwoFactorProps } from '@/components/manage-two-factor';
import ManageTwoFactor from '@/components/manage-two-factor';
/* @end-chisel-2fa */

type SessionDevice = {
    id: string;
    browser: string;
    os: string;
    location: string;
    isCurrent: boolean;
    lastActive: string;
    deviceType?: string;
    ip?: string;
};

type SecurityActivityItem = {
    id: number;
    type: string;
    title: string;
    date: string;
    detail: string;
    status: string;
};

type Props = {
    passwordRules: string;
    lastPasswordUpdatedWita?: string;
    lastPasskeyUsedWita?: string;
    sessions?: SessionDevice[];
    currentSession?: SessionDevice;
    securityActivities?: SecurityActivityItem[];
} /* @chisel-passkeys */ & ManagePasskeysProps /* @end-chisel-passkeys */ /* @chisel-2fa */ &
    ManageTwoFactorProps /* @end-chisel-2fa */;

export default function Security(props: Props) {
    const passwordInput = useRef<HTMLInputElement>(null);
    const currentPasswordInput = useRef<HTMLInputElement>(null);

    // Modals
    const [showPasswordModal, setShowPasswordModal] = useState(false);
    const [showLogoutOtherModal, setShowLogoutOtherModal] = useState(false);
    const [showDeleteAllActivitiesModal, setShowDeleteAllActivitiesModal] = useState(false);
    const [isMinLoading, setIsMinLoading] = useState(false);

    // Toast state
    const [toastMessage, setToastMessage] = useState<string | null>(null);

    const showToast = (msg: string) => {
        setToastMessage(msg);
        setTimeout(() => setToastMessage(null), 3500);
    };

    const handlePasswordSubmit = () => {
        setIsMinLoading(true);
        setTimeout(() => {
            setIsMinLoading(false);
        }, 600);
    };

    // Real DB sessions
    const devices = props.sessions || [];

    const handleLogoutDevice = (id: string) => {
        router.delete(`/settings/security/sessions/${id}`, {
            preserveScroll: true,
            onSuccess: () => showToast('Perangkat berhasil dikeluarkan dari sesi.'),
        });
    };

    const handleLogoutAllOtherDevices = () => {
        router.delete('/settings/security/sessions', {
            preserveScroll: true,
            onSuccess: () => {
                setShowLogoutOtherModal(false);
                showToast('Seluruh perangkat lain berhasil dikeluarkan.');
            },
        });
    };

    // Real Persistent Security Activities
    const securityLogs = props.securityActivities || [];

    const handleDeleteSingleActivity = (id: number) => {
        router.delete(`/settings/security/activities/${id}`, {
            preserveScroll: true,
            onSuccess: () => showToast('Aktivitas keamanan berhasil dihapus.'),
        });
    };

    const handleDeleteAllActivities = () => {
        router.delete('/settings/security/activities', {
            preserveScroll: true,
            onSuccess: () => {
                setShowDeleteAllActivitiesModal(false);
                showToast('Seluruh aktivitas keamanan berhasil dihapus.');
            },
        });
    };

    return (
        <>
            <Head title="Keamanan Akun" />

            {/* --- FLOATING TOAST --- */}
            {toastMessage && (
                <div className="fixed bottom-6 right-6 z-50 flex items-center gap-3 px-4 py-3 bg-slate-900 text-white dark:bg-white dark:text-slate-900 rounded-2xl shadow-2xl border border-slate-800 dark:border-slate-200 animate-in fade-in slide-in-from-bottom-4 text-xs font-semibold">
                    <div className="p-1 rounded-full bg-emerald-500/20 text-emerald-400 dark:text-emerald-600">
                        <CheckCircle2 className="size-4 shrink-0" />
                    </div>
                    <span>{toastMessage}</span>
                    <button onClick={() => setToastMessage(null)} className="ml-2 p-1 text-slate-400 hover:text-white dark:hover:text-slate-900 transition-colors cursor-pointer">
                        <X className="size-3.5" />
                    </button>
                </div>
            )}

            {/* =====================================================
                GLOBAL DYNAMIC BACKGROUND PT SANATA SYSTEM
            ====================================================== */}
            <div className="pointer-events-none fixed inset-0 overflow-hidden print:hidden z-0">
                <div className="absolute inset-0 bg-[linear-gradient(125deg,#E8F5FC_0%,#F6FBFF_38%,#DDFBFC_100%)] dark:bg-[radial-gradient(ellipse_at_top_right,_var(--tw-gradient-stops))] dark:from-[#0B1E36] dark:via-[#070D18] dark:to-[#04070E]" />
                <div className="absolute -right-[140px] -top-[100px] h-[550px] w-[550px] rounded-full bg-[#00B8C8]/15 blur-[120px] dark:bg-[#00C9C8]/15 dark:blur-[140px]" />
                <div className="absolute -left-[180px] top-[140px] h-[520px] w-[520px] rounded-full bg-[#1677FF]/10 blur-[120px] dark:bg-[#005F73]/25 dark:blur-[130px]" />
                <div className="absolute -right-[200px] top-[320px] h-[650px] w-[650px] rounded-full border-[60px] border-[#00B8C8]/10 dark:border-[#00C9C8]/10 dark:blur-sm" />
                <div className="absolute -left-[220px] -bottom-[280px] h-[700px] w-[700px] rounded-full border-[50px] border-[#1677FF]/10 dark:border-[#005F73]/15 dark:blur-sm" />
                <div className="absolute inset-0 opacity-[0.15] dark:opacity-[0.06] [background-image:radial-gradient(circle,rgba(0,201,200,0.35)_1px,transparent_1px)] [background-size:28px_28px]" />
            </div>

            <div className="relative z-10 flex flex-col gap-6 w-full max-w-[1400px] mx-auto p-4 sm:p-6 min-w-0">
                {/* --- 1. HEADER HALAMAN --- */}
                <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 pb-4 border-b border-slate-200/80 dark:border-slate-800/80">
                    <div className="flex items-start sm:items-center gap-3 min-w-0">
                        <Shield className="size-6 text-slate-700 dark:text-slate-200 shrink-0" />
                        <div className="min-w-0">
                            <div className="flex items-center gap-2 flex-wrap">
                                <h1 className="text-xl sm:text-2xl font-extrabold tracking-tight text-slate-900 dark:text-slate-100">
                                    Keamanan Akun
                                </h1>
                            </div>
                            <p className="text-xs sm:text-sm text-slate-500 dark:text-slate-400 mt-1 truncate">
                                Kelola kata sandi, autentikasi dua langkah, passkey, sesi perangkat, dan aktivitas keamanan akun Anda.
                            </p>
                        </div>
                    </div>
                </div>

                {/* --- SECTION 1: KATA SANDI --- */}
                <div className="bg-white dark:bg-slate-900 rounded-2xl p-5 sm:p-7 border border-slate-200/80 dark:border-slate-800 shadow-xs space-y-4">
                    <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 pb-4 border-b border-slate-100 dark:border-slate-800">
                        <div className="flex items-start gap-3.5">
                            <Lock className="size-5 text-slate-700 dark:text-slate-200 shrink-0 mt-0.5" />
                            <div>
                                <h3 className="text-base font-bold text-slate-900 dark:text-slate-100">
                                    Kata Sandi
                                </h3>
                                <p className="text-xs text-slate-500 dark:text-slate-400 mt-0.5">
                                    Perbarui kata sandi secara berkala untuk menjaga keamanan akun Anda.
                                </p>
                                <div className="inline-flex items-center gap-1.5 text-[11px] font-medium text-slate-400 mt-2">
                                    <History className="size-3 text-slate-400" />
                                    <span>Terakhir diperbarui: <span className="font-bold text-slate-700 dark:text-slate-300">{props.lastPasswordUpdatedWita}</span></span>
                                </div>
                            </div>
                        </div>

                        <Button
                            type="button"
                            variant="default"
                            onClick={() => setShowPasswordModal(true)}
                            className="cursor-pointer shrink-0 self-start sm:self-center font-bold text-xs"
                        >
                            <Lock className="size-4 mr-2" />
                            <span>Ubah Kata Sandi</span>
                        </Button>
                    </div>

                    {/* MODAL UBAH KATA SANDI */}
                    {showPasswordModal && (
                        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-950/50 backdrop-blur-sm animate-in fade-in duration-200">
                            <div className="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6 sm:p-7 max-w-lg w-full shadow-2xl space-y-5 animate-in zoom-in-95 duration-150">
                                <div className="flex items-center justify-between pb-3 border-b border-slate-100 dark:border-slate-800">
                                    <div className="flex items-center gap-2.5">
                                        <div className="p-2 rounded-xl bg-[#EAFBFC] dark:bg-cyan-950/60 text-[#00AFC0]">
                                            <Lock className="size-4" />
                                        </div>
                                        <h3 className="text-base font-bold text-slate-900 dark:text-slate-100">
                                            Ubah Kata Sandi Akun
                                        </h3>
                                    </div>
                                    <button
                                        onClick={() => setShowPasswordModal(false)}
                                        className="text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 transition-colors cursor-pointer"
                                    >
                                        <X className="size-5" />
                                    </button>
                                </div>

                                <Form
                                    {...SecurityController.update.form()}
                                    onSubmit={handlePasswordSubmit}
                                    options={{ preserveScroll: true }}
                                    resetOnError={['password', 'password_confirmation', 'current_password']}
                                    resetOnSuccess
                                    onSuccess={() => {
                                        setShowPasswordModal(false);
                                        showToast('Kata sandi berhasil diperbarui.');
                                    }}
                                    onError={(errors) => {
                                        if (errors.password) passwordInput.current?.focus();
                                        if (errors.current_password) currentPasswordInput.current?.focus();
                                    }}
                                    className="space-y-4"
                                >
                                    {({ errors, processing }) => {
                                        const isPending = processing || isMinLoading;
                                        return (
                                            <>
                                                <div className="space-y-1">
                                                    <PasswordInput
                                                        id="current_password"
                                                        label="Kata Sandi Saat Ini"
                                                        ref={currentPasswordInput}
                                                        name="current_password"
                                                        className="w-full rounded-xl focus:border-[#00AFC0] focus:ring-2 focus:ring-[#00AFC0]/20"
                                                        autoComplete="current-password"
                                                        placeholder="••••••••"
                                                    />
                                                    <InputError message={errors.current_password} />
                                                </div>

                                                <div className="space-y-1">
                                                    <PasswordInput
                                                        id="password"
                                                        label="Kata Sandi Baru"
                                                        ref={passwordInput}
                                                        name="password"
                                                        className="w-full rounded-xl focus:border-[#00AFC0] focus:ring-2 focus:ring-[#00AFC0]/20"
                                                        autoComplete="new-password"
                                                        passwordrules={props.passwordRules}
                                                        placeholder="••••••••"
                                                    />
                                                    <InputError message={errors.password} />
                                                </div>

                                                <div className="space-y-1">
                                                    <PasswordInput
                                                        id="password_confirmation"
                                                        label="Konfirmasi Kata Sandi Baru"
                                                        name="password_confirmation"
                                                        className="w-full rounded-xl focus:border-[#00AFC0] focus:ring-2 focus:ring-[#00AFC0]/20"
                                                        autoComplete="new-password"
                                                        passwordrules={props.passwordRules}
                                                        placeholder="••••••••"
                                                    />
                                                    <InputError message={errors.password_confirmation} />
                                                </div>

                                                <div className="pt-3 flex justify-end gap-2 border-t border-slate-100 dark:border-slate-800">
                                                    <Button
                                                        type="button"
                                                        variant="outline"
                                                        size="sm"
                                                        onClick={() => setShowPasswordModal(false)}
                                                    >
                                                        Batalkan
                                                    </Button>

                                                    <Button
                                                        type="submit"
                                                        variant="default"
                                                        size="sm"
                                                        disabled={isPending}
                                                    >
                                                        {isPending ? (
                                                            <>
                                                                <Loader2 className="size-4 animate-spin mr-2" />
                                                                <span>Menyimpan...</span>
                                                            </>
                                                        ) : (
                                                            <>
                                                                <Save className="size-4 mr-2" />
                                                                <span>Simpan Kata Sandi</span>
                                                            </>
                                                        )}
                                                    </Button>
                                                </div>
                                            </>
                                        );
                                    }}
                                </Form>
                            </div>
                        </div>
                    )}
                </div>

                {/* --- SECTION 2 & 3: 2FA & PASSKEYS GRID --- */}
                <div className="grid grid-cols-1 lg:grid-cols-2 gap-6 min-w-0 items-stretch">
                    {/* SECTION 2: TWO-FACTOR AUTHENTICATION */}
                    <div className="bg-white dark:bg-slate-900 rounded-2xl p-5 sm:p-7 border border-slate-200/80 dark:border-slate-800 shadow-xs flex flex-col justify-between h-full space-y-4">
                        <div className="flex items-start justify-between gap-3 pb-3 border-b border-slate-100 dark:border-slate-800">
                            <div className="flex items-start gap-3">
                                <ShieldCheck className="size-5 text-slate-700 dark:text-slate-200 shrink-0 mt-0.5" />
                                <div>
                                    <h3 className="text-base font-bold text-slate-900 dark:text-slate-100">
                                        Two-Factor Authentication
                                    </h3>
                                    <p className="text-xs text-slate-500 dark:text-slate-400 mt-0.5">
                                        Tambahkan lapisan keamanan tambahan saat masuk ke akun Anda.
                                    </p>
                                    <p className="text-[11px] text-slate-400 mt-1 font-medium">
                                        Metode: <strong className="text-slate-700 dark:text-slate-300 font-semibold">Authenticator App</strong>
                                    </p>
                                </div>
                            </div>

                            {props.twoFactorEnabled ? (
                                <span className="inline-flex items-center gap-1 text-[11px] font-bold text-emerald-700 dark:text-emerald-300 bg-emerald-50 dark:bg-emerald-950/60 px-2.5 py-1 rounded-full border border-emerald-200/80 dark:border-emerald-800 shrink-0">
                                    <Check className="size-3 text-emerald-600" />
                                    <span>✓ Aktif</span>
                                </span>
                            ) : (
                                <span className="inline-flex items-center gap-1 text-[11px] font-semibold text-slate-600 dark:text-slate-400 bg-slate-100 dark:bg-slate-800 px-2.5 py-1 rounded-full border border-slate-200 dark:border-slate-700 shrink-0">
                                    <span>Belum Aktif</span>
                                </span>
                            )}
                        </div>

                        {/* Fortify 2FA Component Wrapper */}
                        <div className="flex-1 flex flex-col pt-1">
                            <ManageTwoFactor
                                canManageTwoFactor={props.canManageTwoFactor}
                                requiresConfirmation={props.requiresConfirmation}
                                twoFactorEnabled={props.twoFactorEnabled}
                            />
                        </div>
                    </div>

                    {/* SECTION 3: PASSKEYS */}
                    <div className="bg-white dark:bg-slate-900 rounded-2xl p-5 sm:p-7 border border-slate-200/80 dark:border-slate-800 shadow-xs flex flex-col justify-between h-full space-y-4">
                        <div className="flex items-start justify-between gap-3 pb-3 border-b border-slate-100 dark:border-slate-800">
                            <div className="flex items-start gap-3">
                                <KeyRound className="size-5 text-slate-700 dark:text-slate-200 shrink-0 mt-0.5" />
                                <div>
                                    <h3 className="text-base font-bold text-slate-900 dark:text-slate-100">
                                        Passkeys
                                    </h3>
                                    <p className="text-xs text-slate-500 dark:text-slate-400 mt-0.5">
                                        Gunakan passkey untuk masuk dengan aman tanpa harus mengetik kata sandi.
                                    </p>
                                    <div className="flex flex-wrap items-center gap-2 mt-2">
                                        <span className="px-2.5 py-0.5 text-[10px] font-bold rounded-full bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 border border-slate-200 dark:border-slate-700">
                                            {props.passkeys && props.passkeys.length > 0 ? `${props.passkeys.length} Passkey terdaftar` : 'Belum ada passkey yang terdaftar'}
                                        </span>
                                    </div>
                                    <p className="text-[11px] text-slate-400 mt-1.5">
                                        Terakhir digunakan: <span className="font-bold text-slate-700 dark:text-slate-300">{props.lastPasskeyUsedWita}</span>
                                    </p>
                                </div>
                            </div>
                        </div>

                        {/* Fortify Passkeys Component Wrapper */}
                        <div className="flex-1 flex flex-col pt-1">
                            <ManagePasskeys
                                canManagePasskeys={props.canManagePasskeys}
                                passkeys={props.passkeys}
                            />
                        </div>
                    </div>
                </div>

                {/* --- SECTION 4: SESI & PERANGKAT --- */}
                <div className="bg-white dark:bg-slate-900 rounded-2xl p-5 sm:p-7 border border-slate-200/80 dark:border-slate-800 shadow-xs space-y-5">
                    <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 pb-3 border-b border-slate-100 dark:border-slate-800">
                        <div className="flex items-start gap-3.5">
                            <Smartphone className="size-5 text-slate-700 dark:text-slate-200 shrink-0 mt-0.5" />
                            <div>
                                <h3 className="text-base font-bold text-slate-900 dark:text-slate-100">
                                    Sesi &amp; Perangkat
                                </h3>
                                <p className="text-xs text-slate-500 dark:text-slate-400 mt-0.5">
                                    Kelola perangkat yang sedang menggunakan akun Anda.
                                </p>
                            </div>
                        </div>

                        {devices.filter((d) => !d.isCurrent).length > 0 && (
                            <Button
                                type="button"
                                variant="destructive"
                                size="sm"
                                onClick={() => setShowLogoutOtherModal(true)}
                                className="cursor-pointer self-start sm:self-center font-bold text-xs"
                            >
                                <LogOut className="size-3.5 mr-1.5" />
                                <span>Keluar dari Perangkat Lain</span>
                            </Button>
                        )}
                    </div>

                    <div className="divide-y divide-slate-100 dark:divide-slate-800">
                        {devices.map((device) => (
                            <div key={device.id} className="py-3.5 flex items-center justify-between gap-4 flex-wrap sm:flex-nowrap">
                                <div className="flex items-center gap-3.5 min-w-0">
                                    {device.deviceType === 'mobile' ? <Smartphone className="size-4 text-slate-600 dark:text-slate-300 shrink-0" /> : <Laptop className="size-4 text-slate-600 dark:text-slate-300 shrink-0" />}
                                    <div className="min-w-0">
                                        <div className="flex items-center gap-2">
                                            <span className="text-xs sm:text-sm font-bold text-slate-900 dark:text-slate-100 truncate">
                                                {device.browser} · {device.os}
                                            </span>
                                            {device.isCurrent ? (
                                                <span className="inline-flex items-center gap-1 text-[10px] font-bold text-primary bg-primary/10 px-2.5 py-0.5 rounded-full border border-primary/20">
                                                    <CheckCircle2 className="size-3" />
                                                    <span>Perangkat ini</span>
                                                </span>
                                            ) : (
                                                <span className="inline-flex items-center text-[10px] font-semibold text-emerald-600 dark:text-emerald-400 bg-emerald-50 dark:bg-emerald-950/40 px-2.5 py-0.5 rounded-full border border-emerald-200">
                                                    <span>Aktif</span>
                                                </span>
                                            )}
                                        </div>
                                        <p className="text-[11px] text-slate-500 dark:text-slate-400 mt-0.5 truncate">
                                            {device.isCurrent ? (device.ip || '172.18.0.1') : device.location} • Terakhir aktif: <strong className="font-semibold text-slate-700 dark:text-slate-300">{device.lastActive}</strong>
                                        </p>
                                    </div>
                                </div>

                                {!device.isCurrent && (
                                    <Button
                                        type="button"
                                        variant="destructive"
                                        size="xs"
                                        onClick={() => handleLogoutDevice(device.id)}
                                        className="cursor-pointer shrink-0 flex items-center gap-1 font-bold text-xs"
                                    >
                                        <LogOut className="size-3.5" />
                                        <span>Keluar</span>
                                    </Button>
                                )}
                            </div>
                        ))}
                    </div>

                    {/* MODAL LOGOUT PERANGKAT LAIN */}
                    {showLogoutOtherModal && (
                        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-950/50 backdrop-blur-sm animate-in fade-in duration-200">
                            <div className="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6 max-w-md w-full shadow-2xl space-y-4 animate-in zoom-in-95 duration-150">
                                <div className="flex items-center gap-3 text-red-600">
                                    <AlertTriangle className="size-5 shrink-0" />
                                    <h3 className="text-base font-bold text-slate-900 dark:text-slate-100">
                                        Keluar dari Perangkat Lain?
                                    </h3>
                                </div>

                                <p className="text-xs text-slate-500 dark:text-slate-400 leading-relaxed">
                                    Tindakan ini akan menghentikan sesi aktif di seluruh perangkat lain secara langsung. Anda harus memasukkan kata sandi kembali jika ingin login pada perangkat tersebut.
                                </p>

                                <div className="pt-2 flex justify-end gap-2 border-t border-slate-100 dark:border-slate-800">
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        onClick={() => setShowLogoutOtherModal(false)}
                                    >
                                        Batalkan
                                    </Button>
                                    <Button
                                        type="button"
                                        variant="destructive"
                                        size="sm"
                                        onClick={handleLogoutAllOtherDevices}
                                    >
                                        Ya, Keluar Semua
                                    </Button>
                                </div>
                            </div>
                        </div>
                    )}
                </div>

                {/* --- SECTION 5: AKTIVITAS KEAMANAN --- */}
                <div className="bg-white dark:bg-slate-900 rounded-2xl p-5 sm:p-7 border border-slate-200/80 dark:border-slate-800 shadow-xs space-y-5">
                    <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 pb-3 border-b border-slate-100 dark:border-slate-800">
                        <div className="flex items-start gap-3.5">
                            <History className="size-5 text-slate-700 dark:text-slate-200 shrink-0 mt-0.5" />
                            <div>
                                <h3 className="text-base font-bold text-slate-900 dark:text-slate-100">
                                    Aktivitas Keamanan
                                </h3>
                                <p className="text-xs text-slate-500 dark:text-slate-400 mt-0.5">
                                    Pantau aktivitas penting yang berkaitan dengan keamanan akun Anda.
                                </p>
                            </div>
                        </div>

                        {securityLogs.length > 0 && (
                            <Button
                                type="button"
                                variant="destructive"
                                size="sm"
                                onClick={() => setShowDeleteAllActivitiesModal(true)}
                                className="cursor-pointer flex items-center gap-1.5 self-start sm:self-center font-bold text-xs"
                            >
                                <Trash2 className="size-3.5" />
                                <span>Hapus Semua Aktivitas</span>
                            </Button>
                        )}
                    </div>

                    {securityLogs.length === 0 ? (
                        <div className="p-8 text-center rounded-xl bg-slate-50/50 dark:bg-slate-800/20 border border-slate-100 dark:border-slate-800">
                            <History className="size-8 text-slate-300 mx-auto mb-2" />
                            <p className="text-xs font-semibold text-slate-500">Belum ada riwayat aktivitas keamanan tersimpan.</p>
                        </div>
                    ) : (
                        <div className="space-y-3">
                            {securityLogs.map((log) => {
                                const isWarning = log.status === 'warning' || log.type === 'login_failed';
                                return (
                                    <div
                                        key={log.id}
                                        className={cn(
                                            "flex items-center justify-between gap-3.5 p-3.5 rounded-xl border transition-all group",
                                            isWarning
                                                ? "bg-red-50/50 dark:bg-red-950/20 border-red-200 dark:border-red-900/40"
                                                : "bg-slate-50/70 dark:bg-slate-800/40 border-slate-200/80 dark:border-slate-800"
                                        )}
                                    >
                                        <div className="flex items-center gap-3.5 min-w-0">
                                            {isWarning ? (
                                                <AlertCircle className="size-4 text-red-600 dark:text-red-400 shrink-0" />
                                            ) : (
                                                <ShieldCheck className="size-4 text-slate-600 dark:text-slate-300 shrink-0" />
                                            )}
                                            <div className="min-w-0">
                                                <div className="flex items-center gap-2 flex-wrap">
                                                    <h4 className={cn("text-xs sm:text-sm font-bold", isWarning ? "text-red-700 dark:text-red-300" : "text-slate-900 dark:text-slate-100")}>
                                                        {isWarning ? `⚠ ${log.title}` : log.title}
                                                    </h4>
                                                    <span className="text-[11px] font-mono font-bold text-slate-400 shrink-0">
                                                        {log.date}
                                                    </span>
                                                </div>
                                                <p className="text-xs text-slate-500 dark:text-slate-400 mt-0.5">
                                                    {log.detail}
                                                </p>
                                            </div>
                                        </div>

                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon-xs"
                                            onClick={() => handleDeleteSingleActivity(log.id)}
                                            className="text-red-500 hover:text-red-700 hover:bg-red-50 dark:hover:bg-red-950/40 rounded-md cursor-pointer shrink-0"
                                            title="Hapus Aktivitas Ini"
                                        >
                                            <Trash2 className="size-3.5" />
                                        </Button>
                                    </div>
                                );
                            })}
                        </div>
                    )}

                    {/* MODAL HAPUS SEMUA AKTIVITAS KEAMANAN */}
                    <Dialog open={showDeleteAllActivitiesModal} onOpenChange={setShowDeleteAllActivitiesModal}>
                        <DialogContent size="compact" className="rounded-2xl border border-slate-200 dark:border-slate-800 p-6 space-y-4">
                            <DialogHeader className="flex flex-row items-center gap-3 space-y-0 text-left border-b-0 p-0">
                                <Trash2 className="size-5 text-red-600 shrink-0" />
                                <DialogTitle className="text-base font-bold text-slate-900 dark:text-slate-100">
                                    Hapus Seluruh Aktivitas Keamanan?
                                </DialogTitle>
                            </DialogHeader>
                            <DialogDescription className="text-xs text-slate-500 dark:text-slate-400 mt-1 leading-relaxed">
                                Data riwayat aktivitas keamanan yang dihapus tidak dapat ditampilkan kembali. Apakah Anda yakin ingin membersihkan seluruh catatan?
                            </DialogDescription>
                            <DialogFooter className="gap-2 pt-3 border-t border-slate-100 dark:border-slate-800 bg-transparent px-0 pb-0">
                                <DialogCancel
                                    variant="outline"
                                    size="sm"
                                    onClick={() => setShowDeleteAllActivitiesModal(false)}
                                >
                                    Batal
                                </DialogCancel>
                                <DialogAction
                                    type="button"
                                    variant="destructive"
                                    size="sm"
                                    onClick={handleDeleteAllActivities}
                                >
                                    Hapus Aktivitas
                                </DialogAction>
                            </DialogFooter>
                        </DialogContent>
                    </Dialog>
                </div>
            </div>
        </>
    );
}

Security.layout = {
    breadcrumbs: [
        {
            title: 'Keamanan Akun',
            href: edit(),
        },
    ],
};
