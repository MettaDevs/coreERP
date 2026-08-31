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
    Sparkles,
    Trash2,
    UserCog,
} from 'lucide-react';
import { Fragment, useEffect, useMemo, useRef, useState } from 'react';

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
    Dialog,
    DialogAction,
    DialogBody,
    DialogCancel,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@apperp/ui/dialog';
import { Empty, EmptyDescription, EmptyTitle } from '@apperp/ui/empty';
import {
    Field,
    FieldError,
    FieldGroup,
    FieldLegend,
    FieldSet,
} from '@apperp/ui/field';
import { Input } from '@apperp/ui/input';
import { NativeSelect } from '@apperp/ui/native-select';
import { Separator } from '@apperp/ui/separator';
import { Tabs, TabsList, TabsTrigger } from '@apperp/ui/tabs';
import Heading from '@/components/heading';

type Source = 'manifest' | 'custom';
type Status = 'draft' | 'active';

type App = { id: string; name: string };
type RoleRef = { id: string; name: string };
type Role = {
    id: string;
    name: string;
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
    privilege_codes: string[];
};
type Privilege = {
    code: string;
    name: string;
    description: string | null;
    app_id: string | null;
    source: Source;
    status: Status;
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
            <div className="flex items-center gap-2 border-b border-border bg-primary/10 dark:bg-primary/20 px-3.5 py-2.5">
                <Glyph className="size-4 shrink-0 text-primary" />
                <span className="flex-1 text-xs font-bold uppercase tracking-wider text-foreground">
                    {LABEL[level]}
                </span>
                <Badge variant="secondary" className="bg-primary/15 text-primary border-0 text-[10px] px-1.5 py-0 font-semibold">
                    {rows.length}
                </Badge>
            </div>
            {filter ? <div className="border-b border-border p-2.5 bg-background/50">{filter}</div> : null}
            <div className="min-h-0 flex-1 overflow-y-auto divide-y divide-border/40">
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
                            className={`flex w-full items-center gap-2.5 border-l-4 px-3.5 py-2.5 text-left text-sm transition-all hover:bg-accent/60 ${selected === row.id
                                    ? 'border-l-primary bg-primary/10 font-semibold text-primary'
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
    const grouped = [...Map.groupBy(visible, (row) => row.appId)];
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
        <div className="space-y-3">
            <div className="flex flex-wrap items-center gap-3">
                <div className="relative flex-1 min-w-[200px]">
                    <Search className="absolute left-2.5 top-2.5 size-3.5 text-muted-foreground" />
                    <Input
                        type="search"
                        placeholder="Cari entry point / titik akses…"
                        className="pl-8 text-xs h-9 bg-background"
                        value={query}
                        onChange={(event) => setQuery(event.target.value)}
                    />
                </div>
                <div className="w-56 shrink-0">
                    <NativeSelect
                        value={appFilter}
                        onChange={(event) => setAppFilter(event.target.value)}
                    >
                        <option value="">Semua aplikasi</option>
                        {apps.map((app) => (
                            <option key={app.id} value={app.id}>
                                {app.name}
                            </option>
                        ))}
                    </NativeSelect>
                </div>
            </div>

            <div className="max-h-96 overflow-auto rounded-lg border border-border bg-card shadow-xs">
                <table className="w-full text-xs">
                    <thead className="sticky top-0 bg-muted/90 backdrop-blur-xs z-10">
                        <tr className="border-b border-border">
                            <th className="px-4 py-2.5 text-left font-bold uppercase tracking-wider text-muted-foreground">
                                Titik Akses (Entry Point)
                            </th>
                            {ACCESS_LEVELS.map((access) => (
                                <th key={access} className="px-2 py-2.5 text-center min-w-[90px]">
                                    <button
                                        type="button"
                                        onClick={() => toggleColumn(access)}
                                        className="w-full rounded-md px-2 py-1 font-semibold text-xs border border-border/60 bg-background/80 hover:bg-primary/10 hover:border-primary/40 hover:text-primary transition-all shadow-2xs cursor-pointer"
                                        title={`Klik untuk pilih semua izin ${access}`}
                                    >
                                        {access}
                                    </button>
                                </th>
                            ))}
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-border/60">
                        {grouped.length === 0 ? (
                            <tr>
                                <td
                                    colSpan={ACCESS_LEVELS.length + 1}
                                    className="p-8 text-center text-muted-foreground font-medium"
                                >
                                    Tidak ada entry point yang cocok dengan kriteria pencarian.
                                </td>
                            </tr>
                        ) : (
                            grouped.map(([appId, items]) => (
                                <Fragment key={appId}>
                                    <tr className="bg-muted/40 font-semibold border-y border-border/80">
                                        <td
                                            colSpan={ACCESS_LEVELS.length + 1}
                                            className="px-4 py-2 text-[11px] uppercase tracking-wider text-primary"
                                        >
                                            <span className="inline-flex items-center gap-1.5">
                                                <Layers className="size-3.5 shrink-0" />
                                                {appName.get(appId) ?? appId}
                                            </span>
                                        </td>
                                    </tr>
                                    {items.map((row) => (
                                        <tr key={row.key} className="hover:bg-accent/40 transition-colors">
                                            <td className="px-4 py-2.5">
                                                <div className="flex items-center gap-2">
                                                    <span className="font-semibold text-foreground text-xs">
                                                        {row.label}
                                                    </span>
                                                    <Badge variant="outline" className="text-[10px] px-1.5 py-0 font-mono bg-muted/50 text-muted-foreground border-border/60">
                                                        {row.type}
                                                    </Badge>
                                                </div>
                                                <span className="text-[11px] font-mono text-muted-foreground/80 block mt-0.5">
                                                    {row.key}
                                                </span>
                                            </td>
                                            {ACCESS_LEVELS.map((access) => {
                                                const code = row.byAccess.get(access);
                                                const isChecked = code ? selected.includes(code) : false;

                                                return (
                                                    <td
                                                        key={access}
                                                        className={`px-2 py-2.5 text-center transition-colors ${isChecked ? 'bg-primary/5' : ''
                                                            }`}
                                                    >
                                                        {code ? (
                                                            <div className="flex items-center justify-center">
                                                                <Checkbox
                                                                    checked={isChecked}
                                                                    onCheckedChange={(checked) =>
                                                                        onChange(
                                                                            toggle(
                                                                                selected,
                                                                                code,
                                                                                checked === true,
                                                                            ),
                                                                        )
                                                                    }
                                                                    aria-label={`${row.label} - ${access}`}
                                                                />
                                                            </div>
                                                        ) : (
                                                            <span
                                                                className="text-muted-foreground/30 text-xs select-none"
                                                                title="Aplikasi tidak mendeklarasikan izin ini"
                                                            >
                                                                —
                                                            </span>
                                                        )}
                                                    </td>
                                                );
                                            })}
                                        </tr>
                                    ))}
                                </Fragment>
                            ))
                        )}
                    </tbody>
                </table>
            </div>
            <div className="flex items-center justify-between text-xs text-muted-foreground px-1">
                <span>
                    ✨ Total <strong className="text-foreground font-semibold">{selected.length}</strong> izin terpilih untuk tugas akses ini.
                </span>
                <span>
                    Gunakan tombol kolom di header untuk memilih seluruh izin sejajar.
                </span>
            </div>
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
    const [open, setOpen] = useState(false);
    const form = useForm({
        name: privilege?.name ?? '',
        permission_codes: privilege?.permission_codes ?? [],
    });

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button
                    variant={privilege ? 'outline' : 'default'}
                    size="sm"
                    className={privilege ? 'bg-background shadow-xs hover:bg-accent' : 'shadow-xs font-medium text-xs'}
                >
                    {privilege ? <Pencil className="size-3.5 mr-1" /> : <Plus className="size-3.5 mr-1" />}
                    {privilege ? 'Edit draf' : 'Tugas Akses'}
                </Button>
            </DialogTrigger>
            <DialogContent size="full">
                <DialogHeader>
                    <div className="flex items-center gap-3">
                        <div className="flex size-10 items-center justify-center rounded-xl bg-primary/10 text-primary shrink-0">
                            <KeyRound className="size-5" />
                        </div>
                        <div>
                            <DialogTitle className="text-base font-bold text-foreground">
                                {privilege
                                    ? `Edit ${privilege.name}`
                                    : 'Tugas Akses Baru'}
                            </DialogTitle>
                            <DialogDescription className="text-xs text-muted-foreground mt-0.5">
                                Centang pasangan entry point dan access level yang diperlukan. Klik judul kolom untuk memilih seluruh kolom sekaligus.
                            </DialogDescription>
                        </div>
                    </div>
                </DialogHeader>
                <form
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
                            form.put(
                                `/settings/security-configuration/privileges/${privilege.code}`,
                                done,
                            );

                            return;
                        }

                        form.post(
                            '/settings/security-configuration/privileges',
                            done,
                        );
                    }}
                >
                    <DialogBody>
                        <FieldGroup>
                            <Field>
                                <Input
                                    label="Nama tugas akses"
                                    value={form.data.name}
                                    onChange={(event) =>
                                        form.setData('name', event.target.value)
                                    }
                                />
                                <FieldError>{form.errors.name}</FieldError>
                            </Field>
                            <FieldSet>
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
                        </FieldGroup>
                    </DialogBody>
                    <DialogFooter>
                        <DialogAction type="submit" disabled={form.processing}>
                            <KeyRound className="mr-1.5 size-3.5" /> Simpan sebagai draf
                        </DialogAction>
                        <DialogCancel />
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function DuplicateButton({ path }: { path: string }) {
    const form = useForm({});

    return (
        <Button
            variant="outline"
            size="sm"
            disabled={form.processing}
            onClick={() => form.post(path)}
        >
            <CopyPlus />
            Duplikat
        </Button>
    );
}

function DutyDialog({
    apps,
    privileges,
    duty,
}: {
    apps: App[];
    privileges: Privilege[];
    duty?: Duty;
}) {
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');
    const form = useForm({
        name: duty?.name ?? '',
        privilege_codes: duty?.privilege_codes ?? [],
    });
    const appName = new Map(apps.map((app) => [app.id, app.name]));
    const visible = privileges.filter(
        (privilege) => !query || matches(privilege.name, query),
    );

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button
                    size="sm"
                    variant={duty ? 'outline' : 'default'}
                    className={duty ? 'bg-background shadow-xs hover:bg-accent' : 'shadow-xs font-medium text-xs'}
                >
                    {duty ? <Pencil className="size-3.5 mr-1" /> : <Plus className="size-3.5 mr-1" />}
                    {duty ? 'Edit draf' : 'Tanggung Jawab'}
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <div className="flex items-center gap-3">
                        <div className="flex size-10 items-center justify-center rounded-xl bg-primary/10 text-primary shrink-0">
                            <ShieldCheck className="size-5" />
                        </div>
                        <div>
                            <DialogTitle className="text-base font-bold text-foreground">
                                {duty ? `Edit ${duty.name}` : 'Tanggung Jawab Baru'}
                            </DialogTitle>
                            <DialogDescription className="text-xs text-muted-foreground mt-0.5">
                                Tanggung jawab menggabungkan beberapa tugas akses menjadi satu bagian proses bisnis yang dapat dipasang pada role.
                            </DialogDescription>
                        </div>
                    </div>
                </DialogHeader>
                <form
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
                            form.put(
                                `/settings/security-configuration/duties/${duty.code}`,
                                done,
                            );

                            return;
                        }

                        form.post(
                            '/settings/security-configuration/duties',
                            done,
                        );
                    }}
                >
                    <DialogBody>
                        <FieldGroup>
                            <Field>
                                <Input
                                    label="Nama tanggung jawab"
                                    value={form.data.name}
                                    onChange={(event) =>
                                        form.setData('name', event.target.value)
                                    }
                                />
                                <FieldError>{form.errors.name}</FieldError>
                            </Field>
                            <FieldSet>
                                <FieldLegend>Pilih tugas akses</FieldLegend>
                                <Input
                                    label="Cari tugas akses"
                                    value={query}
                                    onChange={(event) =>
                                        setQuery(event.target.value)
                                    }
                                />
                                <div className="max-h-72 space-y-2 overflow-y-auto rounded-lg border border-border bg-white dark:bg-card p-3 shadow-2xs">
                                    {visible.map((privilege) => {
                                        const checked = form.data.privilege_codes.includes(privilege.code);
                                        return (
                                            <label
                                                key={privilege.code}
                                                className={`flex items-start gap-3 rounded-md border p-2.5 text-sm transition-colors cursor-pointer ${checked
                                                        ? 'border-primary/40 bg-primary/5'
                                                        : 'border-border/60 bg-card hover:bg-accent/40'
                                                    }`}
                                            >
                                                <Checkbox
                                                    checked={checked}
                                                    onCheckedChange={(checked) =>
                                                        form.setData(
                                                            'privilege_codes',
                                                            toggle(
                                                                form.data
                                                                    .privilege_codes,
                                                                privilege.code,
                                                                checked === true,
                                                            ),
                                                        )
                                                    }
                                                    className="mt-0.5"
                                                />
                                                <span className="flex-1 min-w-0">
                                                    <span className="block font-semibold text-foreground text-xs">
                                                        {privilege.name}
                                                    </span>
                                                    <span className="text-[11px] text-muted-foreground">
                                                        {privilege.app_id
                                                            ? (appName.get(
                                                                privilege.app_id,
                                                            ) ?? privilege.app_id)
                                                            : 'Dibuat khusus'}{' '}
                                                        ·{' '}
                                                        {
                                                            privilege
                                                                .permission_codes
                                                                .length
                                                        }{' '}
                                                        izin
                                                    </span>
                                                </span>
                                            </label>
                                        );
                                    })}
                                </div>
                                <FieldError>
                                    {form.errors.privilege_codes}
                                </FieldError>
                            </FieldSet>
                        </FieldGroup>
                    </DialogBody>
                    <DialogFooter>
                        <DialogAction type="submit" disabled={form.processing}>
                            <ShieldCheck className="mr-1.5 size-3.5" /> Simpan sebagai draf
                        </DialogAction>
                        <DialogCancel />
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function RoleDialog({
    apps,
    duties,
    role,
}: {
    apps: App[];
    duties: Duty[];
    role?: Role;
}) {
    const [open, setOpen] = useState(false);
    const form = useForm({
        name: role?.name ?? '',
        duty_codes: role?.duty_codes ?? [],
        // Sub role dipertahankan apa adanya; form ini hanya mengurus duty.
        child_role_ids: role?.child_roles.map((item) => item.id) ?? [],
    });
    // Draf tidak boleh menjadi sumber hak role — API menolaknya dengan 422.
    const selectable = duties.filter((duty) => duty.status === 'active');
    const groups = [
        ...apps.map((app) => ({
            id: app.id,
            name: app.name,
            items: selectable.filter((duty) => duty.app_id === app.id),
        })),
        {
            id: 'custom',
            name: 'Dibuat khusus',
            items: selectable.filter((duty) => duty.source === 'custom'),
        },
    ].filter((group) => group.items.length > 0);

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button
                    variant={role ? 'outline' : 'default'}
                    size="sm"
                    className={role ? 'bg-background shadow-xs hover:bg-accent' : 'shadow-xs font-medium text-xs'}
                >
                    {role ? <Pencil className="size-3.5 mr-1" /> : <Plus className="size-3.5 mr-1" />}
                    {role ? 'Edit role' : 'Role'}
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <div className="flex items-center gap-3">
                        <div className="flex size-10 items-center justify-center rounded-xl bg-primary/10 text-primary shrink-0">
                            <UserCog className="size-5" />
                        </div>
                        <div>
                            <DialogTitle className="text-base font-bold text-foreground">
                                {role ? 'Edit Security Role' : 'Security Role Baru'}
                            </DialogTitle>
                            <DialogDescription className="text-xs text-muted-foreground mt-0.5">
                                Role menentukan tindakan yang boleh dilakukan. Batas data diatur saat role diberikan ke anggota.
                            </DialogDescription>
                        </div>
                    </div>
                </DialogHeader>
                <form
                    className="contents"
                    onSubmit={(event) => {
                        event.preventDefault();
                        const done = { onSuccess: () => setOpen(false) };

                        if (role) {
                            form.put(`/settings/access/roles/${role.id}`, done);

                            return;
                        }

                        form.post('/settings/access/roles', done);
                    }}
                >
                    <DialogBody>
                        <FieldGroup>
                            <Field>
                                <Input
                                    label="Nama role"
                                    value={form.data.name}
                                    onChange={(event) =>
                                        form.setData('name', event.target.value)
                                    }
                                />
                                <FieldError>{form.errors.name}</FieldError>
                            </Field>
                            <FieldSet>
                                <FieldLegend>Tanggung jawab bisnis</FieldLegend>
                                <div className="max-h-72 space-y-4 overflow-y-auto rounded-lg border border-border bg-white dark:bg-card p-3.5 shadow-2xs">
                                    {groups.map((group) => (
                                        <div
                                            key={group.id}
                                            className="space-y-2"
                                        >
                                            <p className="text-xs font-bold uppercase tracking-wider text-muted-foreground">
                                                {group.name}
                                            </p>
                                            <div className="space-y-1.5">
                                                {group.items.map((duty) => {
                                                    const checked = form.data.duty_codes.includes(duty.code);
                                                    return (
                                                        <label
                                                            key={duty.code}
                                                            className={`flex items-start gap-3 rounded-md border p-2.5 text-sm transition-colors cursor-pointer ${checked
                                                                    ? 'border-primary/40 bg-primary/5'
                                                                    : 'border-border/60 bg-card hover:bg-accent/40'
                                                                }`}
                                                        >
                                                            <Checkbox
                                                                checked={checked}
                                                                onCheckedChange={(checked) =>
                                                                    form.setData(
                                                                        'duty_codes',
                                                                        toggle(
                                                                            form.data.duty_codes,
                                                                            duty.code,
                                                                            checked === true,
                                                                        ),
                                                                    )
                                                                }
                                                                className="mt-0.5"
                                                            />
                                                            <span className="flex-1 min-w-0">
                                                                <span className="block font-semibold text-foreground text-xs">
                                                                    {duty.name}
                                                                </span>
                                                                <span className="text-[11px] text-muted-foreground">
                                                                    {duty.privilege_codes.length} tugas akses
                                                                </span>
                                                            </span>
                                                        </label>
                                                    );
                                                })}
                                            </div>
                                        </div>
                                    ))}
                                </div>
                                <FieldError>
                                    {form.errors.duty_codes}
                                </FieldError>
                            </FieldSet>
                        </FieldGroup>
                    </DialogBody>
                    <DialogFooter>
                        <DialogAction type="submit" disabled={form.processing}>
                            <UserCog className="mr-1.5 size-3.5" /> Simpan role
                        </DialogAction>
                        <DialogCancel />
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function DeleteRoleButton({
    role,
    onDeleted,
}: {
    role: Role;
    onDeleted: () => void;
}) {
    const form = useForm({});

    return (
        <AlertDialog>
            <AlertDialogTrigger asChild>
                <Button variant="destructive" size="sm">
                    <Trash2 className="size-3.5 mr-1" />
                    Hapus role
                </Button>
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
                <AlertDialogFooter>
                    <AlertDialogCancel>Batal</AlertDialogCancel>
                    <AlertDialogAction
                        disabled={form.processing}
                        className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
                        onClick={() =>
                            form.delete(`/settings/access/roles/${role.id}`, {
                                onSuccess: onDeleted,
                            })
                        }
                    >
                        Hapus
                    </AlertDialogAction>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
    );
}

function PublishButton({ path }: { path: string }) {
    const form = useForm({});

    return (
        <Button
            size="sm"
            variant="default"
            disabled={form.processing}
            onClick={() => form.post(path)}
        >
            <Check className="size-3.5 mr-1" />
            Terbitkan
        </Button>
    );
}

function DeleteDraftButton({ path, name }: { path: string; name: string }) {
    const form = useForm({});

    return (
        <AlertDialog>
            <AlertDialogTrigger asChild>
                <Button variant="destructive" size="sm">
                    <Trash2 className="size-3.5 mr-1" />
                    Hapus
                </Button>
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
                <AlertDialogFooter>
                    <AlertDialogCancel>Batal</AlertDialogCancel>
                    <AlertDialogAction
                        disabled={form.processing}
                        className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
                        onClick={() => form.delete(path)}
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
                <div key={item.code} className="rounded-lg border border-border bg-card p-4 shadow-sm hover:shadow transition-all">
                    <div className="flex flex-wrap items-center gap-3">
                        <span className="flex-1 min-w-0">
                            <span className="flex items-center gap-2">
                                <span className="font-semibold text-foreground truncate">{item.name}</span>
                                <Badge variant="outline">
                                    Draf
                                </Badge>
                            </span>
                            <span className="text-xs text-muted-foreground">
                                Tugas akses · {item.permission_codes.length} izin
                            </span>
                        </span>
                        <div className="flex items-center gap-2">
                            <PrivilegeDialog
                                apps={apps}
                                permissions={permissions}
                                privilege={item}
                            />
                            <DeleteDraftButton
                                name={item.name}
                                path={`/settings/security-configuration/privileges/${item.code}`}
                            />
                            <PublishButton
                                path={`/settings/security-configuration/privileges/${item.code}/publish`}
                            />
                        </div>
                    </div>
                    <ul className="mt-3 flex flex-wrap gap-1.5">
                        {item.permission_codes.map((code) => (
                            <li key={code}>
                                <Badge variant="secondary" className="text-xs font-normal">
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
                <div key={item.code} className="rounded-lg border border-border bg-card p-4 shadow-sm hover:shadow transition-all">
                    <div className="flex flex-wrap items-center gap-3">
                        <span className="flex-1 min-w-0">
                            <span className="flex items-center gap-2">
                                <span className="font-semibold text-foreground truncate">{item.name}</span>
                                <Badge variant="outline">
                                    Draf
                                </Badge>
                            </span>
                            <span className="text-xs text-muted-foreground">
                                Tanggung jawab · {item.privilege_codes.length} tugas akses
                            </span>
                        </span>
                        <div className="flex items-center gap-2">
                            <DutyDialog
                                apps={apps}
                                privileges={allPrivileges}
                                duty={item}
                            />
                            <DeleteDraftButton
                                name={item.name}
                                path={`/settings/security-configuration/duties/${item.code}`}
                            />
                            <PublishButton
                                path={`/settings/security-configuration/duties/${item.code}/publish`}
                            />
                        </div>
                    </div>
                    <ul className="mt-3 flex flex-wrap gap-1.5">
                        {item.privilege_codes.map((code) => (
                            <li key={code}>
                                <Badge variant="secondary" className="text-xs font-normal">
                                    {privilegeByCode.get(code)?.name ?? code}
                                </Badge>
                            </li>
                        ))}
                    </ul>
                </div>
            ))}
            <p className="text-xs text-muted-foreground pt-1">
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
                        <Link href="/settings/access?section=roles" className="inline-flex items-center gap-1.5">
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
                    icon={ShieldCheck}
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
                                    <TabsTrigger value="role" className="gap-1.5">
                                        <UserCog className="size-3.5" />
                                        Role
                                    </TabsTrigger>
                                    <TabsTrigger value="duty" className="gap-1.5">
                                        <ShieldCheck className="size-3.5" />
                                        Tanggung jawab
                                    </TabsTrigger>
                                    <TabsTrigger value="privilege" className="gap-1.5">
                                        <KeyRound className="size-3.5" />
                                        Tugas akses
                                    </TabsTrigger>
                                    <TabsTrigger value="draft" className="gap-1.5">
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
                                            apps={apps}
                                            duties={duties}
                                        />
                                        {selectedRole ? (
                                            <>
                                                <RoleDialog
                                                    apps={apps}
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
                                        apps={apps}
                                        privileges={privileges}
                                    />
                                ) : null}
                                {tab === 'privilege' ? (
                                    <PrivilegeDialog
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
                                                apps={apps}
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
                                                        <NativeSelect
                                                            value={appFilter}
                                                            onChange={(event) =>
                                                                changeAppFilter(
                                                                    event.target
                                                                        .value,
                                                                )
                                                            }
                                                        >
                                                            <option value="">
                                                                Semua aplikasi
                                                            </option>
                                                            {apps.map((app) => (
                                                                <option
                                                                    key={app.id}
                                                                    value={
                                                                        app.id
                                                                    }
                                                                >
                                                                    {app.name}
                                                                </option>
                                                            ))}
                                                        </NativeSelect>
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