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
    AlertDialogTrigger,
} from '@apperp/ui/alert-dialog';
import { Badge } from '@apperp/ui/badge';
import { Button } from '@apperp/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@apperp/ui/card';
import { Checkbox } from '@apperp/ui/checkbox';
import {
    CollapsibleSection,
    CollapsibleSectionGroup,
} from '@apperp/ui/collapsible-section';
import { DataTable } from '@apperp/ui/data-table';
import { Empty, EmptyDescription, EmptyTitle } from '@apperp/ui/empty';
import { FieldError, FieldLegend, FieldSet } from '@apperp/ui/field';
import { FormControl, FormGrid } from '@apperp/ui/form-control';
import { InlineLookup } from '@apperp/ui/inline-lookup';
import { Input } from '@apperp/ui/input';
import {
    PageDialog,
    PageDialogBody,
    PageDialogClose,
    PageDialogContent,
    PageDialogToolbar,
    PageDialogTrigger,
} from '@apperp/ui/page-dialog';
import { Separator } from '@apperp/ui/separator';
import { Tabs, TabsList, TabsTrigger } from '@apperp/ui/tabs';
import { TransferList } from '@apperp/ui/transfer-list';
import { useRecordTransition } from '@apperp/ui/use-record-transition';
import { Head, Link, useForm } from '@inertiajs/react';
import {
    Check,
    ChevronRight,
    CopyPlus,
    ExternalLink,
    FileKey2,
    FileText,
    KeyRound,
    Layers,
    Pencil,
    Plus,
    Search,
    ShieldCheck,
    UserCog,
} from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { FormErrorHeader } from '@/components/form-error-header';
import Heading from '@/components/heading';
import { useInertiaFeedback } from '@/hooks/use-inertia-feedback';

type Source = 'manifest' | 'custom';
type Status = 'draft' | 'active';

type App = { id: string; name: string };
type RoleRef = { id: string; name: string };
type Role = {
    id: string;
    name: string;
    /** Role Owner: selalu memegang semua duty yang sah, diatur otomatis oleh server. */
    is_owner: boolean;
    version: number;
    duty_codes: string[];
    child_roles: RoleRef[];
    parent_roles: RoleRef[];
};
type Duty = {
    code: string;
    name: string;
    description: string | null;
    app_id: string | null;
    source: Source;
    status: Status;
    version: number;
    privilege_codes: string[];
};
type Privilege = {
    code: string;
    name: string;
    description: string | null;
    app_id: string | null;
    source: Source;
    status: Status;
    version: number;
    permission_codes: string[];
};
type Permission = {
    code: string;
    name: string;
    description: string | null;
    app_id: string;
    access_level: string;
    entry_point: { code: string; name: string; type: string } | null;
};

type Props = {
    canManage: boolean;
    apps: App[];
    roles: Role[];
    duties: Duty[];
    privileges: Privilege[];
    permissions: Permission[];
};

type Level = 'role' | 'duty' | 'privilege' | 'permission';

/**
 * Rantai kalian selalu satu tipe anak per tingkat, jadi kolom kategori
 * referensi milik F&O tidak diperlukan — cascade langsung turun ke anaknya.
 * Referensi arah balik ditampilkan pada panel detail.
 */
const CHAIN: Level[] = ['role', 'duty', 'privilege', 'permission'];

const LABEL: Record<Level, string> = {
    role: 'Role',
    duty: 'Tanggung jawab',
    privilege: 'Tugas akses',
    permission: 'Izin',
};

const ICON: Record<Level, typeof ShieldCheck> = {
    role: UserCog,
    duty: ShieldCheck,
    privilege: KeyRound,
    permission: FileKey2,
};

const toggle = (values: string[], value: string, checked: boolean) =>
    checked
        ? [...new Set([...values, value])]
        : values.filter((item) => item !== value);

const matches = (haystack: string, needle: string) =>
    haystack.toLowerCase().includes(needle.trim().toLowerCase());

function StatusBadge({ value }: { value: Status }) {
    return (
        <Badge variant={value === 'active' ? 'secondary' : 'outline'}>
            {value === 'active' ? 'Diterbitkan' : 'Draf'}
        </Badge>
    );
}

function SourceBadge({ value }: { value: Source }) {
    return (
        <span className="text-xs text-muted-foreground">
            {value === 'custom' ? 'Dibuat khusus' : 'Dari aplikasi'}
        </span>
    );
}

type Row = {
    id: string;
    name: string;
    hint?: string;
    childCount: number;
    status?: Status;
};

function Column({
    level,
    rows,
    selected,
    onSelect,
    filter,
}: {
    level: Level;
    rows: Row[];
    selected: string | null;
    onSelect: (id: string) => void;
    filter?: React.ReactNode;
}) {
    const Glyph = ICON[level];

    return (
        <div className="flex w-64 shrink-0 flex-col border-r border-border bg-card/30 last:border-r-0">
            <div className="flex items-center gap-2 border-b border-border bg-primary/10 px-3.5 py-2.5 dark:bg-primary/20">
                <Glyph className="size-4 shrink-0 text-primary" />
                <span className="flex-1 text-xs font-bold tracking-wider text-foreground uppercase">
                    {LABEL[level]}
                </span>
                <Badge
                    variant="secondary"
                    className="border-0 bg-primary/15 px-1.5 py-0 text-[10px] font-semibold text-primary"
                >
                    {rows.length}
                </Badge>
            </div>
            {filter ? (
                <div className="border-b border-border bg-background/50 p-2.5">
                    {filter}
                </div>
            ) : null}
            <div className="min-h-0 flex-1 divide-y divide-border/40 overflow-y-auto">
                {rows.length === 0 ? (
                    <p className="p-4 text-xs text-muted-foreground italic">
                        Tidak ada {LABEL[level].toLowerCase()}.
                    </p>
                ) : (
                    rows.map((row) => (
                        <button
                            key={row.id}
                            type="button"
                            onClick={() => onSelect(row.id)}
                            className={`flex w-full items-center gap-2.5 border-l-4 px-3.5 py-2.5 text-left text-sm transition-all hover:bg-accent/60 ${
                                selected === row.id
                                    ? 'border-l-primary bg-primary/10 text-primary'
                                    : 'border-l-transparent text-foreground'
                            }`}
                        >
                            <span className="min-w-0 flex-1">
                                <span className="block truncate">
                                    {row.name}
                                </span>
                                {row.hint ? (
                                    <span className="block truncate text-xs text-muted-foreground">
                                        {row.hint}
                                    </span>
                                ) : null}
                            </span>
                            {row.status === 'draft' ? (
                                <Badge variant="outline">Draf</Badge>
                            ) : null}
                            {row.childCount > 0 ? (
                                <ChevronRight className="size-4 shrink-0 text-muted-foreground" />
                            ) : null}
                        </button>
                    ))
                )}
            </div>
        </div>
    );
}

function DetailRow({
    label,
    value,
}: {
    label: string;
    value: React.ReactNode;
}) {
    return (
        <div className="grid grid-cols-[7rem_1fr] gap-2 py-1 text-sm">
            <span className="text-muted-foreground">{label}</span>
            <span className="min-w-0 break-words">{value}</span>
        </div>
    );
}

function UsedBy({ groups }: { groups: { label: string; names: string[] }[] }) {
    const populated = groups.filter((group) => group.names.length > 0);

    return (
        <div className="space-y-3">
            <p className="text-sm font-medium">Dipakai oleh</p>
            {populated.length === 0 ? (
                <p className="text-xs text-muted-foreground">
                    Belum dipakai di mana pun. Aman diubah.
                </p>
            ) : (
                populated.map((group) => (
                    <div key={group.label}>
                        <p className="text-xs text-muted-foreground">
                            {group.label} ({group.names.length})
                        </p>
                        <ul className="mt-1 space-y-0.5">
                            {group.names.map((name) => (
                                <li key={name} className="text-sm">
                                    {name}
                                </li>
                            ))}
                        </ul>
                    </div>
                ))
            )}
        </div>
    );
}

