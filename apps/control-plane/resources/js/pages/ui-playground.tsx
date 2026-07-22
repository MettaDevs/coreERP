import { Head } from '@inertiajs/react';
import {
    Bell,
    Box,
    CalendarDays,
    ChevronRight,
    CircleAlert,
    CreditCard,
    Database,
    Mail,
    PackageOpen,
    Search,
    Settings,
    Sparkles,
    User,
} from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';

import {
    Accordion,
    AccordionContent,
    AccordionItem,
    AccordionTrigger,
} from '@/components/ui/accordion';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
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
} from '@/components/ui/alert-dialog';
import { AspectRatio } from '@/components/ui/aspect-ratio';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import {
    Breadcrumb,
    BreadcrumbItem,
    BreadcrumbLink,
    BreadcrumbList,
    BreadcrumbPage,
    BreadcrumbSeparator,
} from '@/components/ui/breadcrumb';
import { Button } from '@/components/ui/button';
import { ButtonGroup } from '@/components/ui/button-group';
import { Calendar } from '@/components/ui/calendar';
import {
    Card,
    CardContent,
    CardDescription,
    CardFooter,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    Carousel,
    CarouselContent,
    CarouselItem,
    CarouselNext,
    CarouselPrevious,
} from '@/components/ui/carousel';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import {
    Command,
    CommandEmpty,
    CommandGroup,
    CommandInput,
    CommandItem,
    CommandList,
} from '@/components/ui/command';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { DataTable } from '@/components/ui/data-table';
import type {
    DataTableColumn,
    DataTableRowAction,
} from '@/components/ui/data-table';
import {
    DropdownMenu,
    DropdownMenuCheckboxItem,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    Empty,
    EmptyContent,
    EmptyDescription,
    EmptyHeader,
    EmptyMedia,
    EmptyTitle,
} from '@/components/ui/empty';
import { Field, FieldDescription, FieldGroup } from '@/components/ui/field';
import {
    HoverCard,
    HoverCardContent,
    HoverCardTrigger,
} from '@/components/ui/hover-card';
import { Input } from '@/components/ui/input';
import {
    InputGroup,
    InputGroupAddon,
    InputGroupInput,
    InputGroupText,
} from '@/components/ui/input-group';
import {
    InputOTP,
    InputOTPGroup,
    InputOTPSeparator,
    InputOTPSlot,
} from '@/components/ui/input-otp';
import {
    Item,
    ItemActions,
    ItemContent,
    ItemDescription,
    ItemMedia,
    ItemTitle,
} from '@/components/ui/item';
import { Kbd, KbdGroup } from '@/components/ui/kbd';
import { Label } from '@/components/ui/label';
import {
    Menubar,
    MenubarContent,
    MenubarItem,
    MenubarMenu,
    MenubarSeparator,
    MenubarTrigger,
} from '@/components/ui/menubar';
import { MultiSelect } from '@/components/ui/multi-select';
import {
    Pagination,
    PaginationContent,
    PaginationEllipsis,
    PaginationItem,
    PaginationLink,
    PaginationNext,
    PaginationPrevious,
} from '@/components/ui/pagination';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { Progress } from '@/components/ui/progress';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import {
    ResizableHandle,
    ResizablePanel,
    ResizablePanelGroup,
} from '@/components/ui/resizable';
import { ScrollArea } from '@/components/ui/scroll-area';
import { Select } from '@/components/ui/select';
import { Separator } from '@/components/ui/separator';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
    SheetTrigger,
} from '@/components/ui/sheet';
import { Skeleton } from '@/components/ui/skeleton';
import { Slider } from '@/components/ui/slider';
import { Spinner } from '@/components/ui/spinner';
import { Switch } from '@/components/ui/switch';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Textarea } from '@/components/ui/textarea';
import { Toggle } from '@/components/ui/toggle';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';

