import { Head } from "@inertiajs/react";
import {
  ArrowLeft,
  Bell,
  Box,
  CalendarDays,
  ChevronRight,
  CircleAlert,
  CreditCard,
  Database,
  Layers3,
  Maximize2,
  Minimize2,
  Mail,
  PackageOpen,
  PanelRight,
  Search,
  Settings,
  Sparkles,
  User,
} from "lucide-react";
import { useRef, useState } from "react";
import { toast } from "sonner";

import {
  Accordion,
  AccordionContent,
  AccordionItem,
  AccordionTrigger,
} from "@apperp/ui/accordion";
import { Alert, AlertDescription, AlertTitle } from "@apperp/ui/alert";
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
} from "@apperp/ui/alert-dialog";
import { AspectRatio } from "@apperp/ui/aspect-ratio";
import { Avatar, AvatarFallback } from "@apperp/ui/avatar";
import { Badge } from "@apperp/ui/badge";
import {
  Breadcrumb,
  BreadcrumbItem,
  BreadcrumbLink,
  BreadcrumbList,
  BreadcrumbPage,
  BreadcrumbSeparator,
} from "@apperp/ui/breadcrumb";
import { Button } from "@apperp/ui/button";
import { ButtonGroup } from "@apperp/ui/button-group";
import { Calendar } from "@apperp/ui/calendar";
import {
  Card,
  CardContent,
  CardDescription,
  CardFooter,
  CardHeader,
  CardTitle,
} from "@apperp/ui/card";
import {
  Carousel,
  CarouselContent,
  CarouselItem,
  CarouselNext,
  CarouselPrevious,
} from "@apperp/ui/carousel";
import { Checkbox } from "@apperp/ui/checkbox";
import {
  Collapsible,
  CollapsibleContent,
  CollapsibleTrigger,
} from "@apperp/ui/collapsible";
import {
  Command,
  CommandEmpty,
  CommandGroup,
  CommandInput,
  CommandItem,
  CommandList,
} from "@apperp/ui/command";
import {
  Dialog,
  DialogAction,
  DialogBody,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
  DialogToolbar,
  DialogTrigger,
} from "@apperp/ui/dialog";
import { DataTable } from "@apperp/ui/data-table";
import type {
  DataTableColumn,
  DataTableRowAction,
} from "@apperp/ui/data-table";
import {
  DropdownMenu,
  DropdownMenuCheckboxItem,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from "@apperp/ui/dropdown-menu";
import {
  Empty,
  EmptyContent,
  EmptyDescription,
  EmptyHeader,
  EmptyMedia,
  EmptyTitle,
} from "@apperp/ui/empty";
import { Field, FieldDescription, FieldGroup } from "@apperp/ui/field";
import {
  HoverCard,
  HoverCardContent,
  HoverCardTrigger,
} from "@apperp/ui/hover-card";
import { Input } from "@apperp/ui/input";
import {
  InputGroup,
  InputGroupAddon,
  InputGroupInput,
  InputGroupText,
} from "@apperp/ui/input-group";
import {
  InputOTP,
  InputOTPGroup,
  InputOTPSeparator,
  InputOTPSlot,
} from "@apperp/ui/input-otp";
import {
  Item,
  ItemActions,
  ItemContent,
  ItemDescription,
  ItemMedia,
  ItemTitle,
} from "@apperp/ui/item";
import { Kbd, KbdGroup } from "@apperp/ui/kbd";
import { Label } from "@apperp/ui/label";
import {
  Menubar,
  MenubarContent,
  MenubarItem,
  MenubarMenu,
  MenubarSeparator,
  MenubarTrigger,
} from "@apperp/ui/menubar";
import { MultiSelect } from "@apperp/ui/multi-select";
import {
  Pagination,
  PaginationContent,
  PaginationEllipsis,
  PaginationItem,
  PaginationLink,
  PaginationNext,
  PaginationPrevious,
} from "@apperp/ui/pagination";
import { Popover, PopoverContent, PopoverTrigger } from "@apperp/ui/popover";
import { Progress } from "@apperp/ui/progress";
import { RadioGroup, RadioGroupItem } from "@apperp/ui/radio-group";
import {
  ResizableHandle,
  ResizablePanel,
  ResizablePanelGroup,
} from "@apperp/ui/resizable";
import { ScrollArea } from "@apperp/ui/scroll-area";
import { Select } from "@apperp/ui/select";
import { Separator } from "@apperp/ui/separator";
import {
  Sheet,
  SheetContent,
  SheetDescription,
  SheetHeader,
  SheetTitle,
  SheetTrigger,
} from "@apperp/ui/sheet";
import { Skeleton } from "@apperp/ui/skeleton";
import { Slider } from "@apperp/ui/slider";
import { Spinner } from "@apperp/ui/spinner";
import { Switch } from "@apperp/ui/switch";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@apperp/ui/tabs";
import { Textarea } from "@apperp/ui/textarea";
import { Toggle } from "@apperp/ui/toggle";
import { ToggleGroup, ToggleGroupItem } from "@apperp/ui/toggle-group";
import { Tooltip, TooltipContent, TooltipTrigger } from "@apperp/ui/tooltip";
import { cn } from "@/lib/utils";

const availableComponents = [
  "Accordion",
  "Alert",
  "Alert Dialog",
  "Aspect Ratio",
  "Attachment",
  "Avatar",
  "Badge",
  "Breadcrumb",
  "Bubble",
  "Button",
  "Button Group",
  "Calendar",
  "Card",
  "Carousel",
  "Chart",
  "Checkbox",
  "Collapsible",
  "Combobox",
  "Command",
  "Context Menu",
  "Data Table",
  "Dialog",
  "Direction",
  "Drawer",
  "Dropdown Menu",
  "Empty",
  "Field",
  "Form",
  "Hover Card",
  "Input",
  "Input Group",
  "Input OTP",
  "Item",
  "Kbd",
  "Label",
  "Marker",
  "Menubar",
  "Message",
  "Message Scroller",
  "Native Select",
  "Navigation Menu",
  "Pagination",
  "Popover",
  "Progress",
  "Radio Group",
  "Resizable",
  "Scroll Area",
  "Select",
  "Separator",
  "Sheet",
  "Sidebar",
  "Skeleton",
  "Slider",
  "Sonner",
  "Spinner",
  "Switch",
  "Table",
  "Tabs",
  "Textarea",
  "Toggle",
  "Toggle Group",
  "Tooltip",
];

type Invoice = {
  invoice: string;
  customer: string;
  status: string;
  amount: number;
};

const invoices: Invoice[] = [
  {
    invoice: "INV-2401",
    customer: "Acme Inc.",
    status: "Paid",
    amount: 4200,
  },
  {
    invoice: "INV-2402",
    customer: "Northstar",
    status: "Pending",
    amount: 8750,
  },
  {
    invoice: "INV-2403",
    customer: "Globex",
    status: "Overdue",
    amount: 2100,
  },
];

const invoiceColumns: DataTableColumn<Invoice>[] = [
  {
    id: "invoice",
    header: "Invoice",
    cell: (row) => row.invoice,
    sortValue: (row) => row.invoice,
    width: 170,
  },
  {
    id: "customer",
    header: "Customer",
    cell: (row) => row.customer,
    sortValue: (row) => row.customer,
    width: 220,
  },
  {
    id: "status",
    header: "Status",
    cell: (row) => <Badge variant="outline">{row.status}</Badge>,
    sortValue: (row) => row.status,
    width: 150,
  },
  {
    id: "amount",
    header: "Amount",
    cell: (row) => `$${row.amount.toLocaleString("en-US")}`,
    sortValue: (row) => row.amount,
    width: 160,
    align: "right",
  },
];

const invoiceActions: DataTableRowAction[] = [
  { id: "view", label: "View details" },
  { id: "edit", label: "Edit invoice" },
  {
    id: "delete",
    label: "Delete invoice",
    destructive: true,
    separatorBefore: true,
  },
];

function DemoCard({
  title,
  description,
  children,
  className = "",
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

const modalPageRows = [
  ["Barang", "ITEM-001", "Item contoh", "2", "0,00"],
  ["Akun", "ACC-001", "Layanan contoh", "1", "0,00"],
  ["Barang", "ITEM-002", "Perlengkapan contoh", "4", "0,00"],
] as const;

const modalPageFactBoxRows = [
  ["No. dokumen", "DOC-0001"],
  ["Status", "Tersimpan"],
  ["Jumlah baris", "3"],
  ["Nilai bersih", "0,00"],
] as const;

const visibleModalStackLimit = 4;
const defaultModalStackStride = 7;
const expandedModalRailWidth = 7;

function ModalPageSurface({
  layerNumber,
  isActive,
  expanded,
  factBoxOpen,
  overlayContentRef,
  onBack,
  onNext,
  onToggleExpanded,
  onToggleFactBox,
}: {
  layerNumber: number;
  isActive: boolean;
  expanded: boolean;
  factBoxOpen: boolean;
  overlayContentRef: React.RefObject<HTMLDivElement | null>;
  onBack: () => void;
  onNext: () => void;
  onToggleExpanded: () => void;
  onToggleFactBox: () => void;
}) {
  const title = "Dokumen contoh";

  return (
    <div
      aria-hidden={!isActive}
      className="relative flex h-full min-h-0 flex-col overflow-hidden bg-background"
      inert={!isActive ? true : undefined}
    >
      <DialogHeader
        className={cn(
          "grid min-h-16 items-center gap-0 border-b p-0",
          isActive
            ? "grid-cols-[4.25rem_minmax(0,1fr)_4.25rem]"
            : "grid-cols-[1rem_minmax(0,1fr)_1rem]",
        )}
      >
        <div className="flex justify-center">
          <Button
            type="button"
            size="icon"
            variant="ghost"
            aria-label="Kembali ke halaman sebelumnya"
            title="Kembali ke halaman sebelumnya"
            onClick={onBack}
          >
            <ArrowLeft />
          </Button>
        </div>
        <div className="min-w-0">
          {isActive ? (
            <DialogTitle>{title}</DialogTitle>
          ) : (
            <h2 className="text-lg leading-tight font-semibold">{title}</h2>
          )}
          {isActive ? (
            <DialogDescription>
              Modal page dengan tinggi tetap, footer tetap, dan halaman
              sebelumnya yang tersimpan di stack.
            </DialogDescription>
          ) : (
            <p className="text-sm text-muted-foreground">
              Halaman ini berada di bawah layer aktif.
            </p>
          )}
        </div>
      </DialogHeader>

      <DialogToolbar
        className={cn(
          "grid min-h-14 items-center border-b p-0",
          isActive
            ? "grid-cols-[4.25rem_minmax(0,1fr)_4.25rem]"
            : "grid-cols-[1rem_minmax(0,1fr)_1rem]",
        )}
      >
        <div className="col-start-2 flex min-w-0 items-center gap-3">
          <Badge variant="outline">Modal page</Badge>
          <div className="ml-auto flex flex-wrap items-center gap-2">
            <Button
              type="button"
              size="sm"
              variant="outline"
              onClick={onToggleFactBox}
            >
              <PanelRight />
              {factBoxOpen ? "Sembunyikan FactBox" : "Tampilkan FactBox"}
            </Button>
            <Button
              type="button"
              size="sm"
              variant="outline"
              className="hidden sm:inline-flex"
              aria-label={
                expanded
                  ? "Kembalikan ukuran halaman"
                  : "Perbesar halaman sampai batas viewport"
              }
              title={
                expanded
                  ? "Kembalikan ukuran halaman"
                  : "Perbesar halaman sampai batas viewport"
              }
              onClick={onToggleExpanded}
            >
              {expanded ? <Minimize2 /> : <Maximize2 />}
              {expanded ? "Kembalikan ukuran" : "Perbesar"}
            </Button>
          </div>
        </div>
      </DialogToolbar>

      <DialogBody className="min-h-0 flex-1 overflow-hidden p-0">
        <div
          className={cn(
            "grid h-full min-h-0 bg-muted/20",
            isActive
              ? "grid-cols-[minmax(1rem,4.25rem)_minmax(0,1fr)_minmax(1rem,4.25rem)]"
              : "grid-cols-[1rem_minmax(0,1fr)_1rem]",
          )}
        >
          <div className="col-start-2 flex min-h-0 min-w-0 overflow-hidden">
            <div className="min-h-0 min-w-0 flex-1 overflow-auto py-6 pr-6">
              <div
                className={cn(
                  "space-y-6",
                  expanded || !isActive
                    ? "w-full max-w-none"
                    : "mx-auto max-w-5xl",
                )}
              >
                <div className="flex flex-wrap items-end justify-between gap-3">
                  <div>
                    <p className="text-sm text-muted-foreground">
                      Halaman dokumen
                    </p>
                    <h2 className="text-2xl font-semibold tracking-tight">
                      Transaksi contoh
                    </h2>
                  </div>
                  <Badge variant="secondary">
                    {expanded ? "Lebar maksimum" : "Lebar default"}
                  </Badge>
                </div>

                <div className="grid gap-4 md:grid-cols-2">
                  <Input
                    id={`modal-rnd-document-${layerNumber}`}
                    label="Nomor dokumen"
                    defaultValue={`DOC-${String(layerNumber).padStart(4, "0")}`}
                  />
                  <Input
                    id={`modal-rnd-customer-${layerNumber}`}
                    label="Nama pelanggan"
                    defaultValue="Pelanggan contoh"
                  />
                  <Select
                    items={["Ringkasan", "Rincian", "Riwayat"]}
                    label="Mode tampilan"
                    defaultValue="Ringkasan"
                    portalContainer={overlayContentRef}
                  />
                  <Input
                    id={`modal-rnd-owner-${layerNumber}`}
                    label="Penanggung jawab"
                    defaultValue="Pengguna contoh"
                  />
                </div>

                <section className="space-y-3">
                  <div className="flex items-center justify-between gap-3">
                    <h3 className="text-lg font-semibold">Baris dokumen</h3>
                    <Button type="button" size="sm" variant="outline">
                      Tambah baris
                    </Button>
                  </div>
                  <div className="overflow-x-auto rounded-lg border">
                    <table className="w-full min-w-[640px] text-sm">
                      <thead className="bg-muted/40 text-left">
                        <tr className="border-b">
                          <th className="px-4 py-3 font-medium">Tipe</th>
                          <th className="px-4 py-3 font-medium">No.</th>
                          <th className="px-4 py-3 font-medium">Deskripsi</th>
                          <th className="px-4 py-3 text-right font-medium">
                            Kuantitas
                          </th>
                          <th className="px-4 py-3 text-right font-medium">
                            Jumlah
                          </th>
                          <th className="px-4 py-3 text-right font-medium">
                            Aksi
                          </th>
                        </tr>
                      </thead>
                      <tbody className="divide-y">
                        {modalPageRows.map((row) => (
                          <tr key={row[1]}>
                            <td className="px-4 py-3">{row[0]}</td>
                            <td className="px-4 py-3 font-medium">{row[1]}</td>
                            <td className="px-4 py-3">{row[2]}</td>
                            <td className="px-4 py-3 text-right">{row[3]}</td>
                            <td className="px-4 py-3 text-right">{row[4]}</td>
                            <td className="px-4 py-3 text-right">
                              <Button
                                type="button"
                                size="sm"
                                variant="ghost"
                                aria-label={`Buka halaman ${row[1]}`}
                                onClick={onNext}
                              >
                                <Layers3 />
                                Buka
                              </Button>
                            </td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  </div>
                </section>

                <section className="grid gap-4 md:grid-cols-2">
                  {[
                    [
                      "Informasi umum",
                      "Data utama halaman tetap di area content.",
                    ],
                    [
                      "Area kerja",
                      "Isi yang panjang menggulir tanpa menggeser header atau footer.",
                    ],
                    [
                      "Navigasi",
                      "Tombol panah kembali hanya mengurangi satu layer.",
                    ],
                    [
                      "Layer baru",
                      "Aksi di isi halaman membuka layer baru; halaman lama tetap tersimpan.",
                    ],
                  ].map(([heading, description]) => (
                    <div
                      key={heading}
                      className="rounded-lg border bg-muted/20 p-4"
                    >
                      <p className="font-medium">{heading}</p>
                      <p className="mt-1 text-sm text-muted-foreground">
                        {description}
                      </p>
                    </div>
                  ))}
                </section>
              </div>
            </div>

            {factBoxOpen && (
              <aside className="max-h-72 min-h-0 shrink-0 overflow-auto border-t bg-background p-5 lg:h-auto lg:max-h-none lg:w-80 lg:border-t-0 lg:border-l">
                <div className="space-y-5">
                  <div>
                    <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                      FactBox
                    </p>
                    <h3 className="mt-1 text-lg font-semibold">Ringkasan</h3>
                  </div>
                  <div className="space-y-3">
                    {modalPageFactBoxRows.map(([label, value]) => (
                      <div
                        key={label}
                        className="flex items-start justify-between gap-4 text-sm"
                      >
                        <span className="text-muted-foreground">{label}</span>
                        <span className="text-right font-medium">{value}</span>
                      </div>
                    ))}
                  </div>
                  <div className="border-t pt-4">
                    <p className="text-sm font-medium">Tentang FactBox</p>
                    <p className="mt-1 text-sm text-muted-foreground">
                      Ini adalah kolom internal halaman yang bisa ditampilkan
                      atau disembunyikan. Bukan Sheet dan bukan layer baru.
                    </p>
                  </div>
                </div>
              </aside>
            )}
          </div>
        </div>
      </DialogBody>

      <DialogFooter
        className={cn(
          "grid min-h-16 items-center border-t p-0",
          isActive
            ? "grid-cols-[4.25rem_minmax(0,1fr)_4.25rem]"
            : "grid-cols-[1rem_minmax(0,1fr)_1rem]",
        )}
      >
        <div className="col-start-2 flex items-center justify-between gap-3">
          <p className="hidden text-xs text-muted-foreground sm:block">
            Gunakan panah kiri untuk kembali satu layer.
          </p>
          <DialogAction
            type="button"
            onClick={() =>
              toast.success("Perubahan contoh disimpan sebagai notifikasi")
            }
          >
            Simpan contoh
          </DialogAction>
        </div>
      </DialogFooter>
      {!isActive && (
        <div
          aria-hidden="true"
          className="pointer-events-none absolute inset-0 z-40 bg-muted/40"
        />
      )}
    </div>
  );
}

export default function UiPlayground() {
  const [date, setDate] = useState<Date | undefined>(new Date());
  const [advancedOpen, setAdvancedOpen] = useState(false);
  const [modalLayerCount, setModalLayerCount] = useState(0);
  const [modalStackViewportLayers, setModalStackViewportLayers] = useState(1);
  const [modalExpanded, setModalExpanded] = useState(false);
  const [factBoxOpen, setFactBoxOpen] = useState(true);
  const [modalReturning, setModalReturning] = useState(false);
  const modalReturnTimerRef = useRef<ReturnType<typeof setTimeout> | null>(
    null,
  );
  const overlayContentRef = useRef<HTMLDivElement>(null);
  const visibleModalLayerCount = Math.min(
    modalLayerCount,
    visibleModalStackLimit,
  );
  const firstVisibleModalLayer = Math.max(
    1,
    modalLayerCount - visibleModalStackLimit + 1,
  );

  const openModalPage = (expanded: boolean) => {
    if (modalReturnTimerRef.current) {
      clearTimeout(modalReturnTimerRef.current);
      modalReturnTimerRef.current = null;
    }

    setModalReturning(false);
    setModalExpanded(expanded);
    setModalStackViewportLayers(1);
    setModalLayerCount(1);
  };

  const openNextModalPage = () => {
    if (modalReturning) {
      return;
    }

    setModalStackViewportLayers((current) =>
      Math.min(Math.max(current, modalLayerCount + 1), visibleModalStackLimit),
    );
    setModalLayerCount((current) => current + 1);
  };

  const goToPreviousModalPage = () => {
    if (modalReturning) {
      return;
    }

    if (modalLayerCount <= 1) {
      setModalStackViewportLayers(1);
      setModalLayerCount(0);

      return;
    }

    setModalReturning(true);
    modalReturnTimerRef.current = setTimeout(() => {
      setModalLayerCount((current) => Math.max(current - 1, 0));
      setModalReturning(false);
      modalReturnTimerRef.current = null;
    }, 160);
  };

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
                  Satu halaman untuk menguji warna, radius, spacing, typography,
                  dan interaction sebelum dipakai di aplikasi.
                </p>
              </div>
              <div className="flex flex-wrap gap-2">
                <Button
                  onClick={() => toast.success("Komponen siap digunakan")}
                >
                  Test toast
                </Button>
                <Button
                  variant="outline"
                  onClick={() =>
                    document.documentElement.classList.toggle("dark")
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
                  <Button variant="secondary">Secondary</Button>
                  <Button variant="outline">Outline</Button>
                  <Button variant="ghost">Ghost</Button>
                  <Button variant="destructive">Delete</Button>
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
                  <ToggleGroup type="single" defaultValue="month">
                    <ToggleGroupItem value="day">Day</ToggleGroupItem>
                    <ToggleGroupItem value="week">Week</ToggleGroupItem>
                    <ToggleGroupItem value="month">Month</ToggleGroupItem>
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
                    items={["Finance", "Operations", "People"]}
                    defaultValue="Finance"
                    placeholder="Department"
                    searchPlaceholder="Search department..."
                  />
                  <MultiSelect
                    items={["Active", "Draft", "Archived", "Suspended"]}
                    defaultValue={["Active", "Draft"]}
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
                    <Checkbox defaultChecked /> Remember selection
                  </Label>
                  <Label className="flex items-center gap-2">
                    <Switch defaultChecked /> Auto approval
                  </Label>
                </div>
                <Slider defaultValue={[64]} max={100} step={1} />
                <RadioGroup defaultValue="monthly" className="flex gap-5">
                  <Label className="flex items-center gap-2">
                    <RadioGroupItem value="monthly" /> Monthly
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
                    Three purchase orders need approval today.
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
                    <Button variant="outline">Open dialog</Button>
                  </DialogTrigger>
                  <DialogContent>
                    <DialogHeader>
                      <DialogTitle>Edit customer</DialogTitle>
                      <DialogDescription>
                        Update customer information from this modal.
                      </DialogDescription>
                    </DialogHeader>
                    <Input label="Customer name" />
                  </DialogContent>
                </Dialog>
                <AlertDialog>
                  <AlertDialogTrigger asChild>
                    <Button variant="destructive">Confirm delete</Button>
                  </AlertDialogTrigger>
                  <AlertDialogContent>
                    <AlertDialogHeader>
                      <AlertDialogTitle>Delete this record?</AlertDialogTitle>
                      <AlertDialogDescription>
                        This action cannot be undone.
                      </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                      <AlertDialogCancel>Cancel</AlertDialogCancel>
                      <AlertDialogAction>Continue</AlertDialogAction>
                    </AlertDialogFooter>
                  </AlertDialogContent>
                </AlertDialog>
                <Sheet>
                  <SheetTrigger asChild>
                    <Button variant="outline">Open sheet</Button>
                  </SheetTrigger>
                  <SheetContent>
                    <SheetHeader>
                      <SheetTitle>Quick settings</SheetTitle>
                      <SheetDescription>
                        Configure this workspace.
                      </SheetDescription>
                    </SheetHeader>
                  </SheetContent>
                </Sheet>
                <Popover>
                  <PopoverTrigger asChild>
                    <Button variant="outline">Popover</Button>
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
                    <Button variant="outline">Actions</Button>
                  </DropdownMenuTrigger>
                  <DropdownMenuContent>
                    <DropdownMenuLabel>Record</DropdownMenuLabel>
                    <DropdownMenuSeparator />
                    <DropdownMenuItem>Edit</DropdownMenuItem>
                    <DropdownMenuItem>Duplicate</DropdownMenuItem>
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
                        <AvatarFallback>UI</AvatarFallback>
                      </Avatar>
                      <div>
                        <p className="font-medium">Design system</p>
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
                  <TooltipContent>Search records</TooltipContent>
                </Tooltip>
              </div>
            </DemoCard>

            <DemoCard
              title="Modal Page Stack R&D"
              description="Eksperimen modal page ala Business Central: tinggi seragam, footer tetap, stack opaque, FactBox internal, dan mode responsif."
              className="xl:col-span-2"
            >
              <div className="space-y-5">
                <div className="grid gap-3 md:grid-cols-3">
                  <div className="rounded-lg border border-primary/30 bg-primary/5 p-4">
                    <div className="flex items-center justify-between gap-3">
                      <span className="text-sm font-medium">Default</span>
                      <Badge variant="outline">modal page</Badge>
                    </div>
                    <p className="mt-2 text-sm text-muted-foreground">
                      Ukuran halaman lebih sempit. Layer sebelumnya tetap
                      terlihat sebagai tumpukan di kiri.
                    </p>
                  </div>
                  <div className="rounded-lg border p-4">
                    <div className="flex items-center justify-between gap-3">
                      <span className="text-sm font-medium">Expanded</span>
                      <Badge variant="outline">lebar maksimum</Badge>
                    </div>
                    <p className="mt-2 text-sm text-muted-foreground">
                      Tinggi tetap sama, halaman memakai ruang horizontal lebih
                      luas, dan ruang stack tetap tersisa di kiri.
                    </p>
                  </div>
                  <div className="rounded-lg border p-4">
                    <div className="flex items-center justify-between gap-3">
                      <span className="text-sm font-medium">Stack opaque</span>
                      <Badge variant="outline">tanpa transparansi</Badge>
                    </div>
                    <p className="mt-2 text-sm text-muted-foreground">
                      Layer lama tetap opaque. Yang terlihat di kiri adalah rail
                      dan border, bukan isi halaman lama.
                    </p>
                  </div>
                </div>

                <div className="flex flex-wrap items-center gap-2">
                  <Button onClick={() => openModalPage(false)}>
                    <Layers3 />
                    Buka modal page
                  </Button>
                  <Button variant="outline" onClick={() => openModalPage(true)}>
                    <Maximize2 />
                    Buka expanded
                  </Button>
                </div>
                <p className="text-sm text-muted-foreground">
                  Di desktop, expand mengubah lebar saja. Di viewport kecil,
                  halaman otomatis memenuhi layar dan tombol expand menghilang.
                  FactBox di dalam halaman bisa dibuka atau disembunyikan.
                </p>

                <Dialog
                  open={modalLayerCount > 0}
                  onOpenChange={(open) => {
                    if (!open) {
                      if (modalReturnTimerRef.current) {
                        clearTimeout(modalReturnTimerRef.current);
                        modalReturnTimerRef.current = null;
                      }

                      setModalReturning(false);
                      setModalStackViewportLayers(1);
                      setModalLayerCount(0);
                    }
                  }}
                >
                  {modalLayerCount > 0 && (
                    <DialogContent
                      ref={overlayContentRef}
                      size="full"
                      showCloseButton={false}
                      onEscapeKeyDown={(event) => event.preventDefault()}
                      onPointerDownOutside={(event) => event.preventDefault()}
                      className={cn(
                        "inset-y-0 max-sm:rounded-none sm:inset-y-0",
                        "overflow-visible rounded-none border-0 bg-transparent p-0 shadow-none",
                      )}
                    >
                      <div className="relative h-full min-h-0 w-full">
                        {Array.from(
                          {
                            length: visibleModalLayerCount,
                          },
                          (_, index) => {
                            const layerNumber = firstVisibleModalLayer + index;
                            const isActive = layerNumber === modalLayerCount;
                            const stackDepth = modalLayerCount - layerNumber;
                            const displayedStackDepth =
                              modalReturning && !isActive
                                ? Math.max(stackDepth - 1, 0)
                                : stackDepth;

                            return (
                              <div
                                key={layerNumber}
                                className={cn(
                                  "absolute inset-y-0 flex min-h-0 flex-col overflow-hidden border shadow-2xl transition-transform duration-150 ease-out motion-reduce:transition-none sm:rounded-lg",
                                  isActive
                                    ? "z-20 bg-background"
                                    : cn(
                                        "pointer-events-none z-10 max-sm:hidden",
                                        "bg-background",
                                      ),
                                )}
                                style={
                                  modalExpanded
                                    ? !isActive
                                      ? {
                                          transform: `translateX(-${displayedStackDepth * expandedModalRailWidth}px)`,
                                          left: 0,
                                          right: 0,
                                        }
                                      : {
                                          left: 0,
                                          right: 0,
                                        }
                                    : {
                                        left: `${15 + (modalStackViewportLayers - 1) * 2}%`,
                                        width: "70%",
                                        transform: `translateX(-${displayedStackDepth * defaultModalStackStride}vw)`,
                                      }
                                }
                              >
                                {!isActive && modalExpanded && (
                                  <div
                                    aria-hidden="true"
                                    className="pointer-events-none absolute inset-y-0 left-0 z-30 w-[7px] border-r bg-background"
                                  />
                                )}
                                <ModalPageSurface
                                  layerNumber={layerNumber}
                                  isActive={isActive}
                                  expanded={modalExpanded}
                                  factBoxOpen={factBoxOpen}
                                  overlayContentRef={overlayContentRef}
                                  onBack={goToPreviousModalPage}
                                  onNext={openNextModalPage}
                                  onToggleExpanded={() =>
                                    setModalExpanded((current) => !current)
                                  }
                                  onToggleFactBox={() =>
                                    setFactBoxOpen((current) => !current)
                                  }
                                />
                              </div>
                            );
                          },
                        )}
                      </div>
                    </DialogContent>
                  )}
                </Dialog>
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
                      <BreadcrumbLink href="#">Workspace</BreadcrumbLink>
                    </BreadcrumbItem>
                    <BreadcrumbSeparator />
                    <BreadcrumbItem>
                      <BreadcrumbLink href="#">Finance</BreadcrumbLink>
                    </BreadcrumbItem>
                    <BreadcrumbSeparator />
                    <BreadcrumbItem>
                      <BreadcrumbPage>Invoices</BreadcrumbPage>
                    </BreadcrumbItem>
                  </BreadcrumbList>
                </Breadcrumb>
                <Menubar>
                  <MenubarMenu>
                    <MenubarTrigger>File</MenubarTrigger>
                    <MenubarContent>
                      <MenubarItem>New invoice</MenubarItem>
                      <MenubarItem>Import</MenubarItem>
                      <MenubarSeparator />
                      <MenubarItem>Export</MenubarItem>
                    </MenubarContent>
                  </MenubarMenu>
                  <MenubarMenu>
                    <MenubarTrigger>View</MenubarTrigger>
                    <MenubarContent>
                      <MenubarItem>Compact</MenubarItem>
                      <MenubarItem>Comfortable</MenubarItem>
                    </MenubarContent>
                  </MenubarMenu>
                </Menubar>
                <Tabs defaultValue="overview">
                  <TabsList>
                    <TabsTrigger value="overview">Overview</TabsTrigger>
                    <TabsTrigger value="activity">Activity</TabsTrigger>
                    <TabsTrigger value="settings">Settings</TabsTrigger>
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
                    Konfigurasi aplikasi.
                  </TabsContent>
                </Tabs>
                <Accordion type="single" collapsible>
                  <AccordionItem value="one">
                    <AccordionTrigger>
                      What is this playground?
                    </AccordionTrigger>
                    <AccordionContent>
                      A safe place to customize components before production
                      use.
                    </AccordionContent>
                  </AccordionItem>
                </Accordion>
                <Collapsible open={advancedOpen} onOpenChange={setAdvancedOpen}>
                  <CollapsibleTrigger asChild>
                    <Button variant="ghost" className="w-full justify-between">
                      Advanced filters{" "}
                      <ChevronRight
                        className={
                          advancedOpen
                            ? "rotate-90 transition-transform"
                            : "transition-transform"
                        }
                      />
                    </Button>
                  </CollapsibleTrigger>
                  <CollapsibleContent className="rounded-lg border p-3 text-sm text-muted-foreground">
                    Additional filtering options would live here.
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
                      <PaginationLink href="#">2</PaginationLink>
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
                      "Finance workspace",
                      "Inventory workspace",
                      "Human resources",
                      "Sales pipeline",
                    ].map((name, index) => (
                      <div key={name}>
                        <Item>
                          <ItemMedia variant="icon">
                            <Database />
                          </ItemMedia>
                          <ItemContent>
                            <ItemTitle>{name}</ItemTitle>
                            <ItemDescription>
                              Updated {index + 1} hour ago
                            </ItemDescription>
                          </ItemContent>
                          <ItemActions>
                            <Button size="sm" variant="ghost">
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
                    <InputOTP maxLength={6} className="mt-2">
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
                      {date?.toLocaleDateString("en-US") ?? "No date selected"}
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
                <CommandInput placeholder="Cari aplikasi..." />
                <CommandList>
                  <CommandEmpty>Aplikasi tidak ditemukan.</CommandEmpty>
                  <CommandGroup heading="Aplikasi">
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
                    {["Finance", "Inventory", "People"].map((item) => (
                      <CarouselItem key={item}>
                        <Card>
                          <CardContent className="flex h-28 items-center justify-center text-xl font-semibold">
                            {item}
                          </CardContent>
                        </Card>
                      </CarouselItem>
                    ))}
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
                  <EmptyTitle>No purchase orders yet</EmptyTitle>
                  <EmptyDescription>
                    Create the first purchase order or import existing records.
                  </EmptyDescription>
                </EmptyHeader>
                <EmptyContent>
                  <div className="flex gap-2">
                    <Button>Create order</Button>
                    <Button variant="outline">Import CSV</Button>
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
                    Paste JSON atau URL Lottie untuk menampilkan preview di
                    sini.
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
