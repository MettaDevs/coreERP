import { usePage } from '@inertiajs/react';
import { useRef, useState } from 'react';
import { toast } from 'sonner';
import type { Workspace } from '@/types';
import { ActionButton } from '@apperp/ui/action-button';
import { Button } from '@apperp/ui/button';
import { DataTable } from '@apperp/ui/data-table';
import type {
    DataTableColumn,
    DataTableRowAction,
} from '@apperp/ui/data-table';
import {
    Empty,
    EmptyDescription,
    EmptyHeader,
    EmptyTitle,
} from '@apperp/ui/empty';
import { Input } from '@apperp/ui/input';
import { ListPageLayout } from '@apperp/ui/list-page-layout';
import { PageDialog, PageDialogContent } from '@apperp/ui/page-dialog';
import { RecordActionBar } from '@apperp/ui/record-action-bar';
import { Select } from '@apperp/ui/select';
import { useRecordNavigation } from '@apperp/ui/use-record-navigation';
import { useRecordList } from '../../use-record-list';
import type { RecordCardState } from '../_shared/record-card';
import { bukaDaftar } from './aset';
import { ImporSaldoAwal } from './ImporSaldoAwal';
import type { Context, Penerimaan } from './penerimaan';
import { STATUS, StatusBadge, izin, tanggalTampil } from './penerimaan';
import PenerimaanDetailPage from './PenerimaanDetailPage';

/** Nilai filter status; `''` berarti semua. */
const PILIHAN_STATUS = [
    { value: '', label: 'Semua status' },
    { value: 'draft', label: 'Draf' },
    { value: 'selesai', label: 'Selesai' },
];

/**
 * Daftar dokumen penerimaan aset.
 *
 * Kolom "Jumlah aset" bukan hiasan: ia yang membedakan dokumen ini dari berkas lain —
 * satu baris dokumen bisa berarti dua puluh baris register, dan angka itulah yang
 * dicocokkan dengan surat jalan.
 */