const availableComponents = [
    'Accordion',
    'Alert',
    'Alert Dialog',
    'Aspect Ratio',
    'Attachment',
    'Avatar',
    'Badge',
    'Breadcrumb',
    'Bubble',
    'Button',
    'Button Group',
    'Calendar',
    'Card',
    'Carousel',
    'Chart',
    'Checkbox',
    'Collapsible',
    'Combobox',
    'Command',
    'Context Menu',
    'Data Table',
    'Dialog',
    'Direction',
    'Drawer',
    'Dropdown Menu',
    'Empty',
    'Field',
    'Form',
    'Hover Card',
    'Input',
    'Input Group',
    'Input OTP',
    'Item',
    'Kbd',
    'Label',
    'Marker',
    'Menubar',
    'Message',
    'Message Scroller',
    'Native Select',
    'Navigation Menu',
    'Pagination',
    'Popover',
    'Progress',
    'Radio Group',
    'Resizable',
    'Scroll Area',
    'Select',
    'Separator',
    'Sheet',
    'Sidebar',
    'Skeleton',
    'Slider',
    'Sonner',
    'Spinner',
    'Switch',
    'Table',
    'Tabs',
    'Textarea',
    'Toggle',
    'Toggle Group',
    'Tooltip',
];

type Invoice = {
    invoice: string;
    customer: string;
    status: string;
    amount: number;
};

const invoices: Invoice[] = [
    {
        invoice: 'INV-2401',
        customer: 'Acme Inc.',
        status: 'Paid',
        amount: 4200,
    },
    {
        invoice: 'INV-2402',
        customer: 'Northstar',
        status: 'Pending',
        amount: 8750,
    },
    {
        invoice: 'INV-2403',
        customer: 'Globex',
        status: 'Overdue',
        amount: 2100,
    },
];

const invoiceColumns: DataTableColumn<Invoice>[] = [
    {
        id: 'invoice',
        header: 'Invoice',
        cell: (row) => row.invoice,
        sortValue: (row) => row.invoice,
        width: 170,
    },
    {
        id: 'customer',
        header: 'Customer',
        cell: (row) => row.customer,
        sortValue: (row) => row.customer,
        width: 220,
    },
    {
        id: 'status',
        header: 'Status',
        cell: (row) => <Badge variant="outline">{row.status}</Badge>,
        sortValue: (row) => row.status,
        width: 150,
    },
    {
        id: 'amount',
        header: 'Amount',
        cell: (row) => `$${row.amount.toLocaleString('en-US')}`,
        sortValue: (row) => row.amount,
        width: 160,
        align: 'right',
    },
];

const invoiceActions: DataTableRowAction[] = [
    { id: 'view', label: 'View details' },
    { id: 'edit', label: 'Edit invoice' },
    {
        id: 'delete',
        label: 'Delete invoice',
        destructive: true,
        separatorBefore: true,
    },
];

function DemoCard({
    title,
    description,
    children,
    className = '',
}: {
    title: string;
    description: string;
    children: React.ReactNode;
    className?: string;
}) {
    return (
        <Card className={className}>
            <CardHeader>
                <CardTitle>{title}</CardTitle>
                <CardDescription>{description}</CardDescription>
            </CardHeader>
            <CardContent>{children}</CardContent>
        </Card>
    );
}

