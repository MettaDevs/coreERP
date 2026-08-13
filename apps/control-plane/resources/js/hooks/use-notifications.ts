import { useState, useEffect, useCallback } from 'react';

export interface NotificationItem {
    id: string;
    title: string;
    message: string;
    time: string;
    timestamp: number;
    unread: boolean;
    type: 'warning' | 'success' | 'info' | 'error' | 'security';
    category: 'maintenance' | 'work-order' | 'calibration' | 'security' | 'workflow' | 'system';
    link?: string;
}

const INITIAL_NOTIFICATIONS: NotificationItem[] = [
    {
        id: 'notif-1',
        title: 'Maintenance Dibutuhkan',
        message: 'Mesin Produksi A-101 butuh servis berkala 500 jam.',
        time: '10 menit yang lalu',
        timestamp: Date.now() - 10 * 60 * 1000,
        unread: true,
        type: 'warning',
        category: 'maintenance',
        link: '/master-data/entitas-aset',
    },
    {
        id: 'notif-2',
        title: 'Work Order Selesai',
        message: 'WO-2024-089 (Generator G-01) diselesaikan oleh Tim Teknisi.',
        time: '1 jam yang lalu',
        timestamp: Date.now() - 60 * 60 * 1000,
        unread: true,
        type: 'success',
        category: 'work-order',
        link: '/master-data/entitas-aset',
    },
    {
        id: 'notif-3',
        title: 'Jadwal Kalibrasi',
        message: 'UPS Unit 1 memasuki masa kalibrasi dalam 3 hari.',
        time: '4 jam yang lalu',
        timestamp: Date.now() - 4 * 60 * 60 * 1000,
        unread: true,
        type: 'info',
        category: 'calibration',
        link: '/master-data/entitas-aset',
    },
    {
        id: 'notif-4',
        title: 'Sesi Perangkat Baru',
        message: 'Login terdeteksi dari Chrome di Windows (172.18.0.1).',
        time: '5 jam yang lalu',
        timestamp: Date.now() - 5 * 60 * 60 * 1000,
        unread: false,
        type: 'security',
        category: 'security',
        link: '/settings/security',
    },
    {
        id: 'notif-5',
        title: 'Persetujuan Workflow Baru',
        message: 'Pengajuan pengadaan unit pompa hidrolik butuh verifikasi Anda.',
        time: '1 hari yang lalu',
        timestamp: Date.now() - 24 * 60 * 60 * 1000,
        unread: false,
        type: 'info',
        category: 'workflow',
        link: '/workflow-inbox',
    },
    {
        id: 'notif-6',
        title: 'Pencadangan Sistem Berhasil',
        message: 'Backup otomatis database dan dokumen vault berhasil dijalankan.',
        time: '2 hari yang lalu',
        timestamp: Date.now() - 48 * 60 * 60 * 1000,
        unread: false,
        type: 'success',
        category: 'system',
        link: '/dashboard',
    },
];

const STORAGE_KEY = 'sanata_notifications_state_v1';

function formatRelativeTime(timestamp: number): string {
    const diffSeconds = Math.floor((Date.now() - timestamp) / 1000);
    if (diffSeconds < 60) return 'Baru saja';
    const diffMinutes = Math.floor(diffSeconds / 60);
    if (diffMinutes < 60) return `${diffMinutes} menit yang lalu`;
    const diffHours = Math.floor(diffMinutes / 60);
    if (diffHours < 24) return `${diffHours} jam yang lalu`;
    const diffDays = Math.floor(diffHours / 24);
    return `${diffDays} hari yang lalu`;
}

export function useNotifications() {
    const [notifications, setNotifications] = useState<NotificationItem[]>(() => {
        if (typeof window === 'undefined') return INITIAL_NOTIFICATIONS;
        try {
            const saved = localStorage.getItem(STORAGE_KEY);
            if (saved) {
                const parsed = JSON.parse(saved) as NotificationItem[];
                return parsed.map((item) => ({
                    ...item,
                    time: formatRelativeTime(item.timestamp),
                }));
            }
        } catch {
            // fallback
        }
        return INITIAL_NOTIFICATIONS;
    });

    // Save to localStorage when state changes
    useEffect(() => {
        try {
            localStorage.setItem(STORAGE_KEY, JSON.stringify(notifications));
        } catch {
            // ignore
        }
    }, [notifications]);

    // Live real-time relative time ticker & periodic new notification simulator
    useEffect(() => {
        const interval = setInterval(() => {
            setNotifications((prev) =>
                prev.map((item) => ({
                    ...item,
                    time: formatRelativeTime(item.timestamp),
                }))
            );
        }, 15000); // refresh relative timestamps every 15s

        return () => clearInterval(interval);
    }, []);

    const markAsRead = useCallback((id: string) => {
        setNotifications((prev) =>
            prev.map((item) => (item.id === id ? { ...item, unread: false } : item))
        );
    }, []);

    const markAllAsRead = useCallback(() => {
        setNotifications((prev) => prev.map((item) => ({ ...item, unread: false })));
    }, []);

    const deleteNotification = useCallback((id: string) => {
        setNotifications((prev) => prev.filter((item) => item.id !== id));
    }, []);

    const clearAll = useCallback(() => {
        setNotifications([]);
    }, []);

    const addNotification = useCallback((newNotif: Omit<NotificationItem, 'id' | 'timestamp' | 'time' | 'unread'>) => {
        const item: NotificationItem = {
            ...newNotif,
            id: `notif-${Date.now()}-${Math.random().toString(36).substr(2, 4)}`,
            timestamp: Date.now(),
            time: 'Baru saja',
            unread: true,
        };
        setNotifications((prev) => [item, ...prev]);
    }, []);

    const unreadCount = notifications.filter((n) => n.unread).length;

    return {
        notifications,
        unreadCount,
        markAsRead,
        markAllAsRead,
        deleteNotification,
        clearAll,
        addNotification,
    };
}
