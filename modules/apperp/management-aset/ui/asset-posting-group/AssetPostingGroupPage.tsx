import type { ReactNode, RefObject } from 'react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { toast } from 'sonner';
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
} from '@apperp/ui/field';
import { Input } from '@apperp/ui/input';
import { Select } from '@apperp/ui/select';
import {
    Sheet,
    SheetContent,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@apperp/ui/sheet';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@apperp/ui/table';
import { api, ApiError, errorMessage } from '../api';

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
    [column: string]: string | string[] | null;
};

type Group = {
    id: string;
    kode: string;
    nama: string;
    aktif: boolean;
    current: PostingRow | null;
    rows: PostingRow[];
    missing: string[];
};

type Matrix = {
    today: string;
    accounts: AccountColumn[];
    groups: Group[];
    incomplete_groups: number;
    account_details: Record<string, Account>;
};

/** Nilai pilihan "tidak dipetakan"; `Select` tidak punya pilihan kosong bawaan. */
const NONE = '__none__';

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
    const [matrix, setMatrix] = useState<Matrix | null>(null);
    const [loadError, setLoadError] = useState<string | null>(null);
    const [editing, setEditing] = useState<Group | null>(null);
    // Effect satu-satunya pemilik pengambilan data; pemuatan ulang sesudah simpan dinyatakan
    // dengan menaikkan penanda ini.
    const [versiMuat, setVersiMuat] = useState(0);

    useEffect(() => {
        let dilepas = false;

        const muat = async () => {
            try {
                const response = await api<{ data: Matrix }>(
                    '/posting-group-aset',
                );

                if (dilepas) {
                    return;
                }

                setMatrix(response.data);
                setLoadError(null);
            } catch (caught) {
                if (dilepas) {
                    return;
                }

                setLoadError(
                    errorMessage(caught, 'Posting group belum dapat dimuat.'),
                );
            }
        };

        void muat();

        return () => {
            dilepas = true;
        };
    }, [versiMuat]);

    return (
        <Card className="min-h-full rounded-none border-0 shadow-none">
            <CardHeader className="border-b px-5 py-4">
                <CardTitle>Posting group aset</CardTitle>
                <p className="text-muted-foreground mt-1 text-sm">
                    Akun jurnal untuk setiap group aset. Posting memakai baris
                    yang tanggal berlakunya paling akhir, tetapi tidak melewati
                    tanggal postingnya.
                </p>
            </CardHeader>
            <CardContent className="space-y-4 px-5 py-4">
                {loadError && <FieldError>{loadError}</FieldError>}
                {matrix && matrix.groups.length === 0 && (
                    <Empty>
                        <EmptyHeader>
                            <EmptyTitle>Belum ada group aset</EmptyTitle>
                            <EmptyDescription>
                                Buat group aset lebih dulu di Master data ›
                                Group aset, lalu petakan akunnya di sini.
                            </EmptyDescription>
                        </EmptyHeader>
                    </Empty>
                )}
                {matrix && matrix.groups.length > 0 && (
                    <>
                        <Summary matrix={matrix} />
                        <PostingMatrix
                            matrix={matrix}
                            canEdit={can('create') || can('update')}
                            onOpen={setEditing}
                        />
                    </>
                )}
            </CardContent>
            {editing && matrix && (
                <PostingGroupSheet
                    group={editing}
                    matrix={matrix}
                    canCreate={can('create')}
                    canUpdate={can('update')}
                    canArchive={can('archive')}
                    onClose={() => setEditing(null)}
                    onSaved={() => setVersiMuat((versi) => versi + 1)}
                />
            )}
        </Card>
    );
}

function Summary({ matrix }: { matrix: Matrix }) {
    const required = matrix.accounts
        .filter((account) => account.required)
        .map((account) => account.label.toLowerCase());

    if (matrix.incomplete_groups === 0) {
        return (
            <p className="text-muted-foreground text-sm">
                Semua group sudah punya akun wajib yang berlaku hari ini.
            </p>
        );
    }

    return (
        <div
            role="status"
            className="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 dark:border-red-900/60 dark:bg-red-950/40 dark:text-red-200"
        >
            <p className="font-medium">
                {matrix.incomplete_groups} dari {matrix.groups.length} group
                belum lengkap.
            </p>
            <p className="mt-1">
                Akun wajib ({required.join(', ')}) masih kosong di sel yang
                ditandai merah. Posting aset dari group itu akan tertahan sampai
                akunnya diisi.
            </p>
        </div>
    );
}