export default function UiPlayground() {
    const [date, setDate] = useState<Date | undefined>(new Date());
    const [advancedOpen, setAdvancedOpen] = useState(false);

    return (
        <>
            <Head title="UI Playground" />
            <main className="min-h-screen bg-muted/30">
                <div className="flex w-full flex-col gap-4 p-4">
                    <header className="overflow-hidden rounded-lg border bg-background p-5 shadow-sm">
                        <div className="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                            <div className="max-w-3xl space-y-3">
                                <Badge variant="secondary">
                                    <Sparkles className="mr-1 size-3" />
                                    Boilerplate design lab
                                </Badge>
                                <h1 className="text-4xl font-bold tracking-tight md:text-5xl">
                                    Shadcn UI Playground
                                </h1>
                                <p className="text-lg text-muted-foreground">
                                    Satu halaman untuk menguji warna, radius,
                                    spacing, typography, dan interaction sebelum
                                    dipakai di aplikasi.
                                </p>
                            </div>
                            <div className="flex flex-wrap gap-2">
                                <Button
                                    onClick={() =>
                                        toast.success('Komponen siap digunakan')
                                    }
                                >
                                    Test toast
                                </Button>
                                <Button
                                    variant="outline"
                                    onClick={() =>
                                        document.documentElement.classList.toggle(
                                            'dark',
                                        )
                                    }
                                >
                                    Toggle dark mode
                                </Button>
                            </div>
                        </div>
                    </header>

                    <DemoCard
                        title="Component inventory"
                        description={`${availableComponents.length} kelompok komponen tersedia di folder UI.`}
                    >
                        <div className="flex flex-wrap gap-2">
                            {availableComponents.map((component) => (
                                <Badge key={component} variant="outline">
                                    {component}
                                </Badge>
                            ))}
                        </div>
                    </DemoCard>

                    <div className="grid gap-4 xl:grid-cols-2">
                        <DemoCard
                            title="Buttons & actions"
                            description="Variants, grouped actions, toggles, dan shortcut."
                        >
                            <div className="space-y-5">
                                <div className="flex flex-wrap gap-2">
                                    <Button>Primary</Button>
                                    <Button variant="secondary">
                                        Secondary
                                    </Button>
                                    <Button variant="outline">Outline</Button>
                                    <Button variant="ghost">Ghost</Button>
                                    <Button variant="destructive">
                                        Delete
                                    </Button>
                                    <Button size="icon" aria-label="Settings">
                                        <Settings />
                                    </Button>
                                </div>
                                <ButtonGroup>
                                    <Button variant="outline">Draft</Button>
                                    <Button variant="outline">Preview</Button>
                                    <Button variant="outline">Publish</Button>
                                </ButtonGroup>
                                <div className="flex flex-wrap items-center gap-3">
                                    <Toggle aria-label="Toggle notifications">
                                        <Bell /> Alerts
                                    </Toggle>
                                    <ToggleGroup
                                        type="single"
                                        defaultValue="month"
                                    >
                                        <ToggleGroupItem value="day">
                                            Day
                                        </ToggleGroupItem>
                                        <ToggleGroupItem value="week">
                                            Week
                                        </ToggleGroupItem>
                                        <ToggleGroupItem value="month">
                                            Month
                                        </ToggleGroupItem>
                                    </ToggleGroup>
                                    <KbdGroup>
                                        <Kbd>Ctrl</Kbd>
                                        <span>+</span>
                                        <Kbd>K</Kbd>
                                    </KbdGroup>
                                </div>
                            </div>
                        </DemoCard>

                        <DemoCard
                            title="Form controls"
                            description="Input, select, checkbox, switch, slider, dan field composition."
                        >
                            <FieldGroup>
                                <Field>
                                    <Input id="company" label="Company name" />
                                    <FieldDescription>
                                        Nama yang tampil pada dokumen resmi.
                                    </FieldDescription>
                                </Field>
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <Select
                                        items={[
                                            'Finance',
                                            'Operations',
                                            'People',
                                        ]}
                                        defaultValue="Finance"
                                        placeholder="Department"
                                        searchPlaceholder="Search department..."
                                    />
                                    <MultiSelect
                                        items={[
                                            'Active',
                                            'Draft',
                                            'Archived',
                                            'Suspended',
                                        ]}
                                        defaultValue={['Active', 'Draft']}
                                        placeholder="Select statuses"
                                        searchPlaceholder="Search statuses..."
                                    />
                                </div>
                                <InputGroup>
                                    <InputGroupAddon>
                                        <InputGroupText>
                                            <Mail /> email
                                        </InputGroupText>
                                    </InputGroupAddon>
                                    <InputGroupInput placeholder="name@company.com" />
                                </InputGroup>
                                <Textarea placeholder="Catatan tambahan..." />
                                <div className="flex flex-wrap gap-6">
                                    <Label className="flex items-center gap-2">
                                        <Checkbox defaultChecked /> Remember
                                        selection
                                    </Label>
                                    <Label className="flex items-center gap-2">
                                        <Switch defaultChecked /> Auto approval
                                    </Label>
                                </div>
                                <Slider
                                    defaultValue={[64]}
                                    max={100}
                                    step={1}
                                />
                                <RadioGroup
                                    defaultValue="monthly"
                                    className="flex gap-5"
                                >
                                    <Label className="flex items-center gap-2">
                                        <RadioGroupItem value="monthly" />{' '}
                                        Monthly
                                    </Label>
                                    <Label className="flex items-center gap-2">
                                        <RadioGroupItem value="yearly" /> Yearly
                                    </Label>
                                </RadioGroup>
                            </FieldGroup>
                        </DemoCard>

                        <DemoCard
                            title="Feedback & loading"
                            description="Alert, progress, skeleton, spinner, badges, dan toast."
                        >
                            <div className="space-y-5">
                                <Alert>
                                    <CircleAlert />
                                    <AlertTitle>Review required</AlertTitle>
                                    <AlertDescription>
                                        Three purchase orders need approval
                                        today.
                                    </AlertDescription>
                                </Alert>
                                <div className="space-y-2">
                                    <div className="flex justify-between text-sm">
                                        <span>Monthly closing</span>
                                        <span>72%</span>
                                    </div>
                                    <Progress value={72} />
                                </div>
                                <div className="flex items-center gap-3 rounded-lg border p-4">
                                    <Spinner />
                                    <div className="flex-1 space-y-2">
                                        <Skeleton className="h-4 w-3/4" />
                                        <Skeleton className="h-3 w-1/2" />
                                    </div>
                                </div>
                                <div className="flex flex-wrap gap-2">
                                    <Badge>New</Badge>
                                    <Badge variant="secondary">Pending</Badge>
                                    <Badge variant="destructive">Overdue</Badge>
                                    <Badge variant="outline">Archived</Badge>
                                </div>
                            </div>
                        </DemoCard>

                        <DemoCard
                            title="Dialogs & floating UI"
                            description="Modal, confirmation, sheet, popover, dropdown, hover card, dan tooltip."
                        >
                            <div className="flex flex-wrap gap-2">
                                <Dialog>
                                    <DialogTrigger asChild>
                                        <Button variant="outline">
                                            Open dialog
                                        </Button>
                                    </DialogTrigger>
                                    <DialogContent>
                                        <DialogHeader>
                                            <DialogTitle>
                                                Edit customer
                                            </DialogTitle>
                                            <DialogDescription>
                                                Update customer information from
                                                this modal.
                                            </DialogDescription>
                                        </DialogHeader>
                                        <Input label="Customer name" />
                                    </DialogContent>
                                </Dialog>
                                <AlertDialog>
                                    <AlertDialogTrigger asChild>
                                        <Button variant="destructive">
                                            Confirm delete
                                        </Button>
                                    </AlertDialogTrigger>
                                    <AlertDialogContent>
                                        <AlertDialogHeader>
                                            <AlertDialogTitle>
                                                Delete this record?
                                            </AlertDialogTitle>
                                            <AlertDialogDescription>
                                                This action cannot be undone.
                                            </AlertDialogDescription>
                                        </AlertDialogHeader>
                                        <AlertDialogFooter>
                                            <AlertDialogCancel>
                                                Cancel
                                            </AlertDialogCancel>
                                            <AlertDialogAction>
                                                Continue
                                            </AlertDialogAction>
                                        </AlertDialogFooter>
                                    </AlertDialogContent>
                                </AlertDialog>
                                <Sheet>
                                    <SheetTrigger asChild>
                                        <Button variant="outline">
                                            Open sheet
                                        </Button>
                                    </SheetTrigger>
                                    <SheetContent>
                                        <SheetHeader>
                                            <SheetTitle>
                                                Quick settings
                                            </SheetTitle>
                                            <SheetDescription>
                                                Configure this workspace.
                                            </SheetDescription>
                                        </SheetHeader>
                                    </SheetContent>
                                </Sheet>
                                <Popover>
                                    <PopoverTrigger asChild>
                                        <Button variant="outline">
                                            Popover
                                        </Button>
                                    </PopoverTrigger>
                                    <PopoverContent className="space-y-2">
                                        <Input
                                            id="budget"
                                            label="Budget limit"
                                            defaultValue="25,000,000"
                                        />
                                    </PopoverContent>
                                </Popover>
                                <DropdownMenu>
                                    <DropdownMenuTrigger asChild>
                                        <Button variant="outline">
                                            Actions
                                        </Button>
                                    </DropdownMenuTrigger>
                                    <DropdownMenuContent>
                                        <DropdownMenuLabel>
                                            Record
                                        </DropdownMenuLabel>
                                        <DropdownMenuSeparator />
                                        <DropdownMenuItem>
                                            Edit
                                        </DropdownMenuItem>
                                        <DropdownMenuItem>
                                            Duplicate
                                        </DropdownMenuItem>
                                        <DropdownMenuCheckboxItem checked>
                                            Pin record
                                        </DropdownMenuCheckboxItem>
                                    </DropdownMenuContent>
                                </DropdownMenu>
                                <HoverCard>
                                    <HoverCardTrigger asChild>
                                        <Button variant="link">Hover me</Button>
                                    </HoverCardTrigger>
                                    <HoverCardContent>
                                        <div className="flex gap-3">
                                            <Avatar>
                                                <AvatarFallback>
                                                    UI
                                                </AvatarFallback>
                                            </Avatar>
                                            <div>
                                                <p className="font-medium">
                                                    Design system
                                                </p>
                                                <p className="text-sm text-muted-foreground">
                                                    Shared component library.
                                                </p>
                                            </div>
                                        </div>
                                    </HoverCardContent>
                                </HoverCard>
                                <Tooltip>
                                    <TooltipTrigger asChild>
                                        <Button size="icon" variant="outline">
                                            <Search />
                                        </Button>
                                    </TooltipTrigger>
                                    <TooltipContent>
                                        Search records
                                    </TooltipContent>
                                </Tooltip>
                            </div>
                        </DemoCard>

                        <DemoCard
                            title="Navigation"
                            description="Breadcrumb, tabs, menubar, pagination, accordion, dan collapsible."
                        >
                            <div className="space-y-6">
                                <Breadcrumb>
                                    <BreadcrumbList>
                                        <BreadcrumbItem>
                                            <BreadcrumbLink href="#">
                                                Workspace
                                            </BreadcrumbLink>
                                        </BreadcrumbItem>
                                        <BreadcrumbSeparator />
                                        <BreadcrumbItem>
                                            <BreadcrumbLink href="#">
                                                Finance
                                            </BreadcrumbLink>
                                        </BreadcrumbItem>
                                        <BreadcrumbSeparator />
                                        <BreadcrumbItem>
                                            <BreadcrumbPage>
                                                Invoices
                                            </BreadcrumbPage>
                                        </BreadcrumbItem>
                                    </BreadcrumbList>
                                </Breadcrumb>
                                <Menubar>
                                    <MenubarMenu>
                                        <MenubarTrigger>File</MenubarTrigger>
                                        <MenubarContent>
                                            <MenubarItem>
                                                New invoice
                                            </MenubarItem>
                                            <MenubarItem>Import</MenubarItem>
                                            <MenubarSeparator />
                                            <MenubarItem>Export</MenubarItem>
                                        </MenubarContent>
                                    </MenubarMenu>
                                    <MenubarMenu>
                                        <MenubarTrigger>View</MenubarTrigger>
                                        <MenubarContent>
                                            <MenubarItem>Compact</MenubarItem>
                                            <MenubarItem>
                                                Comfortable
                                            </MenubarItem>
                                        </MenubarContent>
                                    </MenubarMenu>
                                </Menubar>
                                <Tabs defaultValue="overview">
                                    <TabsList>
                                        <TabsTrigger value="overview">
                                            Overview
                                        </TabsTrigger>
                                        <TabsTrigger value="activity">
                                            Activity
                                        </TabsTrigger>
                                        <TabsTrigger value="settings">
                                            Settings
                                        </TabsTrigger>
                                    </TabsList>
                                    <TabsContent
                                        value="overview"
                                        className="rounded-lg border p-4 text-sm"
                                    >
                                        Overview content and summary metrics.
                                    </TabsContent>
                                    <TabsContent
                                        value="activity"
                                        className="rounded-lg border p-4 text-sm"
                                    >
                                        Recent activity appears here.
                                    </TabsContent>
                                    <TabsContent
                                        value="settings"
                                        className="rounded-lg border p-4 text-sm"
                                    >
                                        Module configuration.
                                    </TabsContent>
                                </Tabs>
                                <Accordion type="single" collapsible>
                                    <AccordionItem value="one">
                                        <AccordionTrigger>
                                            What is this playground?
                                        </AccordionTrigger>
                                        <AccordionContent>
                                            A safe place to customize components
                                            before production use.
                                        </AccordionContent>
                                    </AccordionItem>
                                </Accordion>
                                <Collapsible
                                    open={advancedOpen}
                                    onOpenChange={setAdvancedOpen}
                                >
                                    <CollapsibleTrigger asChild>
                                        <Button
                                            variant="ghost"
                                            className="w-full justify-between"
                                        >
                                            Advanced filters{' '}
                                            <ChevronRight
                                                className={
                                                    advancedOpen
                                                        ? 'rotate-90 transition-transform'
                                                        : 'transition-transform'
                                                }
                                            />
                                        </Button>
                                    </CollapsibleTrigger>
                                    <CollapsibleContent className="rounded-lg border p-3 text-sm text-muted-foreground">
                                        Additional filtering options would live
                                        here.
                                    </CollapsibleContent>
                                </Collapsible>
                                <Pagination>
                                    <PaginationContent>
                                        <PaginationItem>
                                            <PaginationPrevious href="#" />
                                        </PaginationItem>
                                        <PaginationItem>
                                            <PaginationLink href="#" isActive>
                                                1
                                            </PaginationLink>
                                        </PaginationItem>
                                        <PaginationItem>
                                            <PaginationLink href="#">
                                                2
                                            </PaginationLink>
                                        </PaginationItem>
                                        <PaginationItem>
                                            <PaginationEllipsis />
                                        </PaginationItem>
                                        <PaginationItem>
                                            <PaginationNext href="#" />
                                        </PaginationItem>
                                    </PaginationContent>
                                </Pagination>
                            </div>
                        </DemoCard>

                        <DemoCard
                            title="Data display"
                            description="Klik header untuk sort, drag batas kolom untuk resize, dan klik kanan pada baris."
                            className="xl:col-span-2"
                        >
                            <div className="space-y-5">
                                <DataTable
                                    columns={invoiceColumns}
                                    data={invoices}
                                    getRowKey={(row) => row.invoice}
                                    getRowLabel={(row) => row.invoice}
                                    actions={invoiceActions}
                                    onRowAction={(action, row) =>
                                        toast(`${action}: ${row.invoice}`)
                                    }
                                />
                                <ScrollArea className="h-40 rounded-lg border">
                                    <div className="p-2">
                                        {[
                                            'Finance workspace',
                                            'Inventory workspace',
                                            'Human resources',
                                            'Sales pipeline',
                                        ].map((name, index) => (
                                            <div key={name}>
                                                <Item>
                                                    <ItemMedia variant="icon">
                                                        <Database />
                                                    </ItemMedia>
                                                    <ItemContent>
                                                        <ItemTitle>
                                                            {name}
                                                        </ItemTitle>
                                                        <ItemDescription>
                                                            Updated {index + 1}{' '}
                                                            hour ago
                                                        </ItemDescription>
                                                    </ItemContent>
                                                    <ItemActions>
                                                        <Button
                                                            size="sm"
                                                            variant="ghost"
                                                        >
                                                            Open
                                                        </Button>
                                                    </ItemActions>
                                                </Item>
                                                {index < 3 && <Separator />}
                                            </div>
                                        ))}
                                    </div>
                                </ScrollArea>
                            </div>
                        </DemoCard>

                        <DemoCard
                            title="Calendar & OTP"
                            description="Date selection and segmented verification input."
                        >
                            <div className="grid gap-6 sm:grid-cols-2">
                                <Calendar
                                    mode="single"
                                    selected={date}
                                    onSelect={setDate}
                                    className="rounded-lg border"
                                />
                                <div className="space-y-5">
                                    <div>
                                        <Label>Verification code</Label>
                                        <InputOTP
                                            maxLength={6}
                                            className="mt-2"
                                        >
                                            <InputOTPGroup>
                                                <InputOTPSlot index={0} />
                                                <InputOTPSlot index={1} />
                                                <InputOTPSlot index={2} />
                                            </InputOTPGroup>
                                            <InputOTPSeparator />
                                            <InputOTPGroup>
                                                <InputOTPSlot index={3} />
                                                <InputOTPSlot index={4} />
                                                <InputOTPSlot index={5} />
                                            </InputOTPGroup>
                                        </InputOTP>
                                    </div>
                                    <Alert>
                                        <CalendarDays />
                                        <AlertTitle>Selected date</AlertTitle>
                                        <AlertDescription>
                                            {date?.toLocaleDateString(
                                                'en-US',
                                            ) ?? 'No date selected'}
                                        </AlertDescription>
                                    </Alert>
                                </div>
                            </div>
                        </DemoCard>

                        <DemoCard
                            title="Command palette"
                            description="Searchable action list commonly used in internal applications."
                        >
                            <Command className="rounded-lg border">
                                <CommandInput placeholder="Search modules..." />
                                <CommandList>
                                    <CommandEmpty>
                                        No module found.
                                    </CommandEmpty>
                                    <CommandGroup heading="Modules">
                                        <CommandItem>
                                            <CreditCard /> Finance
                                        </CommandItem>
                                        <CommandItem>
                                            <Box /> Inventory
                                        </CommandItem>
                                        <CommandItem>
                                            <User /> Human Resources
                                        </CommandItem>
                                        <CommandItem>
                                            <Settings /> Administration
                                        </CommandItem>
                                    </CommandGroup>
                                </CommandList>
                            </Command>
                        </DemoCard>

                        <DemoCard
                            title="Resizable workspace"
                            description="Layout primitive for dashboards and split editors."
                        >
                            <ResizablePanelGroup
                                orientation="horizontal"
                                className="min-h-56 rounded-lg border"
                            >
                                <ResizablePanel defaultSize={35}>
                                    <div className="flex h-full items-center justify-center bg-muted/40 p-6 text-sm">
                                        Navigation
                                    </div>
                                </ResizablePanel>
                                <ResizableHandle withHandle />
                                <ResizablePanel defaultSize={65}>
                                    <div className="flex h-full items-center justify-center p-6 text-sm">
                                        Workspace content
                                    </div>
                                </ResizablePanel>
                            </ResizablePanelGroup>
                        </DemoCard>

                        <DemoCard
                            title="Carousel & aspect ratio"
                            description="Media layout and horizontally browsable cards."
                        >
                            <div className="space-y-6">
                                <AspectRatio
                                    ratio={16 / 5}
                                    className="overflow-hidden rounded-xl bg-gradient-to-r from-primary/20 via-primary/5 to-background"
                                >
                                    <div className="flex h-full items-center justify-center">
                                        <PackageOpen className="size-12 text-primary" />
                                    </div>
                                </AspectRatio>
                                <Carousel className="mx-10">
                                    <CarouselContent>
                                        {['Finance', 'Inventory', 'People'].map(
                                            (item) => (
                                                <CarouselItem key={item}>
                                                    <Card>
                                                        <CardContent className="flex h-28 items-center justify-center text-xl font-semibold">
                                                            {item}
                                                        </CardContent>
                                                    </Card>
                                                </CarouselItem>
                                            ),
                                        )}
                                    </CarouselContent>
                                    <CarouselPrevious />
                                    <CarouselNext />
                                </Carousel>
                            </div>
                        </DemoCard>

                        <DemoCard
                            title="Empty state"
                            description="Recommended place for optional illustration or Lottie animation."
                            className="xl:col-span-2"
                        >
                            <Empty className="border">
                                <EmptyHeader>
                                    <EmptyMedia variant="icon">
                                        <PackageOpen />
                                    </EmptyMedia>
                                    <EmptyTitle>
                                        No purchase orders yet
                                    </EmptyTitle>
                                    <EmptyDescription>
                                        Create the first purchase order or
                                        import existing records.
                                    </EmptyDescription>
                                </EmptyHeader>
                                <EmptyContent>
                                    <div className="flex gap-2">
                                        <Button>Create order</Button>
                                        <Button variant="outline">
                                            Import CSV
                                        </Button>
                                    </div>
                                </EmptyContent>
                            </Empty>
                        </DemoCard>

                        <DemoCard
                            title="Lottie preview"
                            description="Slot preview untuk animasi Lottie berikutnya."
                        >
                            <Empty>
                                <EmptyHeader>
                                    <EmptyMedia variant="icon">
                                        <Sparkles />
                                    </EmptyMedia>
                                    <EmptyTitle>Animation preview</EmptyTitle>
                                    <EmptyDescription>
                                        Paste JSON atau URL Lottie untuk
                                        menampilkan preview di sini.
                                    </EmptyDescription>
                                </EmptyHeader>
                            </Empty>
                        </DemoCard>
                    </div>

                    <Card>
                        <CardHeader>
                            <CardTitle>Customization checkpoint</CardTitle>
                            <CardDescription>
                                Use this page while editing theme tokens in
                                resources/css/app.css.
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="grid gap-3 sm:grid-cols-3">
                            <div className="rounded-xl bg-primary p-5 text-primary-foreground">
                                Primary
                            </div>
                            <div className="rounded-xl bg-secondary p-5 text-secondary-foreground">
                                Secondary
                            </div>
                            <div className="rounded-xl bg-destructive p-5 text-destructive-foreground">
                                Destructive
                            </div>
                        </CardContent>
                        <CardFooter className="text-sm text-muted-foreground">
                            Route: /ui-playground
                        </CardFooter>
                    </Card>
                </div>
            </main>
        </>
    );
}
