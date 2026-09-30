import { Avatar, AvatarFallback } from '@apperp/ui/avatar';
import { Button } from '@apperp/ui/button';
import { Spinner } from '@apperp/ui/spinner';
import { useCallback, useEffect, useState } from 'react';
import { useDateTimeFormat } from '@/hooks/use-date-time';

/**
 * Satu entri log perubahan, seperti dipulangkan kontrak `ChangeHistory` Core.
 *
 * `*_display` adalah nilai yang sudah diterjemahkan pemilik tabel (nama lokasi, label status);
 * kosong berarti nilai mentahnya yang ditampilkan. `user_name` kosong berarti diubah sistem.
 */
export type ChangeHistoryEntry = {
    id: number;
    changed_at: string;
    user_id: number | null;
    user_name: string | null;
    field_name: string;
    field_caption: string | null;
    change_type: 'insertion' | 'modification' | 'deletion';
    old_value: string | null;
    new_value: string | null;
    old_display: string | null;
    new_display: string | null;
};

export type ChangeHistoryPage = {
    data: ChangeHistoryEntry[];
    next_page: number | null;
};

type Group = {
    key: string;
    userName: string | null;
    changedAt: string;
    changeType: ChangeHistoryEntry['change_type'];
    entries: ChangeHistoryEntry[];
};

/**
 * Entri yang lahir dari satu penyimpanan — pelaku, detik, dan jenis yang sama — digabung menjadi
 * satu baris linimasa, seperti riwayat aktivitas di aplikasi tugas: "Budi mengubah Lokasi dan
 * Status", bukan dua baris terpisah.
 */
function groupEntries(entries: ChangeHistoryEntry[]): Group[] {
    const groups: Group[] = [];

    for (const entry of entries) {
        const second = entry.changed_at.slice(0, 19);
        const key = `${second}|${entry.user_id ?? 'sistem'}|${entry.change_type}`;
        const last = groups.at(-1);

        if (last?.key === key) {
            last.entries.push(entry);
        } else {
            groups.push({
                key,
                userName: entry.user_name,
                changedAt: entry.changed_at,
                changeType: entry.change_type,
                entries: [entry],
            });
        }
    }

    return groups;
}

function initials(name: string | null): string {
    if (!name) {
        return 'S';
    }

    return name
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part[0]?.toUpperCase() ?? '')
        .join('');
}

function fieldName(entry: ChangeHistoryEntry): string {
    return entry.field_caption ?? entry.field_name;
}

function value(display: string | null, raw: string | null): string {
    if (display) {
        return display;
    }

    return raw === null || raw === '' ? 'kosong' : raw;
}

/** Satu baris rincian: pengarsipan dibaca sebagai tindakan, bukan sebagai perubahan tanggal. */
function Detail({ entry }: { entry: ChangeHistoryEntry }) {
    if (
        entry.field_name === 'deleted_at' &&
        entry.change_type === 'modification'
    ) {
        return (
            <li>
                {entry.new_value
                    ? 'Mengarsipkan data ini'
                    : 'Memulihkan data ini dari arsip'}
            </li>
        );
    }

    if (entry.change_type === 'insertion') {
        return (
            <li>
                {fieldName(entry)}:{' '}
                <span className="text-foreground">
                    {value(entry.new_display, entry.new_value)}
                </span>
            </li>
        );
    }

    if (entry.change_type === 'deletion') {
        return (
            <li>
                {fieldName(entry)}:{' '}
                <span className="text-foreground">
                    {value(entry.old_display, entry.old_value)}
                </span>
            </li>
        );
    }

    return (
        <li>
            {fieldName(entry)}:{' '}
            <span className="line-through">
                {value(entry.old_display, entry.old_value)}
            </span>{' '}
            →{' '}
            <span className="text-foreground">
                {value(entry.new_display, entry.new_value)}
            </span>
        </li>
    );
}

