import { Head } from '@inertiajs/react';
import { useState, useEffect } from 'react';
import { edit as editAppearance } from '@/routes/appearance';
import { useAppearance, applyAllAppearanceSettings } from '@/hooks/use-appearance';
import type { Appearance } from '@/hooks/use-appearance';
import { Switch } from '@apperp/ui/switch';
import {
    Palette,
    Sun,
    Moon,
    Monitor,
    PanelLeft,
    PanelLeftOpen,
    PanelLeftClose,
    Sliders,
    Sparkles,
    CheckCircle2,
    X,
    LayoutGrid,
} from 'lucide-react';
import { useSidebar } from '@apperp/ui/sidebar';
import { cn } from '@/lib/utils';

export default function AppearancePage() {
    const { appearance, updateAppearance } = useAppearance();
    const { state: currentSidebarState, setOpen: setSidebarOpen } = useSidebar();

    // Floating toast state
    const [toastMessage, setToastMessage] = useState<string | null>(null);
    const showToast = (msg: string) => {
        setToastMessage(msg);
        setTimeout(() => setToastMessage(null), 3500);
    };

    const handleSidebarModeChange = (mode: 'expanded' | 'collapsed') => {
        setSidebarOpen(mode === 'expanded');
        if (typeof window !== 'undefined') {
            localStorage.setItem('sidebar_mode', mode);
        }
        showToast('Pengaturan tampilan berhasil diperbarui.');
    };

    // Display Density state ('compact' | 'default' | 'comfortable')
    const [density, setDensity] = useState<'compact' | 'default' | 'comfortable'>(() => {
        if (typeof window !== 'undefined') {
            return (localStorage.getItem('ui_density') as 'compact' | 'default' | 'comfortable') || 'default';
        }
        return 'default';
    });

    const handleDensityChange = (val: 'compact' | 'default' | 'comfortable') => {
        setDensity(val);
        if (typeof window !== 'undefined') {
            localStorage.setItem('ui_density', val);
        }
        applyAllAppearanceSettings();
        showToast('Pengaturan tampilan berhasil diperbarui.');
    };

    // Interface Animations toggle
    const [enableAnimations, setEnableAnimations] = useState<boolean>(() => {
        if (typeof window !== 'undefined') {
            const stored = localStorage.getItem('ui_animations');
            return stored === null ? true : stored === 'true';
        }
        return true;
    });

    const handleAnimationsToggle = (checked: boolean) => {
        setEnableAnimations(checked);
        if (typeof window !== 'undefined') {
            localStorage.setItem('ui_animations', String(checked));
        }
        applyAllAppearanceSettings();
        showToast('Pengaturan tampilan berhasil diperbarui.');
    };

    // Sidebar Tooltips toggle
    const [enableTooltips, setEnableTooltips] = useState<boolean>(() => {
        if (typeof window !== 'undefined') {
            const stored = localStorage.getItem('ui_tooltips');
            return stored === null ? true : stored === 'true';
        }
        return true;
    });

    const handleTooltipsToggle = (checked: boolean) => {
        setEnableTooltips(checked);
        if (typeof window !== 'undefined') {
            localStorage.setItem('ui_tooltips', String(checked));
        }
        applyAllAppearanceSettings();
        showToast('Pengaturan tampilan berhasil diperbarui.');
    };

    // Handle theme update with toast feedback
    const handleThemeChange = (mode: Appearance) => {
        updateAppearance(mode);
        applyAllAppearanceSettings();
        showToast('Pengaturan tampilan berhasil diperbarui.');
    };

    const themeOptions: { value: Appearance; icon: typeof Sun; label: string; desc: string }[] = [
        { value: 'light', icon: Sun, label: 'Light', desc: 'Tampilan terang modern' },
        { value: 'dark', icon: Moon, label: 'Dark', desc: 'Tampilan gelap elegan' },
        { value: 'system', icon: Monitor, label: 'System', desc: 'Mengikuti tema perangkat' },
    ];

    const sidebarOptions = [
        { value: 'expanded', icon: PanelLeftOpen, label: 'Expanded', desc: 'Navigasi terbuka penuh' },
        { value: 'collapsed', icon: PanelLeftClose, label: 'Collapsed', desc: 'Navigasi ikon ringkas' },
    ] as const;

    const densityOptions = [
        { value: 'compact', label: 'Compact', desc: 'Ringkas & padat' },
        { value: 'default', label: 'Default', desc: 'Standar seimbang' },
        { value: 'comfortable', label: 'Comfortable', desc: 'Longgar & lega' },
    ] as const;

    return (
        <>
            <Head title="Appearance Settings" />

            {/* --- FLOATING TOAST --- */}
            {toastMessage && (
                <div className="fixed bottom-6 right-6 z-50 flex items-center gap-3 px-4 py-3 bg-slate-900 text-white dark:bg-white dark:text-slate-900 rounded-2xl shadow-2xl border border-slate-800 dark:border-slate-200 animate-in fade-in slide-in-from-bottom-4 text-xs font-semibold">
                    <div className="p-1 rounded-full bg-emerald-500/20 text-emerald-400 dark:text-emerald-600">
                        <CheckCircle2 className="size-4 shrink-0" />
                    </div>
                    <span>{toastMessage}</span>
                    <button
                        onClick={() => setToastMessage(null)}
                        className="ml-2 p-1 text-slate-400 hover:text-white dark:hover:text-slate-900 transition-colors"
                    >
                        <X className="size-3.5" />
                    </button>
                </div>
            )}

            {/* =====================================================
                GLOBAL DYNAMIC BACKGROUND PT SANATA SYSTEM
            ====================================================== */}
            <div className="pointer-events-none fixed inset-0 overflow-hidden print:hidden z-0 bg-slate-50/50 dark:bg-slate-950" />

            <div className="relative z-10 flex flex-col gap-6 w-full max-w-[1400px] mx-auto p-4 sm:p-6 min-w-0">
                {/* --- HEADER HALAMAN --- */}
                <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 pb-4 border-b border-slate-200/80 dark:border-slate-800/80">
                    <div className="flex items-start sm:items-center gap-3 min-w-0">
                        <Palette className="size-6 text-slate-700 dark:text-slate-200 shrink-0" />
                        <div className="min-w-0">
                            <div className="flex items-center gap-2 flex-wrap">
                                <h1 className="text-xl sm:text-2xl font-extrabold tracking-tight text-slate-900 dark:text-slate-100">
                                    Appearance
                                </h1>
                            </div>
                            <p className="text-xs sm:text-sm text-slate-500 dark:text-slate-400 mt-1 truncate">
                                Atur tema dan tampilan aplikasi sesuai kenyamanan penggunaan Anda.
                            </p>
                        </div>
                    </div>
                </div>

                {/* --- SECTION 1: TEMA --- */}
                <div className="bg-white dark:bg-slate-900 rounded-2xl p-5 sm:p-7 border border-slate-200/80 dark:border-slate-800 shadow-xs space-y-5">
                    <div className="flex items-start gap-3.5 pb-3 border-b border-slate-100 dark:border-slate-800">
                        <Sun className="size-5 text-slate-700 dark:text-slate-200 shrink-0 mt-0.5" />
                        <div>
                            <h2 className="text-base font-bold text-slate-900 dark:text-slate-100">
                                Tema Antarmuka
                            </h2>
                            <p className="text-xs text-slate-500 dark:text-slate-400 mt-0.5">
                                Pilih skema warna tampilan antarmuka aplikasi (Light, Dark, atau System).
                            </p>
                        </div>
                    </div>

                    <div className="grid grid-cols-1 sm:grid-cols-3 gap-3.5 max-w-3xl">
                        {themeOptions.map(({ value, icon: Icon, label, desc }) => {
                            const isActive = appearance === value;
                            return (
                                <button
                                    key={value}
                                    type="button"
                                    onClick={() => handleThemeChange(value)}
                                    className={cn(
                                        'flex flex-col items-start p-4 rounded-xl text-left border transition-all cursor-pointer relative',
                                        isActive
                                            ? 'bg-slate-100/80 dark:bg-slate-800 border-slate-900 dark:border-slate-100 shadow-xs ring-1 ring-slate-900/20 dark:ring-slate-100/20'
                                            : 'bg-slate-50/50 dark:bg-slate-800/40 border-slate-200/80 dark:border-slate-800 hover:bg-slate-100/70 dark:hover:bg-slate-800/80'
                                    )}
                                >
                                    <div className="flex items-center justify-between w-full mb-2">
                                        <div
                                            className={cn(
                                                'p-2 rounded-lg',
                                                isActive
                                                    ? 'bg-slate-900 dark:bg-slate-100 text-white dark:text-slate-900'
                                                    : 'bg-slate-200/60 dark:bg-slate-700/60 text-slate-600 dark:text-slate-400'
                                            )}
                                        >
                                            <Icon className="size-4" />
                                        </div>
                                        {isActive && (
                                            <span className="px-2 py-0.5 text-[10px] font-bold rounded-full bg-slate-900 dark:bg-slate-100 text-white dark:text-slate-900 shadow-xs">
                                                Aktif
                                            </span>
                                        )}
                                    </div>
                                    <span className="text-sm font-bold text-slate-900 dark:text-slate-100">
                                        {label}
                                    </span>
                                    <span className="text-[11px] text-slate-500 dark:text-slate-400 mt-0.5">
                                        {desc}
                                    </span>
                                </button>
                            );
                        })}
                    </div>
                </div>

                {/* --- SECTION 2: SIDEBAR --- */}
                <div className="bg-white dark:bg-slate-900 rounded-2xl p-5 sm:p-7 border border-slate-200/80 dark:border-slate-800 shadow-xs space-y-5">
                    <div className="flex items-start gap-3.5 pb-3 border-b border-slate-100 dark:border-slate-800">
                        <PanelLeft className="size-5 text-slate-700 dark:text-slate-200 shrink-0 mt-0.5" />
                        <div>
                            <h2 className="text-base font-bold text-slate-900 dark:text-slate-100">
                                Modus Sidebar
                            </h2>
                            <p className="text-xs text-slate-500 dark:text-slate-400 mt-0.5">
                                Atur tata letak navigasi utama dalam posisi melebar (Expanded) atau ringkas (Collapsed).
                            </p>
                        </div>
                    </div>

                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-3.5 max-w-2xl">
                        {sidebarOptions.map(({ value, icon: Icon, label, desc }) => {
                            const isActive = currentSidebarState === value;
                            return (
                                <button
                                    key={value}
                                    type="button"
                                    onClick={() => handleSidebarModeChange(value)}
                                    className={cn(
                                        'flex items-center gap-3.5 p-4 rounded-xl text-left border transition-all cursor-pointer relative',
                                        isActive
                                            ? 'bg-slate-100/80 dark:bg-slate-800 border-slate-900 dark:border-slate-100 shadow-xs ring-1 ring-slate-900/20 dark:ring-slate-100/20'
                                            : 'bg-slate-50/50 dark:bg-slate-800/40 border-slate-200/80 dark:border-slate-800 hover:bg-slate-100/70 dark:hover:bg-slate-800/80'
                                    )}
                                >
                                    <div
                                        className={cn(
                                            'p-2.5 rounded-lg shrink-0',
                                            isActive
                                                ? 'bg-slate-900 dark:bg-slate-100 text-white dark:text-slate-900'
                                                : 'bg-slate-200/60 dark:bg-slate-700/60 text-slate-600 dark:text-slate-400'
                                        )}
                                    >
                                        <Icon className="size-5" />
                                    </div>
                                    <div className="flex flex-col min-w-0 flex-1">
                                        <div className="flex items-center justify-between">
                                            <span className="text-sm font-bold text-slate-900 dark:text-slate-100">
                                                {label}
                                            </span>
                                            {isActive && (
                                                <span className="px-2 py-0.5 text-[10px] font-bold rounded-full bg-slate-900 dark:bg-slate-100 text-white dark:text-slate-900 shadow-xs">
                                                    Aktif
                                                </span>
                                            )}
                                        </div>
                                        <span className="text-[11px] text-slate-500 dark:text-slate-400 mt-0.5 truncate">
                                            {desc}
                                        </span>
                                    </div>
                                </button>
                            );
                        })}
                    </div>
                </div>

                {/* --- SECTION 3: KEPADATAN TAMPILAN --- */}
                <div className="bg-white dark:bg-slate-900 rounded-2xl p-5 sm:p-7 border border-slate-200/80 dark:border-slate-800 shadow-xs space-y-5">
                    <div className="flex items-start gap-3.5 pb-3 border-b border-slate-100 dark:border-slate-800">
                        <Sliders className="size-5 text-slate-700 dark:text-slate-200 shrink-0 mt-0.5" />
                        <div>
                            <h2 className="text-base font-bold text-slate-900 dark:text-slate-100">
                                Kepadatan Tampilan (Density)
                            </h2>
                            <p className="text-xs text-slate-500 dark:text-slate-400 mt-0.5">
                                Atur jarak antar elemen tabel, formulir, dan margin komponen antarmuka.
                            </p>
                        </div>
                    </div>

                    <div className="grid grid-cols-1 sm:grid-cols-3 gap-3.5 max-w-3xl">
                        {densityOptions.map(({ value, label, desc }) => {
                            const isActive = density === value;
                            return (
                                <button
                                    key={value}
                                    type="button"
                                    onClick={() => handleDensityChange(value)}
                                    className={cn(
                                        'flex flex-col items-start p-4 rounded-xl text-left border transition-all cursor-pointer relative',
                                        isActive
                                            ? 'bg-slate-100/80 dark:bg-slate-800 border-slate-900 dark:border-slate-100 shadow-xs ring-1 ring-slate-900/20 dark:ring-slate-100/20'
                                            : 'bg-slate-50/50 dark:bg-slate-800/40 border-slate-200/80 dark:border-slate-800 hover:bg-slate-100/70 dark:hover:bg-slate-800/80'
                                    )}
                                >
                                    <div className="flex items-center justify-between w-full mb-2">
                                        <div
                                            className={cn(
                                                'p-2 rounded-lg',
                                                isActive
                                                    ? 'bg-slate-900 dark:bg-slate-100 text-white dark:text-slate-900'
                                                    : 'bg-slate-200/60 dark:bg-slate-700/60 text-slate-600 dark:text-slate-400'
                                            )}
                                        >
                                            <LayoutGrid className="size-4" />
                                        </div>
                                        {isActive && (
                                            <span className="px-2 py-0.5 text-[10px] font-bold rounded-full bg-slate-900 dark:bg-slate-100 text-white dark:text-slate-900 shadow-xs">
                                                Aktif
                                            </span>
                                        )}
                                    </div>
                                    <span className="text-sm font-bold text-slate-900 dark:text-slate-100">
                                        {label}
                                    </span>
                                    <span className="text-[11px] text-slate-500 dark:text-slate-400 mt-0.5">
                                        {desc}
                                    </span>
                                </button>
                            );
                        })}
                    </div>
                </div>

                {/* --- SECTION 4: PREFERENSI TAMPILAN --- */}
                <div className="bg-white dark:bg-slate-900 rounded-2xl p-5 sm:p-7 border border-slate-200/80 dark:border-slate-800 shadow-xs space-y-5">
                    <div className="flex items-start gap-3.5 pb-3 border-b border-slate-100 dark:border-slate-800">
                        <Sparkles className="size-5 text-slate-700 dark:text-slate-200 shrink-0 mt-0.5" />
                        <div>
                            <h2 className="text-base font-bold text-slate-900 dark:text-slate-100">
                                Preferensi Antarmuka
                            </h2>
                            <p className="text-xs text-slate-500 dark:text-slate-400 mt-0.5">
                                Aktifkan mikro-animasi dan petunjuk navigasi untuk pengalaman pengguna yang optimal.
                            </p>
                        </div>
                    </div>

                    <div className="space-y-4 max-w-3xl">
                        {/* Toggle 1: Animasi Antarmuka */}
                        <div className="flex items-center justify-between gap-4 p-4 rounded-xl bg-slate-50/70 dark:bg-slate-800/40 border border-slate-100 dark:border-slate-800">
                            <div className="space-y-0.5">
                                <label
                                    htmlFor="toggle-animations"
                                    className="text-xs sm:text-sm font-bold text-slate-900 dark:text-slate-100 block cursor-pointer"
                                >
                                    Animasi Antarmuka
                                </label>
                                <p className="text-xs text-slate-500 dark:text-slate-400">
                                    Menampilkan efek transisi dan mikro-animasi halus pada elemen antarmuka.
                                </p>
                            </div>
                            <Switch
                                id="toggle-animations"
                                checked={enableAnimations}
                                onCheckedChange={handleAnimationsToggle}
                                className="shrink-0"
                            />
                        </div>

                        {/* Toggle 2: Tooltip Navigasi Sidebar */}
                        <div className="flex items-center justify-between gap-4 p-4 rounded-xl bg-slate-50/70 dark:bg-slate-800/40 border border-slate-100 dark:border-slate-800">
                            <div className="space-y-0.5">
                                <label
                                    htmlFor="toggle-tooltips"
                                    className="text-xs sm:text-sm font-bold text-slate-900 dark:text-slate-100 block cursor-pointer"
                                >
                                    Tooltip Navigasi Sidebar
                                </label>
                                <p className="text-xs text-slate-500 dark:text-slate-400">
                                    Menampilkan petunjuk nama menu saat mengarahkan kursor di atas ikon sidebar.
                                </p>
                            </div>
                            <Switch
                                id="toggle-tooltips"
                                checked={enableTooltips}
                                onCheckedChange={handleTooltipsToggle}
                                className="shrink-0"
                            />
                        </div>
                    </div>
                </div>
            </div>
        </>
    );
}

AppearancePage.layout = {
    breadcrumbs: [
        {
            title: 'Appearance',
            href: editAppearance(),
        },
    ],
};
