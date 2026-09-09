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
import { Select } from '@apperp/ui/select';
import { Checkbox } from '@apperp/ui/checkbox';
import { ActionButton } from '@apperp/ui/action-button';
import { Button } from '@apperp/ui/button';
import {
    Empty,
    EmptyDescription,
    EmptyHeader,
    EmptyMedia,
    EmptyTitle,
} from '@apperp/ui/empty';
import {
    Users,
    ArrowLeftRight,
    HelpCircle,
    ChevronUp,
    ChevronDown,
} from 'lucide-react';
import type {
    RelationshipItem,
    RelationshipDirection,
} from '@/types/global-address-book';
import { RelationshipDialog } from './relationship-dialog';

interface RelationshipSectionProps {
    relationships: RelationshipItem[];
    currentPartyId: string;
    currentPartyName: string;
    onChange: (relationships: RelationshipItem[]) => void;
}

export function RelationshipSection({
    relationships,
    currentPartyId,
    currentPartyName,
    onChange,
}: RelationshipSectionProps) {
    const [isExpanded, setIsExpanded] = useState(true);
    const [dialogOpen, setDialogOpen] = useState(false);
    const [selectedRelId, setSelectedRelId] = useState<string | null>(null);
    const [direction, setDirection] = useState<RelationshipDirection>('a_to_b');
    const [showExplanation, setShowExplanation] = useState(false);

    // Filters
    const [filterExpired, setFilterExpired] = useState(true);
    const [filterActive, setFilterActive] = useState(true);
    const [filterFuture, setFilterFuture] = useState(true);

    const filteredRelationships = relationships.filter((rel) => {
        if (rel.status === 'expired' && !filterExpired) return false;
        if (rel.status === 'active' && !filterActive) return false;
        if (rel.status === 'future' && !filterFuture) return false;
        return true;
    });

    const handleRemove = () => {
        if (!selectedRelId) return;
        onChange(relationships.filter((r) => r.id !== selectedRelId));
        setSelectedRelId(null);
    };

    const handleSaveNew = (newRel: RelationshipItem) => {
        onChange([...relationships, newRel]);
    };

    return (
        <>
            <Card className="overflow-hidden border border-border shadow-xs">
                <CardHeader
                    className="flex cursor-pointer flex-row items-center justify-between border-b px-5 py-3.5 transition-colors select-none hover:bg-muted/30"
                    onClick={() => setIsExpanded(!isExpanded)}
                >
                    <CardTitle className="flex items-center gap-2 text-sm font-semibold text-foreground">
                        <Users className="h-4 w-4 text-primary" />
                        Hubungan Antar Pihak (Relationships)
                    </CardTitle>
                    <div className="flex items-center gap-2">
                        {isExpanded && (
                            <div
                                className="mr-1 flex flex-wrap items-center gap-2"
                                onClick={(e) => e.stopPropagation()}
                            >
                                <ActionButton
                                    action="create"
                                    size="sm"
                                    onClick={() => setDialogOpen(true)}
                                >
                                    Tambah Hubungan
                                </ActionButton>

                                <ActionButton
                                    action="archive"
                                    size="sm"
                                    disabled={!selectedRelId}
                                    onClick={handleRemove}
                                >
                                    Hapus / Arsipkan
                                </ActionButton>
                            </div>
                        )}
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
                    </div>
                </CardHeader>

                {isExpanded && (
                    <CardContent className="space-y-4 pt-4">
                        {/* EFFECTIVE DATE FILTER & VIEW CONTROLS */}
                        <div className="flex flex-col gap-4 rounded-lg border bg-muted/20 p-3 sm:flex-row sm:items-center sm:justify-between">
                            <div className="w-full sm:w-72">
                                <Select
                                    label="Tampilan / Sudut Pandang"
                                    items={[
                                        {
                                            value: 'a_to_b',
                                            label: 'Tampilan: Perspektif A ke B',
                                        },
                                        {
                                            value: 'b_to_a',
                                            label: 'Tampilan: Perspektif B ke A (Kebalikan)',
                                        },
                                    ]}
                                    value={direction}
                                    onValueChange={(val) =>
                                        setDirection(
                                            (val as RelationshipDirection) ||
                                                'a_to_b',
                                        )
                                    }
                                />
                            </div>

                            <div className="flex flex-wrap items-center gap-4 text-sm">
                                <span className="text-xs font-semibold tracking-wider text-muted-foreground uppercase">
                                    Filter Masa Berlaku:
                                </span>

                                <label className="flex cursor-pointer items-center gap-1.5">
                                    <Checkbox
                                        checked={filterExpired}
                                        onCheckedChange={(checked) =>
                                            setFilterExpired(!!checked)
                                        }
                                    />
                                    <span className="text-xs">
                                        Kedaluwarsa (Expired)
                                    </span>
                                </label>

                                <label className="flex cursor-pointer items-center gap-1.5">
                                    <Checkbox
                                        checked={filterActive}
                                        onCheckedChange={(checked) =>
                                            setFilterActive(!!checked)
                                        }
                                    />
                                    <span className="text-xs">
                                        Aktif (Active)
                                    </span>
                                </label>

                                <label className="flex cursor-pointer items-center gap-1.5">
                                    <Checkbox
                                        checked={filterFuture}
                                        onCheckedChange={(checked) =>
                                            setFilterFuture(!!checked)
                                        }
                                    />
                                    <span className="text-xs">
                                        Masa Depan (Future)
                                    </span>
                                </label>

                                <button
                                    type="button"
                                    onClick={() =>
                                        setShowExplanation(!showExplanation)
                                    }
                                    className="ml-auto flex h-6 w-6 items-center justify-center rounded-sm text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
                                    title="Arti Hubungan Timbal Balik"
                                    aria-label="Arti Hubungan Timbal Balik"
                                >
                                    <HelpCircle className="h-4 w-4" />
                                </button>
                            </div>
                        </div>

                        {/* RELATIONSHIP CONCEPT EXPLANATION PANEL */}
                        {showExplanation && (
                            <div className="rounded-lg border bg-card p-4 shadow-xs">
                                <div className="mb-2 flex items-center gap-2 text-sm font-semibold">
                                    <ArrowLeftRight className="h-4 w-4 text-primary" />
                                    Logika Relasi Dua Arah Global Address Book
                                </div>
                                <div className="grid grid-cols-1 gap-2 text-xs sm:grid-cols-2">
                                    <div className="rounded-md bg-muted/40 p-2.5">
                                        <span className="font-semibold text-foreground">
                                            Dari Party B ke A
                                        </span>
                                        <ul className="mt-1 list-disc space-y-1 pl-4 text-muted-foreground">
                                            <li>PIC dari</li>
                                            <li>Anak perusahaan dari</li>
                                            <li>Cabang dari</li>
                                            <li>Kontak dari</li>
                                        </ul>
                                    </div>
                                    <div className="rounded-md bg-muted/40 p-2.5">
                                        <span className="font-semibold text-foreground">
                                            Kebalikannya dari A ke B
                                        </span>
                                        <ul className="mt-1 list-disc space-y-1 pl-4 text-muted-foreground">
                                            <li>Memiliki PIC</li>
                                            <li>Induk perusahaan dari</li>
                                            <li>Memiliki cabang</li>
                                            <li>Memiliki kontak</li>
                                        </ul>
                                    </div>
                                </div>
                            </div>
                        )}

                        {/* TABLE */}
                        {filteredRelationships.length === 0 ? (
                            <Empty className="py-8">
                                <EmptyMedia variant="icon">
                                    <Users className="h-6 w-6" />
                                </EmptyMedia>
                                <EmptyHeader>
                                    <EmptyTitle>
                                        Belum ada hubungan relasi tercatat
                                    </EmptyTitle>
                                    <EmptyDescription>
                                        Hubungkan pihak ini dengan organisasi
                                        atau individu lain sebagai cabang, PIC,
                                        atau mitra bisnis.
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
                                                {direction === 'a_to_b'
                                                    ? 'Party A (Sumber)'
                                                    : 'Party B (Tujuan)'}
                                            </TableHead>
                                            <TableHead className="text-center">
                                                {direction === 'a_to_b'
                                                    ? 'Hubungan (A ➔ B)'
                                                    : 'Hubungan (B ➔ A)'}
                                            </TableHead>
                                            <TableHead>
                                                {direction === 'a_to_b'
                                                    ? 'Party B (Tujuan)'
                                                    : 'Party A (Sumber)'}
                                            </TableHead>
                                            <TableHead>Berlaku Sejak</TableHead>
                                            <TableHead>Berakhir Pada</TableHead>
                                            <TableHead className="text-center">
                                                Status
                                            </TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {filteredRelationships.map(
                                            (rel, idx) => {
                                                const isSelected =
                                                    selectedRelId === rel.id;
                                                const relText =
                                                    direction === 'a_to_b'
                                                        ? rel.relationship_a_to_b
                                                        : rel.relationship_b_to_a;
                                                const firstParty =
                                                    direction === 'a_to_b'
                                                        ? rel.party_a_name
                                                        : rel.party_b_name;
                                                const secondParty =
                                                    direction === 'a_to_b'
                                                        ? rel.party_b_name
                                                        : rel.party_a_name;

                                                return (
                                                    <TableRow
                                                        key={rel.id || idx}
                                                        className={`cursor-pointer transition-colors ${
                                                            isSelected
                                                                ? 'bg-primary/10 font-medium'
                                                                : 'hover:bg-muted/30'
                                                        }`}
                                                        onClick={() =>
                                                            setSelectedRelId(
                                                                rel.id,
                                                            )
                                                        }
                                                    >
                                                        <TableCell className="text-center font-mono text-xs text-muted-foreground">
                                                            {idx + 1}
                                                        </TableCell>
                                                        <TableCell className="font-medium">
                                                            {firstParty}
                                                            <span className="block font-mono text-xs text-muted-foreground">
                                                                {direction ===
                                                                'a_to_b'
                                                                    ? rel.party_a_id
                                                                    : rel.party_b_id}
                                                            </span>
                                                        </TableCell>
                                                        <TableCell className="text-center">
                                                            <Badge
                                                                variant="outline"
                                                                className="bg-background font-normal"
                                                            >
                                                                {relText}
                                                            </Badge>
                                                        </TableCell>
                                                        <TableCell className="font-medium">
                                                            {secondParty}
                                                            <span className="block font-mono text-xs text-muted-foreground">
                                                                {direction ===
                                                                'a_to_b'
                                                                    ? rel.party_b_id
                                                                    : rel.party_a_id}
                                                            </span>
                                                        </TableCell>
                                                        <TableCell className="text-xs">
                                                            {rel.effective_date}
                                                        </TableCell>
                                                        <TableCell className="text-xs text-muted-foreground">
                                                            {rel.expiration_date ||
                                                                'Selamanya (Never)'}
                                                        </TableCell>
                                                        <TableCell className="text-center">
                                                            {rel.status ===
                                                                'active' && (
                                                                <Badge
                                                                    variant="default"
                                                                    className="bg-emerald-600 text-[10px]"
                                                                >
                                                                    Aktif
                                                                </Badge>
                                                            )}
                                                            {rel.status ===
                                                                'expired' && (
                                                                <Badge
                                                                    variant="secondary"
                                                                    className="text-[10px] text-muted-foreground"
                                                                >
                                                                    Kedaluwarsa
                                                                </Badge>
                                                            )}
                                                            {rel.status ===
                                                                'future' && (
                                                                <Badge
                                                                    variant="outline"
                                                                    className="border-blue-300 text-[10px] text-blue-600"
                                                                >
                                                                    Masa Depan
                                                                </Badge>
                                                            )}
                                                        </TableCell>
                                                    </TableRow>
                                                );
                                            },
                                        )}
                                    </TableBody>
                                </Table>
                            </div>
                        )}
                    </CardContent>
                )}
            </Card>

            <RelationshipDialog
                open={dialogOpen}
                onOpenChange={setDialogOpen}
                currentPartyId={currentPartyId}
                currentPartyName={currentPartyName}
                onSave={handleSaveNew}
            />
        </>
    );
}
