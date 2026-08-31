import { Head, router, useForm, usePage } from '@inertiajs/react';
import { Ban, Check, ChevronRight, Copy, Pencil, Save, ShieldCheck, UserCog, UserPlus, Users } from 'lucide-react';
import { useRef, useState } from 'react';
import type { RefObject } from 'react';
import { toast } from 'sonner';

import { Alert, AlertDescription, AlertTitle } from '@apperp/ui/alert';
import { Avatar, AvatarFallback, AvatarImage } from '@apperp/ui/avatar';
import { useInitials } from '@/hooks/use-initials';
import { cn } from '@/lib/utils';
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
    CardAction,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@apperp/ui/card';
import { Checkbox } from '@apperp/ui/checkbox';
import { DataTable } from '@apperp/ui/data-table';
import type { DataTableColumn } from '@apperp/ui/data-table';
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
import {
    Empty,
    EmptyDescription,
    EmptyHeader,
    EmptyMedia,
    EmptyTitle,
} from '@apperp/ui/empty';
import {
    Field,
    FieldDescription,
    FieldGroup,
    FieldLegend,
    FieldSet,
} from '@apperp/ui/field';
import { Input } from '@apperp/ui/input';
import { MultiSelect } from '@apperp/ui/multi-select';
import { NativeSelect } from '@apperp/ui/native-select';
import { RadioGroup, RadioGroupItem } from '@apperp/ui/radio-group';
import { Select } from '@apperp/ui/select';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@apperp/ui/table';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@apperp/ui/tabs';
import Heading from '@/components/heading';

type Permission = {
    code: string;
    name: string;
    entry_point_code: string;
    access_level: string;
};
type Privilege = { code: string; name: string; permissions: Permission[] };
type Duty = {
    code: string;
    app_id: string | null;
    name: string;
    privileges?: Privilege[];
};
type App = { id: string; name: string; duties: Duty[] };
type Role = {
    id: string;
    name: string;
    duties: Duty[];
    data_policy_codes: string[];
};
type Organization = {
    id: string;
    name: string;
    classification: string;
    unit_type: string | null;
};
type HierarchyNode = {
    organization_id: string;
    parent_organization_id: string | null;
};
type Hierarchy = {
    id: string;
    name: string;
    version_id: string | null;
    nodes: HierarchyNode[];
};
type DataPolicy = {
    code: string;
    name: string;
    requires_legal_entity: boolean;
    requires_operating_unit: boolean;
    allows_descendants: boolean;
};
type PolicyScope = {
    policy_code: string;
    legal_entity_id: string | null;
    organization_id: string | null;
    hierarchy_id: string | null;
    include_descendants: boolean;
    /** Grant tanpa dimensi apa pun: seluruh organisasi pada policy ini. */
    unrestricted: boolean;
};
type Assignment = {
    role_id: string;
    role_name?: string;
    source?: 'manual' | 'automatic';
    policy_scopes: PolicyScope[];
};
type Member = {
    id: string;
    name: string;
    email: string;
    system_role: string;
    platform_role?: string;
    security_role?: string;
    avatar_url?: string | null;
    roles: string[];
    assignments: Assignment[];
    can_edit_access: boolean;
};
type Invitation = {
    id: string;
    system_role: string;
    label: string | null;
    roles: string[];
    assignments: Assignment[];
    redeemed_count: number;
    code: string | null;
    revoked_at: string | null;
};
type Props = {
    tenant: { id: string; name: string };
    canManage: boolean;
    members: Member[];
    apps: App[];
    roles: Role[];
    dataPolicies: DataPolicy[];
    organizations: Organization[];
    hierarchies: Hierarchy[];
    invitations: Invitation[];
    newInvitationCodes: string[];
};

const unrestrictedScope = (policyCode: string): PolicyScope => ({
    policy_code: policyCode,
    legal_entity_id: null,
    organization_id: null,
    hierarchy_id: null,
    include_descendants: false,
    unrestricted: true,
});

const grantKey = (scope: PolicyScope): string =>
    [
        scope.legal_entity_id ?? '',
        scope.organization_id ?? '',
        scope.hierarchy_id ?? '',
        scope.include_descendants ? '1' : '0',
    ].join('|');

type TreeRow = { organization: Organization; depth: number };

/**
 * Susunan organisasi menentukan bentuk pohon, jadi ia dipilih lebih dulu dan
 * daftar node mengikutinya. Urutan ini membuat kombinasi unit-di-luar-susunan
 * mustahil dibentuk, bukan sekadar ditolak backend setelah disimpan.
 */
function hierarchyRows(
    hierarchy: Hierarchy | undefined,
    organizations: Organization[],
): TreeRow[] {
    const byId = new Map(
        organizations.map((organization) => [organization.id, organization]),
    );

    if (!hierarchy) {
        return organizations
            .filter(
                (organization) =>
                    organization.classification === 'operating_unit',
            )
            .map((organization) => ({ organization, depth: 0 }));
    }

    const children = new Map<string | null, string[]>();

    for (const node of hierarchy.nodes) {
        children.set(node.parent_organization_id, [
            ...(children.get(node.parent_organization_id) ?? []),
            node.organization_id,
        ]);
    }

    const rows: TreeRow[] = [];
    const walk = (parent: string | null, depth: number) => {
        for (const id of children.get(parent) ?? []) {
            const organization = byId.get(id);

            if (organization) {
                rows.push({ organization, depth });
            }

            walk(id, depth + 1);
        }
    };
    walk(null, 0);

    return rows;
}

/**
 * Satu policy, satu panel. Bentuknya mengikuti layar `Assign organizations`
 * Dynamics 365: pilih cakupan, pilih susunan, tunjuk node, lalu beri akses —
 * hasilnya menumpuk sebagai baris grant yang dapat dibaca utuh.
 *
 * `Revoke` sengaja tidak ditiru. Pengecualian harus menjadi policy tersendiri,
 * bukan daftar deny yang menabrak policy lain.
 */
