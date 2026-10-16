import { usePage } from '@inertiajs/react';
import {
    BookOpen,
    Building2,
    CalendarCheck,
    CalendarDays,
    ChartColumn,
    Clock,
    Coins,
    Contact,
    Database,
    FileOutput,
    FileText,
    KeyRound,
    LayoutDashboard,
    LayoutGrid,
    ListChecks,
    Hash,
    History,
    Map,
    Plug,
    Ruler,
    Package,
    Palette,
    ShieldCheck,
    Truck,
    UserRound,
    Users,
} from 'lucide-react';

export type NavigationItem = {
    label: string;
    icon?: typeof LayoutDashboard;
    href: string;
    /** Permission layar Core yang membuat entri ini tampil; kosong berarti selalu tampil. */
    permission?: string;
};
type PrimaryNavigationItem = NavigationItem & {
    icon: typeof LayoutDashboard;
    children: NavigationItem[];
};

export function useShellNavigation() {
    const { props } = usePage();

    // Menu pengaturan disaring dengan permission layar Core anggota ini (SEC-22). Hanya penyaring tampilan: setiap
    // rute dan aksinya memeriksa permission-nya sendiri di server. Kelompok yang semua entrinya tersaring ikut hilang.
    const grantedPermissions = new Set(
        props.auth.membership?.permissions ?? [],
    );
    const isAllowed = (permission?: string) =>
        permission === undefined || grantedPermissions.has(permission);
    const filterByPermission = (
        items: PrimaryNavigationItem[],
    ): PrimaryNavigationItem[] =>
        items
            .map((item) => ({
                ...item,
                children: item.children.filter((child) =>
                    isAllowed(child.permission),
                ),
            }))
            .filter(
                (item) =>
                    isAllowed(item.permission) && item.children.length > 0,
            );

    const coreItems: PrimaryNavigationItem[] = [
        {
            label: 'Dashboard',
            icon: LayoutDashboard,
            href: '/dashboard',
            children: [
                {
                    label: 'Ringkasan',
                    icon: LayoutDashboard,
                    href: '/dashboard',
                },
                ...(props.auth.membership
                    ? [
                          {
                              label: 'Ekspor laporan',
                              icon: FileOutput,
                              href: '/reports/exports',
                          },
                      ]
                    : []),
                // Area 7: daftar dasbor analitik, untuk pemegang permission rutenya (`core.analytics.dashboard.read`).
                // Hanya penyaring tampilan; rute dan API memeriksanya sendiri.
                ...(isAllowed('core.analytics.dashboard.read')
                    ? [
                          {
                              label: 'Dasbor',
                              icon: LayoutGrid,
                              href: '/analytics',
                          },
                      ]
                    : []),
                // Halaman Analisis data menjalankan analisis bebas, jadi menunya untuk pemegang permission yang
                // sama dengan rutenya (`core.analytics.explore.invoke`, KA-14). Hanya penyaring tampilan.
                ...(isAllowed('core.analytics.explore.invoke')
                    ? [
                          {
                              label: 'Analisis data',
                              icon: ChartColumn,
                              href: '/analytics/explore',
                          },
                      ]
                    : []),
            ],
        },
        ...filterByPermission([
            {
                label: 'Setup Address',
                icon: Map,
                href: '/settings/address-setup',
                children: [
                    {
                        label: 'Pengaturan',
                        icon: Map,
                        href: '/settings/address-setup',
                        permission: 'core.reference-data.read',
                    },
                ],
            },
            {
                label: 'Kalender',
                icon: CalendarDays,
                href: '/settings/working-time-templates',
                children: [
                    {
                        label: 'Pola jam kerja',
                        icon: Clock,
                        href: '/settings/working-time-templates',
                        permission: 'core.reference-data.read',
                    },
                    {
                        label: 'Kalender kerja',
                        icon: CalendarDays,
                        href: '/settings/working-time-calendars',
                        permission: 'core.reference-data.read',
                    },
                    {
                        label: 'Jadwal kerja',
                        icon: Clock,
                        href: '/settings/working-time-calendar-times',
                        permission: 'core.reference-data.read',
                    },
                    {
                        label: 'Jadwal dari pola',
                        icon: CalendarCheck,
                        href: '/settings/compose-working-times',
                        // Halaman ini hanya berisi formulir penyusunan jadwal, jadi ia untuk
                        // yang boleh mengubah data referensi saja.
                        permission: 'core.reference-data.update',
                    },
                ],
            },
            {
                label: 'Organisasi',
                icon: Building2,
                href: '/settings/legal-entities',
                children: [
                    {
                        label: 'Perusahaan',
                        icon: Building2,
                        href: '/settings/legal-entities',
                        permission: 'core.organization.read',
                    },
                    {
                        label: 'Operating unit',
                        href: '/settings/operating-units',
                        permission: 'core.organization.read',
                    },
                    {
                        label: 'Hierarki organisasi',
                        href: '/settings/organization-hierarchies',
                        permission: 'core.organization.read',
                    },
                ],
            },
            {
                label: 'Buku alamat',
                icon: Contact,
                href: '/settings/global-address-book',
                children: [
                    {
                        label: 'Party',
                        icon: UserRound,
                        href: '/settings/global-address-book',
                        permission: 'core.organization.read',
                    },
                    {
                        label: 'Vendor',
                        icon: Truck,
                        href: '/settings/vendors',
                        permission: 'core.vendor.read',
                    },
                ],
            },
            {
                label: 'Identity & access',
                icon: KeyRound,
                href: '/settings/access?section=members',
                children: [
                    {
                        label: 'Anggota',
                        icon: Users,
                        href: '/settings/access?section=members',
                        permission: 'core.access.read',
                    },
                    {
                        label: 'Undangan',
                        icon: Users,
                        href: '/settings/access?section=invitations',
                        permission: 'core.access.read',
                    },
                    {
                        label: 'Konfigurasi keamanan',
                        icon: ShieldCheck,
                        href: '/settings/security-configuration',
                        permission: 'core.access.read',
                    },
                    {
                        label: 'Riwayat perubahan',
                        icon: History,
                        href: '/settings/change-log',
                        permission: 'core.change-log.read',
                    },
                    {
                        label: 'Retensi data',
                        icon: History,
                        href: '/settings/retention',
                        permission: 'core.retention.read',
                    },
                    {
                        label: 'Workflow',
                        icon: ShieldCheck,
                        href: '/settings/workflows',
                        permission: 'core.workflow.read',
                    },
                    {
                        label: 'Klien integrasi',
                        icon: Plug,
                        href: '/settings/integration-clients',
                        permission: 'core.integration-clients.read',
                    },
                ],
            },
            {
                label: 'Data referensi',
                icon: Ruler,
                href: '/settings/units-of-measure',
                children: [
                    {
                        label: 'Satuan',
                        icon: Ruler,
                        href: '/settings/units-of-measure',
                        permission: 'core.reference-data.read',
                    },
                    {
                        label: 'Mata uang',
                        icon: Coins,
                        href: '/settings/currencies',
                        permission: 'core.reference-data.read',
                    },
                    {
                        label: 'Daftar akun',
                        icon: BookOpen,
                        href: '/settings/finance-accounts',
                        permission: 'core.finance-setup.read',
                    },
                    {
                        label: 'Setup Address',
                        icon: Map,
                        href: '/settings/address-setup',
                        permission: 'core.reference-data.read',
                    },
                    {
                        label: 'Layout laporan',
                        icon: FileText,
                        href: '/settings/report-layouts',
                        permission: 'core.report-layout.read',
                    },
                ],
            },
            {
                label: 'Nomor dokumen',
                icon: Hash,
                href: '/settings/number-sequences',
                children: [
                    {
                        label: 'Atur nomor',
                        icon: Hash,
                        href: '/settings/number-sequences',
                        permission: 'core.number-sequence.read',
                    },
                ],
            },
            {
                label: 'Posting finance',
                icon: ListChecks,
                href: '/settings/finance-postings',
                children: [
                    {
                        label: 'Pantau posting',
                        icon: ListChecks,
                        href: '/settings/finance-postings',
                        permission: 'core.finance-posting.read',
                    },
                ],
            },
        ]),
        {
            label: 'Profile',
            icon: UserRound,
            href: '/settings/profile',
            children: [
                {
                    label: 'Profil',
                    icon: UserRound,
                    href: '/settings/profile',
                },
            ],
        },
        {
            label: 'Security',
            icon: ShieldCheck,
            href: '/settings/security',
            children: [
                {
                    label: 'Keamanan akun',
                    icon: ShieldCheck,
                    href: '/settings/security',
                },
            ],
        },
        {
            label: 'Appearance',
            icon: Palette,
            href: '/settings/appearance',
            children: [
                {
                    label: 'Tampilan',
                    icon: Palette,
                    href: '/settings/appearance',
                },
            ],
        },
        ...(props.auth.provider_admin
            ? [
                  {
                      label: 'Operations',
                      icon: Package,
                      href: '/control/identities',
                      children: [
                          {
                              label: 'Identity monitor',
                              icon: Users,
                              href: '/control/identities',
                          },
                          {
                              label: 'Katalog aplikasi',
                              icon: Package,
                              href: '/control/apps',
                          },
                      ],
                  },
              ]
            : []),
    ];
    const hostedItems: PrimaryNavigationItem[] =
        props.app?.navigation.rails.map((rail) => ({
            label: rail.label,
            icon: Database,
            href: rail.href,
            children: rail.items.map((item) => ({
                label: item.label,
                href: item.href,
            })),
        })) ?? [];
    const primaryItems = props.app ? hostedItems : coreItems;

    return { coreItems, primaryItems };
}
