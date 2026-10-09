import { Info, PanelRightClose, PanelRightOpen, Paperclip } from 'lucide-react';
import type { ReactNode } from 'react';
import { useState } from 'react';
import { cn } from '../utils';
import { Button } from './button';
import { Card, CardContent, CardHeader } from './card';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from './collapsible';
import { Tabs, TabsContent, TabsList, TabsTrigger } from './tabs';

/** Wadah FactBox bersama. Isi bisnis pada Detail dan isi Lampiran dipasok halaman lewat slot. */
export function FactBox({
    children,
    details,
    attachments,
    attachmentCount,
    defaultOpen = true,
    className,
}: {
    children: ReactNode;
    details: ReactNode;
    attachments: ReactNode;
    attachmentCount?: number | null;
    defaultOpen?: boolean;
    className?: string;
}) {
    const [open, setOpen] = useState(defaultOpen);

    return (
        <Collapsible
            open={open}
            onOpenChange={setOpen}
            data-slot="fact-box-layout"
            className={cn(
                'grid min-w-0 items-start gap-5',
                open
                    ? 'xl:grid-cols-[minmax(0,1fr)_24rem]'
                    : 'xl:grid-cols-[minmax(0,1fr)_2.5rem]',
                className,
            )}
        >
            <div className="min-w-0 space-y-4">{children}</div>
            <aside
                aria-label="Detail dan lampiran"
                className="min-w-0 xl:sticky xl:top-0"
            >
                <div className="flex justify-end">
                    <CollapsibleTrigger asChild>
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            aria-label={
                                open
                                    ? 'Sembunyikan detail dan lampiran'
                                    : 'Tampilkan detail dan lampiran'
                            }
                            title={
                                open
                                    ? 'Sembunyikan detail dan lampiran'
                                    : 'Tampilkan detail dan lampiran'
                            }
                        >
                            {open ? <PanelRightClose /> : <PanelRightOpen />}
                        </Button>
                    </CollapsibleTrigger>
                </div>
                <CollapsibleContent>
                    <Tabs defaultValue="details">
                        <Card>
                            <CardHeader>
                                <TabsList
                                    variant="line"
                                    aria-label="Informasi pendamping"
                                >
                                    <TabsTrigger value="details">
                                        <Info />
                                        Detail
                                    </TabsTrigger>
                                    <TabsTrigger value="attachments">
                                        <Paperclip />
                                        Lampiran
                                        {attachmentCount != null
                                            ? ` (${attachmentCount})`
                                            : ''}
                                    </TabsTrigger>
                                </TabsList>
                            </CardHeader>
                            <CardContent>
                                <TabsContent value="details">
                                    {details}
                                </TabsContent>
                                <TabsContent
                                    value="attachments"
                                    forceMount
                                    className="data-[state=inactive]:hidden"
                                >
                                    {attachments}
                                </TabsContent>
                            </CardContent>
                        </Card>
                    </Tabs>
                </CollapsibleContent>
            </aside>
        </Collapsible>
    );
}
