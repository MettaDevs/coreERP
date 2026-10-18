import { CircleAlert } from 'lucide-react';
import { useEffect } from 'react';
import { useCallback, useMemo, useRef, useState } from 'react';
import type { ReactNode, RefObject } from 'react';
import { toast } from 'sonner';
import { ActionButton } from '@apperp/ui/action-button';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@apperp/ui/alert-dialog';
import { Badge } from '@apperp/ui/badge';
import { Button } from '@apperp/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@apperp/ui/card';
import { DataTable, DataTableText } from '@apperp/ui/data-table';
import type { DataTableColumn } from '@apperp/ui/data-table';
import { DialogToolbar } from '@apperp/ui/dialog';
import {
    Empty,
    EmptyDescription,
    EmptyHeader,
    EmptyTitle,
} from '@apperp/ui/empty';
import {
    Field,
    FieldDescription,
    FieldError,
    FieldGroup,
    FieldHint,
} from '@apperp/ui/field';
import { IncrementalListStatus } from '@apperp/ui/incremental-list-status';
import { Input } from '@apperp/ui/input';
import { usePageDialogRecord } from '@apperp/ui/page-dialog';
import { PageDialog, PageDialogContent } from '@apperp/ui/page-dialog';
import { Select } from '@apperp/ui/select';

import type { ListLoader } from '@apperp/ui/use-incremental-list';
import { useIncrementalList } from '@apperp/ui/use-incremental-list';
import { useRecordNavigation } from '@apperp/ui/use-record-navigation';
import { api, ApiError, toastSaveError } from '../api';
import { recordQueryUrl } from '../transactions/_shared/record-card';
import { useRecordCardDirty } from '../transactions/_shared/use-record-card-dirty';
import { useRecordQueryTarget } from '../transactions/_shared/use-record-query-target';
import { recordListParams, useRecordOptions } from '../use-record-list';

type AccountColumn = { column: string; label: string; required: boolean };

type Account = {
    id: string;
    code: string;
    name: string;
    type: string;
    active: boolean;
    legal_entity_id: string | null;
};

type PostingRow = {
    group_aset_id: string;
    effective_from: string;
    missing: string[];
    version: number;
    [column: string]: string | string[] | number | null;
};

type Group = {
    id: string;
    kode: string;
    nama: string;
    aktif: boolean;
    current: PostingRow | null;
    rows: PostingRow[];
    missing: string[];
    unusable_accounts: string[];
};

type Matrix = {
    today: string;
    accounts: AccountColumn[];
    groups: Group[];
    groups_needing_attention: number;
    account_details: Record<string, Account>;
};

/** Nilai pilihan "tidak dipetakan"; `Select` tidak punya pilihan kosong bawaan. */
const NONE = '__none__';

/**
 * Kenapa akun yang sudah dipetakan tidak bisa dipakai posting, atau `null` bila bisa. Hanya terjadi
 * bila daftar akun berubah sesudah dipetakan; posting yang memakainya tertahan di Core.
 */
function accountProblem(account: Account | undefined): string | null {
    if (account === undefined) {
        return 'Tidak ada di daftar akun';
    }

    if (!account.active) {
        return 'Nonaktif';
    }

    return account.legal_entity_id === null ? null : 'Khusus satu perusahaan';
}

function accountOf(row: PostingRow | null, column: string): string | null {
    const value = row?.[column];

    return typeof value === 'string' ? value : null;
}

function accountLabel(account: Account): string {
    return `${account.code} — ${account.name}`;
}

function formatDate(value: string): string {
    return new Intl.DateTimeFormat('id-ID', { dateStyle: 'medium' }).format(
        new Date(`${value}T00:00:00`),
    );
}