function PolicyScopePanel({
    policy,
    scopes,
    organizations,
    hierarchies,
    portalContainer,
    onChange,
}: {
    policy: DataPolicy;
    scopes: PolicyScope[];
    organizations: Organization[];
    hierarchies: Hierarchy[];
    portalContainer: RefObject<HTMLDivElement | null>;
    onChange: (scopes: PolicyScope[]) => void;
}) {
    const grants = scopes.filter((scope) => !scope.unrestricted);
    const unrestricted = scopes.length > 0 && grants.length === 0;
    const [legalEntityId, setLegalEntityId] = useState<string | null>(null);
    const [hierarchyId, setHierarchyId] = useState<string | null>(null);
    const [organizationId, setOrganizationId] = useState<string | null>(null);
    const [filter, setFilter] = useState('');

    const organizationById = new Map(
        organizations.map((organization) => [organization.id, organization]),
    );
    const hierarchyById = new Map(
        hierarchies.map((hierarchy) => [hierarchy.id, hierarchy]),
    );
    const query = filter.trim().toLowerCase();
    const rows = hierarchyRows(
        hierarchyId ? hierarchyById.get(hierarchyId) : undefined,
        organizations,
    ).filter(({ organization }) =>
        organization.name.toLowerCase().includes(query),
    );
    const selectable = (organization: Organization) =>
        !policy.requires_operating_unit ||
        organization.classification === 'operating_unit';
    const legalEntityReady =
        !policy.requires_legal_entity || legalEntityId !== null;
    const canGrant = organizationId !== null && legalEntityReady;

    const grant = (withChildren: boolean) => {
        if (!organizationId) {
            return;
        }

        const next: PolicyScope = {
            policy_code: policy.code,
            legal_entity_id: policy.requires_legal_entity
                ? legalEntityId
                : null,
            organization_id: organizationId,
            hierarchy_id: withChildren ? hierarchyId : null,
            include_descendants: withChildren,
            unrestricted: false,
        };

        if (grants.some((scope) => grantKey(scope) === grantKey(next))) {
            toast('Batas data yang sama sudah ditambahkan.');

            return;
        }

        if (
            grants.some(
                (scope) =>
                    scope.legal_entity_id === next.legal_entity_id &&
                    scope.organization_id === next.organization_id &&
                    scope.include_descendants !== next.include_descendants,
            )
        ) {
            toast(
                'Unit ini sudah tercakup dalam akses beserta turunannya. Hapus salah satu batas data.',
            );

            return;
        }

        onChange([...grants, next]);
    };

    return (
        <div className="space-y-4">
            <RadioGroup
                value={unrestricted ? 'all' : 'specific'}
                onValueChange={(value) =>
                    onChange(
                        value === 'all' ? [unrestrictedScope(policy.code)] : [],
                    )
                }
            >
                <label className="flex items-center gap-3 text-sm">
                    <RadioGroupItem value="all" />
                    Seluruh organisasi
                </label>
                <label className="flex items-center gap-3 text-sm">
                    <RadioGroupItem value="specific" />
                    Organisasi tertentu
                </label>
            </RadioGroup>
            {unrestricted ? (
                <FieldDescription>
                    Role ini menjangkau semua data yang dilindungi{' '}
                    {policy.name.toLowerCase()}, tanpa batas organisasi.
                </FieldDescription>
            ) : (
                <>
                    <div className="grid gap-3 sm:grid-cols-2">
                        {policy.requires_legal_entity && (
                            <Field>
                                <Select
                                    label="Badan hukum"
                                    items={organizations
                                        .filter(
                                            (organization) =>
                                                organization.classification ===
                                                'legal_entity',
                                        )
                                        .map((organization) => ({
                                            value: organization.id,
                                            label: organization.name,
                                        }))}
                                    value={legalEntityId}
                                    onValueChange={setLegalEntityId}
                                    placeholder="Pilih badan hukum"
                                    searchPlaceholder="Cari badan hukum..."
                                    portalContainer={portalContainer}
                                />
                            </Field>
                        )}
                        <Field>
                            <Select
                                label="Susunan organisasi"
                                items={hierarchies.map((hierarchy) => ({
                                    value: hierarchy.id,
                                    label: hierarchy.name,
                                }))}
                                value={hierarchyId}
                                onValueChange={(value) => {
                                    setHierarchyId(value);
                                    setOrganizationId(null);
                                }}
                                placeholder="(Semua unit kerja)"
                                searchPlaceholder="Cari susunan organisasi..."
                                portalContainer={portalContainer}
                            />
                            <FieldDescription>
                                Menentukan bentuk pohon di bawah, dan arti
                                &quot;beserta turunannya&quot;.
                            </FieldDescription>
                        </Field>
                    </div>
                    <div className="space-y-2">
                        <Input
                            value={filter}
                            onChange={(event) => setFilter(event.target.value)}
                            placeholder="Cari organisasi..."
                        />
                        <div className="max-h-64 overflow-auto rounded-md border">
                            {rows.length === 0 ? (
                                <p className="p-3 text-sm text-muted-foreground">
                                    Tidak ada organisasi yang cocok.
                                </p>
                            ) : (
                                rows.map(({ organization, depth }) => (
                                    <button
                                        key={organization.id}
                                        type="button"
                                        disabled={!selectable(organization)}
                                        onClick={() =>
                                            setOrganizationId(organization.id)
                                        }
                                        data-selected={
                                            organizationId === organization.id
                                                ? ''
                                                : undefined
                                        }
                                        style={{
                                            paddingLeft: `${depth * 16 + 12}px`,
                                        }}
                                        className="flex w-full items-center gap-2 py-1.5 pr-3 text-left text-sm hover:bg-accent/40 disabled:cursor-default disabled:text-muted-foreground disabled:hover:bg-transparent data-selected:bg-accent"
                                    >
                                        {organization.name}
                                        {organization.classification ===
                                            'legal_entity' && (
                                                <Badge variant="outline">
                                                    Badan hukum
                                                </Badge>
                                            )}
                                    </button>
                                ))
                            )}
                        </div>
                        <div className="flex flex-wrap gap-2">
                            <Button
                                type="button"
                                size="sm"
                                onClick={() => grant(false)}
                                disabled={!canGrant}
                            >
                                Beri akses
                            </Button>
                            <Button
                                type="button"
                                size="sm"
                                variant="outline"
                                onClick={() => grant(true)}
                                disabled={
                                    !canGrant ||
                                    !policy.allows_descendants ||
                                    hierarchyId === null
                                }
                            >
                                Beri akses beserta turunannya
                            </Button>
                        </div>
                        {policy.requires_legal_entity && !legalEntityReady && (
                            <FieldDescription>
                                Pilih badan hukum lebih dulu — grant policy ini
                                selalu berupa pasangan badan hukum dan unit
                                kerja.
                            </FieldDescription>
                        )}
                    </div>
                    <div className="overflow-auto rounded-md border">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    {policy.requires_legal_entity && (
                                        <TableHead>Badan hukum</TableHead>
                                    )}
                                    <TableHead>Unit kerja</TableHead>
                                    <TableHead>Jenis unit</TableHead>
                                    <TableHead>Turunan</TableHead>
                                    <TableHead>Susunan organisasi</TableHead>
                                    <TableHead />
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {grants.length === 0 ? (
                                    <TableRow>
                                        <TableCell
                                            colSpan={
                                                policy.requires_legal_entity
                                                    ? 6
                                                    : 5
                                            }
                                            className="text-muted-foreground"
                                        >
                                            Belum ada organisasi yang diberi
                                            akses.
                                        </TableCell>
                                    </TableRow>
                                ) : (
                                    grants.map((scope) => {
                                        const unit = scope.organization_id
                                            ? organizationById.get(
                                                scope.organization_id,
                                            )
                                            : undefined;

                                        return (
                                            <TableRow key={grantKey(scope)}>
                                                {policy.requires_legal_entity && (
                                                    <TableCell>
                                                        {(scope.legal_entity_id &&
                                                            organizationById.get(
                                                                scope.legal_entity_id,
                                                            )?.name) ??
                                                            '—'}
                                                    </TableCell>
                                                )}
                                                <TableCell>
                                                    {unit?.name ?? '—'}
                                                </TableCell>
                                                <TableCell className="text-muted-foreground">
                                                    {unit?.unit_type ?? '—'}
                                                </TableCell>
                                                <TableCell>
                                                    {scope.include_descendants
                                                        ? 'Termasuk'
                                                        : 'Tidak'}
                                                </TableCell>
                                                <TableCell className="text-muted-foreground">
                                                    {(scope.hierarchy_id &&
                                                        hierarchyById.get(
                                                            scope.hierarchy_id,
                                                        )?.name) ??
                                                        '—'}
                                                </TableCell>
                                                <TableCell className="text-right">
                                                    <Button
                                                        type="button"
                                                        size="sm"
                                                        variant="ghost"
                                                        onClick={() =>
                                                            onChange(
                                                                grants.filter(
                                                                    (item) =>
                                                                        grantKey(
                                                                            item,
                                                                        ) !==
                                                                        grantKey(
                                                                            scope,
                                                                        ),
                                                                ),
                                                            )
                                                        }
                                                    >
                                                        Hapus
                                                    </Button>
                                                </TableCell>
                                            </TableRow>
                                        );
                                    })
                                )}
                            </TableBody>
                        </Table>
                    </div>
                </>
            )}
        </div>
    );
}

