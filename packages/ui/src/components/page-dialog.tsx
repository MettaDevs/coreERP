import { ArrowLeft, Maximize2, Minimize2, X } from 'lucide-react';
import { Dialog as DialogPrimitive } from 'radix-ui';
import * as React from 'react';
import {
    getPageDialogRevision,
    getPageDialogStack,
    registerPageDialog,
    subscribePageDialogs,
} from '../lib/page-dialog-stack';
import { cn } from '../utils';
import { Badge } from './badge';
import { Button } from './button';
import {
    DialogAction as PageDialogAction,
    DialogBody as PageDialogBody,
    DialogDescription as PageDialogDescription,
    DialogFooter as PageDialogFooter,
    DialogTitle as PageDialogTitle,
} from './dialog';

const popupEscapes = new WeakSet<KeyboardEvent>();
const serverSnapshot = () => 0;
const activeElement = () =>
    document.activeElement instanceof HTMLElement
        ? document.activeElement
        : null;

type PageContext = {
    count: number;
    active: boolean;
    expanded: boolean;
    setExpanded: (expanded: boolean) => void;
};
const PageDialogContext = React.createContext<PageContext | null>(null);

/** Kartu/pemilih halaman memakai stack R&D; konfirmasi kecil tetap memakai Dialog. */
function PageDialog(
    props: Omit<React.ComponentProps<typeof DialogPrimitive.Root>, 'modal'>,
) {
    return <DialogPrimitive.Root {...props} modal={false} />;
}

function PageDialogContent({
    children,
    expanded,
    defaultExpanded = false,
    onExpandedChange,
    ...props
}: React.ComponentPropsWithRef<typeof DialogPrimitive.Content> & {
    expanded?: boolean;
    defaultExpanded?: boolean;
    onExpandedChange?: (expanded: boolean) => void;
}) {
    const anchorRef = React.useRef<HTMLSpanElement>(null);
    const [host, setHost] = React.useState<HTMLElement | null>(null);
    const [internalExpanded, setInternalExpanded] =
        React.useState(defaultExpanded);
    React.useLayoutEffect(() => {
        setHost(
            anchorRef.current
                ?.closest('[data-shell-content]')
                ?.querySelector<HTMLElement>('[data-shell-overlay-host]') ??
                document.body,
        );
    }, []);
    const setExpanded = (next: boolean) => {
        if (expanded === undefined) {
            setInternalExpanded(next);
        }

        onExpandedChange?.(next);
    };

    return (
        <>
            <span hidden ref={anchorRef} />
            <DialogPrimitive.Portal container={host}>
                {host && (
                    <PageDialogSurface
                        {...props}
                        host={host}
                        expanded={expanded ?? internalExpanded}
                        setExpanded={setExpanded}
                    >
                        {children}
                    </PageDialogSurface>
                )}
            </DialogPrimitive.Portal>
        </>
    );
}