export default function AssetPostingGroupPage({
    permissions,
}: {
    permissions: string[];
}) {
    const can = (action: string) =>
        permissions.includes(
            `management-aset.fixed-asset-posting-profiles.${action}`,
        );
    const [metadata, setMetadata] = useState<Matrix | null>(null);
    const [openedMatrix, setOpenedMatrix] = useState<Matrix | null>(null);
    const [initialDate, setInitialDate] = useState<string | undefined>();
    const [search, setSearch] = useState('');
    const [editing, setEditing] = useState<Group | null>(null);
    const loadItems = useCallback<ListLoader<Group>>(async (request) => {
        const response = await api<{
            data: Matrix;
            next_cursor: string | null;
        }>(`/posting-group-aset?${recordListParams(request)}`, {
            signal: request.signal,
        });

        if (!request.signal.aborted) {
            setMetadata((current) => ({
                ...response.data,
                account_details: request.cursor
                    ? {
                          ...current?.account_details,
                          ...response.data.account_details,
                      }
                    : response.data.account_details,
            }));
        }

        return {
            items: response.data.groups,
            nextCursor: response.next_cursor,
        };
    }, []);
    const list = useIncrementalList({
        loadItems,
        query: search,
        getKey: (group: Group) => group.id,
    });
    const matrix = metadata
        ? {
              ...metadata,
              groups: list.items,
              groups_needing_attention: list.items.filter(
                  (group) =>
                      group.missing.length || group.unusable_accounts.length,
              ).length,
          }
        : null;

    const clearRecordTarget = useRecordQueryTarget({
        scopeKey: 'fixed-aset-posting-profiles',
        enabled: can('read'),
        requireDate: true,
        onOpen: async (id, signal, _kind, date) => {
            if (!date) {
                return;
            }

            const result = await api<{
                data: {
                    group: Group;
                    row: PostingRow;
                    today: string;
                    accounts: AccountColumn[];
                    account_details: Record<string, Account>;
                };
            }>('/posting-group-aset/' + id + '/' + date, { signal });

            if (signal.aborted) {
                return;
            }

            const exact = result.data;
            setOpenedMatrix({
                today: exact.today,
                accounts: exact.accounts,
                account_details: exact.account_details,
                groups: [exact.group],
                groups_needing_attention:
                    exact.group.missing.length ||
                    exact.group.unusable_accounts.length
                        ? 1
                        : 0,
            });
            setInitialDate(exact.row.effective_from);
            setEditing(exact.group);
        },
    });
    const openGroup = (group: Group) => {
        clearRecordTarget();
        setOpenedMatrix(null);
        setInitialDate(undefined);
        setEditing(group);
    };
    const closeGroup = () => {
        clearRecordTarget();
        setEditing(null);
        setOpenedMatrix(null);
        setInitialDate(undefined);
    };
    const cardMatrix = openedMatrix ?? matrix;

    return (
        <Card className="flex h-full min-h-0 flex-col overflow-hidden rounded-none border-0 shadow-none">
            <CardHeader className="shrink-0 border-b">
                <CardTitle>Setup Akun/COA Aset</CardTitle>
                <Input
                    aria-label="Cari group aset"
                    placeholder="Cari kode atau nama group"
                    value={search}
                    onChange={(event) => setSearch(event.target.value)}
                />
                <p className="text-muted-foreground mt-1 text-sm">
                    Akun jurnal untuk setiap group aset. Posting memakai baris
                    yang tanggal berlakunya paling akhir, tetapi tidak melewati
                    tanggal postingnya.
                </p>
            </CardHeader>
            <CardContent className="flex min-h-0 flex-1 flex-col gap-4 px-5 py-4">
                {!matrix && <IncrementalListStatus state={list} />}
                {matrix &&
                    matrix.groups.length === 0 &&
                    !list.loading &&
                    !list.error && (
                        <Empty>
                            <EmptyHeader>
                                <EmptyTitle>
                                    {search
                                        ? 'Group aset tidak ditemukan'
                                        : 'Belum ada group aset'}
                                </EmptyTitle>
                                <EmptyDescription>
                                    Buat group aset lebih dulu di Master data ›
                                    Group aset, lalu petakan akunnya di sini.
                                </EmptyDescription>
                            </EmptyHeader>
                        </Empty>
                    )}
                {matrix &&
                    (matrix.groups.length > 0 ||
                        list.loading ||
                        list.error) && (
                        <>
                            <div className="shrink-0">
                                <Summary
                                    matrix={matrix}
                                    hasMore={list.hasMore}
                                />
                            </div>
                            <PostingMatrix
                                matrix={matrix}
                                list={list}
                                canEdit={can('create') || can('update')}
                                onOpen={openGroup}
                            />
                        </>
                    )}
            </CardContent>
            {editing && cardMatrix && (
                <PostingGroupSheet
                    key={editing.id + ':' + (initialDate ?? 'default')}
                    list={list}
                    scopeKey={JSON.stringify([search, list.sort])}
                    onNavigate={openGroup}
                    group={editing}
                    matrix={cardMatrix}
                    initialDate={initialDate}
                    onDateChange={clearRecordTarget}
                    canCreate={can('create')}
                    canUpdate={can('update')}
                    canArchive={can('archive')}
                    onClose={closeGroup}
                    onSaved={list.reload}
                />
            )}
        </Card>
    );
}

