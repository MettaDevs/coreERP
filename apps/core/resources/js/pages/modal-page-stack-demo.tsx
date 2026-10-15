import { Button } from '@apperp/ui/button';
import { DataTable } from '@apperp/ui/data-table';
import type { DataTableColumn } from '@apperp/ui/data-table';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@apperp/ui/dropdown-menu';
import { Input } from '@apperp/ui/input';
import {
    PageDialog,
    PageDialogContent,
    PageDialogHeader,
    PageDialogTitle,
} from '@apperp/ui/page-dialog';
import { Select } from '@apperp/ui/select';
import { Textarea } from '@apperp/ui/textarea';
import { Check, ChevronDown, PanelRight, Pencil } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import './modal-page-stack-demo.css';

type PageKind = 'document' | 'entries' | 'item';
type Page = {
    id: number;
    kind: PageKind;
    code: string;
    expanded: boolean;
    factBox: boolean;
};
type Row = {
    code: string;
    description: string;
    quantity: string;
    amount: string;
};

const rows: Row[] = [
    {
        code: 'ITEM-001',
        description: 'Item contoh',
        quantity: '2',
        amount: '0,00',
    },
    {
        code: 'ITEM-002',
        description: 'Perlengkapan contoh',
        quantity: '4',
        amount: '0,00',
    },
    {
        code: 'ITEM-003',
        description: 'Layanan contoh',
        quantity: '1',
        amount: '0,00',
    },
];

// Data contoh domain; frame dan perilaku stack memakai PageDialog SDK.
export function ModalPageStackDemo() {
    const [pages, setPages] = useState<Page[]>([]);
    const nextId = useRef(0);
    const openPage = (kind: PageKind, code: string, expanded = false) => {
        const id = ++nextId.current;
        setPages((current) => [
            ...current,
            {
                id,
                kind,
                code,
                expanded,
                factBox: true,
            },
        ]);
    };
    const goBack = () => setPages((current) => current.slice(0, -1));
    const updatePage = (id: number, update: Partial<Page>) => {
        setPages((current) =>
            current.map((page) =>
                page.id === id ? { ...page, ...update } : page,
            ),
        );
    };

    return (
        <div className="space-y-4">
            <p className="text-sm text-muted-foreground">
                Buka dokumen, coba Ubah, menu Terkait, link pada nomor item atau
                nilai bersih, lalu kembali. Ukuran, isi field, posisi scroll,
                dan panel kanan tetap tersimpan pada halaman sebelumnya.
            </p>
            <div className="flex flex-wrap gap-2">
                <Button onClick={() => openPage('document', 'DOC-0001')}>
                    Buka modal page
                </Button>
                <Button
                    variant="outline"
                    onClick={() => openPage('document', 'DOC-0001', true)}
                >
                    Buka expanded
                </Button>
            </div>
            <p className="text-sm text-muted-foreground">
                Font, field, dan FactBox mengikuti tampilan Core. Pada layar
                sempit, halaman memenuhi ruang di bawah header, kolom berkurang,
                dan panel kanan berpindah ke tampilan Rincian.
            </p>
            {pages.map((page) => (
                <PageSurface
                    key={page.id}
                    page={page}
                    onBack={goBack}
                    onUpdate={(update) => updatePage(page.id, update)}
                    onOpen={openPage}
                />
            ))}
        </div>
    );
}

