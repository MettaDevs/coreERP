import { Button } from '@apperp/ui/button';
import { Popover, PopoverContent, PopoverTrigger } from '@apperp/ui/popover';
import { ScrollArea } from '@apperp/ui/scroll-area';
import { router } from '@inertiajs/react';
import { Bell, Check, Trash2 } from 'lucide-react';
import { useState } from 'react';

import { useNotifications } from '@/hooks/use-notifications';
import {
    clearNotifications,
    markAllNotificationsRead,
    markNotificationRead,
    removeNotification,
} from '@/lib/notifications';
import type { ShellNotification } from '@/lib/notifications';
import { cn } from '@/lib/utils';

/**
 * Lonceng di header: jumlah belum dibaca pada ikonnya, daftar notifikasi pada popover.
 * Membuka popover menandai semuanya sudah dibaca; mengeklik satu notifikasi membawa ke
 * halaman app yang mengirimnya.
 */
export function NotificationBell() {
    const notifications = useNotifications();
    const [open, setOpen] = useState(false);
    const unread = notifications.filter((item) => !item.readAt).length;

    const onOpenChange = (next: boolean) => {
        setOpen(next);

        if (!next && unread > 0) {
            markAllNotificationsRead();
        }
    };

    const buka = (item: ShellNotification) => {
        markNotificationRead(item.id);
        setOpen(false);

        if (item.href) {
            router.visit(item.href);
        }
    };

    return (
        <Popover open={open} onOpenChange={onOpenChange}>
            <PopoverTrigger asChild>
                <Button
                    variant="ghost"
                    size="icon"
                    className="relative"
                    aria-label={
                        unread > 0
                            ? `Notifikasi, ${unread} belum dibaca`
                            : 'Notifikasi'
                    }
                >
                    <Bell />
                    {unread > 0 && (
                        <span
                            aria-hidden
                            className="absolute -top-0.5 -right-0.5 flex h-4 min-w-4 items-center justify-center rounded-full bg-primary px-1 text-[10px] leading-none font-semibold text-primary-foreground"
                        >
                            {unread > 99 ? '99+' : unread}
                        </span>
                    )}
                </Button>
            </PopoverTrigger>
            <PopoverContent align="end" className="w-96 p-0">
                <div className="flex items-center justify-between border-b px-4 py-3">
                    <p className="text-sm font-medium">Notifikasi</p>
                    {notifications.length > 0 && (
                        <div className="flex gap-1">
                            {unread > 0 && (
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    onClick={markAllNotificationsRead}
                                >
                                    <Check />
                                    Tandai dibaca
                                </Button>
                            )}
                            <Button
                                variant="ghost"
                                size="sm"
                                onClick={clearNotifications}
                            >
                                <Trash2 />
                                Bersihkan
                            </Button>
                        </div>
                    )}
                </div>
                {notifications.length === 0 ? (
                    <p className="px-4 py-8 text-center text-sm text-muted-foreground">
                        Belum ada notifikasi. Pemberitahuan dari aplikasi,
                        seperti hasil ekspor yang siap diunduh, muncul di sini.
                    </p>
                ) : (
                    <ScrollArea className="max-h-96">
                        <ul className="divide-y">
                            {notifications.map((item) => (
                                <li key={item.id}>
                                    <BarisNotifikasi
                                        item={item}
                                        onOpen={() => buka(item)}
                                        onRemove={() =>
                                            removeNotification(item.id)
                                        }
                                    />
                                </li>
                            ))}
                        </ul>
                    </ScrollArea>
                )}
            </PopoverContent>
        </Popover>
    );
}

function BarisNotifikasi({
    item,
    onOpen,
    onRemove,
}: {
    item: ShellNotification;
    onOpen: () => void;
    onRemove: () => void;
}) {
    return (
        <div
            className={cn(
                'flex items-start gap-3 px-4 py-3',
                !item.readAt && 'bg-muted/40',
            )}
        >
            <span
                aria-hidden
                className={cn(
                    'mt-1.5 h-2 w-2 shrink-0 rounded-full',
                    item.level === 'error' && 'bg-destructive',
                    item.level === 'warning' && 'bg-warning',
                    item.level === 'success' && 'bg-success',
                    item.level === 'info' && 'bg-primary',
                    item.readAt && 'opacity-30',
                )}
            />
            <button
                type="button"
                className="min-w-0 flex-1 text-left"
                onClick={onOpen}
            >
                <p className={cn('text-sm', !item.readAt && 'font-medium')}>
                    {item.title}
                </p>
                {item.body && (
                    <p className="line-clamp-2 text-xs text-muted-foreground">
                        {item.body}
                    </p>
                )}
                <p className="mt-1 text-[11px] text-muted-foreground">
                    {item.appName} · {waktuRelatif(item.createdAt)}
                </p>
            </button>
            <Button
                variant="ghost"
                size="icon"
                className="h-7 w-7 shrink-0"
                aria-label="Hapus notifikasi"
                onClick={onRemove}
            >
                <Trash2 className="h-3.5 w-3.5" />
            </Button>
        </div>
    );
}

function waktuRelatif(iso: string): string {
    const selisih = Date.now() - Date.parse(iso);

    if (Number.isNaN(selisih)) {
        return '';
    }

    const menit = Math.round(selisih / 60_000);

    if (menit < 1) {
        return 'baru saja';
    }

    if (menit < 60) {
        return `${menit} menit lalu`;
    }

    const jam = Math.round(menit / 60);

    if (jam < 24) {
        return `${jam} jam lalu`;
    }

    return new Date(iso).toLocaleDateString('id-ID', {
        day: 'numeric',
        month: 'short',
    });
}