function Summary({ matrix, hasMore }: { matrix: Matrix; hasMore: boolean }) {
    const required = matrix.accounts
        .filter((account) => account.required)
        .map((account) => account.label.toLowerCase());

    if (matrix.groups_needing_attention === 0) {
        return (
            <p className="text-muted-foreground text-sm">
                {hasMore
                    ? 'Group yang sudah tampil memiliki akun wajib yang berlaku hari ini. Geser daftar untuk memeriksa group lainnya.'
                    : 'Semua group sudah punya akun wajib yang berlaku hari ini.'}
            </p>
        );
    }

    return (
        <div
            role="status"
            className="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 dark:border-red-900/60 dark:bg-red-950/40 dark:text-red-200"
        >
            <p className="font-medium">
                {matrix.groups_needing_attention} dari {matrix.groups.length}{' '}
                group yang sudah tampil perlu dibenahi.
            </p>
            <p className="mt-1">
                Sel merah menandai akun wajib ({required.join(', ')}) yang masih
                kosong, atau akun yang tidak bisa dipakai lagi. Posting aset
                dari group itu akan tertahan sampai akunnya dibenahi.
            </p>
        </div>
    );
}

function PostingMatrix({
    matrix,
    list,
    canEdit,
    onOpen,
}: {
    matrix: Matrix;
    list: ReturnType<typeof useIncrementalList<Group>>;
    canEdit: boolean;
    onOpen: (group: Group) => void;
}) {
    const columns: DataTableColumn<Group>[] = [
        {
            id: 'kode',
            header: 'Kode group aset',
            width: 200,
            minWidth: 140,
            sortValue: (group) => group.kode,
            cell: (group) => (
                <span className="font-mono text-xs">{group.kode}</span>
            ),
        },
        {
            id: 'nama',
            header: 'Nama group aset',
            width: 240,
            minWidth: 160,
            sortValue: (group) => group.nama,
            cell: (group) => (
                <>
                    <DataTableText>{group.nama}</DataTableText>
                    {!group.aktif && (
                        <Badge variant="outline" className="mt-1">
                            Nonaktif
                        </Badge>
                    )}
                </>
            ),
        },
        {
            id: 'effective_from',
            header: 'Berlaku sejak',
            width: 180,
            minWidth: 140,
            sortValue: (group) => group.current?.effective_from ?? '',
            cell: (group) => (
                <>
                    {group.current
                        ? formatDate(group.current.effective_from)
                        : 'Belum ada'}
                    {group.rows.length > 1 && (
                        <span className="text-muted-foreground block text-xs">
                            {group.rows.length} tanggal berlaku
                        </span>
                    )}
                </>
            ),
        },
        ...matrix.accounts.map((column): DataTableColumn<Group> => ({
            id: column.column,
            header: `${column.label} (${column.required ? 'wajib' : 'bila dipakai'})`,
            width: 280,
            minWidth: 180,
            cell: (group) => (
                <AccountCell
                    column={column}
                    accountId={accountOf(group.current, column.column)}
                    details={matrix.account_details}
                />
            ),
        })),
    ];

    return (
        <DataTable
            scrollMode="viewport"
            className="min-h-0 flex-1"
            columns={columns}
            data={matrix.groups}
            incremental={list}
            sort={list.sort}
            onSortChange={list.onSortChange}
            getRowKey={(group) => group.id}
            getRowLabel={(group) => `${group.kode} — ${group.nama}`}
            actions={[
                { id: 'open', label: canEdit ? 'Ubah akun' : 'Lihat akun' },
            ]}
            actionsPlacement="inline"
            onRowAction={(_, group) => onOpen(group)}
            emptyMessage="Belum ada group aset."
        />
    );
}

