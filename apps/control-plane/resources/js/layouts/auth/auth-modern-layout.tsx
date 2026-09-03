import { Link } from '@inertiajs/react';
import type { ReactNode } from 'react';
import AppLogoIcon from '@/components/app-logo-icon';
import { home } from '@/routes';

export default function AuthModernLayout({
    children,
    title,
    description,
}: {
    children: ReactNode;
    title?: string;
    description?: string;
}) {
    return (
        <div className="relative flex h-screen w-full flex-col justify-center overflow-hidden bg-[#F4F9FC] font-sans transition-colors duration-300 dark:bg-[#070F1E]">
            {/* ================================================== */}
            {/* 1. BACKGROUND SCENE (LIGHT & DARK DUAL THEME)      */}
            {/* ================================================== */}
            <div className="pointer-events-none absolute inset-0 z-0 overflow-hidden select-none">
                {/* Light Mode Original Background */}
                <img
                    src="/images/auth-bg-full.jpg?v=20260813_0954"
                    alt="Sanata System Enterprise Background Illustration"
                    className="h-full w-full object-cover object-center dark:hidden"
                />

                {/* Dark Mode Enterprise Background */}
                <img
                    src="/images/auth-bg-dark.jpg?v=20260820_0830"
                    alt="Sanata System Enterprise Dark Mode Background Illustration"
                    className="hidden h-full w-full object-cover dark:block"
                    style={{ objectPosition: 'left center' }}
                />
            </div>

            {/* ================================================== */}
            {/* 2. MAIN CONTENT LAYOUT WRAPPER                     */}
            {/* ================================================== */}
            <div className="relative z-10 mx-auto flex h-full w-full max-w-[1480px] flex-col items-center justify-between gap-6 overflow-y-auto p-4 sm:gap-8 sm:p-6 lg:flex-row lg:overflow-hidden lg:p-7 xl:p-8">
                {/* ===== LEFT BRANDING AREA ===== */}
                <div className="flex h-full w-full max-w-[540px] flex-col justify-between space-y-4 py-2 text-slate-800 lg:w-[50%] xl:w-[48%] dark:text-slate-100">
                    {/* Top Logo Header */}
                    <Link
                        href={home()}
                        className="group inline-flex w-fit items-center gap-3.5"
                    >
                        <div className="flex size-12 shrink-0 items-center justify-center overflow-hidden rounded-2xl border border-[#DCE8F0] bg-white p-2 shadow-lg shadow-cyan-900/10 transition-all group-hover:scale-105 sm:size-13 dark:border-cyan-500/50 dark:bg-slate-900/90 dark:shadow-[0_0_15px_rgba(0,201,200,0.25)]">
                            <AppLogoIcon className="size-full" />
                        </div>
                        <div>
                            <span className="block text-lg leading-tight font-black tracking-tight text-[#0B2040] sm:text-xl dark:text-white">
                                PT SANATA SYSTEM
                            </span>
                            <span className="mt-0.5 block text-[10px] font-extrabold tracking-[0.2em] text-[#007C89] uppercase sm:text-[10.5px] dark:text-cyan-400">
                                IT Solutions &amp; Enterprise System
                            </span>
                        </div>
                    </Link>
                </div>

                {/* ===== RIGHT FLOATING FORM CARD ===== */}
                <div className="my-auto flex h-full max-h-full w-full items-center justify-center lg:w-[46%] xl:w-[42%]">
                    <div className="relative z-20 w-full max-w-[425px] space-y-3.5 rounded-3xl border border-[#DCE8F0] bg-white p-5 text-slate-800 shadow-xl shadow-cyan-900/10 transition-colors duration-200 sm:p-6 dark:border-cyan-500/30 dark:bg-[#071527]/75 dark:text-slate-100 dark:shadow-[0_20px_60px_-15px_rgba(0,0,0,0.8)] dark:ring-1 dark:ring-cyan-500/20 dark:backdrop-blur-2xl">
                        {/* Top Logo Badge */}
                        <div className="flex flex-col items-center text-center">
                            <div className="flex size-11 shrink-0 items-center justify-center rounded-xl border border-[#DCE8F0] bg-[#F4F9FC] p-2 text-[#08BFC3] shadow-xs dark:border-cyan-500/50 dark:bg-slate-800 dark:text-cyan-400 dark:shadow-[0_0_12px_rgba(0,201,200,0.2)]">
                                <AppLogoIcon className="size-full" />
                            </div>

                            {title && (
                                <h2 className="mt-2 text-lg font-extrabold tracking-tight text-[#0B2040] sm:text-xl dark:text-slate-100">
                                    {title}
                                </h2>
                            )}
                            {description && (
                                <p className="mx-auto mt-0.5 max-w-[270px] text-[10.5px] leading-tight font-medium text-slate-500 dark:text-slate-400">
                                    {description}
                                </p>
                            )}
                        </div>

                        {/* Form Body */}
                        {children}
                    </div>
                </div>
            </div>
        </div>
    );
}
