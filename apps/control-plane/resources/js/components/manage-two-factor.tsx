import { Form } from '@inertiajs/react';
import { ShieldCheck } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import Heading from '@/components/heading';
import TwoFactorRecoveryCodes from '@/components/two-factor-recovery-codes';
import TwoFactorSetupModal from '@/components/two-factor-setup-modal';
import { Button } from '@apperp/ui/button';
import { useTwoFactorAuth } from '@/hooks/use-two-factor-auth';
import { disable, enable } from '@/routes/two-factor';

export type Props = {
    canManageTwoFactor?: boolean;
    requiresConfirmation?: boolean;
    twoFactorEnabled?: boolean;
};

export default function ManageTwoFactor(props: Props) {
    const requiresConfirmation = props.requiresConfirmation ?? false;
    const twoFactorEnabled = props.twoFactorEnabled ?? false;

    const {
        qrCodeSvg,
        hasSetupData,
        manualSetupKey,
        clearSetupData,
        clearTwoFactorAuthData,
        fetchSetupData,
        recoveryCodesList,
        fetchRecoveryCodes,
        errors,
    } = useTwoFactorAuth();
    const [showSetupModal, setShowSetupModal] = useState<boolean>(false);
    const prevTwoFactorEnabled = useRef(twoFactorEnabled);

    useEffect(() => {
        if (prevTwoFactorEnabled.current && !twoFactorEnabled) {
            clearTwoFactorAuthData();
        }

        prevTwoFactorEnabled.current = twoFactorEnabled;
    }, [twoFactorEnabled, clearTwoFactorAuthData]);

    if (!(props.canManageTwoFactor ?? false)) {
        return null;
    }

    return (
        <div className="flex flex-col justify-between h-full space-y-4">
            {twoFactorEnabled ? (
                <div className="flex flex-col justify-between flex-1 space-y-4">
                    <div className="p-4 rounded-xl border border-slate-200/80 dark:border-slate-800 bg-slate-50/50 dark:bg-slate-800/30 flex-1 flex flex-col justify-center space-y-1.5">
                        <div className="flex items-center gap-2">
                            <span className="size-2 rounded-full bg-emerald-500 animate-pulse"></span>
                            <p className="text-xs font-bold text-slate-900 dark:text-slate-100">
                                2FA Aktif &amp; Terlindungi
                            </p>
                        </div>
                        <p className="text-[11px] text-slate-500 dark:text-slate-400 leading-relaxed">
                            Setiap kali masuk, Anda akan diminta memasukkan kode verifikasi 6-digit dari aplikasi authenticator di ponsel Anda.
                        </p>
                    </div>

                    <div className="pt-2 space-y-3">
                        <TwoFactorRecoveryCodes
                            recoveryCodesList={recoveryCodesList}
                            fetchRecoveryCodes={fetchRecoveryCodes}
                            errors={errors}
                        />

                        <Form {...disable.form()}>
                            {({ processing }) => (
                                <Button
                                    variant="destructive"
                                    type="submit"
                                    disabled={processing}
                                    className="bg-red-600 hover:bg-red-500 text-white font-semibold text-xs rounded-xl px-4 py-2.5 shadow-md shadow-red-500/20 cursor-pointer"
                                >
                                    {processing ? 'Memproses...' : 'Nonaktifkan 2FA'}
                                </Button>
                            )}
                        </Form>
                    </div>
                </div>
            ) : (
                <div className="flex flex-col justify-between flex-1 space-y-4">
                    <div className="p-5 text-center rounded-xl border border-slate-200/80 dark:border-slate-800 bg-slate-50/50 dark:bg-slate-800/30 flex-1 flex flex-col items-center justify-center">
                        <div className="mx-auto mb-3 flex h-11 w-11 items-center justify-center rounded-xl bg-[#EAFBFC] dark:bg-cyan-950/60 text-[#00AFC0] dark:text-cyan-400 border border-[#00AFC0]/20">
                            <ShieldCheck className="h-5 w-5" />
                        </div>
                        <p className="text-xs font-bold text-slate-900 dark:text-slate-100">Verifikasi Dua Langkah</p>
                        <p className="mt-1 text-[11px] text-slate-500 dark:text-slate-400 max-w-xs leading-relaxed">
                            Saat diaktifkan, Anda akan diminta kode verifikasi dari aplikasi authenticator di ponsel saat masuk ke akun.
                        </p>
                    </div>

                    <div className="pt-2">
                        {hasSetupData ? (
                            <Button
                                onClick={() => setShowSetupModal(true)}
                                className="btn-gradient-primary text-white font-bold text-xs rounded-full px-5 py-2.5 cursor-pointer"
                            >
                                <ShieldCheck className="size-4 mr-2" />
                                Lanjutkan Konfigurasi
                            </Button>
                        ) : (
                            <Form
                                {...enable.form()}
                                onSuccess={() => setShowSetupModal(true)}
                            >
                                {({ processing }) => (
                                    <Button
                                        type="submit"
                                        disabled={processing}
                                        className="btn-gradient-primary text-white font-bold text-xs rounded-full px-5 py-2.5 cursor-pointer"
                                    >
                                        <ShieldCheck className="size-4 mr-2" />
                                        {processing ? 'Memproses...' : 'Aktifkan 2FA'}
                                    </Button>
                                )}
                            </Form>
                        )}
                    </div>
                </div>
            )}

            <TwoFactorSetupModal
                isOpen={showSetupModal}
                onClose={() => setShowSetupModal(false)}
                requiresConfirmation={requiresConfirmation}
                twoFactorEnabled={twoFactorEnabled}
                qrCodeSvg={qrCodeSvg}
                manualSetupKey={manualSetupKey}
                clearSetupData={clearSetupData}
                fetchSetupData={fetchSetupData}
                errors={errors}
            />
        </div>
    );
}
