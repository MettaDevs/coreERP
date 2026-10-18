import { useRef, useState } from 'react';
import { ActionButton } from '@apperp/ui/action-button';
import { DataTable } from '@apperp/ui/data-table';
import type { DataTableColumn } from '@apperp/ui/data-table';
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
import { useRecordNavigation } from '@apperp/ui/use-record-navigation';
import { useRecordList } from '../../use-record-list';
import type { RecordCardState } from '../_shared/record-card';
import type { Disposal, DisposalResource } from './disposal';
import {
    DISPOSALS,
    STATUS,
    StatusBadge,
    izin,
    tanggalTampil,
    uang,
} from './disposal';
import DisposalDetailPage from './DisposalDetailPage';

/**
 * Daftar penjualan atau pemusnahan aset. Ia hanya menampilkan dan memilih; pratinjau dan posting ada di
 * halaman rincian.
 */
export default function DisposalListPage({
    resource,
    permissions,
}: {
    resource: DisposalResource;
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
    const bukaBaru = () => setCard({ mode: 'create' });
    const bukaDokumen = (id: string) => setCard({ mode: 'view', id });

    const jenis = DISPOSALS[resource];
    const can = izin(resource, permissions);
    const [search, setSearch] = useState('');

    const list = useRecordList<Disposal>(`/${resource}`, search);
    const daftar = list.items;
    const terlihat = daftar;
    const memuat = list.loading;

    const columns: DataTableColumn<Disposal>[] = [
        {
            id: 'kode',
            header: 'No. bukti',
            cell: (baris) => (
                <span className="text-primary font-medium">{baris.kode}</span>
            ),
            sortValue: (baris) => baris.kode,
            width: 170,
        },
        {
            id: 'tanggal',
            header: 'Tanggal',
            cell: (baris) => tanggalTampil(baris.tanggal),
            sortValue: (baris) => baris.tanggal ?? '',
            width: 130,
        },
        {
            id: 'aset',
            header: 'Aset',
            cell: (baris) =>
                [baris.aset_kode, baris.aset_nama]
                    .filter(Boolean)
                    .join(' · ') || '—',
            sortValue: (baris) => baris.aset_kode ?? '',
            minWidth: 200,
        },
        ...(jenis.hasProceeds
            ? [
                  {
                      id: 'nilai',
                      header: 'Nilai penjualan',
                      cell: (baris: Disposal) => uang(baris.nilai),
                      sortValue: (baris: Disposal) => Number(baris.nilai ?? 0),
                      align: 'right' as const,
                      width: 160,
                  },
              ]
            : []),
        {
            id: 'status',
            header: 'Status',
            cell: (baris) => <StatusBadge status={baris.status} />,
            sortValue: (baris) => STATUS[baris.status]?.label ?? baris.status,
            width: 130,
        },
    ];

    const currentCardId = card && 'id' in card ? card.id : null;
    const cardNavigation = useRecordNavigation({
        items: list.items,
        currentId: currentCardId,
        getId: (item) => item.id,
        onNavigate: (id) => setCard({ mode: 'view', id }),
        scopeKey: JSON.stringify([`/${resource}`, search, list.sort]),
        hasMore: list.hasMore,
        loading: list.loading,
        loadMore: list.loadMore,
        disabled: cardBusy || cardDirty,
    });

    return (
        <>
            <ListPageLayout
                header={
                    <RecordActionBar title={jenis.title}>
                        {can('create') && (
                            <ActionButton
                                action="create"
                                type="button"
                                onClick={() => bukaBaru()}
                            />
                        )}
                    </RecordActionBar>
                }
                filters={
                    <div className="border-b px-5 py-3">
                        <div className="w-full sm:w-72">
                            <Input
                                label="Cari"
                                type="search"
                                placeholder="Nomor bukti, aset, atau keterangan"
                                value={search}
                                onChange={(event) =>
                                    setSearch(event.target.value)
                                }
                            />
                        </div>
                    </div>
                }
            >
                {!memuat && !terlihat.length ? (
                    <Empty>
                        <EmptyHeader>
                            <EmptyTitle>
                                Belum ada dokumen {jenis.noun}
                            </EmptyTitle>
                            <EmptyDescription>{jenis.empty}</EmptyDescription>
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
                        actions={[{ id: 'detail', label: 'Buka rincian' }]}
                        onRowClick={(baris) => bukaDokumen(baris.id)}
                        onRowAction={(_, baris) => bukaDokumen(baris.id)}
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
                        title={jenis.title}
                        busy={cardBusy}
                        onNew={
                            !(card?.mode === 'create') && can('create')
                                ? openNewCard
                                : undefined
                        }
                        navigation={currentCardId ? cardNavigation : undefined}
                    >
                        <DisposalDetailPage
                            key={`${card.mode}-${'id' in card ? card.id : 'new'}-${cardRevision}`}
                            resource={resource}
                            permissions={permissions}
                            disposalId={'id' in card ? card.id : undefined}

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
        </>
    );
}