function PageSurface({
    page,
    onBack,
    onUpdate,
    onOpen,
}: {
    page: Page;
    onBack: () => void;
    onUpdate: (update: Partial<Page>) => void;
    onOpen: (kind: PageKind, code: string, expanded?: boolean) => void;
}) {
    const [editing, setEditing] = useState(false);
    const [name, setName] = useState(() =>
        page.kind === 'item'
            ? (rows.find((row) => row.code === page.code)?.description ??
              'Item contoh')
            : 'Dokumen contoh',
    );
    const [owner, setOwner] = useState('Pengguna contoh');
    const [mode, setMode] = useState('Ringkasan');
    const [detailsTab, setDetailsTab] = useState(false);
    const [saved, setSaved] = useState(false);
    const surfaceRef = useRef<HTMLDivElement>(null);
    const firstFieldRef = useRef<HTMLInputElement>(null);
    useEffect(() => {
        if (editing) {
            firstFieldRef.current?.focus();
        }
    }, [editing]);

    const caption =
        page.kind === 'entries'
            ? 'Baris dokumen'
            : page.kind === 'item'
              ? 'Kartu item'
              : 'Dokumen contoh';
    const columns: DataTableColumn<Row>[] = [
        {
            id: 'code',
            header: 'No.',
            cell: (row) => (
                <Button
                    variant="link"
                    size="sm"

                    onClick={() => onOpen('item', row.code)}
                >
                    {row.code}
                </Button>
            ),
        },
        {
            id: 'description',
            header: 'Deskripsi',
            cell: (row) => row.description,
        },
        { id: 'quantity', header: 'Kuantitas', cell: (row) => row.quantity },
        { id: 'amount', header: 'Jumlah', cell: (row) => row.amount },
    ];
    const factBox = (
        <aside
            className="modal-rnd-factbox bg-background p-5"
            aria-label="Rincian halaman"
        >
            <div className="space-y-5">
                <div>
                    <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                        FactBox
                    </p>
                    <h3 className="mt-1 text-lg font-semibold">Ringkasan</h3>
                </div>
                <div className="space-y-3">
                    {[
                        ['No. dokumen', page.code],
                        ['Status', saved ? 'Tersimpan' : 'Contoh'],
                        ['Jumlah baris', String(rows.length)],
                    ].map(([label, value]) => (
                        <div
                            className="flex items-start justify-between gap-4 text-sm"
                            key={label}
                        >
                            <span className="text-muted-foreground">
                                {label}
                            </span>
                            <span className="text-right font-medium">
                                {value}
                            </span>
                        </div>
                    ))}
                    <div className="flex items-center justify-between gap-4 text-sm">
                        <span className="text-muted-foreground">
                            Nilai bersih
                        </span>
                        <Button
                            variant="link"
                            size="sm"

                            onClick={() => onOpen('entries', page.code)}
                        >
                            0,00
                        </Button>
                    </div>
                </div>
                <div className="border-t pt-4">
                    <p className="text-sm font-medium">Tentang FactBox</p>
                    <p className="mt-1 text-sm text-muted-foreground">
                        Panel rincian bisa ditampilkan atau disembunyikan tanpa
                        menutup halaman. Link nilai bersih membuka baris
                        dokumen.
                    </p>
                </div>
            </div>
        </aside>
    );

    return (
        <PageDialog
            open
            onOpenChange={(open) => {
                if (!open) {
                    onBack();
                }
            }}
        >
            <PageDialogContent
                ref={surfaceRef}
                aria-describedby={undefined}
                expanded={page.expanded}
                onExpandedChange={(expanded) => onUpdate({ expanded })}
                data-kind={page.kind}
                data-details={detailsTab}
            >
                <PageDialogTitle className="sr-only">{caption}</PageDialogTitle>
                <PageDialogHeader
                    actions={
                        <>
                            {page.kind !== 'entries' && (
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    aria-pressed={editing}
                                    onClick={() => {
                                        setEditing((current) => !current);
                                        setSaved(true);
                                    }}
                                >
                                    <Pencil />
                                    {editing ? 'Selesai' : 'Ubah'}
                                </Button>
                            )}
                            <span className="modal-rnd-status flex items-center gap-1 text-sm whitespace-nowrap text-muted-foreground">
                                <Check size={14} />
                                {editing
                                    ? 'Sedang diubah'
                                    : saved
                                      ? 'Tersimpan'
                                      : 'Contoh'}
                            </span>
                        </>
                    }
                >
                    <span className="truncate text-sm text-muted-foreground">
                        {caption}
                    </span>
                </PageDialogHeader>
                {page.kind !== 'entries' && (
                    <h2 className="modal-rnd-title text-2xl font-semibold tracking-tight">
                        {page.code} · {name}
                    </h2>
                )}
                <nav
                    className="modal-rnd-actions"
                    aria-label="Tindakan halaman"
                >
                    {page.kind === 'entries' && <strong>Baris dokumen</strong>}
                    <Button
                        variant="ghost"
                        size="sm"
                        onClick={() => {
                            setDetailsTab(false);
                        }}
                    >
                        Beranda
                    </Button>
                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <Button variant="ghost" size="sm">
                                Terkait
                                <ChevronDown />
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="start">
                            <DropdownMenuItem
                                onSelect={() => onOpen('entries', page.code)}
                            >
                                Baris dokumen
                            </DropdownMenuItem>
                            <DropdownMenuItem
                                onSelect={() => onOpen('item', rows[0].code)}
                            >
                                Kartu item
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                    <Button
                        variant="ghost"
                        size="sm"
                        onClick={() => onOpen('entries', page.code)}
                    >
                        Lihat baris
                    </Button>
                    <Button
                        className="modal-rnd-factbox-toggle"
                        variant="ghost"
                        size="icon"
                        aria-label={
                            page.factBox
                                ? 'Sembunyikan panel rincian'
                                : 'Tampilkan panel rincian'
                        }
                        aria-pressed={page.factBox}
                        onClick={() => onUpdate({ factBox: !page.factBox })}
                    >
                        <PanelRight />
                    </Button>
                </nav>
                <div
                    className="modal-rnd-mobile-tabs"
                    role="tablist"
                    aria-label="Tampilan halaman"
                >
                    <Button
                        variant="ghost"
                        role="tab"
                        aria-selected={!detailsTab}
                        onClick={() => setDetailsTab(false)}
                    >
                        Dokumen
                    </Button>
                    <Button
                        variant="ghost"
                        role="tab"
                        aria-selected={detailsTab}
                        onClick={() => {
                            onUpdate({ factBox: true });
                            setDetailsTab(true);
                        }}
                    >
                        Rincian
                    </Button>
                </div>
                <div
                    className="modal-rnd-workspace"
                    data-factbox={page.factBox}
                >
                    <div className="modal-rnd-body" scroll-region="">
                        {page.kind !== 'entries' && (
                            <>
                                <section className="modal-rnd-section">
                                    <h3 className="text-lg font-semibold">
                                        Umum
                                    </h3>
                                    <div
                                        className="modal-rnd-fields"
                                        onPointerDown={() => setEditing(true)}
                                        onKeyDown={(event) => {
                                            if (
                                                event.key === 'Enter' &&
                                                !editing
                                            ) {
                                                setEditing(true);
                                            }
                                        }}
                                    >
                                        <Input
                                            id={`modal-rnd-code-${page.id}`}
                                            label="Nomor"
                                            value={page.code}
                                            readOnly
                                        />
                                        <Input
                                            ref={firstFieldRef}
                                            id={`modal-rnd-name-${page.id}`}
                                            label="Nama"
                                            value={name}
                                            readOnly={!editing}
                                            onChange={(event) => {
                                                setName(event.target.value);
                                                setSaved(false);
                                            }}
                                        />
                                        <Select
                                            label="Mode tampilan"
                                            searchPlaceholder="Cari mode…"
                                            emptyMessage="Mode tidak ditemukan."
                                            items={[
                                                'Ringkasan',
                                                'Rincian',
                                                'Riwayat',
                                            ]}
                                            value={mode}
                                            onValueChange={(value) => {
                                                setMode(value ?? 'Ringkasan');
                                                setSaved(false);
                                            }}
                                            portalContainer={surfaceRef}
                                        />
                                        <Input
                                            id={`modal-rnd-owner-${page.id}`}
                                            label="Penanggung jawab"
                                            value={owner}
                                            readOnly={!editing}
                                            onChange={(event) => {
                                                setOwner(event.target.value);
                                                setSaved(false);
                                            }}
                                        />
                                    </div>
                                </section>
                                <section className="modal-rnd-section">
                                    <h3 className="text-lg font-semibold">
                                        Ringkasan
                                    </h3>
                                    <div className="modal-rnd-fields">
                                        <div className="modal-rnd-stat">
                                            <span>Nilai bersih</span>
                                            <Button
                                                variant="link"
                                                size="sm"

                                                onClick={() =>
                                                    onOpen('entries', page.code)
                                                }
                                            >
                                                0,00
                                            </Button>
                                        </div>
                                        <div className="modal-rnd-stat">
                                            <span>Jumlah baris</span>
                                            <Button
                                                variant="link"
                                                size="sm"

                                                onClick={() =>
                                                    onOpen('entries', page.code)
                                                }
                                            >
                                                {rows.length}
                                            </Button>
                                        </div>
                                    </div>
                                </section>
                            </>
                        )}
                        <section className="modal-rnd-section">
                            {page.kind !== 'entries' && (
                                <h3 className="text-lg font-semibold">
                                    Baris dokumen
                                </h3>
                            )}
                            <DataTable
                                columns={columns}
                                data={rows}
                                getRowKey={(row) => row.code}
                                getRowLabel={(row) => row.code}
                                scrollMode="inline"
                                onRowClick={(row) => onOpen('item', row.code)}
                                actions={[
                                    { id: 'open', label: 'Buka kartu item' },
                                ]}
                                actionsPlacement="inline"
                                onRowAction={(_, row) =>
                                    onOpen('item', row.code)
                                }
                            />
                        </section>
                        {page.kind !== 'entries' &&
                            [
                                'Informasi tambahan',
                                'Pengiriman',
                                'Pembayaran',
                            ].map((title) => (
                                <details
                                    className="modal-rnd-section"
                                    key={title}
                                >
                                    <summary className="text-lg font-semibold">
                                        {title}
                                    </summary>
                                    <div className="modal-rnd-fields">
                                        <Textarea
                                            label="Catatan"
                                            readOnly={!editing}
                                        />
                                        <Input
                                            label="Referensi"
                                            readOnly={!editing}
                                        />
                                    </div>
                                </details>
                            ))}
                    </div>
                    {page.factBox && factBox}
                </div>
            </PageDialogContent>
        </PageDialog>
    );
}
