import {
    GripVertical,
    Info,
    Maximize2,
    Minimize2,
    PanelRightClose,
    PanelRightOpen,
    Paperclip,
} from 'lucide-react';
import type { CSSProperties, ReactNode } from 'react';
import { useEffect, useId, useRef, useState } from 'react';
import { cn } from '../utils';
import { Button } from './button';
import { Card, CardContent, CardHeader } from './card';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from './collapsible';
import { Tabs, TabsContent, TabsList, TabsTrigger } from './tabs';

const DEFAULT_WIDTH = 384;
const MIN_WIDTH = 288;

/** Wadah FactBox bersama. Grid menjaga form tetap terpasang saat panel diubah ukurannya atau layar menyempit. */
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
    const [width, setWidth] = useState(DEFAULT_WIDTH);
    const [maxWidth, setMaxWidth] = useState(DEFAULT_WIDTH);
    const layout = useRef<HTMLDivElement>(null);
    const drag = useRef<{ pointerId: number; x: number; width: number } | null>(
        null,
    );
    const panelId = useId();
    const panelWidth = Math.min(width, maxWidth);
    const expanded = panelWidth === maxWidth && maxWidth > DEFAULT_WIDTH;

    useEffect(() => {
        const node = layout.current;

        if (!node) {
            return;
        }

        const observer = new ResizeObserver(() => {
            // Form utama tetap punya ruang; pada ponsel pembatas disembunyikan dan panel ditumpuk.
            setMaxWidth(Math.max(MIN_WIDTH, node.clientWidth - 356));
        });
        observer.observe(node);

        return () => observer.disconnect();
    }, []);

    function resize(nextWidth: number) {
        setWidth(Math.min(maxWidth, Math.max(MIN_WIDTH, nextWidth)));
    }

    return (
        <Collapsible
            ref={layout}
            open={open}
            onOpenChange={setOpen}
            data-slot="fact-box-layout"
            style={{ '--fact-box-width': `${panelWidth}px` } as CSSProperties}
            className={cn(
                'grid min-w-0 items-start gap-3',
                open
                    ? 'xl:grid-cols-[minmax(0,1fr)_0.75rem_minmax(0,var(--fact-box-width))]'
                    : 'xl:grid-cols-[minmax(0,1fr)_2.5rem]',
                className,
            )}
        >
            <div className="min-w-0 space-y-4">{children}</div>
            {open && (
                <div
                    role="separator"
                    tabIndex={0}
                    aria-label="Ubah lebar panel detail dan lampiran"
                    aria-orientation="vertical"
                    aria-controls={panelId}
                    aria-valuemin={MIN_WIDTH}
                    aria-valuemax={maxWidth}
                    aria-valuenow={panelWidth}
                    aria-valuetext={`${Math.round(panelWidth)} piksel`}
                    title="Tarik untuk mengubah lebar. Tombol panah kiri/kanan juga bisa digunakan. Klik dua kali untuk mengembalikan lebar."
                    className="bg-border/40 text-muted-foreground hover:bg-accent focus-visible:outline-ring hidden touch-none select-none self-stretch rounded-sm focus-visible:outline-2 xl:flex xl:cursor-col-resize xl:items-start xl:justify-center"
                    onDoubleClick={() => resize(DEFAULT_WIDTH)}
                    onPointerDown={(event) => {
                        if (event.button !== 0) {
                            return;
                        }

                        event.preventDefault();
                        event.currentTarget.focus();
                        drag.current = {
                            pointerId: event.pointerId,
                            x: event.clientX,
                            width: panelWidth,
                        };
                        event.currentTarget.setPointerCapture(event.pointerId);
                    }}
                    onPointerMove={(event) => {
                        const current = drag.current;

                        if (current?.pointerId === event.pointerId) {
                            resize(current.width + current.x - event.clientX);
                        }
                    }}
                    onPointerUp={() => {
                        drag.current = null;
                    }}
                    onPointerCancel={() => {
                        const current = drag.current;

                        if (current) {
                            resize(current.width);
                        }

                        drag.current = null;
                    }}
                    onLostPointerCapture={() => {
                        drag.current = null;
                    }}
                    onKeyDown={(event) => {
                        const next =
                            event.key === 'ArrowLeft'
                                ? panelWidth + 20
                                : event.key === 'ArrowRight'
                                  ? panelWidth - 20
                                  : event.key === 'Home'
                                    ? MIN_WIDTH
                                    : event.key === 'End'
                                      ? maxWidth
                                      : null;

                        if (next !== null) {
                            event.preventDefault();
                            resize(next);
                        }
                    }}
                >
                    <GripVertical
                        aria-hidden="true"
                        className="mt-4 size-4 shrink-0"
                    />
                </div>
            )}
            <aside
                id={panelId}
                aria-label="Detail dan lampiran"
                className="min-w-0 xl:sticky xl:top-0"
            >
                <div className="flex justify-end">
                    {open && (
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            className="hidden xl:inline-flex"
                            disabled={maxWidth <= DEFAULT_WIDTH}
                            aria-label={
                                expanded
                                    ? 'Kembalikan lebar panel'
                                    : 'Perlebar panel detail dan lampiran'
                            }
                            title={
                                expanded
                                    ? 'Kembalikan lebar panel'
                                    : 'Perlebar panel detail dan lampiran'
                            }
                            onClick={() =>
                                resize(expanded ? DEFAULT_WIDTH : maxWidth)
                            }
                        >
                            {expanded ? <Minimize2 /> : <Maximize2 />}
                        </Button>
                    )}
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