function AccountCell({
    column,
    accountId,
    details,
}: {
    column: AccountColumn;
    accountId: string | null;
    details: Record<string, Account>;
}) {
    const account = accountId ? details[accountId] : undefined;
    const reason = accountId === null ? null : accountProblem(account);
    const problem = (accountId === null && column.required) || reason !== null;
    const className = problem
        ? 'rounded-sm bg-destructive/10 px-2 py-1 text-destructive'
        : undefined;

    if (accountId === null) {
        return (
            <div className={className}>
                {column.required ? (
                    <span className="text-sm font-medium">Kosong</span>
                ) : (
                    <span className="text-muted-foreground">—</span>
                )}
            </div>
        );
    }

    return (
        <div className={className}>
            {account ? (
                <>
                    <span className="block font-mono text-xs">
                        <DataTableText>{account.code}</DataTableText>
                    </span>
                    <span className="block text-sm">
                        <DataTableText>{account.name}</DataTableText>
                    </span>
                    {reason && (
                        <span className="block text-xs font-medium">
                            <DataTableText>{reason}</DataTableText>
                        </span>
                    )}
                </>
            ) : (
                <span className="text-sm font-medium">{reason}</span>
            )}
        </div>
    );
}

function PostingGroupSheet({
    group,
    matrix,
    initialDate,
    onDateChange,
    canCreate,
    canUpdate,
    canArchive,
    onClose,
    onSaved,
    list,
    scopeKey,
    onNavigate,
}: {
    group: Group;
    matrix: Matrix;
    initialDate?: string;
    onDateChange: () => void;
    canCreate: boolean;
    canUpdate: boolean;
    canArchive: boolean;
    onClose: () => void;
    onSaved: () => void;
    list: ReturnType<typeof useIncrementalList<Group>>;
    scopeKey: string;
    onNavigate: (group: Group) => void;
}) {
    const sheetRef = useRef<HTMLDivElement>(null);
    const [cardBusy, setCardBusy] = useState(false);
    const [cardDirty, setCardDirty] = useState(false);
    // `null` berarti menyusun baris untuk tanggal berlaku baru.
    const [selected, setSelected] = useState<string | null>(
        initialDate ??
            group.current?.effective_from ??
            group.rows[0]?.effective_from ??
            null,
    );
    const row =
        group.rows.find((baris) => baris.effective_from === selected) ?? null;

    const discardCard = () =>
        !cardDirty || window.confirm('Buang perubahan yang belum disimpan?');
    const closeCard = () => {
        if (!cardBusy && discardCard()) {
            onClose();
        }
    };
    const selectDate = (date: string | null) => {
        if (!cardBusy && discardCard()) {
            onDateChange();
            setSelected(date);
            setCardDirty(false);
        }
    };
    const cardNavigation = useRecordNavigation({
        items: list.items,
        currentId: group.id,
        getId: (item) => item.id,
        onNavigate: (id) => {
            const item = list.items.find((item) => item.id === id);

            if (item) {
                onNavigate(item);
            }
        },
        scopeKey,
        hasMore: list.hasMore,
        loading: list.loading,
        loadMore: list.loadMore,
        disabled: cardBusy || cardDirty,
    });

    return (
        <PageDialog
            open
            onOpenChange={(open) => {
                if (!open) {
                    closeCard();
                }
            }}
        >
            <PageDialogContent
                ref={sheetRef}
                title={'Setup akun/COA aset'}
                busy={cardBusy}
                onNew={
                    canCreate && selected !== null
                        ? () => selectDate(null)
                        : undefined
                }
                navigation={row ? cardNavigation : undefined}
            >
                {/* Ber-key per tanggal: berpindah tanggal memulai form baru dari barisnya sendiri. */}
                <RowEditor
                    key={selected ?? 'baru'}
                    group={group}
                    matrix={matrix}
                    row={row}
                    editable={row ? canUpdate : canCreate}
                    canArchive={canArchive}
                    portal={sheetRef}
                    tabs={
                        <DateTabs
                            group={group}
                            selected={selected}
                            today={matrix.today}
                            canCreate={canCreate}
                            onSelect={selectDate}
                        />
                    }
                    onClose={onClose}
                    onSaved={onSaved}
                    onBusyChange={setCardBusy}
                    onDirtyChange={setCardDirty}
                    onCancel={closeCard}
                />
            </PageDialogContent>
        </PageDialog>
    );
}