// Urutan kolom mengikuti grid F&O.
const ACCESS_LEVELS = [
    'read',
    'update',
    'create',
    'delete',
    'correct',
    'invoke',
] as const;

type MatrixRow = {
    key: string;
    label: string;
    type: string;
    appId: string;
    byAccess: Map<string, string>;
};

/**
 * Matriks entry point x access level. Sel hanya bisa dicentang bila aplikasi
 * memang mendeklarasikan izin untuk pasangan itu — sel mati berarti tidak ada
 * kode yang mengeceknya, jadi mencentangnya tidak akan pernah berefek.
 */
function ApplicationFilter({
    apps,
    value,
    onChange,
    portalContainer,
}: {
    apps: App[];
    value: string;
    onChange: (id: string) => void;
    portalContainer?: React.RefObject<HTMLDivElement | null>;
}) {
    const items = [{ id: '', name: 'Semua aplikasi' }, ...apps];

    return (
        <InlineLookup
            label="Aplikasi"
            items={items}
            value={items.find((item) => item.id === value) ?? null}
            onValueChange={(item) => onChange(item.id)}
            getItemValue={(item) => item.id}
            getItemLabel={(item) => item.name}
            columns={[
                { id: 'name', header: 'Aplikasi', cell: (item) => item.name },
            ]}
            portalContainer={portalContainer}
            showDetails={false}
            showFullList={false}
        />
    );
}

function PermissionMatrix({
    apps,
    permissions,
    selected,
    onChange,
}: {
    apps: App[];
    permissions: Permission[];
    selected: string[];
    onChange: (codes: string[]) => void;
}) {
    const portalRef = useRef<HTMLDivElement>(null);
    const [query, setQuery] = useState('');
    const [appFilter, setAppFilter] = useState('');
    const appName = new Map(apps.map((app) => [app.id, app.name]));

    const rows = useMemo(() => {
        const byEntryPoint = new Map<string, MatrixRow>();

        permissions.forEach((permission) => {
            const key = permission.entry_point?.code ?? permission.code;
            const row = byEntryPoint.get(key) ?? {
                key,
                label: permission.entry_point?.name ?? permission.name,
                type: permission.entry_point?.type ?? '—',
                appId: permission.app_id,
                byAccess: new Map<string, string>(),
            };

            row.byAccess.set(permission.access_level, permission.code);
            byEntryPoint.set(key, row);
        });

        return [...byEntryPoint.values()];
    }, [permissions]);

    const visible = rows.filter(
        (row) =>
            (!appFilter || row.appId === appFilter) &&
            (!query || matches(row.label, query) || matches(row.key, query)),
    );
    const codesIn = (candidates: MatrixRow[], access: string) =>
        candidates
            .map((row) => row.byAccess.get(access))
            .filter((code): code is string => Boolean(code));

    const toggleColumn = (access: string) => {
        const codes = codesIn(visible, access);
        const allOn = codes.every((code) => selected.includes(code));

        onChange(
            allOn
                ? selected.filter((code) => !codes.includes(code))
                : [...new Set([...selected, ...codes])],
        );
    };

    return (
        <div ref={portalRef} className="space-y-3">
            <div className="flex flex-wrap items-center gap-3">
                <div className="relative min-w-[200px] flex-1">
                    <Search className="absolute top-2.5 left-2.5 size-3.5 text-muted-foreground" />
                    <Input
                        type="search"
                        aria-label="Cari titik akses"
                        placeholder="Cari titik akses…"
                        className="h-9 bg-background pl-8 text-xs"
                        value={query}
                        onChange={(event) => setQuery(event.target.value)}
                    />
                </div>
                <div className="w-56 shrink-0">
                    <ApplicationFilter
                        apps={apps}
                        value={appFilter}
                        onChange={setAppFilter}
                        portalContainer={portalRef}
                    />
                </div>
            </div>

            <div
                className="flex flex-wrap items-center gap-2"
                aria-label="Pilih satu kolom izin"
            >
                {ACCESS_LEVELS.map((access) => {
                    const codes = codesIn(visible, access);
                    const allSelected =
                        codes.length > 0 &&
                        codes.every((code) => selected.includes(code));

                    return (
                        <Button
                            key={access}
                            type="button"
                            variant="outline"
                            size="sm"
                            disabled={codes.length === 0}
                            onClick={() => toggleColumn(access)}
                        >
                            {allSelected ? 'Kosongkan' : 'Pilih'} {access}
                        </Button>
                    );
                })}
            </div>
            <DataTable<MatrixRow>
                data={visible}
                getRowKey={(row) => row.key}
                showRowNumbers={false}
                showColumnMenu={false}
                className="max-h-96"
                emptyMessage="Tidak ada titik akses yang cocok dengan pencarian."
                columns={[
                    {
                        id: 'entry',
                        header: 'Titik akses',
                        minWidth: 320,
                        cell: (row) => (
                            <div className="space-y-1 py-1">
                                <span className="block">{row.label}</span>
                                <span className="block text-xs text-muted-foreground">
                                    {appName.get(row.appId) ?? row.appId} ·{' '}
                                    {row.type}
                                </span>
                                <span className="block font-mono text-xs text-muted-foreground">
                                    {row.key}
                                </span>
                            </div>
                        ),
                    },
                    ...ACCESS_LEVELS.map((access) => ({
                        id: access,
                        header: access,
                        width: 96,
                        align: 'center' as const,
                        cell: (row: MatrixRow) => {
                            const code = row.byAccess.get(access);

                            return code ? (
                                <Checkbox
                                    checked={selected.includes(code)}
                                    aria-label={`${row.label} - ${access}`}
                                    onCheckedChange={(checked) =>
                                        onChange(
                                            toggle(
                                                selected,
                                                code,
                                                checked === true,
                                            ),
                                        )
                                    }
                                />
                            ) : (
                                <span title="Izin ini tidak tersedia">—</span>
                            );
                        },
                    })),
                ]}
            />
            <p className="text-sm text-muted-foreground" aria-live="polite">
                {selected.length} izin dipilih. Tombol kolom hanya mengubah izin
                yang tampil sesuai pencarian dan filter. Tanda — berarti izin
                tidak tersedia.
            </p>
        </div>
    );
}

function PrivilegeAccessDetail({
    privilege,
    permissions,
    apps,
}: {
    privilege: Privilege;
    permissions: Permission[];
    apps: App[];
}) {
    const permissionByCode = new Map(
        permissions.map((item) => [item.code, item]),
    );
    const appById = new Map(apps.map((item) => [item.id, item.name]));

    return (
        <div className="space-y-3">
            {privilege.description ? (
                <p className="text-sm text-muted-foreground">
                    {privilege.description}
                </p>
            ) : null}
            <DataTable
                data={privilege.permission_codes}
                getRowKey={(code) => code}
                emptyMessage="Tugas akses ini belum berisi izin."
                columns={[
                    {
                        id: 'name',
                        header: 'Izin',
                        cell: (code) =>
                            permissionByCode.get(code)?.name ??
                            `Izin tidak tersedia: ${code}`,
                    },
                    {
                        id: 'entry',
                        header: 'Titik akses',
                        cell: (code) =>
                            permissionByCode.get(code)?.entry_point?.name ??
                            '—',
                    },
                    {
                        id: 'access',
                        header: 'Tingkat akses',
                        cell: (code) =>
                            permissionByCode.get(code)?.access_level ?? '—',
                    },
                    {
                        id: 'app',
                        header: 'Aplikasi',
                        cell: (code) => {
                            const permission = permissionByCode.get(code);

                            return permission
                                ? (appById.get(permission.app_id) ??
                                      permission.app_id)
                                : '—';
                        },
                    },
                    {
                        id: 'description',
                        header: 'Penjelasan',
                        cell: (code) =>
                            permissionByCode.get(code)?.description ?? '—',
                    },
                ]}
            />
        </div>
    );
}