/**
 * Satu tab per data policy yang dipakai role. Policy dipisah karena dimensi
 * wajibnya berbeda dan karena grant satu policy tidak pernah memperluas policy
 * lain — menggabungkannya dalam satu daftar akan menyiratkan sebaliknya.
 */
function ScopeEditor({
    role,
    scopes,
    policies,
    organizations,
    hierarchies,
    portalContainer,
    onChange,
}: {
    role: Role;
    scopes: PolicyScope[];
    policies: DataPolicy[];
    organizations: Organization[];
    hierarchies: Hierarchy[];
    portalContainer: RefObject<HTMLDivElement | null>;
    onChange: (scopes: PolicyScope[]) => void;
}) {
    const rolePolicies = policies.filter((policy) =>
        role.data_policy_codes.includes(policy.code),
    );
    const [active, setActive] = useState(rolePolicies[0]?.code ?? '');

    if (rolePolicies.length === 0) {
        return null;
    }

    const panel = (policy: DataPolicy) => (
        <PolicyScopePanel
            policy={policy}
            scopes={scopes.filter((scope) => scope.policy_code === policy.code)}
            organizations={organizations}
            hierarchies={hierarchies}
            portalContainer={portalContainer}
            onChange={(next) =>
                onChange([
                    ...scopes.filter(
                        (scope) => scope.policy_code !== policy.code,
                    ),
                    ...next,
                ])
            }
        />
    );

    if (rolePolicies.length === 1) {
        return (
            <FieldSet>
                <FieldLegend>{rolePolicies[0].name}</FieldLegend>
                {panel(rolePolicies[0])}
            </FieldSet>
        );
    }

    return (
        <Tabs value={active} onValueChange={setActive}>
            <TabsList>
                {rolePolicies.map((policy) => (
                    <TabsTrigger key={policy.code} value={policy.code}>
                        {policy.name}
                    </TabsTrigger>
                ))}
            </TabsList>
            {rolePolicies.map((policy) => (
                <TabsContent
                    key={policy.code}
                    value={policy.code}
                    className="pt-4"
                >
                    {panel(policy)}
                </TabsContent>
            ))}
        </Tabs>
    );
}

const scopeSummary = (role: Role, scopes: PolicyScope[]): string => {
    if (role.data_policy_codes.length === 0) {
        return 'Tidak dibatasi';
    }

    if (scopes.length === 0) {
        return 'Belum diatur';
    }

    const grants = scopes.filter((scope) => !scope.unrestricted);

    return grants.length === 0
        ? 'Seluruh organisasi'
        : `${grants.length} batas`;
};

/**
 * Batas data tidak muat dalam satu sel grid, jadi sel itu hanya meringkas dan
 * membuka dialog — pola yang sama dipakai grid anggota dan grid kode undangan.
 */
function RoleScopeDialog({
    role,
    scopes,
    policies,
    organizations,
    hierarchies,
    onChange,
}: {
    role: Role;
    scopes: PolicyScope[];
    policies: DataPolicy[];
    organizations: Organization[];
    hierarchies: Hierarchy[];
    onChange: (scopes: PolicyScope[]) => void;
}) {
    const [open, setOpen] = useState(false);
    const contentRef = useRef<HTMLDivElement>(null);
    const [draft, setDraft] = useState<PolicyScope[]>(scopes);
    const scoped = role.data_policy_codes.length > 0;

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                setOpen(next);

                if (next) {
                    setDraft(scopes);
                }
            }}
        >
            <DialogTrigger asChild>
                <button
                    type="button"
                    disabled={!scoped}
                    className="text-left text-sm underline-offset-2 hover:underline disabled:cursor-default disabled:text-muted-foreground disabled:no-underline"
                >
                    {scopeSummary(role, scopes)}
                    {scoped && <span className="ml-1">…</span>}
                </button>
            </DialogTrigger>
            <DialogContent ref={contentRef} size="wide">
                <DialogHeader>
                    <DialogTitle>Batas data — {role.name}</DialogTitle>
                    <DialogDescription>
                        Menentukan organisasi mana yang datanya boleh dijangkau
                        role ini. Tiap kebijakan dinilai terpisah dan tidak
                        saling memperluas akses.
                    </DialogDescription>
                </DialogHeader>
                <DialogBody>
                    <ScopeEditor
                        role={role}
                        scopes={draft}
                        policies={policies}
                        organizations={organizations}
                        hierarchies={hierarchies}
                        portalContainer={contentRef}
                        onChange={setDraft}
                    />
                </DialogBody>
                <DialogFooter>
                    <DialogAction
                        type="button"
                        onClick={() => {
                            onChange(draft);
                            setOpen(false);
                        }}
                    >
                        Terapkan
                    </DialogAction>
                    <DialogCancel />
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

