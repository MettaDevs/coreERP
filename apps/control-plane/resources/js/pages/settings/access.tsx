import { Head, router, useForm, usePage } from '@inertiajs/react';
import { Ban, Check, Copy, Pencil, UserPlus, Users } from 'lucide-react';
import { useRef, useState, type RefObject } from 'react';
import { toast } from 'sonner';

import Heading from '@/components/heading';
import { Alert, AlertDescription, AlertTitle } from '@apperp/ui/alert';
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
import { DataTable, type DataTableColumn } from '@apperp/ui/data-table';
import {
    Dialog,
    DialogContent,
    DialogDescription,
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
    FieldError,
    FieldGroup,
    FieldLegend,
    FieldSet,
} from '@apperp/ui/field';
import { Input } from '@apperp/ui/input';
import { NativeSelect } from '@apperp/ui/native-select';
import { Select } from '@apperp/ui/select';

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
type Organization = { id: string; name: string; classification: string };
type Hierarchy = { id: string; name: string };
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
    roles: string[];
    assignments: Assignment[];
    can_edit_access: boolean;
};
type Invitation = {
    id: string;
    system_role: string;
    roles: string[];
    code: string | null;
    revoked_at: string | null;
};
type Props = {
    tenant: { id: string; name: string };
    canManage: boolean;
    members: Member[];
    apps: App[];
    customDuties: Duty[];
    roles: Role[];
    dataPolicies: DataPolicy[];
    organizations: Organization[];
    hierarchies: Hierarchy[];
    invitations: Invitation[];
    newInvitationCode: string | null;
};

const blankScope = (policyCode: string): PolicyScope => ({
    policy_code: policyCode,
    legal_entity_id: null,
    organization_id: null,
    hierarchy_id: null,
    include_descendants: false,
});

const toggle = (values: string[], value: string, checked: boolean) =>
    checked
        ? [...new Set([...values, value])]
        : values.filter((item) => item !== value);

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
    const legalEntities = organizations
        .filter(
            (organization) => organization.classification === 'legal_entity',
        )
        .map((organization) => ({
            value: organization.id,
            label: organization.name,
        }));
    const operatingUnits = organizations
        .filter(
            (organization) => organization.classification === 'operating_unit',
        )
        .map((organization) => ({
            value: organization.id,
            label: organization.name,
        }));
    const hierarchyItems = hierarchies.map((hierarchy) => ({
        value: hierarchy.id,
        label: hierarchy.name,
    }));

    return policies
        .filter((policy) => role.data_policy_codes.includes(policy.code))
        .map((policy) => {
            const matchingScopes = scopes
                .map((scope, index) => ({ scope, index }))
                .filter(({ scope }) => scope.policy_code === policy.code);
            const update = (index: number, change: Partial<PolicyScope>) =>
                onChange(
                    scopes.map((scope, itemIndex) =>
                        itemIndex === index ? { ...scope, ...change } : scope,
                    ),
                );

            return (
                <FieldSet key={`${role.id}-${policy.code}`}>
                    <FieldLegend hint={`${role.name}: ${policy.name}.`}>
                        Batas data — {policy.name}
                    </FieldLegend>
                    <div className="space-y-3 rounded-md border p-3">
                        {matchingScopes.map(({ scope, index }) => (
                            <div
                                key={index}
                                className="space-y-3 rounded border p-3"
                            >
                                {policy.requires_legal_entity && (
                                    <Field>
                                        <Select
                                            label="Badan hukum"
                                            items={legalEntities}
                                            value={scope.legal_entity_id}
                                            onValueChange={(value) =>
                                                update(index, {
                                                    legal_entity_id: value,
                                                })
                                            }
                                            placeholder="Pilih badan hukum"
                                            searchPlaceholder="Cari badan hukum..."
                                            portalContainer={portalContainer}
                                        />
                                    </Field>
                                )}
                                {policy.requires_operating_unit && (
                                    <Field>
                                        <Select
                                            label="Unit kerja"
                                            items={operatingUnits}
                                            value={scope.organization_id}
                                            onValueChange={(value) =>
                                                update(index, {
                                                    organization_id: value,
                                                    include_descendants: value
                                                        ? scope.include_descendants
                                                        : false,
                                                    hierarchy_id: value
                                                        ? scope.hierarchy_id
                                                        : null,
                                                })
                                            }
                                            placeholder="Pilih unit kerja"
                                            searchPlaceholder="Cari unit kerja..."
                                            portalContainer={portalContainer}
                                        />
                                    </Field>
                                )}
                                {policy.allows_descendants &&
                                    scope.organization_id && (
                                        <>
                                            <label className="flex items-center gap-3 text-sm">
                                                <Checkbox
                                                    checked={
                                                        scope.include_descendants
                                                    }
                                                    onCheckedChange={(
                                                        checked,
                                                    ) =>
                                                        update(index, {
                                                            include_descendants:
                                                                checked ===
                                                                true,
                                                        })
                                                    }
                                                />
                                                Sertakan unit di bawahnya
                                            </label>
                                            {scope.include_descendants && (
                                                <Field>
                                                    <Select
                                                        label="Susunan organisasi acuan"
                                                        items={hierarchyItems}
                                                        value={
                                                            scope.hierarchy_id
                                                        }
                                                        onValueChange={(
                                                            value,
                                                        ) =>
                                                            update(index, {
                                                                hierarchy_id:
                                                                    value,
                                                            })
                                                        }
                                                        placeholder="Pilih susunan organisasi"
                                                        searchPlaceholder="Cari susunan organisasi..."
                                                        portalContainer={
                                                            portalContainer
                                                        }
                                                    />
                                                    <FieldDescription>
                                                        Turunan mengikuti versi
                                                        susunan yang aktif saat
                                                        akses disimpan.
                                                    </FieldDescription>
                                                </Field>
                                            )}
                                        </>
                                    )}
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="outline"
                                    onClick={() =>
                                        onChange(
                                            scopes.filter(
                                                (_, itemIndex) =>
                                                    itemIndex !== index,
                                            ),
                                        )
                                    }
                                >
                                    Hapus batas
                                </Button>
                            </div>
                        ))}
                        <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            onClick={() =>
                                onChange([...scopes, blankScope(policy.code)])
                            }
                        >
                            Tambah batas data
                        </Button>
                    </div>
                </FieldSet>
            );
        });
}