function DutyAccessDetail({
    duty,
    privileges,
    permissions,
    apps,
}: {
    duty: Duty;
    privileges: Privilege[];
    permissions: Permission[];
    apps: App[];
}) {
    const privilegeByCode = new Map(
        privileges.map((item) => [item.code, item]),
    );

    return (
        <div className="space-y-3">
            {duty.description ? (
                <p className="text-sm text-muted-foreground">
                    {duty.description}
                </p>
            ) : null}
            {duty.privilege_codes.length === 0 ? (
                <p className="text-sm text-muted-foreground">
                    Tanggung jawab ini belum berisi tugas akses.
                </p>
            ) : null}
            <CollapsibleSectionGroup
                key={duty.code}
                defaultValue={duty.privilege_codes}
            >
                {duty.privilege_codes.map((code) => {
                    const privilege = privilegeByCode.get(code);

                    return (
                        <CollapsibleSection
                            key={code}
                            value={code}
                            title={
                                privilege?.name ??
                                `Tugas akses tidak tersedia: ${code}`
                            }
                            summary={
                                privilege
                                    ? `${privilege.permission_codes.length} izin`
                                    : 'Rincian tidak tersedia'
                            }
                        >
                            {privilege ? (
                                <PrivilegeAccessDetail
                                    privilege={privilege}
                                    permissions={permissions}
                                    apps={apps}
                                />
                            ) : (
                                <p className="text-sm text-muted-foreground">
                                    Referensi ini tidak ditemukan dalam daftar
                                    tugas akses yang tersedia.
                                </p>
                            )}
                        </CollapsibleSection>
                    );
                })}
            </CollapsibleSectionGroup>
        </div>
    );
}

function AccessReferencePicker({
    items,
    selected,
    onChange,
    label,
    renderDetail,
}: {
    items: { id: string; label: string; description: string }[];
    selected: string[];
    onChange: (codes: string[]) => void;
    label: string;
    renderDetail: (id: string) => React.ReactNode;
}) {
    const [activeId, setActiveId] = useState<string | null>(null);
    const detailRef = useRef<HTMLDivElement>(null);
    const activeItem = items.find((item) => item.id === activeId);
    const [query, setQuery] = useState('');
    const choices = items.filter(
        (item) =>
            !selected.includes(item.id) &&
            matches(`${item.label} ${item.description}`, query),
    );
    const assigned = items.filter((item) => selected.includes(item.id));

    return (
        <div className="space-y-3">
            <p className="text-sm text-muted-foreground">
                Klik {label} untuk melihat rincian aksesnya di bawah. Gunakan
                panah untuk memasukkan atau mengeluarkannya. Perubahan berlaku
                setelah Simpan.
            </p>
            <div className="flex min-h-7 items-center gap-2">
                <p
                    role="status"
                    className="min-w-0 flex-1 truncate text-sm text-muted-foreground"
                >
                    {activeItem
                        ? `Pratinjau: ${activeItem.label}`
                        : 'Pilih satu baris untuk memeriksa rincian akses.'}
                </p>
                <Button
                    type="button"
                    variant="ghost"
                    size="command"
                    disabled={!activeItem}
                    onClick={() =>
                        detailRef.current?.scrollIntoView({ block: 'start' })
                    }
                >
                    Lihat rincian
                </Button>
            </div>
            <TransferList
                size="large"
                onActiveItemChange={setActiveId}
                remaining={choices}
                selected={assigned}
                remainingTitle={`Tersedia (${choices.length})`}
                selectedTitle={`Dipilih (${assigned.length})`}
                searchValue={query}
                onSearchChange={setQuery}
                remainingEmptyLabel="Tidak ada pilihan yang cocok."
                selectedEmptyLabel="Belum ada yang dipilih. Tidak ada akses tambahan yang diberikan."
                onChange={({ selected: next }) =>
                    onChange([
                        ...selected.filter(
                            (id) => !items.some((item) => item.id === id),
                        ),
                        ...next.map((item) => item.id),
                    ])
                }
            />
            <Card ref={detailRef}>
                <CardHeader>
                    <CardTitle>
                        {activeItem
                            ? `Rincian: ${activeItem.label}`
                            : 'Rincian akses'}
                    </CardTitle>
                    <CardDescription>
                        {activeItem
                            ? `${activeItem.description} · ${selected.includes(activeItem.id) ? 'Sudah dipilih' : 'Belum dipilih'}`
                            : `Klik salah satu ${label} di sisi Tersedia atau Dipilih untuk memeriksa izin yang dibawanya.`}
                    </CardDescription>
                </CardHeader>
                {activeItem ? (
                    <CardContent>{renderDetail(activeItem.id)}</CardContent>
                ) : null}
            </Card>
        </div>
    );
}

function PrivilegeDialog({
    apps,
    permissions,
    privilege,
}: {
    apps: App[];
    permissions: Permission[];
    privilege?: Privilege;
}) {
    const { wrap: wrapFormRequest, summary: formRequestSummary } =
        useInertiaFeedback();

    const [open, setOpen] = useState(false);
    const form = useForm({
        name: privilege?.name ?? '',
        permission_codes: privilege?.permission_codes ?? [],
    });
    const transition = useRecordTransition(form.isDirty, form.processing);

    return (
        <PageDialog
            open={open}
            onOpenChange={(next) => {
                if (next) {
                    setOpen(true);

                    return;
                }

                transition.request(() => {
                    form.reset();
                    form.clearErrors();
                    setOpen(false);
                });
            }}
        >
            {transition.confirmation}
            <PageDialogTrigger asChild>
                <Button
                    variant={privilege ? 'outline' : 'default'}
                    size="sm"
                    className={
                        privilege
                            ? 'bg-background shadow-xs hover:bg-accent'
                            : 'text-xs font-medium shadow-xs'
                    }
                >
                    {privilege ? (
                        <Pencil className="mr-1 size-3.5" />
                    ) : (
                        <Plus className="mr-1 size-3.5" />
                    )}
                    {privilege ? 'Edit draf' : 'Tugas Akses'}
                </Button>
            </PageDialogTrigger>
            <PageDialogContent
                title="Tugas akses"
                editing
                recordTitle={privilege?.name ?? 'Baru'}
                description="Pilih layar dan tindakan yang diperlukan. Izin yang tidak dipilih tidak diberikan."
                busy={form.processing}
            >
                <form
                    noValidate
                    className="contents"
                    onSubmit={(event) => {
                        event.preventDefault();
                        const done = {
                            onSuccess: () => {
                                form.reset();
                                setOpen(false);
                            },
                        };

                        if (privilege) {
                            // Versi diambil dari props saat ini, bukan data awal form,
                            // supaya simpan kedua tidak ditolak sebagai data basi.
                            form.transform((data) => ({
                                ...data,
                                version: privilege.version,
                            }));
                            form.put(
                                `/settings/security-configuration/privileges/${privilege.code}`,
                                wrapFormRequest(done),
                            );

                            return;
                        }

                        form.post(
                            '/settings/security-configuration/privileges',
                            wrapFormRequest(done),
                        );
                    }}
                >
                    <PageDialogToolbar>
                        <ActionButton
                            action="save"
                            type="submit"
                            disabled={form.processing}
                        />
                        <PageDialogClose asChild>
                            <ActionButton action="cancel" type="button" />
                        </PageDialogClose>
                    </PageDialogToolbar>
                    <>
                        {formRequestSummary}
                        <FormErrorHeader
                            errors={form.errors}
                            operation="Perubahan akses belum disimpan"
                        />
                    </>
                    <PageDialogBody>
                        <FieldError>
                            {Object.entries(form.errors)
                                .filter(
                                    ([key]) =>
                                        ![
                                            'name',
                                            'duty_codes',
                                            'privilege_codes',
                                            'permission_codes',
                                        ].includes(key),
                                )
                                .map(([, message]) => message)
                                .join(' ')}
                        </FieldError>
                        <div className="space-y-6">
                            <FormGrid columns={1}>
                                <FormControl error={form.errors.name}>
                                    <Input
                                        name="name"
                                        label="Nama tugas akses"
                                        value={form.data.name}
                                        onChange={(event) =>
                                            form.setData(
                                                'name',
                                                event.target.value,
                                            )
                                        }
                                    />
                                </FormControl>
                            </FormGrid>
                            <FieldSet data-error-field="permission_codes">
                                <FieldLegend>
                                    Pilih izin yang diperlukan
                                </FieldLegend>
                                <PermissionMatrix
                                    apps={apps}
                                    permissions={permissions}
                                    selected={form.data.permission_codes}
                                    onChange={(codes) =>
                                        form.setData('permission_codes', codes)
                                    }
                                />
                                <FieldError>
                                    {form.errors.permission_codes}
                                </FieldError>
                            </FieldSet>
                        </div>
                    </PageDialogBody>
                </form>
            </PageDialogContent>
        </PageDialog>
    );
}