function PostingMatrix({
    matrix,
    canEdit,
    onOpen,
}: {
    matrix: Matrix;
    canEdit: boolean;
    onOpen: (group: Group) => void;
}) {
    return (
        <div className="overflow-x-auto rounded-md border">
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead className="min-w-48">Group aset</TableHead>
                        <TableHead className="min-w-32">
                            Berlaku sejak
                        </TableHead>
                        {matrix.accounts.map((account) => (
                            <TableHead
                                key={account.column}
                                className="min-w-44 align-bottom"
                            >
                                <span className="block">{account.label}</span>
                                <span className="text-muted-foreground block text-xs font-normal">
                                    {account.required
                                        ? 'Wajib'
                                        : 'Bila dipakai'}
                                </span>
                            </TableHead>
                        ))}
                        <TableHead className="w-24">
                            <span className="sr-only">Aksi</span>
                        </TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {matrix.groups.map((group) => (
                        <TableRow key={group.id}>
                            <TableCell>
                                <span className="block font-mono text-xs">
                                    {group.kode}
                                </span>
                                <span className="block">{group.nama}</span>
                                {!group.aktif && (
                                    <Badge variant="outline" className="mt-1">
                                        Nonaktif
                                    </Badge>
                                )}
                            </TableCell>
                            <TableCell className="text-sm">
                                {group.current
                                    ? formatDate(group.current.effective_from)
                                    : 'Belum ada'}
                                {group.rows.length > 1 && (
                                    <span className="text-muted-foreground block text-xs">
                                        {group.rows.length} tanggal berlaku
                                    </span>
                                )}
                            </TableCell>
                            {matrix.accounts.map((column) => (
                                <AccountCell
                                    key={column.column}
                                    column={column}
                                    accountId={accountOf(
                                        group.current,
                                        column.column,
                                    )}
                                    details={matrix.account_details}
                                />
                            ))}
                            <TableCell>
                                <Button
                                    variant="outline"
                                    size="sm"
                                    onClick={() => onOpen(group)}
                                >
                                    {canEdit ? 'Atur' : 'Lihat'}
                                </Button>
                            </TableCell>
                        </TableRow>
                    ))}
                </TableBody>
            </Table>
        </div>
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
    const problem =
        (accountId === null && column.required) ||
        (accountId !== null && (account === undefined || !account.active));
    const className = problem
        ? 'bg-red-50 text-red-800 dark:bg-red-950/40 dark:text-red-200'
        : undefined;

    if (accountId === null) {
        return (
            <TableCell className={className}>
                {column.required ? (
                    <span className="text-sm font-medium">Kosong</span>
                ) : (
                    <span className="text-muted-foreground">—</span>
                )}
            </TableCell>
        );
    }

    return (
        <TableCell className={className}>
            {account ? (
                <>
                    <span className="block font-mono text-xs">
                        {account.code}
                    </span>
                    <span className="block max-w-56 truncate text-sm">
                        {account.name}
                    </span>
                    {!account.active && (
                        <span className="block text-xs font-medium">
                            Nonaktif
                        </span>
                    )}
                </>
            ) : (
                <span className="text-sm font-medium">
                    Tidak ada di daftar akun
                </span>
            )}
        </TableCell>
    );
}

function PostingGroupSheet({
    group,
    matrix,
    canCreate,
    canUpdate,
    canArchive,
    onClose,
    onSaved,
}: {
    group: Group;
    matrix: Matrix;
    canCreate: boolean;
    canUpdate: boolean;
    canArchive: boolean;
    onClose: () => void;
    onSaved: () => void;
}) {
    const sheetRef = useRef<HTMLDivElement>(null);
    // `null` berarti menyusun baris untuk tanggal berlaku baru.
    const [selected, setSelected] = useState<string | null>(
        group.current?.effective_from ?? group.rows[0]?.effective_from ?? null,
    );
    const row =
        group.rows.find((baris) => baris.effective_from === selected) ?? null;

    return (
        <Sheet open onOpenChange={(open) => !open && onClose()}>
            <SheetContent
                ref={sheetRef}
                side="right"
                className="w-full gap-0 p-0 sm:max-w-xl"
            >
                <SheetHeader className="border-b px-6 py-5 pr-12">
                    <SheetTitle>
                        Posting group {group.kode} · {group.nama}
                    </SheetTitle>
                </SheetHeader>
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
                            onSelect={setSelected}
                        />
                    }
                    onClose={onClose}
                    onSaved={onSaved}
                />
            </SheetContent>
        </Sheet>
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
    const [known, setKnown] = useState<Record<string, Account>>(
        matrix.account_details,
    );
    const [results, setResults] = useState<Account[]>([]);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [saving, setSaving] = useState(false);
    const [archiving, setArchiving] = useState(false);
    const searchTimer = useRef<ReturnType<typeof setTimeout> | null>(null);

    const terima = (accounts: Account[]) => {
        setResults(accounts);
        setKnown((current) => ({
            ...current,
            ...Object.fromEntries(
                accounts.map((account) => [account.id, account]),
            ),
        }));
    };

    // Pilihan awal pemilih akun, sebelum pengguna mengetik apa pun.
    useEffect(() => {
        if (!editable) {
            return;
        }

        let dilepas = false;

        const muat = async () => {
            try {
                const response = await api<{ data: Account[] }>(
                    '/posting-group-aset/akun',
                );

                if (!dilepas) {
                    setResults(response.data);
                    setKnown((current) => ({
                        ...current,
                        ...Object.fromEntries(
                            response.data.map((account) => [
                                account.id,
                                account,
                            ]),
                        ),
                    }));
                }
            } catch {
                // Pemilih tetap dapat dipakai lewat pencarian.
            }
        };

        void muat();

        return () => {
            dilepas = true;
        };
    }, [editable]);

    // Satu permintaan setelah pengguna berhenti mengetik, bukan satu per huruf.
    const cariNanti = (query: string) => {
        if (searchTimer.current) {
            clearTimeout(searchTimer.current);
        }

        searchTimer.current = setTimeout(() => {
            api<{ data: Account[] }>(
                `/posting-group-aset/akun?q=${encodeURIComponent(query)}`,
            )
                .then((response) => terima(response.data))
                .catch(() => setResults([]));
        }, 250);
    };

    async function save() {
        const date = row ? row.effective_from : effectiveFrom;

        if (!/^\d{4}-\d{2}-\d{2}$/.test(date)) {
            setErrors({ effective_from: 'Isi tanggal berlaku.' });

            return;
        }

        setSaving(true);
        setErrors({});

        try {
            await api(`/posting-group-aset/${group.id}/${date}`, {
                method: 'PUT',
                body: JSON.stringify(values),
            });
            toast.success(`Posting group ${group.kode} disimpan.`);
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

            toast.error(errorMessage(caught, 'Posting group belum tersimpan.'));
        } finally {
            setSaving(false);
        }
    }

    async function archive() {
        if (!row) {
            return;
        }

        try {
            await api(`/posting-group-aset/${group.id}/${row.effective_from}`, {
                method: 'DELETE',
            });
            toast.success(
                `Baris ${formatDate(row.effective_from)} diarsipkan.`,
            );
            onSaved();
            onClose();
        } catch (caught) {
            toast.error(errorMessage(caught, 'Baris belum diarsipkan.'));
        } finally {
            setArchiving(false);
        }
    }

    return (
        <>
            <div className="min-h-0 flex-1 overflow-y-auto px-6 py-5">
                <FieldGroup>
                    {tabs}
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
                            onSearch={cariNanti}
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
            <SheetFooter className="border-t px-6 py-4 sm:flex-row sm:justify-between">
                <div>
                    {row && canArchive && (
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => setArchiving(true)}
                        >
                            Arsipkan baris
                        </Button>
                    )}
                </div>
                <div className="flex gap-2">
                    <Button type="button" variant="outline" onClick={onClose}>
                        Tutup
                    </Button>
                    {editable && (
                        <Button
                            type="button"
                            disabled={saving}
                            onClick={() => void save()}
                        >
                            {saving ? 'Menyimpan…' : 'Simpan'}
                        </Button>
                    )}
                </div>
            </SheetFooter>
            <AlertDialog
                open={archiving}
                onOpenChange={(open) => !open && setArchiving(false)}
            >
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>
                            Arsipkan baris{' '}
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
                        <AlertDialogAction onClick={() => void archive()}>
                            Arsipkan
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
                <Button
                    type="button"
                    size="sm"
                    variant={selected === null ? 'default' : 'outline'}
                    onClick={() => onSelect(null)}
                >
                    Tanggal berlaku baru
                </Button>
            )}
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
    onSearch,
    onChange,
}: {
    column: AccountColumn;
    value: string | null;
    known: Record<string, Account>;
    results: Account[];
    disabled: boolean;
    error?: string;
    portal: RefObject<HTMLDivElement | null>;
    onSearch: (query: string) => void;
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

    if (disabled) {
        return (
            <Field>
                <Input
                    label={column.label}
                    value={
                        current
                            ? accountLabel(current)
                            : value
                              ? value
                              : 'Tidak dipetakan'
                    }
                    disabled
                />
            </Field>
        );
    }

    return (
        <Field data-invalid={Boolean(error)}>
            <Select
                label={column.label}
                required={column.required}
                items={items}
                value={value ?? NONE}
                placeholder="Pilih akun"
                searchPlaceholder="Cari nomor atau nama akun"
                emptyMessage="Akun tidak ditemukan."
                portalContainer={portal}
                onSearchChange={onSearch}
                onValueChange={(next) =>
                    onChange(next === null || next === NONE ? null : next)
                }
            />
            {current && !current.active && (
                <FieldDescription>
                    Akun ini nonaktif. Posting yang memakainya tertahan sampai
                    akunnya diganti.
                </FieldDescription>
            )}
            {error && <FieldError>{error}</FieldError>}
        </Field>
    );
}
