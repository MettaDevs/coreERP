import { Link, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import AppLogoIcon from '@/components/app-logo-icon';
import { home } from '@/routes';
import { cn } from '@/lib/utils';
import { Boxes, ShieldCheck, Sparkles, Layers, CheckCircle2 } from 'lucide-react';

export default function AuthModernLayout({
    children,
    title,
    description,
}: {
    children: ReactNode;
    title?: string;
    description?: string;
}) {
    const { name } = usePage().props;
    const appName = typeof name === 'string' && name ? name : 'CoreERP';

    return (
        <div className="min-h-screen w-full lg:grid lg:grid-cols-12 bg-slate-50 dark:bg-slate-950 text-slate-900 dark:text-slate-100 transition-colors duration-200">
            {/* Left Hero Panel */}
            <div className="relative hidden lg:col-span-5 lg:flex flex-col justify-between p-12 bg-gradient-to-br from-slate-950 via-slate-900 to-indigo-950 text-white overflow-hidden border-r border-slate-800/60 shadow-2xl">
                {/* Decorative background ambient lighting & grid */}
                <div className="absolute -top-32 -left-32 w-96 h-96 bg-indigo-500/20 rounded-full blur-3xl pointer-events-none" />
                <div className="absolute -bottom-32 -right-32 w-96 h-96 bg-blue-500/15 rounded-full blur-3xl pointer-events-none" />
                <div className="absolute inset-0 opacity-[0.04] bg-[radial-gradient(#ffffff_1px,transparent_1px)] [background-size:20px_20px] pointer-events-none" />

                {/* Header Logo */}
                <div className="relative z-10">
                    <Link
                        href={home()}
                        className="inline-flex items-center gap-3 group transition-transform active:scale-95"
                    >
                        <div className="flex size-11 items-center justify-center rounded-xl bg-gradient-to-tr from-indigo-600 to-blue-500 p-2.5 shadow-lg shadow-indigo-500/25 ring-1 ring-white/20 group-hover:shadow-indigo-500/40 transition-all">
                            <AppLogoIcon className="size-full fill-white" />
                        </div>
                        <div>
                            <span className="text-xl font-bold tracking-tight bg-gradient-to-r from-white via-slate-100 to-slate-300 bg-clip-text text-transparent">
                                {appName}
                            </span>
                            <span className="block text-xs font-medium text-indigo-300/80 tracking-wider uppercase">
                                Enterprise System
                            </span>
                        </div>
                    </Link>
                </div>

                {/* Content Feature Highlights */}
                <div className="relative z-10 my-auto py-8 space-y-8">
                    <div className="inline-flex items-center gap-2 px-3 py-1.5 rounded-full bg-indigo-500/10 border border-indigo-400/20 backdrop-blur-md text-xs font-medium text-indigo-300">
                        <Sparkles className="size-3.5 text-indigo-400 animate-pulse" />
                        <span>Sistem ERP & Asset Management Modern</span>
                    </div>

                    <div className="space-y-3">
                        <h2 className="text-3xl font-extrabold tracking-tight text-white leading-tight">
                            Kelola Aset & Operasional Bisnis Terintegrasi
                        </h2>
                        <p className="text-sm text-slate-300/90 leading-relaxed max-w-md">
                            Platform terpusat untuk otomatisasi hirarki aset, penomoran berjenjang, jadwal pemeliharaan, serta kontrol akses berbasis peran.
                        </p>
                    </div>

                    <div className="space-y-3.5 pt-2">
                        <div className="flex items-start gap-3 p-3 rounded-xl bg-white/5 border border-white/10 backdrop-blur-sm transition-all hover:bg-white/10">
                            <div className="p-2 rounded-lg bg-indigo-500/20 text-indigo-400 shrink-0">
                                <Boxes className="size-4" />
                            </div>
                            <div>
                                <h4 className="text-xs font-semibold text-white">Master Data & Asset Hierarchy</h4>
                                <p className="text-xs text-slate-300">Entitas, Group, Kategori, Jenis Aset, hingga Kondisi & Maintenance.</p>
                            </div>
                        </div>

                        <div className="flex items-start gap-3 p-3 rounded-xl bg-white/5 border border-white/10 backdrop-blur-sm transition-all hover:bg-white/10">
                            <div className="p-2 rounded-lg bg-blue-500/20 text-blue-400 shrink-0">
                                <Layers className="size-4" />
                            </div>
                            <div>
                                <h4 className="text-xs font-semibold text-white">Number Sequence Automatic</h4>
                                <p className="text-xs text-slate-300">Penomoran kode master otomatis oleh backend tanpa input manual.</p>
                            </div>
                        </div>

                        <div className="flex items-start gap-3 p-3 rounded-xl bg-white/5 border border-white/10 backdrop-blur-sm transition-all hover:bg-white/10">
                            <div className="p-2 rounded-lg bg-emerald-500/20 text-emerald-400 shrink-0">
                                <ShieldCheck className="size-4" />
                            </div>
                            <div>
                                <h4 className="text-xs font-semibold text-white">Multi-tenant Security & RBAC</h4>
                                <p className="text-xs text-slate-300">Isolasi data antar tenant dengan otorisasi hak akses berlapis.</p>
                            </div>
                        </div>
                    </div>
                </div>

                {/* Footer status */}
                <div className="relative z-10 flex items-center justify-between text-xs text-slate-400 border-t border-white/10 pt-6">
                    <div className="flex items-center gap-2">
                        <span className="size-2 rounded-full bg-emerald-400 animate-pulse" />
                        <span className="font-medium text-slate-300">System Ready & Operational</span>
                    </div>
                    <span>v0.1.0 Enterprise</span>
                </div>
            </div>

            {/* Right Form Panel */}
            <div className="lg:col-span-7 flex min-h-screen flex-col items-center justify-center p-6 sm:p-10 md:p-12 relative bg-slate-50 dark:bg-slate-950">
                <div className="w-full max-w-md space-y-6 my-auto">
                    {/* Mobile Header Logo */}
                    <div className="flex lg:hidden flex-col items-center gap-3 text-center mb-6">
                        <Link
                            href={home()}
                            className="inline-flex items-center gap-3 group"
                        >
                            <div className="flex size-11 items-center justify-center rounded-xl bg-gradient-to-tr from-indigo-600 to-blue-500 p-2.5 shadow-md">
                                <AppLogoIcon className="size-full fill-white" />
                            </div>
                            <span className="text-xl font-bold text-slate-900 dark:text-white">
                                {appName}
                            </span>
                        </Link>
                    </div>

                    {/* Main Auth Form Container Card */}
                    <div className="bg-white dark:bg-slate-900/90 rounded-2xl border border-slate-200/80 dark:border-slate-800 p-7 sm:p-9 shadow-xl shadow-slate-200/50 dark:shadow-none backdrop-blur-xl space-y-6">
                        <div className="space-y-2">
                            {title && (
                                <h1 className="text-2xl font-bold tracking-tight text-slate-900 dark:text-white">
                                    {title}
                                </h1>
                            )}
                            {description && (
                                <p className="text-sm text-slate-500 dark:text-slate-400">
                                    {description}
                                </p>
                            )}
                        </div>

                        {children}
                    </div>
                </div>
            </div>
        </div>
    );
}
