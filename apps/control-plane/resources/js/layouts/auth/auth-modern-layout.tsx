import { Link } from '@inertiajs/react';
import type { ReactNode } from 'react';
import AppLogoIcon from '@/components/app-logo-icon';
import { home } from '@/routes';
import {
    Network,
    Code2,
    ShieldCheck,
    Shield,
} from 'lucide-react';

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
        <div className="h-screen w-full relative overflow-hidden bg-[#F4F9FC] dark:bg-[#070F1E] font-sans flex flex-col justify-center transition-colors duration-300">
            {/* ================================================== */}
            {/* 1. BACKGROUND SCENE (LIGHT & DARK DUAL THEME)      */}
            {/* ================================================== */}
            <div className="absolute inset-0 z-0 pointer-events-none select-none overflow-hidden">
                {/* Light Mode Original Background */}
                <img
                    src="/images/auth-bg-full.jpg?v=20260813_0954"
                    alt="Sanata System Enterprise Background Illustration"
                    className="w-full h-full object-cover object-center dark:hidden"
                />

                {/* Dark Mode Enterprise Background */}
                <img
                    src="/images/auth-bg-dark.png?v=20260813_0956"
                    alt="Sanata System Enterprise Dark Mode Background Illustration"
                    className="w-full h-full object-cover hidden dark:block"
                    style={{ objectPosition: 'left center' }}
                />
            </div>

            {/* ================================================== */}
            {/* 2. MAIN CONTENT LAYOUT WRAPPER                     */}
            {/* ================================================== */}
            <div className="relative z-10 w-full h-full flex flex-col lg:flex-row items-center justify-between p-4 sm:p-6 lg:p-7 xl:p-8 max-w-[1480px] mx-auto gap-6 sm:gap-8 overflow-y-auto lg:overflow-hidden">
                
                {/* ===== LEFT BRANDING AREA ===== */}
                <div className="w-full lg:w-[50%] xl:w-[48%] max-w-[540px] flex flex-col justify-between h-full py-2 text-slate-800 dark:text-slate-100 space-y-4">
                    
                    {/* Top Logo Header */}
                    <Link href={home()} className="inline-flex items-center gap-3.5 group w-fit">
                        <div className="flex size-12 sm:size-13 items-center justify-center rounded-2xl bg-white dark:bg-slate-900/90 p-2 shadow-lg shadow-cyan-900/10 dark:shadow-[0_0_15px_rgba(0,201,200,0.25)] border border-[#DCE8F0] dark:border-cyan-500/50 group-hover:scale-105 transition-all overflow-hidden shrink-0">
                            <AppLogoIcon className="size-full" />
                        </div>
                        <div>
                            <span className="text-lg sm:text-xl font-black tracking-tight text-[#0B2040] dark:text-white block leading-tight">
                                PT SANATA SYSTEM
                            </span>
                            <span className="block text-[10px] sm:text-[10.5px] font-extrabold text-[#007C89] dark:text-cyan-400 tracking-[0.2em] uppercase mt-0.5">
                                IT Solutions &amp; Enterprise System
                            </span>
                        </div>
                    </Link>

                </div>

                {/* ===== RIGHT FLOATING FORM CARD ===== */}
                <div className="w-full lg:w-[46%] xl:w-[42%] flex items-center justify-center my-auto h-full max-h-full">
                    <div className="w-full max-w-[425px] bg-white dark:bg-[#071527]/75 dark:backdrop-blur-2xl rounded-3xl p-5 sm:p-6 shadow-xl shadow-cyan-900/10 dark:shadow-[0_20px_60px_-15px_rgba(0,0,0,0.8)] border border-[#DCE8F0] dark:border-cyan-500/30 dark:ring-1 dark:ring-cyan-500/20 relative z-20 space-y-3.5 text-slate-800 dark:text-slate-100 transition-colors duration-200">
                        
                        {/* Top Logo Badge */}
                        <div className="flex flex-col items-center text-center">
                            <div className="size-11 rounded-xl bg-[#F4F9FC] dark:bg-slate-800 border border-[#DCE8F0] dark:border-cyan-500/50 shadow-xs dark:shadow-[0_0_12px_rgba(0,201,200,0.2)] p-2 flex items-center justify-center text-[#08BFC3] dark:text-cyan-400 shrink-0">
                                <AppLogoIcon className="size-full" />
                            </div>
                            
                            {title && (
                                <h2 className="text-lg sm:text-xl font-extrabold text-[#0B2040] dark:text-slate-100 tracking-tight mt-2">
                                    {title}
                                </h2>
                            )}
                            {description && (
                                <p className="text-[10.5px] text-slate-500 dark:text-slate-400 font-medium max-w-[270px] mx-auto leading-tight mt-0.5">
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