function PageDialogSurface({
    host,
    children,
    ref: forwardedRef,
    className,
    expanded,
    setExpanded,
    onEscapeKeyDown,
    onInteractOutside,
    onCloseAutoFocus,
    style,
    ...props
}: React.ComponentPropsWithRef<typeof DialogPrimitive.Content> & {
    host: HTMLElement;
    expanded: boolean;
    setExpanded: (expanded: boolean) => void;
}) {
    const [id] = React.useState(() => Symbol('page-dialog'));
    const nodeRef = React.useRef<HTMLDivElement>(null);
    React.useSyncExternalStore(
        subscribePageDialogs,
        getPageDialogRevision,
        serverSnapshot,
    );
    const group = getPageDialogStack(host);
    const index = group.findIndex((item) => item.id === id);
    const depth = index === -1 ? 0 : group.length - index - 1;
    const active = depth === 0;
    const count = index === -1 ? group.length + 1 : group.length;
    React.useLayoutEffect(() => {
        if (!nodeRef.current) {
            return;
        }

        return registerPageDialog({
            id,
            host,
            node: nodeRef.current,
            opener: activeElement(),
        });
    }, [host, id]);
    React.useLayoutEffect(() => {
        // Popup dapat menutup di capture handler-nya sebelum handler Radix halaman.
        const rememberPopup = (event: KeyboardEvent) => {
            if (
                active &&
                event.key === 'Escape' &&
                nodeRef.current?.querySelector(
                    '[role="combobox"][aria-expanded="true"]',
                )
            ) {
                popupEscapes.add(event);
            }
        };
        const ownerWindow = host.ownerDocument.defaultView;
        ownerWindow?.addEventListener('keydown', rememberPopup, true);

        return () =>
            ownerWindow?.removeEventListener('keydown', rememberPopup, true);
    }, [active, host]);

    return (
        <PageDialogContext.Provider
            value={{ count, active, expanded, setExpanded }}
        >
            {active && (
                <div
                    className="core-page-dialog-backdrop"
                    data-shell={host !== document.body}
                    aria-hidden="true"
                />
            )}
            <DialogPrimitive.Content
                {...props}
                ref={nodeRef}
                className={cn('core-page-dialog', className)}
                data-slot="page-dialog-content"
                data-shell={host !== document.body}
                data-active={active}
                data-expanded={expanded}
                aria-hidden={!active || undefined}
                inert={!active || undefined}
                style={
                    {
                        ...style,
                        '--stack-depth': depth,
                        zIndex: 31 + Math.max(index, 0),
                    } as React.CSSProperties
                }
                onInteractOutside={(event) => {
                    onInteractOutside?.(event);
                    event.preventDefault();
                }}
                onEscapeKeyDown={(event) => {
                    onEscapeKeyDown?.(event);

                    if (
                        !active ||
                        !nodeRef.current?.contains(
                            event.target as Node | null,
                        ) ||
                        popupEscapes.has(event) ||
                        nodeRef.current?.querySelector(
                            '[role="combobox"][aria-expanded="true"]',
                        )
                    ) {
                        event.preventDefault();
                    }
                }}
                onCloseAutoFocus={(event) => {
                    onCloseAutoFocus?.(event);
                    event.preventDefault();
                }}
            >
                <div
                    ref={forwardedRef}
                    className="core-page-dialog-contents"
                    data-slot="page-dialog-contents"
                >
                    {children}
                </div>
                {!active && (
                    <div
                        className="core-page-dialog-inactive"
                        data-slot="page-dialog-inactive"
                        aria-hidden="true"
                    />
                )}
            </DialogPrimitive.Content>
        </PageDialogContext.Provider>
    );
}

function PageDialogHeader({
    children,
    actions,
    className,
    ...props
}: React.ComponentProps<'div'> & { actions?: React.ReactNode }) {
    const page = React.useContext(PageDialogContext);

    if (!page) {
        throw new Error('PageDialogHeader requires PageDialogContent.');
    }

    return (
        <div {...props} className={cn('core-page-dialog-header', className)}>
            <DialogPrimitive.Close asChild>
                <Button
                    type="button"
                    data-page-dialog-back
                    size="icon"
                    variant="ghost"
                    aria-label="Kembali ke halaman sebelumnya"
                    title="Kembali (Esc)"
                >
                    <ArrowLeft />
                </Button>
            </DialogPrimitive.Close>
            <div className="min-w-0 flex-1">{children}</div>
            <Badge variant="outline" className="shrink-0" role="status">
                {page.count} halaman terbuka
            </Badge>
            {actions}
            <Button
                type="button"
                className="core-page-dialog-expand"
                size="icon"
                variant="ghost"
                aria-label={
                    page.expanded
                        ? 'Kembalikan ukuran halaman'
                        : 'Perbesar halaman'
                }
                onClick={() => page.setExpanded(!page.expanded)}
            >
                {page.expanded ? <Minimize2 /> : <Maximize2 />}
            </Button>
            <DialogPrimitive.Close asChild>
                <Button
                    type="button"
                    size="icon"
                    variant="ghost"
                    aria-label="Tutup halaman"
                >
                    <X />
                </Button>
            </DialogPrimitive.Close>
        </div>
    );
}

const PageDialogClose = DialogPrimitive.Close;
const PageDialogTrigger = DialogPrimitive.Trigger;
export {
    PageDialog,
    PageDialogContent,
    PageDialogHeader,
    PageDialogTitle,
    PageDialogDescription,
    PageDialogBody,
    PageDialogFooter,
    PageDialogAction,
    PageDialogClose,
    PageDialogTrigger,
};