function DuplicateButton({ path }: { path: string }) {
    const { wrap: wrapFormRequest, summary: formRequestSummary } =
        useInertiaFeedback();

    const form = useForm({});

    return (
        <div className="flex flex-col gap-2">
            <>
                {formRequestSummary}
                <FormErrorHeader
                    errors={form.errors}
                    operation="Tindakan belum berhasil"
                />
            </>
            <Button
                variant="outline"
                size="sm"
                disabled={form.processing}
                onClick={() => form.post(path, wrapFormRequest())}
            >
                <CopyPlus />
                Duplikat
            </Button>
        </div>
    );
}

function DutyDialog({
    apps,
    privileges,
    permissions,
    duty,
}: {
    apps: App[];
    privileges: Privilege[];
    permissions: Permission[];
    duty?: Duty;
}) {
    const { wrap: wrapFormRequest, summary: formRequestSummary } =
        useInertiaFeedback();

    const [open, setOpen] = useState(false);
    const form = useForm({
        name: duty?.name ?? '',
        privilege_codes: duty?.privilege_codes ?? [],
    });
    const transition = useRecordTransition(form.isDirty, form.processing);
    const appName = new Map(apps.map((app) => [app.id, app.name]));

    return (
        <PageDialog
            open={open}
            onOpenChange={(next) => {
                if (next) {
                    setOpen(true);

                    return;
                }

                transition.request(() => {
                    form.reset();
                    form.clearErrors();
                    setOpen(false);
                });
            }}
        >
            {transition.confirmation}
            <PageDialogTrigger asChild>
                <Button
                    size="sm"
                    variant={duty ? 'outline' : 'default'}
                    className={
                        duty
                            ? 'bg-background shadow-xs hover:bg-accent'
                            : 'text-xs font-medium shadow-xs'
                    }
                >
                    {duty ? (
                        <Pencil className="mr-1 size-3.5" />
                    ) : (
                        <Plus className="mr-1 size-3.5" />
                    )}
                    {duty ? 'Edit draf' : 'Tanggung Jawab'}
                </Button>
            </PageDialogTrigger>
            <PageDialogContent
                title="Tanggung jawab"
                editing
                recordTitle={duty?.name ?? 'Baru'}
                description="Gabungkan tugas akses untuk satu pekerjaan. Pilihan akan menjadi bagian dari role yang memakai tanggung jawab ini."
                busy={form.processing}
            >
                <form
                    noValidate
                    className="contents"
                    onSubmit={(event) => {
                        event.preventDefault();
                        const done = {
                            onSuccess: () => {
                                form.reset();
                                setOpen(false);
                            },
                        };

                        if (duty) {
                            form.transform((data) => ({
                                ...data,
                                version: duty.version,
                            }));
                            form.put(
                                `/settings/security-configuration/duties/${duty.code}`,
                                wrapFormRequest(done),
                            );

                            return;
                        }

                        form.post(
                            '/settings/security-configuration/duties',
                            wrapFormRequest(done),
                        );
                    }}
                >
                    <PageDialogToolbar>
                        <ActionButton
                            action="save"
                            type="submit"
                            disabled={form.processing}
                        />
                        <PageDialogClose asChild>
                            <ActionButton action="cancel" type="button" />
                        </PageDialogClose>
                    </PageDialogToolbar>
                    <>
                        {formRequestSummary}
                        <FormErrorHeader
                            errors={form.errors}
                            operation="Perubahan akses belum disimpan"
                        />
                    </>
                    <PageDialogBody>
                        <FieldError>
                            {Object.entries(form.errors)
                                .filter(
                                    ([key]) =>
                                        ![
                                            'name',
                                            'duty_codes',
                                            'privilege_codes',
                                            'permission_codes',
                                        ].includes(key),
                                )
                                .map(([, message]) => message)
                                .join(' ')}
                        </FieldError>
                        <div className="space-y-6">
                            <FormGrid columns={1}>
                                <FormControl error={form.errors.name}>
                                    <Input
                                        name="name"
                                        label="Nama tanggung jawab"
                                        value={form.data.name}
                                        onChange={(event) =>
                                            form.setData(
                                                'name',
                                                event.target.value,
                                            )
                                        }
                                    />
                                </FormControl>
                            </FormGrid>
                            <FieldSet data-error-field="privilege_codes">
                                <FieldLegend>Pilih tugas akses</FieldLegend>
                                <AccessReferencePicker
                                    label="tugas akses"
                                    renderDetail={(code) => {
                                        const privilege = privileges.find(
                                            (item) => item.code === code,
                                        );

                                        return privilege ? (
                                            <PrivilegeAccessDetail
                                                privilege={privilege}
                                                permissions={permissions}
                                                apps={apps}
                                            />
                                        ) : null;
                                    }}
                                    items={privileges.map((item) => ({
                                        id: item.code,
                                        label: item.name,
                                        description: `${item.app_id ? (appName.get(item.app_id) ?? item.app_id) : 'Dibuat khusus'} · ${item.permission_codes.length} izin · ${item.status === 'active' ? 'Diterbitkan' : 'Draf'}`,
                                    }))}
                                    selected={form.data.privilege_codes}
                                    onChange={(codes) =>
                                        form.setData('privilege_codes', codes)
                                    }
                                />
                                <FieldError>
                                    {form.errors.privilege_codes}
                                </FieldError>
                            </FieldSet>
                        </div>
                    </PageDialogBody>
                </form>
            </PageDialogContent>
        </PageDialog>
    );
}