function AssignmentPicker({
    assignments,
    roles,
    dataPolicies,
    organizations,
    hierarchies,
    disabledRoleIds = [],
    onChange,
}: {
    assignments: Assignment[];
    roles: Role[];
    dataPolicies: DataPolicy[];
    organizations: Organization[];
    hierarchies: Hierarchy[];
    disabledRoleIds?: string[];
    onChange: (assignments: Assignment[]) => void;
}) {
    const assignmentFor = (roleId: string) =>
        assignments.find((assignment) => assignment.role_id === roleId);
    const updateScopes = (roleId: string, policyScopes: PolicyScope[]) =>
        onChange(
            assignments.map((assignment) =>
                assignment.role_id === roleId
                    ? { ...assignment, policy_scopes: policyScopes }
                    : assignment,
            ),
        );

    return (
        <>
            <FieldSet>
                <FieldLegend>Security role</FieldLegend>
                {/* Grid bersel ala Business Central: satu baris per role,
                    kolom yang menjelaskan isinya, bukan sekadar daftar centang. */}
                <div className="overflow-auto rounded-lg border border-border bg-card shadow-2xs">
                    <table className="w-full text-xs">
                        <thead className="sticky top-0 bg-muted/80 backdrop-blur-xs z-10">
                            <tr className="border-b border-border">
                                <th className="w-10 px-3 py-2 text-center" />
                                <th className="px-3 py-2 text-left font-bold uppercase tracking-wider text-muted-foreground">
                                    Role
                                </th>
                                <th className="px-3 py-2 text-left font-bold uppercase tracking-wider text-muted-foreground">
                                    Tanggung jawab
                                </th>
                                <th className="px-3 py-2 text-left font-bold uppercase tracking-wider text-muted-foreground">
                                    Batas data
                                </th>
                                <th className="px-3 py-2 text-left font-bold uppercase tracking-wider text-muted-foreground">
                                    Sumber
                                </th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-border/60">
                            {roles.map((role) => {
                                const automatic = disabledRoleIds.includes(
                                    role.id,
                                );
                                const checked =
                                    Boolean(assignmentFor(role.id)) ||
                                    automatic;

                                return (
                                    <tr
                                        key={role.id}
                                        className={`transition-colors ${checked
                                                ? 'bg-primary/5 hover:bg-primary/10'
                                                : 'hover:bg-accent/40'
                                            }`}
                                    >
                                        <td className="px-3 py-2 text-center">
                                            <Checkbox
                                                checked={checked}
                                                disabled={automatic}
                                                onCheckedChange={(value) =>
                                                    onChange(
                                                        value === true
                                                            ? [
                                                                ...assignments,
                                                                {
                                                                    role_id:
                                                                        role.id,
                                                                    policy_scopes:
                                                                        [],
                                                                },
                                                            ]
                                                            : assignments.filter(
                                                                (
                                                                    assignment,
                                                                ) =>
                                                                    assignment.role_id !==
                                                                    role.id,
                                                            ),
                                                    )
                                                }
                                            />
                                        </td>
                                        <td className="px-3 py-2 font-semibold text-foreground">
                                            {role.name}
                                        </td>
                                        <td className="px-3 py-2 text-muted-foreground">
                                            {role.duties.length} tanggung jawab
                                        </td>
                                        <td className="px-3 py-2 text-muted-foreground">
                                            {checked && !automatic ? (
                                                <RoleScopeDialog
                                                    role={role}
                                                    scopes={
                                                        assignmentFor(role.id)
                                                            ?.policy_scopes ??
                                                        []
                                                    }
                                                    policies={dataPolicies}
                                                    organizations={
                                                        organizations
                                                    }
                                                    hierarchies={hierarchies}
                                                    onChange={(scopes) =>
                                                        updateScopes(
                                                            role.id,
                                                            scopes,
                                                        )
                                                    }
                                                />
                                            ) : role.data_policy_codes.length >
                                                0 ? (
                                                `${role.data_policy_codes.length} kebijakan`
                                            ) : (
                                                'Tidak dibatasi'
                                            )}
                                        </td>
                                        <td className="px-3 py-2 text-muted-foreground">
                                            {automatic ? (
                                                <Badge variant="outline" className="text-[10px] font-normal">
                                                    Aturan otomatis
                                                </Badge>
                                            ) : (
                                                <span className="text-xs">Manual</span>
                                            )}
                                        </td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                </div>
            </FieldSet>
        </>
    );
}

type CodeDraft = {
    key: string;
    label: string;
    system_role: string;
    assignments: Assignment[];
    /**
     * Terisi untuk kode yang sudah diterbitkan. Baris itu tetap dapat diubah —
     * kodenya tidak berubah, yang berubah hanya apa yang diterima penukar
     * berikutnya. `redeemed` dipakai untuk memperingatkan bahwa kode sudah
     * beredar sebelum perubahan disimpan.
     */
    issued?: { id: string; revoked: boolean; redeemed: number };
};

const issuedDraft = (invitation: Invitation): CodeDraft => ({
    key: `issued-${invitation.id}`,
    label: invitation.label ?? '',
    system_role: invitation.system_role,
    assignments: invitation.assignments,
    issued: {
        id: invitation.id,
        revoked: Boolean(invitation.revoked_at),
        redeemed: invitation.redeemed_count,
    },
});

/**
 * Sidik jari isi baris, dipakai untuk mengetahui baris terbit mana yang sudah
 * diubah. Role dan grant diurutkan lebih dulu supaya perubahan urutan pilihan
 * tidak terbaca sebagai perubahan isi.
 */
const codeFingerprint = (code: CodeDraft): string =>
    JSON.stringify({
        label: code.label,
        system_role: code.system_role,
        assignments: [...code.assignments]
            .sort((first, second) =>
                first.role_id.localeCompare(second.role_id),
            )
            .map((assignment) => ({
                role_id: assignment.role_id,
                scopes: assignment.policy_scopes
                    .map(
                        (scope) =>
                            `${scope.policy_code}|${grantKey(scope)}|${scope.unrestricted ? '1' : '0'}`,
                    )
                    .sort(),
            })),
    });

/** Ringkasan batas data seluruh role pada satu kode undangan. */
const codeScopeSummary = (roles: Role[], assignments: Assignment[]): string => {
    const scoped = roles.filter(
        (role) =>
            assignments.some((assignment) => assignment.role_id === role.id) &&
            role.data_policy_codes.length > 0,
    );

    if (scoped.length === 0) {
        return 'Tidak dibatasi';
    }

    const scopes = assignments.flatMap(
        (assignment) => assignment.policy_scopes,
    );
    const grants = scopes.filter((scope) => !scope.unrestricted);

    if (grants.length > 0) {
        return `${grants.length} batas`;
    }

    return scopes.length > 0 ? 'Seluruh organisasi' : 'Belum diatur';
};

const blankCode = (): CodeDraft => ({
    key: crypto.randomUUID(),
    label: '',
    system_role: 'user',
    assignments: [],
});

/**
 * Batas data terlalu bercabang untuk muat dalam satu sel, jadi ia dibuka
 * sebagai dialog kedua di atas grid — lapisan di bawahnya otomatis mundur dan
 * mengabur, sehingga jelas pengguna sedang satu lapis lebih dalam.
 */
function ScopeDialog({
    code,
    roles,
    dataPolicies,
    organizations,
    hierarchies,
    onChange,
}: {
    code: CodeDraft;
    roles: Role[];
    dataPolicies: DataPolicy[];
    organizations: Organization[];
    hierarchies: Hierarchy[];
    onChange: (assignments: Assignment[]) => void;
}) {
    const [open, setOpen] = useState(false);
    const contentRef = useRef<HTMLDivElement>(null);
    const [draft, setDraft] = useState<Assignment[]>(code.assignments);
    const scopedRoles = roles.filter(
        (role) =>
            code.assignments.some(
                (assignment) => assignment.role_id === role.id,
            ) && role.data_policy_codes.length > 0,
    );

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                setOpen(next);

                if (next) {
                    setDraft(code.assignments);
                }
            }}
        >
            <DialogTrigger asChild>
                <button
                    type="button"
                    disabled={scopedRoles.length === 0}
                    className="w-full text-left text-sm underline-offset-2 hover:underline disabled:cursor-default disabled:text-muted-foreground disabled:no-underline"
                >
                    {codeScopeSummary(roles, code.assignments)}
                    {scopedRoles.length > 0 && <span className="ml-1">…</span>}
                </button>
            </DialogTrigger>
            <DialogContent ref={contentRef} size="wide">
                <DialogHeader>
                    <DialogTitle>Batas data kode undangan</DialogTitle>
                    <DialogDescription>
                        Menentukan organisasi mana yang datanya boleh disentuh
                        pemakai kode ini. Tiap kebijakan dinilai terpisah dan
                        tidak saling memperluas akses.
                    </DialogDescription>
                </DialogHeader>
                <DialogBody>
                    <FieldGroup>
                        {scopedRoles.map((role) => {
                            const assignment = draft.find(
                                (item) => item.role_id === role.id,
                            );

                            return assignment ? (
                                <div key={role.id} className="space-y-3">
                                    <p className="text-sm font-medium">
                                        {role.name}
                                    </p>
                                    <ScopeEditor
                                        role={role}
                                        scopes={assignment.policy_scopes}
                                        policies={dataPolicies}
                                        organizations={organizations}
                                        hierarchies={hierarchies}
                                        portalContainer={contentRef}
                                        onChange={(next) =>
                                            setDraft((current) =>
                                                current.map((item) =>
                                                    item.role_id === role.id
                                                        ? {
                                                            ...item,
                                                            policy_scopes:
                                                                next,
                                                        }
                                                        : item,
                                                ),
                                            )
                                        }
                                    />
                                </div>
                            ) : null;
                        })}
                    </FieldGroup>
                </DialogBody>
                <DialogFooter>
                    <DialogAction
                        type="button"
                        onClick={() => {
                            onChange(draft);
                            setOpen(false);
                        }}
                    >
                        Terapkan
                    </DialogAction>
                    <DialogCancel />
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

/**
 * Satu baris grid = satu kode undangan. Kolom kiri diisi pengguna, kolom kanan
 * dihitung dari role yang dipilih — pola Edit List Business Central.
 *
 * Kode yang sudah diterbitkan ikut tampil di atas baris baru supaya grid ini
 * memperlihatkan daftar yang sama dengan tabel di halaman. Baris tersebut
 * terkunci: mencabut kode dilakukan dari tabel halaman, bukan dari sini.
 */
function InviteForm({
    roles,
    dataPolicies,
    organizations,
    hierarchies,
    invitations,
}: Pick<
    Props,
    'roles' | 'dataPolicies' | 'organizations' | 'hierarchies' | 'invitations'
>) {
    const [open, setOpen] = useState(false);
    const [activeKey, setActiveKey] = useState<string | null>(null);
    const contentRef = useRef<HTMLDivElement>(null);
    const form = useForm({ codes: [blankCode()] });
    const [issuedRows, setIssuedRows] = useState<CodeDraft[]>(() =>
        invitations.map(issuedDraft),
    );
    const [saving, setSaving] = useState<string | null>(null);
    const roleById = new Map(roles.map((role) => [role.id, role]));
    const pristine = new Map(
        invitations.map((invitation) => [
            `issued-${invitation.id}`,
            codeFingerprint(issuedDraft(invitation)),
        ]),
    );

    const update = (key: string, change: Partial<CodeDraft>) => {
        const apply = (code: CodeDraft) =>
            code.key === key ? { ...code, ...change } : code;

        if (key.startsWith('issued-')) {
            setIssuedRows((current) => current.map(apply));

            return;
        }

        form.setData('codes', form.data.codes.map(apply));
    };
    const rolesOf = (code: CodeDraft) =>
        code.assignments
            .map((assignment) => roleById.get(assignment.role_id))
            .filter((role): role is Role => Boolean(role));
    // Baris terbit yang sudah dicabut tidak dapat ditukar siapa pun lagi,
    // sehingga mengubahnya tidak mengubah akses siapa pun.
    const locked = (code: CodeDraft) => Boolean(code.issued?.revoked);
    const dirty = (code: CodeDraft) =>
        Boolean(code.issued) &&
        pristine.get(code.key) !== codeFingerprint(code);
    const save = (code: CodeDraft) => {
        if (!code.issued) {
            return;
        }

        setSaving(code.key);
        router.patch(
            `/settings/access/invitations/${code.issued.id}`,
            {
                label: code.label,
                system_role: code.system_role,
                assignments: code.assignments.map((assignment) => ({
                    role_id: assignment.role_id,
                    policy_scopes: assignment.policy_scopes,
                })),
            },
            {
                preserveScroll: true,
                onSuccess: (page) => {
                    // Props tersegar dipakai langsung; `invitations` pada
                    // closure ini masih memuat nilai sebelum penyimpanan.
                    setIssuedRows(
                        (page.props.invitations as Invitation[]).map(
                            issuedDraft,
                        ),
                    );
                    toast('Perubahan kode undangan disimpan.');
                },
                onFinish: () => setSaving(null),
            },
        );
    };

    const columns: DataTableColumn<CodeDraft>[] = [
        {
            id: 'label',
            header: 'Keterangan',
            width: 240,
            // Kode berupa huruf acak; tanpa keterangan admin tidak akan ingat
            // kode ini dibuat untuk siapa.
            cell: (code) =>
                locked(code) ? (
                    <span className={code.label ? '' : 'text-muted-foreground'}>
                        {code.label || 'Tanpa keterangan'}
                    </span>
                ) : (
                    <Input
                        value={code.label}
                        placeholder="mis. Onboarding staf gudang Agustus"
                        onChange={(event) =>
                            update(code.key, { label: event.target.value })
                        }
                    />
                ),
        },
        {
            id: 'status',
            header: 'Status',
            width: 160,
            cell: (code) => (
                <div className="flex flex-wrap items-center gap-1">
                    {code.issued ? (
                        <Badge
                            variant={
                                code.issued.revoked ? 'secondary' : 'default'
                            }
                        >
                            {code.issued.revoked ? 'Dicabut' : 'Aktif'}
                        </Badge>
                    ) : (
                        <Badge variant="outline">Baru</Badge>
                    )}
                    {code.issued && code.issued.redeemed > 0 && (
                        <Badge variant="outline">
                            {code.issued.redeemed} terpakai
                        </Badge>
                    )}
                    {dirty(code) && <Badge variant="outline">Diubah</Badge>}
                </div>
            ),
        },
        {
            id: 'system_role',
            header: 'User Platform',
            width: 150,
            cell: (code) =>
                locked(code) ? (
                    <span className="text-muted-foreground">
                        {code.system_role}
                    </span>
                ) : (
                    <NativeSelect
                        value={code.system_role}
                        onChange={(event) =>
                            update(code.key, {
                                system_role: event.target.value,
                            })
                        }
                    >
                        <option value="user">User</option>
                        <option value="admin">Admin</option>
                    </NativeSelect>
                ),
        },
        {
            id: 'roles',
            header: 'Security role',
            width: 260,
            // MultiSelect bekerja dengan daftar teks, jadi nama role dipetakan
            // balik ke id saat nilainya berubah.
            cell: (code) =>
                locked(code) ? (
                    <span className="text-muted-foreground">
                        {rolesOf(code)
                            .map((role) => role.name)
                            .join(', ') || '—'}
                    </span>
                ) : (
                    <MultiSelect
                        items={roles.map((role) => role.name)}
                        value={rolesOf(code).map((role) => role.name)}
                        onValueChange={(names) =>
                            update(code.key, {
                                assignments: names
                                    .map((name) =>
                                        roles.find(
                                            (role) => role.name === name,
                                        ),
                                    )
                                    .filter((role): role is Role =>
                                        Boolean(role),
                                    )
                                    .map(
                                        (role) =>
                                            code.assignments.find(
                                                (assignment) =>
                                                    assignment.role_id ===
                                                    role.id,
                                            ) ?? {
                                                role_id: role.id,
                                                policy_scopes: [],
                                            },
                                    ),
                            })
                        }
                        placeholder="Pilih role"
                        portalContainer={contentRef}
                    />
                ),
        },
        {
            id: 'duties',
            header: 'Tanggung Jawab',
            width: 150,
            align: 'right',
            cell: (code) => {
                const total = new Set(
                    rolesOf(code).flatMap((role) =>
                        role.duties.map((duty) => duty.code),
                    ),
                ).size;

                return (
                    <span className="text-muted-foreground">
                        {total || '—'}
                    </span>
                );
            },
        },
        {
            id: 'scopes',
            header: 'Batas Data',
            width: 180,
            cell: (code) =>
                locked(code) ? (
                    <span className="text-muted-foreground">
                        {codeScopeSummary(roles, code.assignments)}
                    </span>
                ) : (
                    <ScopeDialog
                        code={code}
                        roles={roles}
                        dataPolicies={dataPolicies}
                        organizations={organizations}
                        hierarchies={hierarchies}
                        onChange={(assignments) =>
                            update(code.key, { assignments })
                        }
                    />
                ),
        },
        {
            // Menggantikan kolom "Sumber" yang isinya selalu "Undangan" di
            // dalam dialog ini. Tiap kode terbit adalah record tersendiri,
            // jadi penyimpanannya per baris — tombol footer hanya membuat kode.
            id: 'save',
            header: 'Aksi',
            width: 190,
            align: 'right',
            cell: (code) => {
                if (!code.issued || locked(code)) {
                    return null;
                }

                if (!dirty(code)) {
                    return (
                        <span className="text-muted-foreground">Tersimpan</span>
                    );
                }

                const button = (
                    <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        disabled={saving === code.key}
                        onClick={
                            code.issued.redeemed > 0
                                ? undefined
                                : () => save(code)
                        }
                    >
                        Simpan perubahan
                    </Button>
                );

                if (code.issued.redeemed === 0) {
                    return button;
                }

                return (
                    <AlertDialog>
                        <AlertDialogTrigger asChild>
                            {button}
                        </AlertDialogTrigger>
                        <AlertDialogContent>
                            <AlertDialogHeader>
                                <AlertDialogTitle>
                                    Kode ini sudah dipakai{' '}
                                    {code.issued.redeemed} orang
                                </AlertDialogTitle>
                                <AlertDialogDescription>
                                    Akses anggota yang sudah bergabung tidak
                                    berubah. Yang berubah hanya role dan batas
                                    data yang diterima orang berikutnya yang
                                    menukarkan kode ini. Kodenya sendiri tetap
                                    sama, jadi tidak perlu dibagikan ulang.
                                </AlertDialogDescription>
                            </AlertDialogHeader>
                            <AlertDialogFooter>
                                <AlertDialogCancel>Batal</AlertDialogCancel>
                                <AlertDialogAction onClick={() => save(code)}>
                                    Simpan perubahan
                                </AlertDialogAction>
                            </AlertDialogFooter>
                        </AlertDialogContent>
                    </AlertDialog>
                );
            },
        },
    ];

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                setOpen(next);
                // Selalu mulai dari keadaan server: baris baru dikosongkan dan
                // suntingan baris terbit yang belum disimpan dibuang.
                setIssuedRows(invitations.map(issuedDraft));
                form.setData('codes', next ? [blankCode()] : []);
            }}
        >
            <DialogTrigger asChild>
                <Button variant="default" className="shadow-xs font-medium text-xs">
                    <UserPlus className="mr-1.5 size-3.5" />
                    Buat undangan
                </Button>
            </DialogTrigger>
            <DialogContent ref={contentRef} size="full">
                <DialogHeader>
                    <div className="flex items-center gap-3">
                        <div className="flex size-10 items-center justify-center rounded-xl bg-primary/10 text-primary shrink-0">
                            <UserPlus className="size-5" />
                        </div>
                        <div>
                            <DialogTitle className="text-base font-bold text-foreground">
                                Kelola & Buat Kode Undangan
                            </DialogTitle>
                            <DialogDescription className="text-xs text-muted-foreground mt-0.5">
                                Kode yang sudah terbit dapat diubah di tempat dan disimpan per baris; kodenya tidak berganti. Setiap baris baru menghasilkan satu kode yang berlaku sampai dicabut.
                            </DialogDescription>
                        </div>
                    </div>
                </DialogHeader>
                <form
                    className="contents"
                    onSubmit={(event) => {
                        event.preventDefault();
                        // `key` hanya penanda baris di klien, tidak dikirim.
                        form.transform((data) => ({
                            codes: data.codes.map((code) => ({
                                label: code.label,
                                system_role: code.system_role,
                                assignments: code.assignments,
                            })),
                        }));
                        form.post('/settings/access/invitations', {
                            onSuccess: () => setOpen(false),
                        });
                    }}
                >
                    <DialogBody className="space-y-4 py-4">
                        <DataTable
                            columns={columns}
                            data={[...issuedRows, ...form.data.codes]}
                            getRowKey={(code) => code.key}
                            getRowLabel={() => 'kode undangan'}
                            activeRowKey={activeKey ?? undefined}
                            onRowClick={(code) => setActiveKey(code.key)}
                            actions={[
                                { id: 'duplicate', label: 'Duplikat baris' },
                                {
                                    id: 'delete',
                                    label: 'Hapus baris',
                                    destructive: true,
                                    separatorBefore: true,
                                },
                            ]}
                            onRowAction={(action, code) => {
                                if (action === 'duplicate') {
                                    form.setData('codes', [
                                        ...form.data.codes,
                                        {
                                            ...code,
                                            key: crypto.randomUUID(),
                                            issued: undefined,
                                        },
                                    ]);

                                    return;
                                }

                                if (code.issued) {
                                    toast(
                                        'Kode yang sudah terbit dihentikan lewat tombol Cabut di daftar, bukan dihapus dari sini.',
                                    );

                                    return;
                                }

                                form.setData(
                                    'codes',
                                    form.data.codes.filter(
                                        (item) => item.key !== code.key,
                                    ),
                                );
                            }}
                            onAddRow={() =>
                                form.setData('codes', [
                                    ...form.data.codes,
                                    blankCode(),
                                ])
                            }
                            addRowLabel="Tambah kode undangan"
                            emptyMessage="Belum ada baris."
                        />
                        <div className="rounded-md border border-border/60 bg-muted/20 p-3 text-xs text-muted-foreground leading-relaxed">
                            💡 <strong>Catatan:</strong> Perubahan pada baris <span className="font-semibold text-foreground">Aktif</span> disimpan lewat tombol <span className="font-semibold text-foreground">Simpan perubahan</span> di baris itu, dan hanya berlaku untuk penukaran berikutnya. Baris <span className="font-semibold text-foreground">Dicabut</span> terkunci karena kodenya sudah tidak dapat ditukar. Tanggung jawab terisi otomatis dari role yang dipilih; klik sel <span className="font-semibold text-foreground">Batas Data</span> untuk mengatur badan hukum dan unit kerja per role.
                        </div>
                    </DialogBody>
                    <DialogFooter>
                        <DialogAction
                            type="submit"
                            disabled={
                                form.processing || form.data.codes.length === 0
                            }
                            className="font-medium shadow-xs px-5"
                        >
                            <UserPlus className="mr-1.5 size-3.5" /> Buat {form.data.codes.length} kode
                        </DialogAction>
                        <DialogCancel />
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function MemberAccessDialog({
    member,
    apps,
    roles,
    dataPolicies,
    organizations,
    hierarchies,
    onClose,
}: {
    member: Member | null;
    apps: App[];
    roles: Role[];
    dataPolicies: DataPolicy[];
    organizations: Organization[];
    hierarchies: Hierarchy[];
    onClose: () => void;
}) {
    const contentRef = useRef<HTMLDivElement>(null);
    const getInitials = useInitials();
    const form = useForm({
        system_role: member?.system_role ?? 'user',
        assignments: (member?.assignments ?? [])
            .filter((assignment) => assignment.source !== 'automatic')
            .map((assignment) => ({
                role_id: assignment.role_id,
                policy_scopes: assignment.policy_scopes,
            })),
    });
    const automaticRoleIds = (member?.assignments ?? [])
        .filter((assignment) => assignment.source === 'automatic')
        .map((assignment) => assignment.role_id);
    const dutiesByCode = new Map(
        apps.flatMap((app) => app.duties).map((duty) => [duty.code, duty]),
    );

    return (
        <Dialog
            open={Boolean(member)}
            onOpenChange={(open) => !open && onClose()}
        >
            <DialogContent ref={contentRef} size="full">
                <DialogHeader>
                    <div className="flex items-center gap-3">
                        <div className="flex size-10 items-center justify-center rounded-xl bg-primary/10 text-primary shrink-0">
                            <UserCog className="size-5" />
                        </div>
                        <div>
                            <DialogTitle className="text-base font-bold text-foreground">
                                Atur Akses Anggota
                            </DialogTitle>
                            <DialogDescription className="text-xs text-muted-foreground mt-0.5">
                                Atur role platform, tanggung jawab security role, dan batas data anggota.
                            </DialogDescription>
                        </div>
                    </div>

                    {member && (
                        <div className="mt-3 flex items-center justify-between gap-4 rounded-lg border border-border bg-muted/30 p-2.5">
                            <div className="flex items-center gap-3">
                                <Avatar className="size-8 border border-border">
                                    {member.avatar_url && (
                                        <AvatarImage src={member.avatar_url} alt={member.name} />
                                    )}
                                    <AvatarFallback className="bg-primary/10 text-primary font-medium text-xs">
                                        {getInitials(member.name)}
                                    </AvatarFallback>
                                </Avatar>
                                <div>
                                    <p className="text-xs font-semibold text-foreground">{member.name}</p>
                                    <p className="text-[11px] text-muted-foreground">{member.email}</p>
                                </div>
                            </div>
                            <Badge variant="outline" className="text-[11px] font-medium border-primary/30 text-primary bg-primary/5">
                                {member.system_role}
                            </Badge>
                        </div>
                    )}
                </DialogHeader>

                <form
                    className="contents"
                    onSubmit={(event) => {
                        event.preventDefault();

                        if (member) {
                            form.patch(
                                `/settings/access/memberships/${member.id}`,
                                { onSuccess: onClose },
                            );
                        }
                    }}
                >
                    <DialogBody>
                        <FieldGroup>
                            <Field>
                                <NativeSelect
                                    label="Role platform"
                                    value={form.data.system_role}
                                    disabled={member?.system_role === 'owner'}
                                    onChange={(event) =>
                                        form.setData(
                                            'system_role',
                                            event.target.value,
                                        )
                                    }
                                >
                                    {member?.system_role === 'owner' && (
                                        <option value="owner">Pemilik</option>
                                    )}
                                    <option value="user">Anggota</option>
                                    <option value="admin">Admin</option>
                                </NativeSelect>
                            </Field>
                            <AssignmentPicker
                                assignments={form.data.assignments}
                                roles={roles}
                                dataPolicies={dataPolicies}
                                organizations={organizations}
                                hierarchies={hierarchies}
                                disabledRoleIds={automaticRoleIds}
                                onChange={(assignments) =>
                                    form.setData('assignments', assignments)
                                }
                            />
                            <FieldSet>
                                <FieldLegend>Rincian akses anggota</FieldLegend>
                                <FieldDescription>
                                    Menjelaskan alasan anggota dapat memakai
                                    layar atau tindakan tertentu.
                                </FieldDescription>
                                <div className="space-y-2 rounded-md border p-3 text-sm">
                                {(member?.assignments ?? []).map(
                                    (assignment) => {
                                        const role = roles.find(
                                            (item) =>
                                                item.id ===
                                                assignment.role_id,
                                        );

                                        return (
                                            <details
                                                key={`${assignment.role_id}-${assignment.source}`}
                                                className="group rounded-md border border-border/60 bg-muted/20 p-2.5 transition-colors [&[open]]:bg-muted/40"
                                            >
                                                <summary className="cursor-pointer font-semibold text-xs flex items-center justify-between text-foreground">
                                                    <span className="flex items-center gap-2">
                                                        <ShieldCheck className="size-4 text-primary" />
                                                        {assignment.role_name ?? role?.name ?? 'Role'}
                                                    </span>
                                                    <ChevronRight className="size-4 text-muted-foreground transition-transform group-open:rotate-90" />
                                                </summary>
                                                <div className="mt-3 space-y-2 border-t border-border/40 pt-2.5 pl-2 text-muted-foreground">
                                                    {role?.duties.map(
                                                        (roleDuty) => {
                                                            const duty =
                                                                dutiesByCode.get(
                                                                    roleDuty.code,
                                                                );

                                                            return (
                                                                <details
                                                                    key={
                                                                        roleDuty.code
                                                                    }
                                                                    className="group/duty space-y-1"
                                                                >
                                                                    <summary className="cursor-pointer text-xs font-medium text-foreground flex items-center gap-1.5 hover:text-primary">
                                                                        <ChevronRight className="size-3.5 text-muted-foreground transition-transform group-open/duty:rotate-90" />
                                                                        {roleDuty.name}
                                                                    </summary>
                                                                    <div className="mt-1.5 space-y-1.5 pl-5 text-[11px]">
                                                                        {duty?.privileges?.map(
                                                                            (
                                                                                privilege,
                                                                            ) => (
                                                                                <div
                                                                                    key={
                                                                                        privilege.code
                                                                                    }
                                                                                    className="rounded bg-background p-2 border border-border/40 space-y-1"
                                                                                >
                                                                                    <p className="font-semibold text-foreground">
                                                                                        {privilege.name}
                                                                                    </p>
                                                                                    {privilege.permissions.map(
                                                                                        (
                                                                                            permission,
                                                                                        ) => (
                                                                                            <p
                                                                                                key={
                                                                                                    permission.code
                                                                                                }
                                                                                                className="text-muted-foreground"
                                                                                            >
                                                                                                • {permission.name}{' '}
                                                                                                <span className="text-primary font-mono">
                                                                                                    ({permission.access_level})
                                                                                                </span>
                                                                                            </p>
                                                                                        ),
                                                                                    )}
                                                                                </div>
                                                                            ),
                                                                        )}
                                                                    </div>
                                                                </details>
                                                            );
                                                        },
                                                    )}
                                                    {assignment.policy_scopes.map(
                                                        (scope) => (
                                                            <p
                                                                key={`${scope.policy_code}-${scope.organization_id}`}
                                                                className="text-xs font-medium text-foreground pt-1"
                                                            >
                                                                Batas data:{' '}
                                                                <span className="text-primary">
                                                                    {dataPolicies.find(
                                                                        (policy) =>
                                                                            policy.code ===
                                                                            scope.policy_code,
                                                                    )?.name ??
                                                                        scope.policy_code}
                                                                </span>
                                                            </p>
                                                        ),
                                                    )}
                                                </div>
                                            </details>
                                        );
                                    },
                                )}
                            </div>
                            </FieldSet>
                        </FieldGroup>
                    </DialogBody>
                    <DialogFooter>
                        <DialogAction type="submit" disabled={form.processing}>
                            <Save className="mr-1.5 size-3.5" /> Simpan akses
                        </DialogAction>
                        <DialogCancel />
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

export default function Access({
    tenant,
    canManage,
    members,
    apps,
    roles,
    dataPolicies,
    organizations,
    hierarchies,
    invitations = [],
    newInvitationCodes = [],
}: Props) {
    const url = usePage().url;
    const queryString = url.includes('?') ? url.split('?')[1] : '';
    const section = new URLSearchParams(queryString).get('section');
    const activeSection = section === 'invitations' ? section : 'members';
    const [editingMember, setEditingMember] = useState<Member | null>(null);
    const getInitials = useInitials();
    const copy = (code: string) => {
        void navigator.clipboard.writeText(code);
        toast('Kode disalin');
    };

    const platformRoleLabel: Record<string, string> = {
        owner: 'Pemilik',
        admin: 'Admin',
        user: 'Anggota',
    };

    const memberColumns: DataTableColumn<Member>[] = [
        {
            id: 'identity',
            header: 'Identity',
            cell: (member) => (
                <div className="flex items-center gap-3 py-0.5">
                    <Avatar size="default">
                        {member.avatar_url ? (
                            <AvatarImage src={member.avatar_url} alt={member.name} />
                        ) : null}
                        <AvatarFallback className="bg-primary/10 text-primary font-medium text-xs">
                            {getInitials(member.name)}
                        </AvatarFallback>
                    </Avatar>
                    <div className="flex flex-col min-w-0">
                        <span className="font-medium text-foreground truncate text-sm">{member.name}</span>
                        <span className="text-xs text-muted-foreground truncate">{member.email}</span>
                    </div>
                </div>
            ),
            sortValue: (member) => member.name,
        },
        {
            id: 'platform-role',
            header: 'Role platform',
            cell: (member) => (
                <Badge>
                    {platformRoleLabel[member.system_role] ??
                        member.system_role}
                </Badge>
            ),
        },
        {
            id: 'security-role',
            header: 'Security role',
            cell: (member) => member.roles.join(', ') || '—',
        },
        {
            id: 'actions',
            header: 'Aksi',
            align: 'right',
            width: 140,
            cell: (member) =>
                canManage && member.can_edit_access ? (
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={() => setEditingMember(member)}
                    >
                        <Pencil />
                        Atur akses
                    </Button>
                ) : null,
        },
    ];
    const invitationColumns: DataTableColumn<Invitation>[] = [
        {
            id: 'label',
            header: 'Keterangan',
            cell: (invitation) =>
                invitation.label || (
                    <span className="text-muted-foreground">
                        Tanpa keterangan
                    </span>
                ),
            sortValue: (invitation) => invitation.label ?? '',
        },
        {
            id: 'status',
            header: 'Status',
            width: 120,
            cell: (invitation) => (
                <Badge
                    variant={invitation.revoked_at ? 'secondary' : 'default'}
                >
                    {invitation.revoked_at ? 'Dicabut' : 'Aktif'}
                </Badge>
            ),
        },
        {
            id: 'access',
            header: 'Akses',
            cell: (invitation) =>
                `${platformRoleLabel[invitation.system_role] ?? invitation.system_role} · sesuai batas data role`,
        },
        {
            id: 'roles',
            header: 'Security role',
            cell: (invitation) => (invitation.roles ?? []).join(', ') || '—',
        },
        {
            id: 'actions',
            header: 'Aksi',
            align: 'right',
            width: 240,
            cell: (invitation) =>
                canManage && !invitation.revoked_at ? (
                    <div className="flex items-center justify-end gap-2 shrink-0 whitespace-nowrap">
                        {invitation.code ? (
                            <Button
                                size="sm"
                                variant="outline"
                                onClick={() => copy(invitation.code ?? '')}
                            >
                                <Copy />
                                Salin kode
                            </Button>
                        ) : null}
                        <AlertDialog>
                            <AlertDialogTrigger asChild>
                                <Button size="sm" variant="outline">
                                    <Ban />
                                    Cabut
                                </Button>
                            </AlertDialogTrigger>
                            <AlertDialogContent>
                                <AlertDialogHeader>
                                    <AlertDialogTitle>
                                        Cabut kode akses?
                                    </AlertDialogTitle>
                                    <AlertDialogDescription>
                                        Anggota baru tidak dapat memakai kode
                                        ini lagi. Akses anggota yang sudah
                                        bergabung tidak berubah.
                                    </AlertDialogDescription>
                                </AlertDialogHeader>
                                <AlertDialogFooter>
                                    <AlertDialogCancel>Batal</AlertDialogCancel>
                                    <AlertDialogAction
                                        onClick={() =>
                                            router.delete(
                                                `/settings/access/invitations/${invitation.id}`,
                                            )
                                        }
                                    >
                                        Cabut kode
                                    </AlertDialogAction>
                                </AlertDialogFooter>
                            </AlertDialogContent>
                        </AlertDialog>
                    </div>
                ) : null,
        },
    ];

    return (
        <>
            <Head title="Identity & access" />
            <main className="mx-auto flex w-full max-w-7xl min-w-0 flex-col gap-6 p-6">
                <Heading
                    title="Identity & access"
                    description={`Kelola anggota dan tanggung jawab bisnis untuk ${tenant.name}.`}
                />

                {newInvitationCodes.length > 0 && (
                    <Alert>
                        <Check />
                        <AlertTitle>
                            {newInvitationCodes.length} kode undangan berhasil
                            dibuat
                        </AlertTitle>
                        <AlertDescription className="flex flex-col gap-2">
                            {newInvitationCodes.map((code) => (
                                <span
                                    key={code}
                                    className="flex flex-wrap items-center gap-3"
                                >
                                    <code className="rounded bg-muted px-2 py-1 font-mono text-base">
                                        {code}
                                    </code>
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        onClick={() => copy(code)}
                                    >
                                        <Copy />
                                        Salin
                                    </Button>
                                </span>
                            ))}
                            {newInvitationCodes.length > 1 && (
                                <Button
                                    size="sm"
                                    variant="outline"
                                    className="self-start"
                                    onClick={() =>
                                        copy(newInvitationCodes.join('\n'))
                                    }
                                >
                                    <Copy />
                                    Salin semua
                                </Button>
                            )}
                        </AlertDescription>
                    </Alert>
                )}

                {activeSection === 'members' && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Daftar anggota</CardTitle>
                            <CardDescription>
                                Orang yang dapat masuk ke bisnis ini.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <DataTable
                                columns={memberColumns}
                                data={members}
                                getRowKey={(member) => member.id}
                            />
                        </CardContent>
                    </Card>
                )}

                {activeSection === 'invitations' && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Kode undangan</CardTitle>
                            <CardDescription>
                                Kode dapat dipakai berulang sampai dicabut.
                            </CardDescription>
                            {canManage && (
                                <CardAction>
                                    <InviteForm
                                        roles={roles}
                                        dataPolicies={dataPolicies}
                                        organizations={organizations}
                                        hierarchies={hierarchies}
                                        invitations={invitations}
                                    />
                                </CardAction>
                            )}
                        </CardHeader>
                        <CardContent>
                            {invitations.length ? (
                                <DataTable
                                    columns={invitationColumns}
                                    data={invitations}
                                    getRowKey={(invitation) => invitation.id}
                                />
                            ) : (
                                <Empty>
                                    <EmptyHeader>
                                        <EmptyMedia variant="icon">
                                            <Users />
                                        </EmptyMedia>
                                        <EmptyTitle>
                                            Belum ada undangan
                                        </EmptyTitle>
                                        <EmptyDescription>
                                            Buat kode pertama untuk anggota
                                            baru.
                                        </EmptyDescription>
                                    </EmptyHeader>
                                </Empty>
                            )}
                        </CardContent>
                    </Card>
                )}
                <MemberAccessDialog
                    key={editingMember?.id ?? 'closed'}
                    member={editingMember}
                    apps={apps}
                    roles={roles}
                    dataPolicies={dataPolicies}
                    organizations={organizations}
                    hierarchies={hierarchies}
                    onClose={() => setEditingMember(null)}
                />
            </main>
        </>
    );
}

Access.layout = {
    breadcrumbs: [
        { title: 'Settings', href: '/settings/access' },
        { title: 'Identity & access', href: '/settings/access' },
    ],
};
