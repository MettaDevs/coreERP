import React, { useState } from 'react';
import { Link, router } from '@inertiajs/react';
import {
    Bell,
    AlertTriangle,
    CheckCircle2,
    Clock,
    ShieldAlert,
    Sparkles,
    CheckCheck,
    ArrowRight,
    X,
} from 'lucide-react';
import { Button } from '@apperp/ui/button';
import { useNotifications, NotificationItem } from '@/hooks/use-notifications';
import { cn } from '@/lib/utils';

interface NotificationDropdownProps {
    className?: string;
    align?: 'left' | 'right';
}

export function NotificationDropdown({ className, align = 'right' }: NotificationDropdownProps) {
    const [isOpen, setIsOpen] = useState(false);
    const { notifications, unreadCount, markAsRead, markAllAsRead } = useNotifications();

    const handleItemClick = (notif: NotificationItem) => {
        markAsRead(notif.id);
        setIsOpen(false);
        if (notif.link) {
            router.visit(notif.link);
        }
    };

    const handleViewAll = (e: React.MouseEvent) => {
        e.preventDefault();
        e.stopPropagation();
        setIsOpen(false);
        router.visit('/notifications');
    };

    const renderTypeIcon = (type: NotificationItem['type']) => {
        switch (type) {
            case 'warning':
                return <AlertTriangle className="size-4 text-amber-500 shrink-0" />;
            case 'success':
                return <CheckCircle2 className="size-4 text-emerald-500 shrink-0" />;
            case 'security':
                return <ShieldAlert className="size-4 text-rose-500 shrink-0" />;
            case 'error':
                return <AlertTriangle className="size-4 text-red-500 shrink-0" />;
            default:
                return <Clock className="size-4 text-[#00AFC0] shrink-0" />;
        }
    };

    return (
        <div className="relative inline-block text-left">
            <Button
                variant="outline"
                size="icon"
                type="button"
                onClick={() => setIsOpen(!isOpen)}
                className={cn(
                    'relative size-9 shrink-0 cursor-pointer',
                    isOpen && 'ring-2 ring-primary/30 border-primary',
                    className
                )}
                title="Notifikasi & Pemberitahuan Aset"
            >
                <Bell className="size-4" />
                {unreadCount > 0 && (
                    <span className="absolute -top-1 -right-1 flex h-4 min-w-4 items-center justify-center rounded-full bg-rose-500 px-1 text-[9px] font-extrabold text-white ring-2 ring-white dark:ring-slate-900 animate-pulse">
                        {unreadCount > 9 ? '9+' : unreadCount}
                    </span>
                )}
            </Button>

            {isOpen && (
                <>
                    <div
                        className="fixed inset-0 z-40 bg-black/10 dark:bg-black/40 backdrop-blur-[1px]"
                        onClick={() => setIsOpen(false)}
                    />
                    <div
                        className={cn(
                            'absolute mt-2 w-80 sm:w-96 max-w-[calc(100vw-2rem)] bg-white dark:bg-slate-900 rounded-2xl shadow-2xl border border-slate-200/90 dark:border-slate-800 z-50 p-4 animate-in fade-in slide-in-from-top-2',
                            align === 'right' ? 'right-0' : 'left-0'
                        )}
                    >
                        {/* Dropdown Header */}
                        <div className="flex items-center justify-between pb-3 border-b border-slate-100 dark:border-slate-800">
                            <div className="flex items-center gap-2">
                                <h4 className="text-xs font-extrabold text-slate-900 dark:text-slate-100 flex items-center gap-1.5">
                                    <span>Notifikasi Aset</span>
                                    <Sparkles className="size-3.5 text-[#00AFC0]" />
                                </h4>
                                {unreadCount > 0 && (
                                    <span className="px-2 py-0.5 rounded-full text-[10px] font-bold bg-rose-50 dark:bg-rose-950/60 text-rose-600 dark:text-rose-400 border border-rose-200 dark:border-rose-900/40">
                                        {unreadCount} baru
                                    </span>
                                )}
                            </div>

                            <div className="flex items-center gap-2">
                                {unreadCount > 0 && (
                                    <button
                                        type="button"
                                        onClick={(e) => {
                                            e.stopPropagation();
                                            markAllAsRead();
                                        }}
                                        className="text-[11px] font-bold text-[#00AFC0] hover:text-[#008F9E] hover:underline cursor-pointer flex items-center gap-1"
                                    >
                                        <CheckCheck className="size-3.5" />
                                        <span>Tandai Dibaca</span>
                                    </button>
                                )}
                                <button
                                    type="button"
                                    onClick={() => setIsOpen(false)}
                                    className="p-1 rounded-lg text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors"
                                >
                                    <X className="size-3.5" />
                                </button>
                            </div>
                        </div>

                        {/* Notification Items List */}
                        <div className="divide-y divide-slate-100 dark:divide-slate-800/60 max-h-72 overflow-y-auto my-1 pr-1 custom-scrollbar">
                            {notifications.length === 0 ? (
                                <div className="py-8 text-center text-slate-400 text-xs">
                                    Tidak ada pemberitahuan saat ini.
                                </div>
                            ) : (
                                notifications.map((notif) => (
                                    <div
                                        key={notif.id}
                                        onClick={() => handleItemClick(notif)}
                                        className={cn(
                                            'p-3 rounded-xl transition-all cursor-pointer flex items-start gap-3 my-1 relative group',
                                            notif.unread
                                                ? 'bg-[#C8F1F5]/50 dark:bg-cyan-950/40 border border-[#08BFC3]/30 dark:border-cyan-900/50'
                                                : 'hover:bg-slate-50 dark:hover:bg-slate-800/40 border border-transparent'
                                        )}
                                    >
                                        <div className={cn(
                                            'size-10 rounded-xl flex items-center justify-center shrink-0 shadow-xs border mt-0.5',
                                            notif.type === 'warning' && 'bg-amber-50 dark:bg-amber-950/60 border-amber-200/80 dark:border-amber-900/60',
                                            notif.type === 'success' && 'bg-emerald-50 dark:bg-emerald-950/60 border-emerald-200/80 dark:border-emerald-900/60',
                                            notif.type === 'security' && 'bg-rose-50 dark:bg-rose-950/60 border-rose-200/80 dark:border-rose-900/60',
                                            (notif.type === 'info' || notif.type === 'error') && 'bg-[#C8F1F5] dark:bg-cyan-950/60 border-[#08BFC3]/30 dark:border-cyan-900/60'
                                        )}>
                                            {renderTypeIcon(notif.type)}
                                        </div>
                                        <div className="flex-1 text-xs min-w-0">
                                            <div className="flex items-center justify-between gap-1">
                                                <span className="font-bold text-slate-900 dark:text-slate-100 truncate">
                                                    {notif.title}
                                                </span>
                                                {notif.unread && (
                                                    <span className="size-2 rounded-full bg-rose-500 shrink-0" />
                                                )}
                                            </div>
                                            <p className="text-slate-600 dark:text-slate-300 text-[11px] mt-0.5 leading-snug line-clamp-2">
                                                {notif.message}
                                            </p>
                                            <span className="text-[10px] font-semibold text-slate-400 mt-1 block">
                                                {notif.time}
                                            </span>
                                        </div>
                                    </div>
                                ))
                            )}
                        </div>

                        {/* Footer Action: Lihat Semua Pemberitahuan */}
                        <div className="pt-2 border-t border-slate-100 dark:border-slate-800 text-center">
                            <Link
                                href="/notifications"
                                onClick={handleViewAll}
                                className="w-full py-2 px-3 rounded-xl text-xs font-bold text-[#00AFC0] hover:bg-[#EAFBFC] dark:hover:bg-cyan-950/50 flex items-center justify-center gap-1.5 transition-all cursor-pointer group"
                            >
                                <span>Lihat Semua Pemberitahuan</span>
                                <ArrowRight className="size-3.5 group-hover:translate-x-0.5 transition-transform" />
                            </Link>
                        </div>
                    </div>
                </>
            )}
        </div>
    );
}