export default function PenerimaanListPage({
    context,
    permissions,
}: {
    context: Context;
    permissions: string[];
}) {
    const [card, setCard] = useState<RecordCardState>(null);
    const [cardBusy, setCardBusy] = useState(false);
    const [cardDirty, setCardDirty] = useState(false);
    const discardChanges = () =>
        !cardDirty || window.confirm('Buang perubahan yang belum disimpan?');
    const openNewCard = () => {
        if (!cardBusy && discardChanges()) {
            setCardDirty(false);
            setCard({ mode: 'create' });
        }
    };
    const closeCard = () => {
        if (!cardBusy && discardChanges()) {
            setCard(null);
            setCardDirty(false);
            list.reload();
        }
    };
    const [cardRevision, setCardRevision] = useState(0);
    const cardContentRef = useRef<HTMLDivElement>(null);
    const bukaPenerimaanBaru = () => setCard({ mode: 'create' });
    const bukaPenerimaan = (id: string) => setCard({ mode: 'view', id });
    const bukaPenerimaanUbah = (id: string) => setCard({ mode: 'edit', id });

    const can = izin(permissions);
    const { workspace } = usePage<{ workspace: Workspace }>().props;
    const legalEntityNames = new Map(
        workspace.legal_entities.map((entity) => [entity.id, entity.name]),
    );
    const [status, setStatus] = useState('');
    const [search, setSearch] = useState('');
    const [impor, setImpor] = useState(false);
    // Dinaikkan sesudah impor supaya daftar dibaca ulang dengan draf yang baru lahir.
    const [putaran, setPutaran] = useState(0);

    const list = useRecordList<Penerimaan>('/penerimaan-aset', search, {
        ...(status ? { status } : {}),
        refresh: String(putaran),
    });
    const penerimaan = list.items;
    const terlihat = penerimaan;
    const memuat = list.loading;

    const columns: DataTableColumn<Penerimaan>[] = [
        {
            id: 'legal_entity',
            header: 'Perusahaan',
            cell: (receipt) =>
                legalEntityNames.get(receipt.legal_entity_id) ??
                'Perusahaan tidak dikenal',
            sortValue: (receipt) =>
                legalEntityNames.get(receipt.legal_entity_id) ?? '',
            width: 220,
        },
        {
            id: 'kode',
            header: 'No. penerimaan',
            cell: (baris) => (
                <span className="text-primary font-medium">{baris.kode}</span>
            ),
            sortValue: (baris) => baris.kode,
            width: 170,
        },
        {
            id: 'tanggal',
            header: 'Tanggal terima',
            cell: (baris) => tanggalTampil(baris.tanggal),
            sortValue: (baris) => baris.tanggal ?? '',
            width: 150,
        },
        {
            id: 'siap',
            header: 'Siap dipakai',
            cell: (baris) => tanggalTampil(baris.tanggal_siap_pakai),
            sortValue: (baris) => baris.tanggal_siap_pakai ?? '',
            width: 150,
        },
        {
            id: 'unit',
            header: 'Unit pengguna',
            // Nama, bukan ULID: daftar yang menampilkan id tidak dapat dibaca sekilas
            // maupun diurutkan dengan cara yang berarti.
            cell: (baris) =>
                baris.responsible_org_unit_nama ?? 'Unit tidak dikenal',
            sortValue: (baris) => baris.responsible_org_unit_nama ?? '',
            minWidth: 150,
            width: 200,
        },
        {
            id: 'lokasi',
            header: 'Lokasi awal',
            cell: (baris) => baris.lokasi_aset_nama ?? 'Tanpa lokasi',
            sortValue: (baris) => baris.lokasi_aset_nama ?? '',
            minWidth: 150,
            width: 190,
        },
        {
            id: 'baris',
            header: 'Baris',
            cell: (baris) => baris.jumlah_baris ?? 0,
            sortValue: (baris) => baris.jumlah_baris ?? 0,
            align: 'center',
            width: 100,
        },
        {
            id: 'jumlah',
            header: 'Jumlah aset',
            cell: (baris) => baris.jumlah_aset ?? 0,
            sortValue: (baris) => baris.jumlah_aset ?? 0,
            align: 'center',
            width: 130,
        },
        {
            id: 'status',
            header: 'Status',
            cell: (baris) => <StatusBadge status={baris.status} />,
            sortValue: (baris) => STATUS[baris.status]?.label ?? baris.status,
            width: 130,
        },
    ];

    const actions: DataTableRowAction[] = [
        { id: 'detail', label: 'Buka rincian' },
    ];

    if (can('update')) {
        actions.push({ id: 'edit', label: 'Ubah' });
    }

    const currentCardId = card && 'id' in card ? card.id : null;
    const cardNavigation = useRecordNavigation({
        items: list.items,
        currentId: currentCardId,
        getId: (item) => item.id,
        onNavigate: (id) => setCard({ mode: 'view', id }),
        scopeKey: JSON.stringify([
            '/penerimaan-aset',
            search,
            {
                ...(status ? { status } : {}),
                refresh: String(putaran),
            },
            list.sort,
        ]),
        hasMore: list.hasMore,
        loading: list.loading,
        loadMore: list.loadMore,
        disabled: cardBusy || cardDirty,
    });

    return (
        <>
            <ListPageLayout
                header={
                    <RecordActionBar title="Penerimaan aset">
                        {can('create') && (
                            <ActionButton
                                action="create"
                                type="button"
                                onClick={bukaPenerimaanBaru}
                            />
                        )}
                        {can('create') && (
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => setImpor(true)}
                            >
                                Impor saldo awal
                            </Button>
                        )}
                        <Button
                            type="button"
                            variant="outline"
                            onClick={bukaDaftar}
                        >
                            Register aset
                        </Button>
                    </RecordActionBar>
                }
                filters={
                    <div className="flex shrink-0 flex-col gap-3 border-b px-5 py-3 sm:flex-row sm:items-end">
                        <div className="w-full sm:w-72">
                            <Input
                                label="Cari"
                                type="search"
                                placeholder="Nomor penerimaan, lokasi, atau unit"
                                value={search}
                                onChange={(event) =>
                                    setSearch(event.target.value)
                                }
                            />
                        </div>
                        <div className="w-full sm:w-52">
                            <Select
                                label="Status"
                                items={PILIHAN_STATUS.map(
                                    (pilihan) => pilihan.label,
                                )}
                                value={
                                    PILIHAN_STATUS.find(
                                        (pilihan) => pilihan.value === status,
                                    )?.label ?? null
                                }
                                ariaLabel="Saring berdasarkan status"
                                onValueChange={(value) =>
                                    setStatus(
                                        PILIHAN_STATUS.find(
                                            (pilihan) =>
                                                pilihan.label === value,
                                        )?.value ?? '',
                                    )
                                }
                            />
                        </div>
                    </div>
                }
            >
                {!memuat && !terlihat.length ? (
                    <Empty>
                        <EmptyHeader>
                            <EmptyTitle>Belum ada penerimaan aset</EmptyTitle>
                            <EmptyDescription>
                                Satu dokumen penerimaan mencatat satu kedatangan
                                barang, dan melahirkan satu aset bernomor untuk
                                tiap unit yang datang.
                            </EmptyDescription>
                        </EmptyHeader>
                    </Empty>
                ) : (
                    <DataTable
                        columns={columns}
                        incremental={list}
                        sort={list.sort}
                        onSortChange={list.onSortChange}
                        data={terlihat}
                        getRowKey={(baris) => baris.id}
                        getRowLabel={(baris) => baris.kode}
                        actions={actions}
                        onRowClick={(baris) => bukaPenerimaan(baris.id)}
                        onRowAction={(action, baris) => {
                            if (action === 'detail') {
                                bukaPenerimaan(baris.id);
                            }

                            if (action === 'edit') {
                                // Dokumen selesai ditolak server; mengatakannya di sini
                                // membuat orangnya tahu sebelum mengetik ulang isinya.
                                if (baris.status !== 'draft') {
                                    toast.error(
                                        'Penerimaan yang sudah selesai tidak dapat diubah; asetnya sudah terdaftar dan bernomor.',
                                    );

                                    return;
                                }

                                bukaPenerimaanUbah(baris.id);
                            }
                        }}
                    />
                )}
            </ListPageLayout>
            {card && (
                <PageDialog
                    open
                    onOpenChange={(open) => {
                        if (!open) {
                            closeCard();
                        }
                    }}
                >
                    <PageDialogContent
                        ref={cardContentRef}
                        onEscapeKeyDown={(event) => {
                            if (cardBusy) {
                                event.preventDefault();
                            }
                        }}
                        title={'Penerimaan aset'}
                        busy={cardBusy}
                        onNew={
                            !(card?.mode === 'create') && can('create')
                                ? openNewCard
                                : undefined
                        }
                        navigation={currentCardId ? cardNavigation : undefined}
                    >
                        <PenerimaanDetailPage
                            key={`${card.mode}-${'id' in card ? card.id : 'new'}-${cardRevision}`}
                            context={context}
                            permissions={permissions}
                            penerimaanId={'id' in card ? card.id : undefined}
                            mode={card.mode}
                            portalContainer={cardContentRef}
                            onBusyChange={setCardBusy}
                            onClose={closeCard}
                            onSaved={(id) => {
                                setCard({ mode: 'view', id });
                                setCardRevision((version) => version + 1);
                                list.reload();
                            }}
                            onRequestView={(id) => {
                                if (!cardBusy && discardChanges()) {
                                    setCard({ mode: 'view', id });
                                }
                            }}
                            onRequestEdit={(id) => {
                                if (!cardBusy && discardChanges()) {
                                    setCard({ mode: 'edit', id });
                                }
                            }}
                            onDirtyChange={setCardDirty}
                        />
                    </PageDialogContent>
                </PageDialog>
            )}

            {impor && (
                <ImporSaldoAwal
                    context={context}
                    onClose={() => setImpor(false)}
                    onApplied={() => setPutaran((nilai) => nilai + 1)}
                />
            )}
        </>
    );
}