function RowEditor({
    group,
    matrix,
    row,
    editable,
    canArchive,
    portal,
    tabs,
    onClose,
    onSaved,
    onBusyChange,
    onDirtyChange,
    onCancel,
}: {
    group: Group;
    matrix: Matrix;
    row: PostingRow | null;
    editable: boolean;
    canArchive: boolean;
    portal: RefObject<HTMLDivElement | null>;
    tabs: ReactNode;
    onClose: () => void;
    onSaved: () => void;
    onBusyChange: (busy: boolean) => void;
    onDirtyChange: (dirty: boolean) => void;
    onCancel: () => void;
}) {
    const [effectiveFrom, setEffectiveFrom] = useState(matrix.today);
    const [values, setValues] = useState<Record<string, string | null>>(() =>
        Object.fromEntries(
            matrix.accounts.map((account) => [
                account.column,
                accountOf(row, account.column),
            ]),
        ),
    );
    const accountSource = useRecordOptions<Account>(
        editable ? '/posting-group-aset/akun' : null,
        accountLabel,
        Object.values(values).filter((id): id is string => id !== null),
    );
    const results = accountSource.options;
    const known = {
        ...matrix.account_details,
        ...Object.fromEntries(results.map((account) => [account.id, account])),
    };
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [saving, setSaving] = useState(false);
    useEffect(() => {
        onBusyChange(saving);

        return () => onBusyChange(false);
    }, [saving, onBusyChange]);
    const [archiving, setArchiving] = useState(false);
    async function save() {
        const date = row ? row.effective_from : effectiveFrom;

        if (!/^\d{4}-\d{2}-\d{2}$/.test(date)) {
            setErrors({ effective_from: 'Isi tanggal berlaku.' });

            return;
        }

        setSaving(true);
        setErrors({});

        try {
            // Baris yang sudah ada disimpan dari versi yang dibuka, termasuk bila tanggal yang
            // diketik untuk baris baru ternyata sudah punya baris; baris baru tidak punya versi.
            const existing =
                row ?? group.rows.find((item) => item.effective_from === date);
            await api(`/posting-group-aset/${group.id}/${date}`, {
                method: 'PUT',
                body: JSON.stringify(
                    existing
                        ? { ...values, version: existing.version }
                        : values,
                ),
            });
            toast.success(`Setup akun/COA aset ${group.kode} disimpan.`);
            onSaved();
            onClose();
        } catch (caught) {
            if (caught instanceof ApiError) {
                setErrors(
                    Object.fromEntries(
                        Object.entries(caught.validationErrors).map(
                            ([field, messages]) => [field, messages[0]],
                        ),
                    ),
                );
            }

            toastSaveError(caught, 'Setup akun/COA aset belum tersimpan.');
        } finally {
            setSaving(false);
        }
    }

    async function archive() {
        if (!row) {
            return;
        }

        setSaving(true);

        try {
            await api(`/posting-group-aset/${group.id}/${row.effective_from}`, {
                method: 'DELETE',
                body: JSON.stringify({ version: row.version }),
            });
            toast.success(
                `Baris ${formatDate(row.effective_from)} diarsipkan.`,
            );
            onSaved();
            onClose();
        } catch (caught) {
            toastSaveError(caught, 'Baris belum diarsipkan.');
        } finally {
            setArchiving(false);
            setSaving(false);
        }
    }

    useRecordCardDirty({
        value: { effectiveFrom, values },
        ready: true,
        scopeKey: JSON.stringify([group.id, row?.effective_from]),
        onDirtyChange,
    });

    const embedded = usePageDialogRecord({
        recordTitle:
            group.kode +
            ' · ' +
            group.nama +
            (row ? ' · ' + formatDate(row.effective_from) : ''),
        recordUrl: row
            ? recordQueryUrl(
                  'fixed-aset-posting-profiles',
                  group.id,
                  undefined,
                  row.effective_from,
              )
            : undefined,
        editing: editable,
        onDelete: row && canArchive ? () => setArchiving(true) : undefined,
        secondaryNavigation: tabs,
    });

    return (
        <>
            {(editable || (!embedded && row && canArchive)) && (
                <DialogToolbar>
                    {editable && (
                        <ActionButton
                            action="save"
                            type="button"
                            disabled={saving}
                            onClick={() => void save()}
                            pending={saving}
                        />
                    )}

                    {!embedded && row && canArchive && (
                        <ActionButton
                            action="archive"
                            type="button"
                            onClick={() => setArchiving(true)}
                        />
                    )}
                    {editable && (
                        <ActionButton
                            action="cancel"
                            onClick={onCancel}
                            disabled={saving}
                        />
                    )}
                </DialogToolbar>
            )}
            <div
                data-page-dialog-body
                className="min-h-0 flex-1 overflow-y-auto px-6 py-5"
            >
                <FieldGroup>
                    {row === null && (
                        <Field data-invalid={Boolean(errors.effective_from)}>
                            <Input
                                type="date"
                                label="Berlaku sejak"
                                required
                                value={effectiveFrom}
                                onChange={(event) =>
                                    setEffectiveFrom(event.target.value)
                                }
                            />
                            <FieldDescription>
                                Posting bertanggal sejak tanggal ini memakai
                                baris baru; posting sebelumnya tetap memakai
                                baris lama.
                            </FieldDescription>
                            {errors.effective_from && (
                                <FieldError>{errors.effective_from}</FieldError>
                            )}
                        </Field>
                    )}
                    {matrix.accounts.map((column) => (
                        <AccountField
                            key={column.column}
                            column={column}
                            value={values[column.column] ?? null}
                            known={known}
                            results={results}
                            disabled={!editable}
                            error={errors[column.column]}
                            portal={portal}
                            source={accountSource}
                            onChange={(next) =>
                                setValues((current) => ({
                                    ...current,
                                    [column.column]: next,
                                }))
                            }
                        />
                    ))}
                    {!editable && (
                        <FieldDescription>
                            Anda hanya dapat melihat baris ini.
                        </FieldDescription>
                    )}
                </FieldGroup>
            </div>

            <AlertDialog
                open={archiving}
                onOpenChange={(open) => !open && setArchiving(false)}
            >
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>
                            Delete baris{' '}
                            {row ? formatDate(row.effective_from) : ''}?
                        </AlertDialogTitle>
                        <AlertDialogDescription>
                            Posting yang tanggalnya jatuh di masa berlaku baris
                            ini akan memakai baris sebelumnya, atau tertahan
                            bila tidak ada. Posting yang sudah terkirim tidak
                            berubah.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>Batal</AlertDialogCancel>
                        <AlertDialogAction
                            onClick={() => void archive()}
                            asChild
                        >
                            <ActionButton action="archive" />
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </>
    );
}