function RoleDialog({
    apps,
    duties,
    privileges,
    permissions,
    role,
}: {
    apps: App[];
    duties: Duty[];
    privileges: Privilege[];
    permissions: Permission[];
    role?: Role;
}) {
    const { wrap: wrapFormRequest, summary: formRequestSummary } =
        useInertiaFeedback();

    const [open, setOpen] = useState(false);
    const form = useForm({
        name: role?.name ?? '',
        duty_codes: role?.duty_codes ?? [],
        // Sub role dipertahankan apa adanya; form ini hanya mengurus duty.
        child_role_ids: role?.child_roles.map((item) => item.id) ?? [],
    });
    const transition = useRecordTransition(form.isDirty, form.processing);
    // Draf tidak boleh menjadi sumber hak role — API menolaknya dengan 422.
    const selectable = duties.filter((duty) => duty.status === 'active');
    const appName = new Map(apps.map((app) => [app.id, app.name]));

    return (
        <PageDialog
            open={open}
            onOpenChange={(next) => {
                if (next) {
                    setOpen(true);

                    return;
                }

                transition.request(() => {
                    form.reset();
                    form.clearErrors();
                    setOpen(false);
                });
            }}
        >
            {transition.confirmation}
            <PageDialogTrigger asChild>
                <Button
                    variant={role ? 'outline' : 'default'}
                    size="sm"
                    className={
                        role
                            ? 'bg-background shadow-xs hover:bg-accent'
                            : 'text-xs font-medium shadow-xs'
                    }
                >
                    {role ? (
                        <Pencil className="mr-1 size-3.5" />
                    ) : (
                        <Plus className="mr-1 size-3.5" />
                    )}
                    {role ? 'Edit role' : 'Role'}
                </Button>
            </PageDialogTrigger>
            <PageDialogContent
                title="Role"
                editing
                recordTitle={role?.name ?? 'Baru'}
                description="Pilih tanggung jawab yang dibutuhkan. Batas data diatur saat role diberikan kepada anggota."
                busy={form.processing}
            >
                <form
                    noValidate
                    className="contents"
                    onSubmit={(event) => {
                        event.preventDefault();
                        const done = { onSuccess: () => setOpen(false) };

                        if (role) {
                            form.transform((data) => ({
                                ...data,
                                version: role.version,
                            }));
                            form.put(
                                `/settings/access/roles/${role.id}`,
                                wrapFormRequest(done),
                            );

                            return;
                        }

                        form.post(
                            '/settings/access/roles',
                            wrapFormRequest(done),
                        );
                    }}
                >
                    <PageDialogToolbar>
                        <ActionButton
                            action="save"
                            type="submit"
                            disabled={form.processing}
                        />
                        <PageDialogClose asChild>
                            <ActionButton action="cancel" type="button" />
                        </PageDialogClose>
                    </PageDialogToolbar>
                    <>
                        {formRequestSummary}
                        <FormErrorHeader
                            errors={form.errors}
                            operation="Perubahan akses belum disimpan"
                        />
                    </>
                    <PageDialogBody>
                        <FieldError>
                            {Object.entries(form.errors)
                                .filter(
                                    ([key]) =>
                                        ![
                                            'name',
                                            'duty_codes',
                                            'privilege_codes',
                                            'permission_codes',
                                        ].includes(key),
                                )
                                .map(([, message]) => message)
                                .join(' ')}
                        </FieldError>
                        <div className="space-y-6">
                            <FormGrid columns={1}>
                                <FormControl error={form.errors.name}>
                                    <Input
                                        name="name"
                                        label="Nama role"
                                        value={form.data.name}
                                        onChange={(event) =>
                                            form.setData(
                                                'name',
                                                event.target.value,
                                            )
                                        }
                                    />
                                </FormControl>
                            </FormGrid>
                            <FieldSet data-error-field="duty_codes">
                                <FieldLegend>Tanggung jawab bisnis</FieldLegend>
                                <p className="text-sm text-muted-foreground">
                                    Pilih sedikitnya satu tanggung jawab yang
                                    sudah diterbitkan.
                                </p>
                                <AccessReferencePicker
                                    label="tanggung jawab"
                                    renderDetail={(code) => {
                                        const duty = duties.find(
                                            (item) => item.code === code,
                                        );

                                        return duty ? (
                                            <DutyAccessDetail
                                                duty={duty}
                                                privileges={privileges}
                                                permissions={permissions}
                                                apps={apps}
                                            />
                                        ) : null;
                                    }}
                                    items={selectable.map((item) => ({
                                        id: item.code,
                                        label: item.name,
                                        description: `${item.app_id ? (appName.get(item.app_id) ?? item.app_id) : 'Dibuat khusus'} · ${item.privilege_codes.length} tugas akses`,
                                    }))}
                                    selected={form.data.duty_codes}
                                    onChange={(codes) =>
                                        form.setData('duty_codes', codes)
                                    }
                                />
                                <FieldError>
                                    {form.errors.duty_codes}
                                </FieldError>
                            </FieldSet>
                            {role && role.child_roles.length > 0 && (
                                <section className="space-y-2">
                                    <h3 className="text-sm font-medium">
                                        Akses dari role lain
                                    </h3>
                                    <p className="text-sm text-muted-foreground">
                                        Role ini juga mewarisi akses dari{' '}
                                        {role.child_roles
                                            .map((item) => item.name)
                                            .join(', ')}
                                        . Hubungan ini tetap dipertahankan saat
                                        menyimpan.
                                    </p>
                                </section>
                            )}
                        </div>
                    </PageDialogBody>
                </form>
            </PageDialogContent>
        </PageDialog>
    );
}

function DeleteRoleButton({
    role,
    onDeleted,
}: {
    role: Role;
    onDeleted: () => void;
}) {
    const { wrap: wrapFormRequest, summary: formRequestSummary } =
        useInertiaFeedback();

    const form = useForm({});
    const [open, setOpen] = useState(false);

    return (
        <AlertDialog
            open={open}
            onOpenChange={(next) => {
                if (!form.processing) {
                    setOpen(next);

                    if (next) {
                        form.clearErrors();
                    }
                }
            }}
        >
            <AlertDialogTrigger asChild>
                <ActionButton action="delete" />
            </AlertDialogTrigger>
            <AlertDialogContent>
                <AlertDialogHeader>
                    <AlertDialogTitle>Hapus role {role.name}?</AlertDialogTitle>
                    <AlertDialogDescription>
                        Anggota yang memegang role ini kehilangan seluruh hak
                        yang berasal darinya. Tanggung jawab dan tugas akses di
                        dalamnya tidak ikut terhapus.
                    </AlertDialogDescription>
                </AlertDialogHeader>
                <>
                    {formRequestSummary}
                    <FormErrorHeader
                        errors={form.errors}
                        operation="Penghapusan belum berhasil"
                    />
                </>
                <AlertDialogFooter>
                    <AlertDialogCancel disabled={form.processing}>
                        Batal
                    </AlertDialogCancel>
                    <AlertDialogAction
                        disabled={form.processing}
                        className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
                        onClick={(event) => {
                            event.preventDefault();
                            form.transform(() => ({ version: role.version }));
                            form.delete(
                                `/settings/access/roles/${role.id}`,
                                wrapFormRequest({
                                    onSuccess: () => {
                                        setOpen(false);
                                        onDeleted();
                                    },
                                }),
                            );
                        }}
                    >
                        Hapus
                    </AlertDialogAction>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
    );
}

function PublishButton({ path, version }: { path: string; version: number }) {
    const { wrap: wrapFormRequest, summary: formRequestSummary } =
        useInertiaFeedback();

    const form = useForm({});

    return (
        <div className="flex flex-col gap-2">
            <>
                {formRequestSummary}
                <FormErrorHeader
                    errors={form.errors}
                    operation="Tindakan belum berhasil"
                />
            </>
            <Button
                size="sm"
                variant="default"
                disabled={form.processing}
                onClick={() => {
                    form.transform(() => ({ version }));
                    form.post(path, wrapFormRequest());
                }}
            >
                <Check className="mr-1 size-3.5" />
                Terbitkan
            </Button>
        </div>
    );
}

function DeleteDraftButton({
    path,
    name,
    version,
}: {
    path: string;
    name: string;
    version: number;
}) {
    const { wrap: wrapFormRequest, summary: formRequestSummary } =
        useInertiaFeedback();

    const form = useForm({});
    const [open, setOpen] = useState(false);

    return (
        <AlertDialog
            open={open}
            onOpenChange={(next) => {
                if (!form.processing) {
                    setOpen(next);

                    if (next) {
                        form.clearErrors();
                    }
                }
            }}
        >
            <AlertDialogTrigger asChild>
                <ActionButton action="delete" />
            </AlertDialogTrigger>
            <AlertDialogContent>
                <AlertDialogHeader>
                    <AlertDialogTitle>Hapus draf {name}?</AlertDialogTitle>
                    <AlertDialogDescription>
                        Draf belum dipakai role mana pun, jadi menghapusnya
                        tidak mengubah hak siapa pun. Objek bawaan aplikasi yang
                        disalin tidak ikut terhapus.
                    </AlertDialogDescription>
                </AlertDialogHeader>
                <>
                    {formRequestSummary}
                    <FormErrorHeader
                        errors={form.errors}
                        operation="Penghapusan belum berhasil"
                    />
                </>
                <AlertDialogFooter>
                    <AlertDialogCancel disabled={form.processing}>
                        Batal
                    </AlertDialogCancel>
                    <AlertDialogAction
                        disabled={form.processing}
                        className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
                        onClick={(event) => {
                            event.preventDefault();
                            form.transform(() => ({ version }));
                            form.delete(
                                path,
                                wrapFormRequest({
                                    onSuccess: () => setOpen(false),
                                }),
                            );
                        }}
                    >
                        Hapus
                    </AlertDialogAction>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
    );
}

