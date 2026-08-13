import React, { useState, useMemo } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import AppSidebarLayout from '@/layouts/app/app-sidebar-layout';
import {
    Bell,
    AlertTriangle,
    CheckCircle2,
    Clock,
    ShieldAlert,
    Sparkles,
    CheckCheck,
    Trash2,
    Search,
    Filter,
    ArrowUpRight,
    SlidersHorizontal,
    Info,
} from 'lucide-react';
import { useNotifications, NotificationItem } from '@/hooks/use-notifications';
import { Button } from '@apperp/ui/button';
import { Input } from '@apperp/ui/input';
import { cn } from '@/lib/utils';

export default function NotificationsPage() {
    const {
        notifications,
        unreadCount,
        markAsRead,
        markAllAsRead,
        deleteNotification,
        clearAll,
        addNotification,
    } = useNotifications();

    const [searchQuery, setSearchQuery] = useState('');
    const [activeFilter, setActiveFilter] = useState<'all' | 'unread' | 'maintenance' | 'security' | 'workflow' | 'system'>('all');

    const filteredNotifications = useMemo(() => {
        return notifications.filter((notif) => {
            const matchesSearch =
                notif.title.toLowerCase().includes(searchQuery.toLowerCase()) ||
                notif.message.toLowerCase().includes(searchQuery.toLowerCase());

            if (!matchesSearch) return false;

            if (activeFilter === 'unread') return notif.unread;
            if (activeFilter === 'maintenance')
                return notif.category === 'maintenance' || notif.category === 'work-order' || notif.category === 'calibration';
            if (activeFilter === 'security') return notif.category === 'security';
            if (activeFilter === 'workflow') return notif.category === 'workflow';
            if (activeFilter === 'system') return notif.category === 'system';

            return true;
        });
    }, [notifications, searchQuery, activeFilter]);

    const handleSimulateNew = () => {
        const types: NotificationItem['type'][] = ['warning', 'success', 'info', 'security'];
        const categories: NotificationItem['category'][] = ['maintenance', 'work-order', 'calibration', 'security', 'workflow', 'system'];
        const randomType = types[Math.floor(Math.random() * types.length)];
        const randomCat = categories[Math.floor(Math.random() * categories.length)];

        addNotification({
            title: `Notifikasi Realtime Baru (#${Math.floor(Math.random() * 900 + 100)})`,
            message: `Pemberitahuan otomatis sistem terdeteksi pada ${new Date().toLocaleTimeString('id-ID')}. Sesi & aset terpantau aktif.`,
            type: randomType,
            category: randomCat,
            link: '/dashboard',
        });
    };

    const renderTypeIcon = (type: NotificationItem['type']) => {
        switch (type) {
            case 'warning':
                return <AlertTriangle className="size-5 text-amber-500 shrink-0" />;
            case 'success':
                return <CheckCircle2 className="size-5 text-emerald-500 shrink-0" />;
            case 'security':
                return <ShieldAlert className="size-5 text-rose-500 shrink-0" />;
            case 'error':
                return <AlertTriangle className="size-5 text-red-500 shrink-0" />;
            default:
                return <Clock className="size-5 text-[#00AFC0] shrink-0" />;
        }
    };

    const getCategoryBadge = (category: NotificationItem['category']) => {
        switch (category) {
            case 'maintenance':
                return <span className="px-2 py-0.5 rounded-full text-[10px] font-extrabold bg-amber-50 dark:bg-amber-950/60 text-amber-600 dark:text-amber-400 border border-amber-200/80">Aset &amp; Maintenance</span>;
            case 'work-order':
                return <span className="px-2 py-0.5 rounded-full text-[10px] font-extrabold bg-emerald-50 dark:bg-emerald-950/60 text-emerald-600 dark:text-emerald-400 border border-emerald-200/80">Work Order</span>;
            case 'calibration':
                return <span className="px-2 py-0.5 rounded-full text-[10px] font-extrabold bg-blue-50 dark:bg-blue-950/60 text-blue-600 dark:text-blue-400 border border-blue-200/80">Kalibrasi</span>;
            case 'security':
                return <span className="px-2 py-0.5 rounded-full text-[10px] font-extrabold bg-rose-50 dark:bg-rose-950/60 text-rose-600 dark:text-rose-400 border border-rose-200/80">Keamanan</span>;
            case 'workflow':
                return <span className="px-2 py-0.5 rounded-full text-[10px] font-extrabold bg-purple-50 dark:bg-purple-950/60 text-purple-600 dark:text-purple-400 border border-purple-200/80">Workflow</span>;
            default:
                return <span className="px-2 py-0.5 rounded-full text-[10px] font-extrabold bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-400 border border-slate-200/60">Sistem</span>;
        }
    };

    return (
        <>
            <Head title="Pusat Pemberitahuan & Notifikasi Aset" />

            {/* Global Dynamic Background */}
            <div className="pointer-events-none fixed inset-0 overflow-hidden print:hidden z-0">
                <div className="absolute inset-0 bg-[linear-gradient(125deg,#E8F5FC_0%,#F6FBFF_38%,#DDFBFC_100%)] dark:bg-[radial-gradient(ellipse_at_top_right,_var(--tw-gradient-stops))] dark:from-[#0B1E36] dark:via-[#070D18] dark:to-[#04070E]" />
                <div className="absolute -right-[140px] -top-[100px] h-[550px] w-[550px] rounded-full bg-[#00B8C8]/15 blur-[120px] dark:bg-[#00C9C8]/15 dark:blur-[140px]" />
                <div className="absolute -left-[180px] top-[140px] h-[520px] w-[520px] rounded-full bg-[#1677FF]/10 blur-[120px] dark:bg-[#005F73]/25 dark:blur-[130px]" />
                <div className="absolute inset-0 opacity-[0.15] dark:opacity-[0.06] [background-image:radial-gradient(circle,rgba(0,201,200,0.35)_1px,transparent_1px)] [background-size:28px_28px]" />
            </div>

            <div className="relative z-10 flex flex-col gap-6 w-full max-w-[1400px] mx-auto p-4 sm:p-6 min-w-0">
                {/* --- HEADER --- */}
                <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 pb-4 border-b border-slate-200/80 dark:border-slate-800">
                    <div className="flex items-start sm:items-center gap-3.5 min-w-0">
                        <div className="p-3 rounded-2xl bg-[#EAFBFC] dark:bg-cyan-950/60 text-[#00AFC0] dark:text-cyan-400 border border-[#00AFC0]/20 shrink-0">
                            <Bell className="size-6" />
                        </div>
                        <div className="min-w-0">
                            <div className="flex items-center gap-2.5 flex-wrap">
                                <h1 className="text-xl sm:text-2xl font-extrabold tracking-tight text-slate-900 dark:text-slate-100">
                                    Pusat Pemberitahuan
                                </h1>
                                {unreadCount > 0 && (
                                    <span className="px-2.5 py-0.5 text-xs font-bold rounded-full bg-rose-500 text-white shadow-xs animate-pulse">
                                        {unreadCount} Belum Dibaca
                                    </span>
                                )}
                            </div>
                            <p className="text-xs sm:text-sm text-slate-500 dark:text-slate-400 mt-1">
                                Pantau semua aktivitas sistem, jadwal servis aset, serta pemberitahuan real-time.
                            </p>
                        </div>
                    </div>

                    <div className="flex items-center gap-2 flex-wrap">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={handleSimulateNew}
                            className="text-xs border-[#00AFC0] text-[#00AFC0] hover:bg-[#EAFBFC] dark:hover:bg-cyan-950/60 rounded-full font-bold px-3.5 cursor-pointer"
                        >
                            <Sparkles className="size-3.5 mr-1.5" />
                            <span>Simulasi Notifikasi Realtime</span>
                        </Button>

                        {unreadCount > 0 && (
                            <Button
                                type="button"
                                variant="outline"
                                onClick={markAllAsRead}
                                className="text-xs border-emerald-200 dark:border-emerald-900/60 text-emerald-600 dark:text-emerald-400 hover:bg-emerald-50 dark:hover:bg-emerald-950/40 rounded-full font-bold px-3.5 cursor-pointer"
                            >
                                <CheckCheck className="size-3.5 mr-1.5" />
                                <span>Tandai Semua Dibaca</span>
                            </Button>
                        )}

                        {notifications.length > 0 && (
                            <Button
                                type="button"
                                variant="outline"
                                onClick={clearAll}
                                className="text-xs border-rose-200 dark:border-rose-900/60 text-rose-600 dark:text-rose-400 hover:bg-rose-50 dark:hover:bg-rose-950/40 rounded-full font-bold px-3.5 cursor-pointer"
                            >
                                <Trash2 className="size-3.5 mr-1.5" />
                                <span>Bersihkan Semua</span>
                            </Button>
                        )}
                    </div>
                </div>

                {/* --- FILTERS & SEARCH BAR --- */}
                <div className="bg-white dark:bg-slate-900 rounded-2xl p-4 sm:p-5 border border-slate-200/80 dark:border-slate-800 shadow-xs flex flex-col md:flex-row items-stretch md:items-center justify-between gap-4">
                    {/* Search */}
                    <div className="relative flex-1 max-w-md">
                        <Search className="absolute left-3.5 top-1/2 -translate-y-1/2 size-4 text-slate-400" />
                        <Input
                            type="text"
                            placeholder="Cari notifikasi (misal: Mesin A-101, Work Order)..."
                            value={searchQuery}
                            onChange={(e: React.ChangeEvent<HTMLInputElement>) => setSearchQuery(e.target.value)}
                            className="pl-9 text-xs rounded-xl border-slate-200 dark:border-slate-800 bg-slate-50 dark:bg-slate-800/60 focus-visible:ring-[#00AFC0]"
                        />
                    </div>

                    {/* Filter Tabs */}
                    <div className="flex items-center gap-1.5 overflow-x-auto pb-1 md:pb-0 custom-scrollbar">
                        {[
                            { key: 'all', label: 'Semua', count: notifications.length },
                            { key: 'unread', label: 'Belum Dibaca', count: unreadCount },
                            { key: 'maintenance', label: 'Aset & Servis', count: notifications.filter((n) => ['maintenance', 'work-order', 'calibration'].includes(n.category)).length },
                            { key: 'security', label: 'Keamanan', count: notifications.filter((n) => n.category === 'security').length },
                            { key: 'workflow', label: 'Workflow', count: notifications.filter((n) => n.category === 'workflow').length },
                            { key: 'system', label: 'Sistem', count: notifications.filter((n) => n.category === 'system').length },
                        ].map((tab) => (
                            <button
                                key={tab.key}
                                type="button"
                                onClick={() => setActiveFilter(tab.key as typeof activeFilter)}
                                className={cn(
                                    'px-3 py-1.5 rounded-xl text-xs font-bold transition-all cursor-pointer flex items-center gap-1.5 whitespace-nowrap',
                                    activeFilter === tab.key
                                        ? 'bg-[#00AFC0] text-white shadow-xs'
                                        : 'bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-400 hover:bg-slate-200 dark:hover:bg-slate-700'
                                )}
                            >
                                <span>{tab.label}</span>
                                <span className={cn('px-1.5 py-0.2 rounded-full text-[10px]', activeFilter === tab.key ? 'bg-white/20 text-white' : 'bg-slate-200 dark:bg-slate-700 text-slate-500 dark:text-slate-300')}>
                                    {tab.count}
                                </span>
                            </button>
                        ))}
                    </div>
                </div>

                {/* --- NOTIFICATIONS LIST CONTAINER --- */}
                <div className="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200/80 dark:border-slate-800 shadow-xs divide-y divide-slate-100 dark:divide-slate-800 overflow-hidden">
                    {filteredNotifications.length === 0 ? (
                        <div className="py-16 px-4 text-center space-y-3">
                            <div className="p-4 rounded-full bg-slate-100 dark:bg-slate-800/80 text-slate-400 inline-block">
                                <Info className="size-8" />
                            </div>
                            <h3 className="text-sm font-bold text-slate-800 dark:text-slate-200">
                                Tidak ada pemberitahuan yang ditemukan
                            </h3>
                            <p className="text-xs text-slate-500 dark:text-slate-400 max-w-sm mx-auto">
                                Coba ubah kata kunci pencarian atau ganti filter kategori pemberitahuan Anda.
                            </p>
                        </div>
                    ) : (
                        filteredNotifications.map((notif) => (
                            <div
                                key={notif.id}
                                className={cn(
                                    'p-4 sm:p-5 flex flex-col sm:flex-row sm:items-center justify-between gap-4 transition-all relative group',
                                    notif.unread
                                        ? 'bg-[#EAFBFC]/40 dark:bg-cyan-950/20'
                                        : 'hover:bg-slate-50/70 dark:hover:bg-slate-800/40'
                                )}
                            >
                                <div className="flex items-start gap-3.5 min-w-0 flex-1">
                                    <div className="p-3 rounded-2xl bg-white dark:bg-slate-800 border border-slate-200/80 dark:border-slate-700 shadow-xs shrink-0 mt-0.5">
                                        {renderTypeIcon(notif.type)}
                                    </div>

                                    <div className="min-w-0 space-y-1 flex-1">
                                        <div className="flex items-center gap-2 flex-wrap">
                                            <span className="text-sm font-extrabold text-slate-900 dark:text-slate-100">
                                                {notif.title}
                                            </span>
                                            {getCategoryBadge(notif.category)}
                                            {notif.unread && (
                                                <span className="px-2 py-0.5 text-[10px] font-extrabold rounded-full bg-rose-500 text-white">
                                                    Baru
                                                </span>
                                            )}
                                        </div>
                                        <p className="text-xs text-slate-600 dark:text-slate-300 leading-relaxed">
                                            {notif.message}
                                        </p>
                                        <div className="flex items-center gap-3 text-[11px] text-slate-400 font-semibold">
                                            <span>{notif.time}</span>
                                        </div>
                                    </div>
                                </div>

                                <div className="flex items-center gap-2 shrink-0 self-end sm:self-center">
                                    {notif.link && (
                                        <Button
                                            type="button"
                                            variant="outline"
                                            onClick={() => {
                                                markAsRead(notif.id);
                                                router.visit(notif.link!);
                                            }}
                                            className="text-xs border-[#00AFC0] text-[#00AFC0] hover:bg-[#EAFBFC] dark:hover:bg-cyan-950/60 rounded-full font-bold px-3 py-1.5 h-auto cursor-pointer"
                                        >
                                            <span>Buka Tautan</span>
                                            <ArrowUpRight className="size-3.5 ml-1" />
                                        </Button>
                                    )}

                                    {notif.unread ? (
                                        <button
                                            type="button"
                                            onClick={() => markAsRead(notif.id)}
                                            title="Tandai Sudah Dibaca"
                                            className="p-2 rounded-xl text-slate-400 hover:text-emerald-600 hover:bg-emerald-50 dark:hover:bg-emerald-950/40 transition-colors cursor-pointer"
                                        >
                                            <CheckCircle2 className="size-4" />
                                        </button>
                                    ) : null}

                                    <button
                                        type="button"
                                        onClick={() => deleteNotification(notif.id)}
                                        title="Hapus Pemberitahuan Ini"
                                        className="p-2 rounded-xl text-slate-400 hover:text-rose-600 hover:bg-rose-50 dark:hover:bg-rose-950/40 transition-colors cursor-pointer"
                                    >
                                        <Trash2 className="size-4" />
                                    </button>
                                </div>
                            </div>
                        ))
                    )}
                </div>
            </div>
        </>
    );
}

NotificationsPage.layout = {
    type: AppSidebarLayout,
};