function DateTabs({
    group,
    selected,
    today,
    canCreate,
    onSelect,
}: {
    group: Group;
    selected: string | null;
    today: string;
    canCreate: boolean;
    onSelect: (value: string | null) => void;
}) {
    return (
        <div
            className="flex flex-wrap gap-2"
            role="group"
            aria-label="Tanggal berlaku"
        >
            {group.rows.map((row) => (
                <Button
                    key={row.effective_from}
                    type="button"
                    size="sm"
                    variant={
                        selected === row.effective_from ? 'default' : 'outline'
                    }
                    onClick={() => onSelect(row.effective_from)}
                >
                    {formatDate(row.effective_from)}
                    {row.effective_from > today && ' (akan datang)'}
                    {row.effective_from === group.current?.effective_from &&
                        ' (berlaku)'}
                </Button>
            ))}
            {canCreate && (
                <ActionButton
                    action="add"
                    type="button"
                    onClick={() => onSelect(null)}
                />
            )}
        </div>
    );
}

/**
 * Contoh jurnal untuk tiap kolom akun (feed posting finance TODO 0.1.3), diambil dari bagian "Jenis
 * posting dan jurnalnya" di PRD. Pengisi akun lazimnya orang keuangan yang tahu akunnya tetapi belum
 * tahu jurnal aset mana yang memakainya; contohnya menjawab itu tanpa membuka dokumen lain.
 */