/**
 * Setara tab "Unpublished objects" di F&O: seluruh perubahan tenant yang belum
 * diterbitkan, lengkap dengan isinya dan aksinya, supaya draf tidak perlu
 * dicari lagi lewat cascade.
 */
function DraftPanel({
    apps,
    duties,
    privileges,
    permissions,
    allPrivileges,
}: {
    apps: App[];
    duties: Duty[];
    privileges: Privilege[];
    permissions: Permission[];
    allPrivileges: Privilege[];
}) {
    const permissionByCode = new Map(
        permissions.map((item) => [item.code, item]),
    );
    const privilegeByCode = new Map(
        allPrivileges.map((item) => [item.code, item]),
    );

    if (duties.length + privileges.length === 0) {
        return (
            <Empty className="py-12">
                <EmptyTitle>Tidak ada draf</EmptyTitle>
                <EmptyDescription>
                    Duplikat atau buat tugas akses dan tanggung jawab, lalu
                    hasilnya menunggu di sini sampai diterbitkan.
                </EmptyDescription>
            </Empty>
        );
    }

    return (
        <div className="space-y-3 p-4">
            {privileges.map((item) => (
                <div
                    key={item.code}
                    className="rounded-lg border border-border bg-card p-4 shadow-sm transition-all hover:shadow"
                >
                    <div className="flex flex-wrap items-center gap-3">
                        <span className="min-w-0 flex-1">
                            <span className="flex items-center gap-2">
                                <span className="truncate font-semibold text-foreground">
                                    {item.name}
                                </span>
                                <Badge variant="outline">Draf</Badge>
                            </span>
                            <span className="text-xs text-muted-foreground">
                                Tugas akses · {item.permission_codes.length}{' '}
                                izin
                            </span>
                        </span>
                        <div className="flex items-center gap-2">
                            <PrivilegeDialog
                                key={`privilege:${item.code}:${item.version}`}
                                apps={apps}
                                permissions={permissions}
                                privilege={item}
                            />
                            <DeleteDraftButton
                                name={item.name}
                                version={item.version}
                                path={`/settings/security-configuration/privileges/${item.code}`}
                            />
                            <PublishButton
                                version={item.version}
                                path={`/settings/security-configuration/privileges/${item.code}/publish`}
                            />
                        </div>
                    </div>
                    <ul className="mt-3 flex flex-wrap gap-1.5">
                        {item.permission_codes.map((code) => (
                            <li key={code}>
                                <Badge
                                    variant="secondary"
                                    className="text-xs font-normal"
                                >
                                    {permissionByCode.get(code)?.name ?? code}
                                    {' · '}
                                    {permissionByCode.get(code)?.access_level}
                                </Badge>
                            </li>
                        ))}
                    </ul>
                </div>
            ))}
            {duties.map((item) => (
                <div
                    key={item.code}
                    className="rounded-lg border border-border bg-card p-4 shadow-sm transition-all hover:shadow"
                >
                    <div className="flex flex-wrap items-center gap-3">
                        <span className="min-w-0 flex-1">
                            <span className="flex items-center gap-2">
                                <span className="truncate font-semibold text-foreground">
                                    {item.name}
                                </span>
                                <Badge variant="outline">Draf</Badge>
                            </span>
                            <span className="text-xs text-muted-foreground">
                                Tanggung jawab · {item.privilege_codes.length}{' '}
                                tugas akses
                            </span>
                        </span>
                        <div className="flex items-center gap-2">
                            <DutyDialog
                                key={`duty:${item.code}:${item.version}`}
                                apps={apps}
                                permissions={permissions}
                                privileges={allPrivileges}
                                duty={item}
                            />
                            <DeleteDraftButton
                                name={item.name}
                                version={item.version}
                                path={`/settings/security-configuration/duties/${item.code}`}
                            />
                            <PublishButton
                                version={item.version}
                                path={`/settings/security-configuration/duties/${item.code}/publish`}
                            />
                        </div>
                    </div>
                    <ul className="mt-3 flex flex-wrap gap-1.5">
                        {item.privilege_codes.map((code) => (
                            <li key={code}>
                                <Badge
                                    variant="secondary"
                                    className="text-xs font-normal"
                                >
                                    {privilegeByCode.get(code)?.name ?? code}
                                </Badge>
                            </li>
                        ))}
                    </ul>
                </div>
            ))}
            <p className="pt-1 text-xs text-muted-foreground">
                Terbitkan tugas akses lebih dulu — tanggung jawab menolak terbit
                selama masih memuat tugas akses berstatus draf.
            </p>
        </div>
    );
}