function RoleForm({ apps, customDuties, role }: { apps: App[]; customDuties: Duty[]; role?: Role }) {
    const [open, setOpen] = useState(false);
    const form = useForm({
        name: role?.name ?? '',
        duty_codes: role?.duties.map((duty) => duty.code) ?? [],
        child_role_ids: [] as string[],
    });

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button
                    variant={role ? 'outline' : 'default'}
                    size={role ? 'sm' : 'default'}
                >
                    {role ? <Pencil /> : null}
                    {role ? 'Edit' : 'Buat role'}
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>
                        {role ? 'Edit security role' : 'Security role baru'}
                    </DialogTitle>
                    <DialogDescription>
                        Role menentukan tindakan yang boleh dilakukan. Batas
                        data diatur saat role diberikan ke anggota.
                    </DialogDescription>
                </DialogHeader>
                <form
                    onSubmit={(event) => {
                        event.preventDefault();
                        const request = role
                            ? form.put(`/settings/access/roles/${role.id}`, {
                                  onSuccess: () => setOpen(false),
                              })
                            : form.post('/settings/access/roles', {
                                  onSuccess: () => setOpen(false),
                              });
                        void request;
                    }}
                >
                    <FieldGroup>
                        <Field>
                            <Input
                                placeholder="Nama role"
                                value={form.data.name}
                                onChange={(event) =>
                                    form.setData('name', event.target.value)
                                }
                            />
                            <FieldError>{form.errors.name}</FieldError>
                        </Field>
                        <FieldSet>
                            <FieldLegend>Tanggung jawab bisnis</FieldLegend>
                            <div className="max-h-72 space-y-4 overflow-auto rounded-md border p-3">
                                {apps.map((app) => (
                                    <div key={app.id} className="space-y-2">
                                        <p className="text-sm font-medium">
                                            {app.name}
                                        </p>
                                        {app.duties.map((duty) => (
                                            <div key={duty.code} className="space-y-1">
                                                <label className="flex items-center gap-3 text-sm">
                                                    <Checkbox
                                                        checked={form.data.duty_codes.includes(
                                                            duty.code,
                                                        )}
                                                        onCheckedChange={(
                                                            checked,
                                                        ) =>
                                                            form.setData(
                                                                'duty_codes',
                                                                toggle(
                                                                    form.data
                                                                        .duty_codes,
                                                                    duty.code,
                                                                    checked ===
                                                                        true,
                                                                ),
                                                            )
                                                        }
                                                    />
                                                    {duty.name}
                                                </label>
                                                <details className="ml-9 text-xs text-muted-foreground">
                                                    <summary className="cursor-pointer">
                                                        Lihat rincian akses
                                                    </summary>
                                                    <div className="mt-2 space-y-2 border-l pl-3">
                                                        {duty.privileges?.map((privilege) => (
                                                            <div key={privilege.code}>
                                                                <p className="font-medium text-foreground">
                                                                    {privilege.name}
                                                                </p>
                                                                {privilege.permissions.map((permission) => (
                                                                    <p key={permission.code}>
                                                                        {permission.name} ({permission.access_level}) — titik akses: {permission.entry_point_code}
                                                                    </p>
                                                                ))}
                                                            </div>
                                                        ))}
                                                    </div>
                                                </details>
                                            </div>
                                        ))}
                                    </div>
                                ))}
                                {customDuties.length > 0 && <div className="space-y-2"><p className="text-sm font-medium">Dibuat khusus</p>{customDuties.map((duty) => (
                                    <label key={duty.code} className="flex items-center gap-3 text-sm"><Checkbox checked={form.data.duty_codes.includes(duty.code)} onCheckedChange={(checked) => form.setData('duty_codes', toggle(form.data.duty_codes, duty.code, checked === true))} />{duty.name}</label>
                                ))}</div>}
                            </div>
                            <FieldError>{form.errors.duty_codes}</FieldError>
                        </FieldSet>
                        <Button type="submit" disabled={form.processing}>
                            Simpan role
                        </Button>
                    </FieldGroup>
                </form>
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
    portalContainer,
    disabledRoleIds = [],
    onChange,
}: {
    assignments: Assignment[];
    roles: Role[];
    dataPolicies: DataPolicy[];
    organizations: Organization[];
    hierarchies: Hierarchy[];
    portalContainer: RefObject<HTMLDivElement | null>;
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
                <div className="max-h-44 space-y-2 overflow-auto rounded-md border p-3">
                    {roles.map((role) => {
                        const automatic = disabledRoleIds.includes(role.id);
                        return (
                            <label
                                key={role.id}
                                className="flex items-center gap-3 text-sm"
                            >
                                <Checkbox
                                    checked={
                                        Boolean(assignmentFor(role.id)) ||
                                        automatic
                                    }
                                    disabled={automatic}
                                    onCheckedChange={(checked) =>
                                        onChange(
                                            checked === true
                                                ? [
                                                      ...assignments,
                                                      {
                                                          role_id: role.id,
                                                          policy_scopes: [],
                                                      },
                                                  ]
                                                : assignments.filter(
                                                      (assignment) =>
                                                          assignment.role_id !==
                                                          role.id,
                                                  ),
                                        )
                                    }
                                />
                                <span>{role.name}</span>
                                {automatic && (
                                    <span className="text-xs text-muted-foreground">
                                        Dari aturan otomatis
                                    </span>
                                )}
                            </label>
                        );
                    })}
                </div>
            </FieldSet>
            {assignments.map((assignment) => {
                const role = roles.find(
                    (item) => item.id === assignment.role_id,
                );
                return role ? (
                    <ScopeEditor
                        key={role.id}
                        role={role}
                        scopes={assignment.policy_scopes}
                        policies={dataPolicies}
                        organizations={organizations}
                        hierarchies={hierarchies}
                        portalContainer={portalContainer}
                        onChange={(scopes) => updateScopes(role.id, scopes)}
                    />
                ) : null;
            })}
        </>
    );
}

