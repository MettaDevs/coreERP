import AppLogoIcon from '@/components/app-logo-icon';

export default function AppLogo() {
    return (
        <>
            <div className="flex aspect-square size-8 items-center justify-center rounded-md bg-white p-1.5 shadow-xs border border-slate-200 overflow-hidden">
                <AppLogoIcon className="size-full" />
            </div>
            <div className="ml-1.5 grid flex-1 text-left text-sm">
                <span className="mb-0.5 truncate leading-tight font-bold text-slate-900 dark:text-white">
                    PT Sanata System
                </span>
            </div>
        </>
    );
}
