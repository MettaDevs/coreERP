import { router, usePage } from '@inertiajs/react';
import { Building2, Check, ChevronDown, MapPin } from 'lucide-react';

import { Button } from '@apperp/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuTrigger,
} from '@apperp/ui/dropdown-menu';
import type { Workspace } from '@/types';

type PageProps = {
    auth: {
        membership: {
            id: string;
            tenant_name: string;
        } | null;
    };
    workspace: Workspace;
};

export function WorkspaceSwitcher() {
    const { auth, workspace } = usePage<PageProps>().props;
    const membership = auth.membership;

    if (!membership) {
        return null;
    }

    const activate = (
        membershipId: string,
        legalEntityId: string | null,
        orgUnitId: string | null,
    ) => {
        router.put(
            '/api/v1/workspace-context',
            {
                membership_id: membershipId,
                legal_entity_id: legalEntityId,
                org_unit_id: orgUnitId,
            },
            { preserveScroll: true },
        );
    };

    return (
        <div className="flex min-w-0 items-center gap-1">
            <DropdownMenu>
                <DropdownMenuTrigger asChild>
                    <Button
                        variant="ghost"
                        className="max-w-48 justify-between"
                        aria-label="Pilih tenant aktif"
                    >
                        <Building2 data-icon="inline-start" />
                        <span className="truncate">
                            {membership.tenant_name}
                        </span>
                        <ChevronDown data-icon="inline-end" />
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="start" className="min-w-64">
                    <DropdownMenuLabel>Tenant</DropdownMenuLabel>
                    <DropdownMenuGroup>
                        {workspace.memberships.map((item) => (
                            <DropdownMenuItem
                                key={item.id}
                                onSelect={() => activate(item.id, null, null)}
                            >
                                <Building2 />
                                <span className="min-w-0 flex-1 truncate">
                                    {item.tenant_name}
                                </span>
                                {item.id === membership.id && <Check />}
                            </DropdownMenuItem>
                        ))}
                    </DropdownMenuGroup>
                </DropdownMenuContent>
            </DropdownMenu>

            <DropdownMenu>
                <DropdownMenuTrigger asChild>
                    <Button
                        variant="ghost"
                        className="max-w-52 justify-between"
                        disabled={!workspace.legal_entities.length}
                        aria-label="Pilih legal entity aktif"
                    >
                        <Building2 data-icon="inline-start" />
                        <span className="truncate">
                            {workspace.active_legal_entity?.name ??
                                'Belum ada legal entity'}
                        </span>
                        <ChevronDown data-icon="inline-end" />
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="start" className="min-w-72">
                    <DropdownMenuLabel>Legal entity</DropdownMenuLabel>
                    <DropdownMenuGroup>
                        {workspace.legal_entities.map((entity) => (
                            <DropdownMenuItem
                                key={entity.id}
                                onSelect={() =>
                                    activate(
                                        membership.id,
                                        entity.id,
                                        workspace.active_org_unit?.id ?? null,
                                    )
                                }
                            >
                                <Building2 />
                                <span className="min-w-0 flex-1 truncate">
                                    {entity.name}
                                </span>
                                {entity.id ===
                                    workspace.active_legal_entity?.id && (
                                    <Check />
                                )}
                            </DropdownMenuItem>
                        ))}
                    </DropdownMenuGroup>
                </DropdownMenuContent>
            </DropdownMenu>

            <DropdownMenu>
                <DropdownMenuTrigger asChild>
                    <Button
                        variant="ghost"
                        className="max-w-52 justify-between"
                        disabled={!workspace.org_units.length}
                        aria-label="Pilih outlet atau unit aktif"
                    >
                        <MapPin data-icon="inline-start" />
                        <span className="truncate">
                            {workspace.active_org_unit?.name ??
                                'Tidak ada unit'}
                        </span>
                        <ChevronDown data-icon="inline-end" />
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="start" className="min-w-72">
                    <DropdownMenuLabel>Outlet / unit kerja</DropdownMenuLabel>
                    <DropdownMenuGroup>
                        {workspace.org_units.map((unit) => (
                            <DropdownMenuItem
                                key={unit.id}
                                onSelect={() =>
                                    activate(
                                        membership.id,
                                        workspace.active_legal_entity?.id ??
                                            null,
                                        unit.id,
                                    )
                                }
                            >
                                <MapPin />
                                <span className="min-w-0 flex-1 truncate">
                                    {unit.name}
                                </span>
                                {unit.id === workspace.active_org_unit?.id && (
                                    <Check />
                                )}
                            </DropdownMenuItem>
                        ))}
                    </DropdownMenuGroup>
                </DropdownMenuContent>
            </DropdownMenu>
        </div>
    );
}
