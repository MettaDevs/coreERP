/**
 * Pusat notifikasi milik Shell.
 *
 * Notifikasi di sini adalah pemberitahuan ringan yang datang dari app yang sedang
 * dibuka — "ekspor Anda siap diunduh", "ekspor gagal" — bukan fakta bisnis. Karena itu
 * ia disimpan di browser, bukan di server:
 *
 * - Tidak ada tabel, endpoint, atau polling ke Core. Seribu pengguna dengan seribu
 *   notifikasi tidak menambah satu query pun.
 * - Daftar dibatasi jumlah dan umur, jadi localStorage tidak tumbuh tanpa batas dan
 *   membuka lonceng selalu ringan.
 * - Kunci penyimpanan memuat tenant dan user, sehingga berganti akun atau workspace
 *   pada browser yang sama tidak mencampur pemberitahuan orang lain.
 *
 * Batasnya juga jelas: pemberitahuan hanya ada di browser tempat ia diterima. Sumber
 * kebenarannya tetap di app — halaman riwayat ekspor — dan app mengulang
 * pemberitahuan yang belum pernah disampaikan ketika dibuka kembali.
 *
 * Tab lain pada browser yang sama ikut diperbarui lewat event `storage`.
 */

export type NotificationLevel = 'info' | 'success' | 'warning' | 'error';

export type ShellNotification = {
    /** Stabil per kejadian; pengiriman ulang dengan id sama memperbarui, bukan menggandakan. */
    id: string;
    appId: string;
    appName: string;
    level: NotificationLevel;
    title: string;
    body?: string;
    /** Tujuan di Shell, misalnya `/management-aset/entitas-aset`. */
    href?: string;
    createdAt: string;
    readAt: string | null;
};

const MAX_ITEMS = 50;
const MAX_AGE_MS = 14 * 24 * 60 * 60 * 1000;
const PREFIX = 'coreerp.notifications';

type Listener = () => void;

let scopeKey = '';
let cache: ShellNotification[] = [];
const listeners = new Set<Listener>();

function storageKey(): string {
    return `${PREFIX}:${scopeKey || 'anon'}`;
}

function read(): ShellNotification[] {
    try {
        const raw = localStorage.getItem(storageKey());
        const parsed = raw ? (JSON.parse(raw) as unknown) : [];

        if (!Array.isArray(parsed)) {
            return [];
        }

        const cutoff = Date.now() - MAX_AGE_MS;

        return parsed
            .filter(
                (item): item is ShellNotification =>
                    typeof item === 'object' &&
                    item !== null &&
                    typeof (item as ShellNotification).id === 'string' &&
                    typeof (item as ShellNotification).title === 'string' &&
                    typeof (item as ShellNotification).createdAt === 'string',
            )
            .filter((item) => Date.parse(item.createdAt) >= cutoff)
            .slice(0, MAX_ITEMS);
    } catch {
        // Dikecualikan dengan sadar, dan hanya di sini. Penyimpanan peramban yang diblokir
        // adalah keadaan sehari-hari — mode privat, setelan pihak ketiga — bukan kegagalan yang
        // perlu dilaporkan, dan daftar ini memang boleh kosong pada kunjungan pertama siapa pun.
        // Yang hilang karenanya hanya pemberitahuan yang belum sempat dibaca di tab ini, tidak
        // ada fakta yang dinyatakan salah kepada siapa pun.
        // eslint-disable-next-line no-restricted-syntax
        return [];
    }
}

function write(items: ShellNotification[]): void {
    cache = items;

    try {
        localStorage.setItem(storageKey(), JSON.stringify(items));
    } catch {
        // Penyimpanan penuh atau diblokir: notifikasi tetap tampil selama halaman hidup.
    }

    listeners.forEach((listener) => listener());
}

function refresh(): void {
    cache = read();
    listeners.forEach((listener) => listener());
}

if (typeof window !== 'undefined') {
    window.addEventListener('storage', (event) => {
        if (event.key === storageKey()) {
            refresh();
        }
    });
}

/** Dipanggil saat tenant atau user aktif diketahui; mengganti daftar yang dibaca. */
export function scopeNotifications(
    tenantId: string,
    userId: string | number,
): void {
    const next = `${tenantId}:${userId}`;

    if (next === scopeKey) {
        return;
    }

    scopeKey = next;
    refresh();
}

export function getNotifications(): ShellNotification[] {
    return cache;
}

export function subscribeNotifications(listener: Listener): () => void {
    listeners.add(listener);

    return () => {
        listeners.delete(listener);
    };
}

export function addNotification(
    input: Omit<ShellNotification, 'createdAt' | 'readAt'> & {
        createdAt?: string;
    },
): void {
    const existing = cache.find((item) => item.id === input.id);
    const item: ShellNotification = {
        ...input,
        createdAt: input.createdAt ?? new Date().toISOString(),
        // Kejadian yang sama dikirim ulang (misalnya tab lain) tidak menghidupkan lagi
        // tanda belum dibaca.
        readAt: existing?.readAt ?? null,
    };
    write(
        [item, ...cache.filter((other) => other.id !== input.id)].slice(
            0,
            MAX_ITEMS,
        ),
    );
}

export function markNotificationRead(id: string): void {
    const now = new Date().toISOString();
    write(
        cache.map((item) =>
            item.id === id && !item.readAt ? { ...item, readAt: now } : item,
        ),
    );
}

export function markAllNotificationsRead(): void {
    const now = new Date().toISOString();
    write(cache.map((item) => (item.readAt ? item : { ...item, readAt: now })));
}

export function removeNotification(id: string): void {
    write(cache.filter((item) => item.id !== id));
}

export function clearNotifications(): void {
    write([]);
}