function InviteForm({
    roles,
    dataPolicies,
    organizations,
    hierarchies,
}: Pick<Props, 'roles' | 'dataPolicies' | 'organizations' | 'hierarchies'>) {
    const [open, setOpen] = useState(false);
    const contentRef = useRef<HTMLDivElement>(null);
    const form = useForm({
        system_role: 'user',
        assignments: [] as Assignment[],
    });

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button>
                    <UserPlus />
                    Buat undangan
                </Button>
            </DialogTrigger>
            <DialogContent
                ref={contentRef}
                className="!flex h-[calc(100dvh-2rem)] max-h-[44rem] flex-col overflow-hidden"
            >
                <DialogHeader>
                    <DialogTitle>Kode undangan baru</DialogTitle>
                    <DialogDescription>
                        Kode berlaku sampai dicabut. Setiap orang yang memakai
                        kode menerima role dan batas data yang sama.
                    </DialogDescription>
                </DialogHeader>
                <form
                    className="flex min-h-0 flex-1 flex-col"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.post('/settings/access/invitations', {
                            onSuccess: () => setOpen(false),
                        });
                    }}
                >
                    <FieldGroup className="min-h-0 flex-1 overflow-y-auto pr-1">
                        <Field>
                            <NativeSelect
                                label="Role platform"
                                value={form.data.system_role}
                                onChange={(event) =>
                                    form.setData(
                                        'system_role',
                                        event.target.value,
                                    )
                                }
                            >
                                <option value="user">User</option>
                                <option value="admin">Admin</option>
                            </NativeSelect>
                        </Field>
                        <AssignmentPicker
                            assignments={form.data.assignments}
                            roles={roles}
                            dataPolicies={dataPolicies}
                            organizations={organizations}
                            hierarchies={hierarchies}
                            portalContainer={contentRef}
                            onChange={(assignments) =>
                                form.setData('assignments', assignments)
                            }
                        />
                    </FieldGroup>
                    <div className="shrink-0 border-t pt-4">
                        <Button type="submit" disabled={form.processing}>
                            Buat kode
                        </Button>
                    </div>
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
    const dutiesByCode = new Map(apps.flatMap((app) => app.duties).map((duty) => [duty.code, duty]));

    return (
        <Dialog
            open={Boolean(member)}
            onOpenChange={(open) => !open && onClose()}
        >
            <DialogContent
                ref={contentRef}
                className="!flex h-[calc(100dvh-2rem)] max-h-[44rem] flex-col overflow-hidden"
            >
                <DialogHeader>
                    <DialogTitle>Atur akses anggota</DialogTitle>
                    <DialogDescription>
                        Role menentukan tindakan; batas data menentukan data
                        yang dapat dilihat atau diubah.
                    </DialogDescription>
                </DialogHeader>
                <form
                    className="flex min-h-0 flex-1 flex-col"
                    onSubmit={(event) => {
                        event.preventDefault();
                        if (member)
                            form.patch(
                                `/settings/access/memberships/${member.id}`,
                                { onSuccess: onClose },
                            );
                    }}
                >
                    <FieldGroup className="min-h-0 flex-1 overflow-y-auto pr-1">
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
                                <option value="user">User</option>
                                <option value="admin">Admin</option>
                            </NativeSelect>
                        </Field>
                        <AssignmentPicker
                            assignments={form.data.assignments}
                            roles={roles}
                            dataPolicies={dataPolicies}
                            organizations={organizations}
                            hierarchies={hierarchies}
                            portalContainer={contentRef}
                            disabledRoleIds={automaticRoleIds}
                            onChange={(assignments) =>
                                form.setData('assignments', assignments)
                            }
                        />
                        <FieldSet>
                            <FieldLegend>Rincian akses anggota</FieldLegend>
                            <FieldDescription>Menjelaskan alasan anggota dapat memakai layar atau tindakan tertentu.</FieldDescription>
                            <div className="space-y-2 rounded-md border p-3 text-sm">
                                {(member?.assignments ?? []).map((assignment) => {
                                    const role = roles.find((item) => item.id === assignment.role_id);
                                    return <details key={`${assignment.role_id}-${assignment.source}`}><summary className="cursor-pointer font-medium">{assignment.role_name ?? role?.name ?? 'Role'}</summary><div className="mt-2 space-y-2 border-l pl-3 text-muted-foreground">{role?.duties.map((roleDuty) => { const duty = dutiesByCode.get(roleDuty.code); return <details key={roleDuty.code}><summary className="cursor-pointer text-foreground">{roleDuty.name}</summary><div className="mt-2 space-y-2 pl-3 text-xs">{duty?.privileges?.map((privilege) => <div key={privilege.code}><p className="font-medium text-foreground">{privilege.name}</p>{privilege.permissions.map((permission) => <p key={permission.code}>{permission.name} ({permission.access_level}) — titik akses: {permission.entry_point_code}</p>)}</div>)}</div></details>; })}{assignment.policy_scopes.map((scope) => <p key={`${scope.policy_code}-${scope.organization_id}`}>Batas data: {dataPolicies.find((policy) => policy.code === scope.policy_code)?.name ?? scope.policy_code}</p>)}</div></details>;
                                })}
                            </div>
                        </FieldSet>
                    </FieldGroup>
                    <div className="shrink-0 border-t pt-4">
                        <Button type="submit" disabled={form.processing}>
                            Simpan akses
                        </Button>
                    </div>
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
    customDuties,
    roles,
    dataPolicies,
    organizations,
    hierarchies,
    invitations,
    newInvitationCode,
}: Props) {
    const section = new URLSearchParams(usePage().url.split('?')[1]).get(
        'section',
    );
    const activeSection =
        section === 'roles' || section === 'invitations' ? section : 'members';
    const [editingMember, setEditingMember] = useState<Member | null>(null);
    const dutiesByCode = new Map(
        [...apps.flatMap((app) => app.duties), ...customDuties].map((duty) => [duty.code, duty]),
    );
    const actionsForRole = (role: Role) => {
        const actionsByEntryPoint = new Map<string, string[]>();
        role.duties.forEach((roleDuty) =>
            dutiesByCode
                .get(roleDuty.code)
                ?.privileges?.forEach((privilege) =>
                    privilege.permissions.forEach((permission) => {
                        actionsByEntryPoint.set(permission.entry_point_code, [
                            ...new Set([
                                ...(actionsByEntryPoint.get(permission.entry_point_code) ?? []),
                                permission.access_level,
                            ]),
                        ]);
                    }),
                ),
        );

        return [...actionsByEntryPoint]
            .map(([entryPoint, actions]) => `${entryPoint}: ${actions.join(', ')}`)
            .join('; ');
    };
    const copy = (code: string) => {
        void navigator.clipboard.writeText(code);
        toast('Kode disalin');
    };
    const memberColumns: DataTableColumn<Member>[] = [
        {
            id: 'name',
            header: 'Identity',
            cell: (member) => (
                <div>
                    <p className="font-medium">{member.name}</p>
                    <p className="text-xs text-muted-foreground">
                        {member.email}
                    </p>
                </div>
            ),
            sortValue: (member) => member.name,
        },
        {
            id: 'platform-role',
            header: 'Role platform',
            cell: (member) => <Badge>{member.system_role}</Badge>,
        },
        {
            id: 'roles',
            header: 'Security role',
            cell: (member) => member.roles.join(', ') || '—',
        },
        {
            id: 'actions',
            header: 'Aksi',
            align: 'right',
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
    const roleColumns: DataTableColumn<Role>[] = [
        {
            id: 'name',
            header: 'Role',
            cell: (role) => <span className="font-medium">{role.name}</span>,
            sortValue: (role) => role.name,
        },
        {
            id: 'duties',
            header: 'Tanggung jawab',
            cell: (role) => role.duties.map((duty) => duty.name).join(', '),
        },
        {
            id: 'products',
            header: 'Produk terkait',
            cell: (role) =>
                [...new Set(role.duties.map((duty) => duty.app_id))].join(', '),
        },
        {
            id: 'access-summary',
            header: 'Tindakan efektif',
            cell: (role) => actionsForRole(role) || 'Belum ada rincian',
        },
        {
            id: 'actions',
            header: 'Aksi',
            align: 'right',
            cell: (role) =>
                canManage ? <RoleForm apps={apps} customDuties={customDuties} role={role} /> : null,
        },
    ];
    const invitationColumns: DataTableColumn<Invitation>[] = [
        {
            id: 'status',
            header: 'Status',
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
                `${invitation.system_role} · sesuai batas data role`,
        },
        {
            id: 'roles',
            header: 'Security role',
            cell: (invitation) => invitation.roles.join(', ') || '—',
        },
        {
            id: 'actions',
            header: 'Aksi',
            align: 'right',
            cell: (invitation) =>
                canManage && !invitation.revoked_at ? (
                    <div className="flex justify-end gap-2">
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
                {newInvitationCode && (
                    <Alert>
                        <Check />
                        <AlertTitle>Kode undangan berhasil dibuat</AlertTitle>
                        <AlertDescription className="flex flex-wrap items-center gap-3">
                            <code className="rounded bg-muted px-2 py-1 font-mono text-base">
                                {newInvitationCode}
                            </code>
                            <Button
                                size="sm"
                                variant="outline"
                                onClick={() => copy(newInvitationCode)}
                            >
                                <Copy />
                                Salin
                            </Button>
                        </AlertDescription>
                    </Alert>
                )}
                {activeSection === 'members' && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Anggota</CardTitle>
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
                {activeSection === 'roles' && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Security role</CardTitle>
                            <CardDescription>
                                Susun tindakan yang dibutuhkan untuk menjalankan
                                tanggung jawab bisnis.
                            </CardDescription>
                            {canManage && (
                                <CardAction>
                                    <RoleForm apps={apps} customDuties={customDuties} />
                                </CardAction>
                            )}
                        </CardHeader>
                        <CardContent>
                            <DataTable
                                columns={roleColumns}
                                data={roles}
                                getRowKey={(role) => role.id}
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
