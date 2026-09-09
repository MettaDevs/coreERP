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
    Phone,
    Mail,
    Globe,
    Printer,
    Linkedin,
    Twitter,
    MessageSquare,
    PhoneCall,
    ChevronUp,
    ChevronDown,
} from 'lucide-react';
import type { ContactItem, ContactType } from '@/types/global-address-book';
import { ContactDialog } from './contact-dialog';

interface ContactInformationSectionProps {
    contacts: ContactItem[];
    onChange: (contacts: ContactItem[]) => void;
}

const TYPE_ICONS: Record<ContactType, any> = {
    phone: Phone,
    whatsapp: MessageSquare,
    email: Mail,
    url: Globe,
    fax: Printer,
    telex: MessageSquare,
    linkedin: Linkedin,
    twitter: Twitter,
};

const TYPE_LABELS: Record<ContactType, string> = {
    phone: 'Telepon',
    whatsapp: 'WhatsApp',
    email: 'Email',
    url: 'Situs Web',
    fax: 'Fax',
    telex: 'Telex',
    linkedin: 'LinkedIn',
    twitter: 'Twitter / X',
};

export function ContactInformationSection({
    contacts,
    onChange,
}: ContactInformationSectionProps) {
    const [isExpanded, setIsExpanded] = useState(true);
    const [dialogOpen, setDialogOpen] = useState(false);
    const [selectedContactId, setSelectedContactId] = useState<string | null>(
        null,
    );

    const handleRemove = () => {
        if (!selectedContactId) return;
        onChange(contacts.filter((c) => c.id !== selectedContactId));
        setSelectedContactId(null);
    };

    const handleSaveNew = (newContact: ContactItem) => {
        let updated = [...contacts];
        if (newContact.is_primary) {
            updated = updated.map((c) => ({ ...c, is_primary: false }));
        }
        onChange([...updated, newContact]);
    };

    return (
        <>
            <Card className="overflow-hidden border border-border shadow-xs">
                <CardHeader
                    className="flex cursor-pointer flex-row items-center justify-between border-b px-5 py-3.5 transition-colors select-none hover:bg-muted/30"
                    onClick={() => setIsExpanded(!isExpanded)}
                >
                    <CardTitle className="flex items-center gap-2 text-sm font-semibold text-foreground">
                        <PhoneCall className="h-4 w-4 text-primary" />
                        Informasi Kontak (Contact Information)
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
                                    Tambah Kontak
                                </ActionButton>

                                <ActionButton
                                    action="archive"
                                    size="sm"
                                    disabled={!selectedContactId}
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
                    <CardContent className="pt-4">
                        {contacts.length === 0 ? (
                            <Empty className="py-8">
                                <EmptyMedia variant="icon">
                                    <Phone className="h-6 w-6" />
                                </EmptyMedia>
                                <EmptyHeader>
                                    <EmptyTitle>
                                        Belum ada informasi kontak
                                    </EmptyTitle>
                                    <EmptyDescription>
                                        Daftarkan nomor telepon, alamat email,
                                        atau tautan media sosial untuk pihak
                                        ini.
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
                                            <TableHead className="w-36">
                                                Tipe
                                            </TableHead>
                                            <TableHead>
                                                Nomor / Alamat Kontak
                                            </TableHead>
                                            <TableHead>Keterangan</TableHead>
                                            <TableHead className="w-24">
                                                Ekstensi
                                            </TableHead>
                                            <TableHead className="w-24 text-center">
                                                Utama
                                            </TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {contacts.map((cnt, idx) => {
                                            const isSelected =
                                                selectedContactId === cnt.id;
                                            const Icon =
                                                TYPE_ICONS[cnt.type] || Phone;

                                            return (
                                                <TableRow
                                                    key={cnt.id || idx}
                                                    className={`cursor-pointer transition-colors ${
                                                        isSelected
                                                            ? 'bg-primary/10 font-medium'
                                                            : 'hover:bg-muted/30'
                                                    }`}
                                                    onClick={() =>
                                                        setSelectedContactId(
                                                            cnt.id,
                                                        )
                                                    }
                                                >
                                                    <TableCell className="text-center font-mono text-xs text-muted-foreground">
                                                        {idx + 1}
                                                    </TableCell>
                                                    <TableCell>
                                                        <span className="flex items-center gap-1.5 text-xs">
                                                            <Icon className="h-3.5 w-3.5 text-primary" />
                                                            {TYPE_LABELS[
                                                                cnt.type
                                                            ] || cnt.type}
                                                        </span>
                                                    </TableCell>
                                                    <TableCell className="font-mono text-sm font-medium">
                                                        {cnt.value}
                                                    </TableCell>
                                                    <TableCell className="text-xs text-muted-foreground">
                                                        {cnt.description || '-'}
                                                    </TableCell>
                                                    <TableCell className="font-mono text-xs text-muted-foreground">
                                                        {cnt.extension || '-'}
                                                    </TableCell>
                                                    <TableCell className="text-center">
                                                        {cnt.is_primary ? (
                                                            <Badge
                                                                variant="default"
                                                                className="bg-emerald-600 text-[10px]"
                                                            >
                                                                Utama
                                                            </Badge>
                                                        ) : (
                                                            <span className="text-xs text-muted-foreground">
                                                                -
                                                            </span>
                                                        )}
                                                    </TableCell>
                                                </TableRow>
                                            );
                                        })}
                                    </TableBody>
                                </Table>
                            </div>
                        )}
                    </CardContent>
                )}
            </Card>

            <ContactDialog
                open={dialogOpen}
                onOpenChange={setDialogOpen}
                onSave={handleSaveNew}
            />
        </>
    );
}