const ACCOUNT_HINTS: Record<string, string> = {
    acquisition_account_id:
        'Akun aset tetap yang bertambah saat aset diterima, dipindahkan dari sistem lama, atau nilainya dikoreksi. Contoh penerimaan ambulans 500: Dr Aset Tetap – Kendaraan 500.',
    accumulated_depreciation_account_id:
        'Akun pengurang aset tetap yang menampung penyusutan. Contoh penyusutan sebulan 3,7: Cr Akumulasi Penyusutan – Kendaraan 3,7.',
    depreciation_expense_account_id:
        'Akun beban di laba rugi untuk penyusutan tiap periode. Contoh: Dr Beban Penyusutan Kendaraan 3,7.',
    payable_account_id:
        'Hutang ke vendor bila penerimaan aset langsung menjadi hutang di aplikasi finance. Contoh ambulans 500 + PPN 55: Cr Hutang Usaha 555.',
    clearing_account_id:
        'Akun penampung bila faktur vendor dibuat terpisah di aplikasi finance. Contoh: Cr Aset Diterima Belum Difakturkan 555; saat fakturnya dibuat, Dr akun ini dan Cr Hutang Usaha 555 sampai saldonya kembali nol.',
    input_vat_account_id:
        'PPN pembelian aset yang dapat dikreditkan. Contoh PPN ambulans: Dr PPN Masukan 55.',
    opening_balance_offset_account_id:
        'Lawan saat aset lama dipindahkan dari sistem sebelumnya. Contoh aset 100 dengan akumulasi 40: Dr Aset Tetap 100, Cr Akumulasi Penyusutan 40, Cr Penyeimbang Saldo Awal 60.',
    grant_offset_account_id:
        'Lawan untuk aset yang diterima sebagai hibah, lazimnya akun ekuitas atau pendapatan hibah. Contoh hibah 500: Dr Aset Tetap 500, Cr akun ini 500.',
    write_down_account_id:
        'Akun pengurang aset tetap yang menampung penurunan nilai. Contoh penurunan nilai 110: Cr Akumulasi Penurunan Nilai 110; saat aset dilepas, saldonya didebit kembali.',
    write_down_expense_account_id:
        'Akun beban di laba rugi untuk penurunan nilai. Contoh: Dr Rugi Penurunan Nilai Aset 110.',
    appreciation_account_id:
        'Akun aset tetap yang menampung kenaikan nilai hasil revaluasi. Contoh kenaikan 20: Dr akun ini 20; saat aset dilepas, saldonya dikreditkan kembali.',
    appreciation_offset_account_id:
        'Lawan kenaikan nilai, lazimnya surplus revaluasi di ekuitas. Contoh kenaikan 20: Cr Surplus Revaluasi 20.',
    disposal_proceeds_account_id:
        'Tagihan atau kas dari penjualan aset. Contoh ambulans dijual 500: Dr Piutang Penjualan Aset 500; tagihan ke pembelinya dibuat di aplikasi finance.',
    disposal_gain_account_id:
        'Laba bila hasil penjualan lebih besar dari nilai buku. Contoh nilai buku 470 dijual 500: Cr Laba Pelepasan Aset 30.',
    disposal_loss_account_id:
        'Rugi bila hasil penjualan lebih kecil dari nilai buku, dan seluruh nilai buku aset yang dimusnahkan. Contoh nilai buku 470 dimusnahkan: Dr Rugi Pelepasan Aset 470.',
};