export default function SecurityConfiguration({
    canManage,
    apps,
    roles,
    duties,
    privileges,
    permissions,
}: Props) {
    const [tab, setTab] = useState<Level | 'draft'>('role');
    const [appFilter, setAppFilter] = useState('');
    const [query, setQuery] = useState('');
    const [path, setPath] = useState<string[]>([]);
    const scroller = useRef<HTMLDivElement>(null);

    const draftCount = [...duties, ...privileges].filter(
        (item) => item.status === 'draft',
    ).length;

    const dutyByCode = useMemo(
        () => new Map(duties.map((item) => [item.code, item])),
        [duties],
    );
    const privilegeByCode = useMemo(
        () => new Map(privileges.map((item) => [item.code, item])),
        [privileges],
    );
    const permissionByCode = useMemo(
        () => new Map(permissions.map((item) => [item.code, item])),
        [permissions],
    );
    const appName = useMemo(
        () => new Map(apps.map((app) => [app.id, app.name])),
        [apps],
    );

    /**
     * Referensi arah balik. Inilah yang menjawab "kalau saya ubah ini, siapa
     * yang kena?" — pertanyaan pertama admin sebelum menyentuh apa pun.
     */
    const usedBy = useMemo(() => {
        const add = (map: Map<string, string[]>, key: string, value: string) =>
            map.set(key, [...(map.get(key) ?? []), value]);
        const dutyToRoles = new Map<string, string[]>();
        const privilegeToDuties = new Map<string, string[]>();
        const permissionToPrivileges = new Map<string, string[]>();

        roles.forEach((role) =>
            role.duty_codes.forEach((code) => add(dutyToRoles, code, role.id)),
        );
        duties.forEach((duty) =>
            duty.privilege_codes.forEach((code) =>
                add(privilegeToDuties, code, duty.code),
            ),
        );
        privileges.forEach((privilege) =>
            privilege.permission_codes.forEach((code) =>
                add(permissionToPrivileges, code, privilege.code),
            ),
        );

        return { dutyToRoles, privilegeToDuties, permissionToPrivileges };
    }, [roles, duties, privileges]);

    const roleNames = (ids: string[]) =>
        ids
            .map((id) => roles.find((role) => role.id === id)?.name)
            .filter((name): name is string => Boolean(name));
    const dutyNames = (codes: string[]) =>
        codes
            .map((code) => dutyByCode.get(code)?.name)
            .filter((name): name is string => Boolean(name));
    const privilegeNames = (codes: string[]) =>
        codes
            .map((code) => privilegeByCode.get(code)?.name)
            .filter((name): name is string => Boolean(name));

    const levels = tab === 'draft' ? [] : CHAIN.slice(CHAIN.indexOf(tab));
    const select = (index: number, id: string) =>
        setPath((current) =>
            current[index] === id
                ? current.slice(0, index)
                : [...current.slice(0, index), id],
        );

    // Kolom terbaru selalu ditarik ke dalam pandangan, seperti F&O.
    useEffect(() => {
        scroller.current?.scrollTo({
            left: scroller.current.scrollWidth,
            behavior: 'smooth',
        });
    }, [path]);

    const changeTab = (value: string) => {
        setTab(value as Level | 'draft');
        setPath([]);
    };
    const changeAppFilter = (value: string) => {
        setAppFilter(value);
        setPath([]);
    };

    const rowsFor = (level: Level, index: number): Row[] => {
        const parent = index === 0 ? null : path[index - 1];

        if (index > 0 && !parent) {
            return [];
        }

        if (level === 'role') {
            return roles
                .filter(
                    (role) =>
                        (!appFilter ||
                            role.duty_codes.some(
                                (code) =>
                                    dutyByCode.get(code)?.app_id === appFilter,
                            )) &&
                        (!query || matches(role.name, query)),
                )
                .map((role) => ({
                    id: role.id,
                    name: role.name,
                    hint: `${role.duty_codes.length} tanggung jawab`,
                    childCount: role.duty_codes.length,
                }));
        }

        if (level === 'duty') {
            const codes =
                index === 0
                    ? duties.map((duty) => duty.code)
                    : (roles.find((role) => role.id === parent)?.duty_codes ??
                      []);

            return codes
                .map((code) => dutyByCode.get(code))
                .filter((duty): duty is Duty => Boolean(duty))
                .filter(
                    (duty) =>
                        (index > 0 ||
                            !appFilter ||
                            duty.app_id === appFilter) &&
                        (index > 0 || !query || matches(duty.name, query)),
                )
                .map((duty) => ({
                    id: duty.code,
                    name: duty.name,
                    hint: duty.app_id
                        ? (appName.get(duty.app_id) ?? duty.app_id)
                        : 'Dibuat khusus',
                    childCount: duty.privilege_codes.length,
                    status: duty.status,
                }));
        }

        if (level === 'privilege') {
            const codes =
                index === 0
                    ? privileges.map((item) => item.code)
                    : (dutyByCode.get(parent!)?.privilege_codes ?? []);

            return codes
                .map((code) => privilegeByCode.get(code))
                .filter((item): item is Privilege => Boolean(item))
                .filter(
                    (item) =>
                        (index > 0 ||
                            !appFilter ||
                            item.app_id === appFilter) &&
                        (index > 0 || !query || matches(item.name, query)),
                )
                .map((item) => ({
                    id: item.code,
                    name: item.name,
                    hint: `${item.permission_codes.length} izin`,
                    childCount: item.permission_codes.length,
                    status: item.status,
                }));
        }

        return (privilegeByCode.get(parent!)?.permission_codes ?? [])
            .map((code) => permissionByCode.get(code))
            .filter((item): item is Permission => Boolean(item))
            .map((item) => ({
                id: item.code,
                name: item.name,
                hint: `${item.entry_point?.name ?? '—'} · ${item.access_level}`,
                childCount: 0,
            }));
    };

    const deepest = path.length - 1;
    const detailLevel = deepest >= 0 ? levels[deepest] : null;
    const detailId = deepest >= 0 ? path[deepest] : null;

    // Action pane bekerja pada objek terdalam yang dipilih. Role dikecualikan:
    // aksinya tetap tersedia selama role ada di jalur, supaya menambah
    // tanggung jawab tidak menuntut pengguna naik dulu ke kolom pertama.
    const selectedRole =
        tab === 'role' && path[0]
            ? roles.find((item) => item.id === path[0])
            : undefined;
    const selectedDuty =
        detailLevel === 'duty' && detailId
            ? dutyByCode.get(detailId)
            : undefined;
    const selectedPrivilege =
        detailLevel === 'privilege' && detailId
            ? privilegeByCode.get(detailId)
            : undefined;
    const editable = (item: { source: Source; status: Status }) =>
        item.source === 'custom' && item.status === 'draft';

    const renderDetail = () => {
        if (!detailLevel || !detailId) {
            return (
                <p className="p-4 text-sm text-muted-foreground">
                    Pilih salah satu untuk melihat rinciannya.
                </p>
            );
        }

        if (detailLevel === 'role') {
            const role = roles.find((item) => item.id === detailId);

            if (!role) {
                return null;
            }

            return (
                <div className="space-y-4 p-4">
                    <p className="font-medium">{role.name}</p>
                    <DetailRow
                        label="Tanggung jawab"
                        value={role.duty_codes.length}
                    />
                    <DetailRow
                        label="Sub role"
                        value={
                            role.child_roles
                                .map((item) => item.name)
                                .join(', ') || '—'
                        }
                    />
                    <DetailRow
                        label="Role induk"
                        value={
                            role.parent_roles
                                .map((item) => item.name)
                                .join(', ') || '—'
                        }
                    />
                    <Separator />
                    <p className="text-xs text-muted-foreground">
                        Penugasan role ke pengguna beserta scope organisasinya
                        diatur terpisah.
                    </p>
                    <Button variant="outline" size="sm" asChild>
                        <Link
                            href="/settings/access?section=roles"
                            className="inline-flex items-center gap-1.5"
                        >
                            <ExternalLink className="size-3.5" />
                            Buka penugasan role
                        </Link>
                    </Button>
                </div>
            );
        }

        if (detailLevel === 'duty') {
            const duty = dutyByCode.get(detailId);

            if (!duty) {
                return null;
            }

            return (
                <div className="space-y-4 p-4">
                    <div className="flex items-center gap-2">
                        <p className="flex-1 font-medium">{duty.name}</p>
                        <StatusBadge value={duty.status} />
                    </div>
                    <SourceBadge value={duty.source} />
                    {duty.description ? (
                        <p className="text-sm text-muted-foreground">
                            {duty.description}
                        </p>
                    ) : null}
                    <DetailRow label="Kode" value={duty.code} />
                    <DetailRow
                        label="Aplikasi"
                        value={
                            duty.app_id
                                ? (appName.get(duty.app_id) ?? duty.app_id)
                                : '—'
                        }
                    />
                    <Separator />
                    <UsedBy
                        groups={[
                            {
                                label: 'Role',
                                names: roleNames(
                                    usedBy.dutyToRoles.get(duty.code) ?? [],
                                ),
                            },
                        ]}
                    />
                </div>
            );
        }

        if (detailLevel === 'privilege') {
            const privilege = privilegeByCode.get(detailId);

            if (!privilege) {
                return null;
            }

            const dutyCodes =
                usedBy.privilegeToDuties.get(privilege.code) ?? [];
            const roleIds = [
                ...new Set(
                    dutyCodes.flatMap(
                        (code) => usedBy.dutyToRoles.get(code) ?? [],
                    ),
                ),
            ];

            return (
                <div className="space-y-4 p-4">
                    <div className="flex items-center gap-2">
                        <p className="flex-1 font-medium">{privilege.name}</p>
                        <StatusBadge value={privilege.status} />
                    </div>
                    <SourceBadge value={privilege.source} />
                    {privilege.description ? (
                        <p className="text-sm text-muted-foreground">
                            {privilege.description}
                        </p>
                    ) : null}
                    <DetailRow label="Kode" value={privilege.code} />
                    <DetailRow
                        label="Aplikasi"
                        value={
                            privilege.app_id
                                ? (appName.get(privilege.app_id) ??
                                  privilege.app_id)
                                : '—'
                        }
                    />
                    <Separator />
                    <UsedBy
                        groups={[
                            {
                                label: 'Tanggung jawab',
                                names: dutyNames(dutyCodes),
                            },
                            { label: 'Role', names: roleNames(roleIds) },
                        ]}
                    />
                </div>
            );
        }

        const permission = permissionByCode.get(detailId);

        if (!permission) {
            return null;
        }

        const privilegeCodes =
            usedBy.permissionToPrivileges.get(permission.code) ?? [];

        return (
            <div className="space-y-4 p-4">
                <p className="font-medium">{permission.name}</p>
                {permission.description ? (
                    <p className="text-sm text-muted-foreground">
                        {permission.description}
                    </p>
                ) : null}
                <DetailRow label="Kode" value={permission.code} />
                <DetailRow
                    label="Entry point"
                    value={
                        permission.entry_point
                            ? `${permission.entry_point.name} (${permission.entry_point.type})`
                            : '—'
                    }
                />
                <DetailRow
                    label="Access level"
                    value={
                        <Badge variant="secondary">
                            {permission.access_level}
                        </Badge>
                    }
                />
                <DetailRow
                    label="Aplikasi"
                    value={appName.get(permission.app_id) ?? permission.app_id}
                />
                <p className="text-xs text-muted-foreground">
                    Entry point dan access level ditetapkan aplikasi pada
                    manifest, jadi tidak dapat diubah dari sini.
                </p>
                <Separator />
                <UsedBy
                    groups={[
                        {
                            label: 'Tugas akses',
                            names: privilegeNames(privilegeCodes),
                        },
                    ]}
                />
            </div>
        );
    };

    return (
        <>
            <Head title="Konfigurasi keamanan" />
            <main className="mx-auto flex w-full min-w-0 flex-col gap-6 p-6">
                <Heading
                    title="Konfigurasi keamanan"
                    description="Telusuri struktur akses dari role sampai izin. Bagian yang berasal dari aplikasi hanya dapat dilihat; konfigurasi khusus tenant dimulai sebagai draf."
                />
                <Card className="overflow-hidden">
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2">
                            <Layers className="size-5 text-primary" />
                            Struktur akses
                        </CardTitle>
                        <CardDescription>
                            Tab menentukan kolom pertama. Aplikasi dipakai
                            sebagai penyaring, bukan tingkatan tersendiri.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-3 px-0">
                        <div className="px-6">
                            <Tabs value={tab} onValueChange={changeTab}>
                                <TabsList>
                                    <TabsTrigger
                                        value="role"
                                        className="gap-1.5"
                                    >
                                        <UserCog className="size-3.5" />
                                        Role
                                    </TabsTrigger>
                                    <TabsTrigger
                                        value="duty"
                                        className="gap-1.5"
                                    >
                                        <ShieldCheck className="size-3.5" />
                                        Tanggung jawab
                                    </TabsTrigger>
                                    <TabsTrigger
                                        value="privilege"
                                        className="gap-1.5"
                                    >
                                        <KeyRound className="size-3.5" />
                                        Tugas akses
                                    </TabsTrigger>
                                    <TabsTrigger
                                        value="draft"
                                        className="gap-1.5"
                                    >
                                        <FileText className="size-3.5" />
                                        Draf ({draftCount})
                                    </TabsTrigger>
                                </TabsList>
                            </Tabs>
                        </div>
                        {/* Action pane menyesuaikan tab dan objek terpilih, seperti F&O. */}
                        {canManage ? (
                            <div className="flex flex-wrap items-center gap-2 border-y bg-muted/40 px-6 py-2">
                                {tab === 'role' ? (
                                    <>
                                        <RoleDialog
                                            key={'role:new'}
                                            apps={apps}
                                            privileges={privileges}
                                            permissions={permissions}
                                            duties={duties}
                                        />
                                        {selectedRole?.is_owner ? (
                                            <span className="text-xs text-muted-foreground">
                                                Role Owner diatur otomatis dan
                                                selalu memegang semua duty.
                                            </span>
                                        ) : selectedRole ? (
                                            <>
                                                <RoleDialog
                                                    key={`role:${selectedRole.id}:${selectedRole.version}`}
                                                    apps={apps}
                                                    privileges={privileges}
                                                    permissions={permissions}
                                                    duties={duties}
                                                    role={selectedRole}
                                                />
                                                <DeleteRoleButton
                                                    role={selectedRole}
                                                    onDeleted={() =>
                                                        setPath([])
                                                    }
                                                />
                                            </>
                                        ) : null}
                                    </>
                                ) : null}
                                {tab === 'duty' ? (
                                    <DutyDialog
                                        key={'duty:new'}
                                        apps={apps}
                                        permissions={permissions}
                                        privileges={privileges}
                                    />
                                ) : null}
                                {tab === 'privilege' ? (
                                    <PrivilegeDialog
                                        key={'privilege:new'}
                                        apps={apps}
                                        permissions={permissions}
                                    />
                                ) : null}
                                {selectedDuty ? (
                                    <>
                                        <DuplicateButton
                                            path={`/settings/security-configuration/duties/${selectedDuty.code}/duplicate`}
                                        />
                                        {editable(selectedDuty) ? (
                                            <DutyDialog
                                                key={`duty:${selectedDuty.code}:${selectedDuty.version}`}
                                                apps={apps}
                                                permissions={permissions}
                                                privileges={privileges}
                                                duty={selectedDuty}
                                            />
                                        ) : null}
                                    </>
                                ) : null}
                                {selectedPrivilege ? (
                                    <>
                                        <DuplicateButton
                                            path={`/settings/security-configuration/privileges/${selectedPrivilege.code}/duplicate`}
                                        />
                                        {editable(selectedPrivilege) ? (
                                            <PrivilegeDialog
                                                key={`privilege:${selectedPrivilege.code}:${selectedPrivilege.version}`}
                                                apps={apps}
                                                permissions={permissions}
                                                privilege={selectedPrivilege}
                                            />
                                        ) : null}
                                    </>
                                ) : null}
                                <div className="flex-1" />
                                <span className="text-xs text-muted-foreground">
                                    {selectedRole
                                        ? `Edit role untuk menambah atau melepas tanggung jawab ${selectedRole.name}.`
                                        : selectedDuty || selectedPrivilege
                                          ? 'Duplikat objek aplikasi untuk menyempitkan haknya.'
                                          : 'Objek dari aplikasi hanya dapat dilihat.'}
                                </span>
                            </div>
                        ) : null}
                        {tab === 'draft' ? (
                            <DraftPanel
                                apps={apps}
                                permissions={permissions}
                                allPrivileges={privileges}
                                duties={duties.filter(
                                    (item) => item.status === 'draft',
                                )}
                                privileges={privileges.filter(
                                    (item) => item.status === 'draft',
                                )}
                            />
                        ) : (
                            <div
                                ref={scroller}
                                className="flex h-[32rem] overflow-x-auto border-y"
                            >
                                {levels.map((level, index) =>
                                    index === 0 || path[index - 1] ? (
                                        <Column
                                            key={level}
                                            level={level}
                                            rows={rowsFor(level, index)}
                                            selected={path[index] ?? null}
                                            onSelect={(id) => select(index, id)}
                                            filter={
                                                index === 0 ? (
                                                    <div className="space-y-2">
                                                        <div className="relative">
                                                            <Search className="absolute top-2.5 left-2 size-3.5 text-muted-foreground" />
                                                            <input
                                                                value={query}
                                                                onChange={(
                                                                    event,
                                                                ) =>
                                                                    setQuery(
                                                                        event
                                                                            .target
                                                                            .value,
                                                                    )
                                                                }
                                                                placeholder="Cari"
                                                                className="h-8 w-full rounded-md border pl-7 text-sm"
                                                            />
                                                        </div>
                                                        <ApplicationFilter
                                                            apps={apps}
                                                            value={appFilter}
                                                            onChange={
                                                                changeAppFilter
                                                            }
                                                        />
                                                    </div>
                                                ) : undefined
                                            }
                                        />
                                    ) : null,
                                )}
                                <div className="w-80 shrink-0 overflow-y-auto">
                                    {renderDetail()}
                                </div>
                            </div>
                        )}
                    </CardContent>
                </Card>
            </main>
        </>
    );
}

SecurityConfiguration.layout = {
    breadcrumbs: [
        { title: 'Settings', href: '/settings/access' },
        { title: 'Identity & access', href: '/settings/access' },
        {
            title: 'Konfigurasi keamanan',
            href: '/settings/security-configuration',
        },
    ],
};
