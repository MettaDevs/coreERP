import { useState } from 'react';
import { Card, CardContent, CardHeader, CardTitle } from '@apperp/ui/card';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@apperp/ui/table';
import { Badge } from '@apperp/ui/badge';
import { Button } from '@apperp/ui/button';
import {
    Empty,
    EmptyDescription,
    EmptyHeader,
    EmptyMedia,
    EmptyTitle,
} from '@apperp/ui/empty';
import { Briefcase, ChevronUp, ChevronDown } from 'lucide-react';
import type { PartyRoleItem } from '@/types/global-address-book';

interface RolesSectionProps {
    roles?: PartyRoleItem[];
}

export function RolesSection({ roles = [] }: RolesSectionProps) {
    const [isExpanded, setIsExpanded] = useState(true);

    return (
        <Card className="overflow-hidden border border-border shadow-xs">
            <CardHeader
                className="flex cursor-pointer flex-row items-center justify-between border-b px-5 py-3.5 transition-colors select-none hover:bg-muted/30"
                onClick={() => setIsExpanded(!isExpanded)}
            >
                <CardTitle className="flex items-center gap-2 text-sm font-semibold text-foreground">
                    <Briefcase className="h-4 w-4 text-primary" />
                    Peran Entitas (Roles)
                </CardTitle>
                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    onClick={(e) => {
                        e.stopPropagation();
                        setIsExpanded(!isExpanded);
                    }}
                    className="h-7 w-7 rounded-sm text-muted-foreground hover:text-foreground"
                >
                    {isExpanded ? (
                        <ChevronUp className="h-4 w-4" />
                    ) : (
                        <ChevronDown className="h-4 w-4" />
                    )}
                </Button>
            </CardHeader>

            {isExpanded && (
                <CardContent className="pt-4">
                    {roles.length === 0 ? (
                        <Empty className="py-6">
                            <EmptyMedia variant="icon">
                                <Briefcase className="h-5 w-5" />
                            </EmptyMedia>
                            <EmptyHeader>
                                <EmptyTitle>
                                    Belum ada peran terdaftar
                                </EmptyTitle>
                                <EmptyDescription>
                                    Peran bisnis pihak ini (Customer, Vendor,
                                    Prospect) akan terdaftar otomatis saat
                                    dipakai di modul terkait.
                                </EmptyDescription>
                            </EmptyHeader>
                        </Empty>
                    ) : (
                        <div className="overflow-x-auto rounded-md border">
                            <Table>
                                <TableHeader>
                                    <TableRow className="bg-muted/40">
                                        <TableHead className="w-12 text-center">
                                            #
                                        </TableHead>
                                        <TableHead>
                                            Perusahaan (Company)
                                        </TableHead>
                                        <TableHead>
                                            Nomor Akun (Account)
                                        </TableHead>
                                        <TableHead>
                                            Peran Bisnis (Role)
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {roles.map((r, idx) => (
                                        <TableRow key={r.id || idx}>
                                            <TableCell className="text-center font-mono text-xs text-muted-foreground">
                                                {idx + 1}
                                            </TableCell>
                                            <TableCell className="font-mono text-xs font-semibold">
                                                {r.company}
                                            </TableCell>
                                            <TableCell className="font-mono text-xs text-muted-foreground">
                                                {r.account_number}
                                            </TableCell>
                                            <TableCell>
                                                <Badge
                                                    variant={
                                                        r.role === 'Customer'
                                                            ? 'default'
                                                            : r.role ===
                                                                'Vendor'
                                                              ? 'secondary'
                                                              : 'outline'
                                                    }
                                                    className="text-xs"
                                                >
                                                    {r.role}
                                                </Badge>
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </div>
                    )}
                </CardContent>
            )}
        </Card>
    );
}