/** Ikon bantuan kecil di sebelah kontrolnya, pola yang sama dengan `DynamicField`. */
function withAccountHint(column: AccountColumn, control: ReactNode) {
    const hint = ACCOUNT_HINTS[column.column];

    if (!hint) {
        return control;
    }

    return (
        <div className="flex items-end gap-1.5">
            <div className="min-w-0 flex-1">{control}</div>
            <FieldHint hint={hint}>
                <button
                    type="button"
                    aria-label={`Contoh jurnal ${column.label}`}
                    className="text-muted-foreground hover:text-foreground mb-2.5 shrink-0"
                >
                    <CircleAlert className="size-4" />
                </button>
            </FieldHint>
        </div>
    );
}

function AccountField({
    column,
    value,
    known,
    results,
    disabled,
    error,
    portal,
    source,
    onChange,
}: {
    column: AccountColumn;
    value: string | null;
    known: Record<string, Account>;
    results: Account[];
    disabled: boolean;
    error?: string;
    portal: RefObject<HTMLDivElement | null>;
    source: ReturnType<typeof useRecordOptions<Account>>;
    onChange: (next: string | null) => void;
}) {
    const items = useMemo(() => {
        const accounts = new Map(
            results.map((account) => [account.id, account]),
        );

        if (value && known[value]) {
            accounts.set(value, known[value]);
        }

        return [
            { value: NONE, label: 'Tidak dipetakan' },
            ...[...accounts.values()].map((account) => ({
                value: account.id,
                label: accountLabel(account),
            })),
        ];
    }, [results, known, value]);
    const current = value ? known[value] : undefined;
    const reason = value ? accountProblem(current) : null;

    if (disabled) {
        return (
            <Field>
                {withAccountHint(
                    column,
                    <Input
                        label={column.label}
                        value={
                            current
                                ? accountLabel(current)
                                : value
                                  ? 'Akun tidak tersedia'
                                  : 'Tidak dipetakan'
                        }
                        disabled
                    />,
                )}
            </Field>
        );
    }

    return (
        <Field data-invalid={Boolean(error)}>
            {withAccountHint(
                column,
                <Select
                    {...source.selectProps}
                    label={column.label}
                    required={column.required}
                    items={items}
                    value={value ?? NONE}
                    placeholder="Pilih akun"
                    searchPlaceholder="Cari nomor atau nama akun"
                    emptyMessage="Akun tidak ditemukan."
                    portalContainer={portal}
                    onValueChange={(next) =>
                        onChange(next === null || next === NONE ? null : next)
                    }
                />,
            )}
            {reason && (
                <FieldDescription>
                    {reason}. Posting yang memakai akun ini tertahan sampai
                    akunnya diganti.
                </FieldDescription>
            )}
            {error && <FieldError>{error}</FieldError>}
        </Field>
    );
}