function sentence(group: Group): string {
    const who = group.userName ?? 'Sistem';
    const fields = group.entries
        .filter((entry) => entry.field_name !== 'deleted_at')
        .map(fieldName);

    if (group.changeType === 'insertion') {
        return `${who} membuat data ini`;
    }

    if (group.changeType === 'deletion') {
        return `${who} menghapus data ini`;
    }

    if (fields.length === 0) {
        return `${who} mengubah status arsip`;
    }

    return fields.length <= 2
        ? `${who} mengubah ${fields.join(' dan ')}`
        : `${who} mengubah ${fields.length} data`;
}

/**
 * Riwayat perubahan satu record: siapa mengubah apa, dari nilai apa ke nilai apa, dan kapan.
 *
 * Pemanggilnya menyediakan `load`, karena riwayat dibuka lewat rute pemilik record-nya — module
 * memeriksa hak dan cakupan atas record itu sendiri, dan memakai klien API-nya sendiri. Halaman
 * berikutnya dimuat saat diminta, bukan sekaligus.
 */
export function ChangeHistory({
    load,
}: {
    load: (page: number) => Promise<ChangeHistoryPage>;
}) {
    const formatTime = useDateTimeFormat();
    const [entries, setEntries] = useState<ChangeHistoryEntry[]>([]);
    const [nextPage, setNextPage] = useState<number | null>(null);
    const [state, setState] = useState<'loading' | 'ready' | 'error'>(
        'loading',
    );

    const apply = useCallback((page: number, result: ChangeHistoryPage) => {
        setEntries((current) =>
            page === 1 ? result.data : [...current, ...result.data],
        );
        setNextPage(result.next_page);
        setState('ready');
    }, []);

    // Halaman pertama dimuat saat komponen tampil; jawabannya dibuang bila komponen sudah dilepas
    // atau record-nya berganti sebelum jawaban tiba.
    useEffect(() => {
        let cancelled = false;

        load(1)
            .then((result) => !cancelled && apply(1, result))
            .catch(() => !cancelled && setState('error'));

        return () => {
            cancelled = true;
        };
    }, [load, apply]);

    function fetchPage(page: number) {
        setState('loading');
        load(page)
            .then((result) => apply(page, result))
            .catch(() => setState('error'));
    }

    if (state === 'error' && entries.length === 0) {
        return (
            <div className="flex items-center gap-3 text-sm text-muted-foreground">
                Riwayat perubahan belum dapat dimuat.
                <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    onClick={() => fetchPage(1)}
                >
                    Coba lagi
                </Button>
            </div>
        );
    }

    if (state === 'loading' && entries.length === 0) {
        return (
            <div className="flex items-center gap-2 text-sm text-muted-foreground">
                <Spinner /> Memuat riwayat perubahan
            </div>
        );
    }

    if (entries.length === 0) {
        return (
            <p className="text-sm text-muted-foreground">
                Belum ada perubahan yang tercatat.
            </p>
        );
    }

    return (
        <div className="space-y-4">
            <ol className="space-y-4">
                {groupEntries(entries).map((group) => (
                    <li
                        className="flex gap-3"
                        key={`${group.key}|${group.entries[0].id}`}
                    >
                        <Avatar className="size-8">
                            <AvatarFallback className="text-xs">
                                {initials(group.userName)}
                            </AvatarFallback>
                        </Avatar>
                        <div className="min-w-0 flex-1">
                            <p className="text-sm">
                                <span className="font-medium">
                                    {sentence(group)}
                                </span>
                                <time
                                    className="ml-2 text-xs text-muted-foreground"
                                    dateTime={group.changedAt}
                                >
                                    {formatTime(group.changedAt)}
                                </time>
                            </p>
                            <ul className="mt-1 space-y-0.5 text-sm text-muted-foreground">
                                {group.entries.map((entry) => (
                                    <Detail entry={entry} key={entry.id} />
                                ))}
                            </ul>
                        </div>
                    </li>
                ))}
            </ol>
            {nextPage !== null && (
                <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    disabled={state === 'loading'}
                    onClick={() => fetchPage(nextPage)}
                >
                    {state === 'loading' ? 'Memuat' : 'Muat riwayat sebelumnya'}
                </Button>
            )}
        </div>
    );
}
