import { useState, useMemo, useEffect } from 'react';
import { Head, usePage, Link } from '@inertiajs/react';
import { NotificationDropdown } from '@/components/notification-dropdown';
import { Button } from '@apperp/ui/button';
import { dashboard } from '@/routes';
import {
    Search,
    Bell,
    Calendar,
    Box,
    Wallet,
    ShieldCheck,
    TrendingDown,
    Wrench,
    TrendingUp,
    ChevronDown,
    X,
    CheckCircle2,
    Clock,
    AlertTriangle,
    Download,
    Eye,
    Check,
    MapPin,
    Plus,
    FileText,
    RefreshCw,
    Sparkles,
    Printer,
    Building2,
} from 'lucide-react';
import {
    ResponsiveContainer,
    PieChart,
    Pie,
    Cell,
    BarChart,
    Bar,
    XAxis,
    YAxis,
    Tooltip as RechartsTooltip,
    AreaChart,
    Area,
    LineChart,
    Line,
    LabelList,
} from 'recharts';

// --- DATASETS & LOCATION MAPS ---

const DOUGHNUT_KONDISI_DATA: Record<string, { name: string; value: number; color: string }[]> = {
    'Semua Lokasi': [
        { name: 'Baik', value: 975, color: '#10b981' },
        { name: 'Perlu Perhatian', value: 180, color: '#eab308' },
        { name: 'Rusak Ringan', value: 75, color: '#f97316' },
        { name: 'Rusak Berat', value: 20, color: '#ef4444' },
    ],
    'Pabrik Utama (Jakarta)': [
        { name: 'Baik', value: 450, color: '#10b981' },
        { name: 'Perlu Perhatian', value: 90, color: '#eab308' },
        { name: 'Rusak Ringan', value: 40, color: '#f97316' },
        { name: 'Rusak Berat', value: 10, color: '#ef4444' },
    ],
    'Gedung Kantor (Bandung)': [
        { name: 'Baik', value: 300, color: '#10b981' },
        { name: 'Perlu Perhatian', value: 35, color: '#eab308' },
        { name: 'Rusak Ringan', value: 15, color: '#f97316' },
        { name: 'Rusak Berat', value: 3, color: '#ef4444' },
    ],
    'Gudang Logistik (Surabaya)': [
        { name: 'Baik', value: 150, color: '#10b981' },
        { name: 'Perlu Perhatian', value: 40, color: '#eab308' },
        { name: 'Rusak Ringan', value: 12, color: '#f97316' },
        { name: 'Rusak Berat', value: 5, color: '#ef4444' },
    ],
    'Site Operasional (Kaltim)': [
        { name: 'Baik', value: 75, color: '#10b981' },
        { name: 'Perlu Perhatian', value: 15, color: '#eab308' },
        { name: 'Rusak Ringan', value: 8, color: '#f97316' },
        { name: 'Rusak Berat', value: 2, color: '#ef4444' },
    ],
};

const BAR_KATEGORI_DATA: Record<string, { name: string; value: number; displayVal: string }[]> = {
    'Rp (Juta)': [
        { name: 'Mesin', value: 7200, displayVal: '7.200' },
        { name: 'Kendaraan', value: 5100, displayVal: '5.100' },
        { name: 'Peralatan IT', value: 4200, displayVal: '4.200' },
        { name: 'Gedung', value: 3800, displayVal: '3.800' },
        { name: 'Peralatan Kantor', value: 3100, displayVal: '3.100' },
        { name: 'Lainnya', value: 2200, displayVal: '2.200' },
    ],
    'Rp (Miliar)': [
        { name: 'Mesin', value: 7.2, displayVal: '7.2' },
        { name: 'Kendaraan', value: 5.1, displayVal: '5.1' },
        { name: 'Peralatan IT', value: 4.2, displayVal: '4.2' },
        { name: 'Gedung', value: 3.8, displayVal: '3.8' },
        { name: 'Peralatan Kantor', value: 3.1, displayVal: '3.1' },
        { name: 'Lainnya', value: 2.2, displayVal: '2.2' },
    ],
    'Jumlah Unit': [
        { name: 'Mesin', value: 180, displayVal: '180' },
        { name: 'Kendaraan', value: 95, displayVal: '95' },
        { name: 'Peralatan IT', value: 420, displayVal: '420' },
        { name: 'Gedung', value: 12, displayVal: '12' },
        { name: 'Peralatan Kantor', value: 350, displayVal: '350' },
        { name: 'Lainnya', value: 193, displayVal: '193' },
    ],
};

const DASHBOARD_PERIOD_OPTIONS = [
    { label: 'Hari Ini', key: 'Hari Ini' },
    { label: '7 Hari Terakhir', key: '7 Hari Terakhir' },
    { label: '30 Hari Terakhir', key: '30 Hari Terakhir' },
    { label: 'Bulan Ini', key: 'Bulan Ini' },
    { label: 'Kuartal Ini', key: 'Kuartal Ini' },
    { label: 'Tahun Ini', key: 'Tahun Ini' },
    { label: 'Custom', key: 'Custom', isCustom: true },
];

const AREA_BUKU_HABIS_DATA: Record<string, { month: string; value: number }[]> = {
    'Hari Ini': [
        { month: '08:00', value: 3.2 },
        { month: '10:00', value: 3.25 },
        { month: '12:00', value: 3.3 },
        { month: '14:00', value: 3.35 },
        { month: '16:00', value: 3.4 },
    ],
    '7 Hari Terakhir': [
        { month: '5 Agu', value: 2.9 },
        { month: '6 Agu', value: 3.0 },
        { month: '7 Agu', value: 3.1 },
        { month: '8 Agu', value: 3.2 },
        { month: '9 Agu', value: 3.3 },
        { month: '10 Agu', value: 3.4 },
        { month: '11 Agu', value: 3.4 },
    ],
    '30 Hari Terakhir': [
        { month: '1 Agu', value: 2.7 },
        { month: '6 Agu', value: 2.8 },
        { month: '11 Agu', value: 2.9 },
        { month: '16 Agu', value: 3.0 },
        { month: '21 Agu', value: 3.2 },
        { month: '26 Agu', value: 3.3 },
        { month: '31 Agu', value: 3.4 },
    ],
    'Bulan Ini': [
        { month: '1 Agu', value: 2.7 },
        { month: '5 Agu', value: 2.8 },
        { month: '10 Agu', value: 2.9 },
        { month: '15 Agu', value: 3.1 },
        { month: '20 Agu', value: 3.2 },
        { month: '25 Agu', value: 3.3 },
        { month: '31 Agu', value: 3.4 },
    ],
    'Kuartal Ini': [
        { month: 'Juni', value: 2.5 },
        { month: 'Juli', value: 2.9 },
        { month: 'Agustus', value: 3.4 },
    ],
    'Tahun Ini': [
        { month: 'Jan', value: 1.0 }, { month: 'Feb', value: 1.3 }, { month: 'Mar', value: 2.0 }, { month: 'Apr', value: 1.7 },
        { month: 'Mei', value: 2.1 }, { month: 'Jun', value: 2.5 }, { month: 'Jul', value: 2.8 }, { month: 'Agu', value: 3.1 },
        { month: 'Sep', value: 2.4 }, { month: 'Okt', value: 2.7 }, { month: 'Nov', value: 2.8 }, { month: 'Des', value: 3.4 },
    ],
};

const DOUGHNUT_WORK_ORDER_DATA: Record<string, { name: string; value: number; color: string }[]> = {
    'Hari Ini': [
        { name: 'Selesai', value: 18, color: '#10b981' },
        { name: 'Dalam Proses', value: 5, color: '#f59e0b' },
        { name: 'Tertunda', value: 1, color: '#ef4444' },
    ],
    '7 Hari Terakhir': [
        { name: 'Selesai', value: 86, color: '#10b981' },
        { name: 'Dalam Proses', value: 22, color: '#f59e0b' },
        { name: 'Tertunda', value: 5, color: '#ef4444' },
    ],
    '30 Hari Terakhir': [
        { name: 'Selesai', value: 156, color: '#10b981' },
        { name: 'Dalam Proses', value: 78, color: '#f59e0b' },
        { name: 'Tertunda', value: 12, color: '#ef4444' },
    ],
    'Bulan Ini': [
        { name: 'Selesai', value: 156, color: '#10b981' },
        { name: 'Dalam Proses', value: 78, color: '#f59e0b' },
        { name: 'Tertunda', value: 12, color: '#ef4444' },
    ],
    'Kuartal Ini': [
        { name: 'Selesai', value: 420, color: '#10b981' },
        { name: 'Dalam Proses', value: 110, color: '#f59e0b' },
        { name: 'Tertunda', value: 25, color: '#ef4444' },
    ],
    'Tahun Ini': [
        { name: 'Selesai', value: 1450, color: '#10b981' },
        { name: 'Dalam Proses', value: 310, color: '#f59e0b' },
        { name: 'Tertunda', value: 85, color: '#ef4444' },
    ],
};

const LINE_TREND_DATA: Record<string, { month: string; value: number }[]> = {
    'Hari Ini': [
        { month: '08:00', value: 4.2 },
        { month: '10:00', value: 6.3 },
        { month: '12:00', value: 8.5 },
        { month: '14:00', value: 10.4 },
        { month: '16:00', value: 12.1 },
    ],
    '7 Hari Terakhir': [
        { month: '5 Agu', value: 8.2 },
        { month: '6 Agu', value: 9.6 },
        { month: '7 Agu', value: 11.0 },
        { month: '8 Agu', value: 13.4 },
        { month: '9 Agu', value: 15.8 },
        { month: '10 Agu', value: 16.2 },
        { month: '11 Agu', value: 17.2 },
    ],
    '30 Hari Terakhir': [
        { month: '1 Agu', value: 8.2 },
        { month: '6 Agu', value: 9.8 },
        { month: '11 Agu', value: 11.5 },
        { month: '16 Agu', value: 13.8 },
        { month: '21 Agu', value: 15.2 },
        { month: '26 Agu', value: 16.4 },
        { month: '31 Agu', value: 31.2 },
    ],
    'Bulan Ini': [
        { month: '1 Agu', value: 8.2 },
        { month: '5 Agu', value: 9.5 },
        { month: '10 Agu', value: 11.8 },
        { month: '15 Agu', value: 14.2 },
        { month: '20 Agu', value: 15.6 },
        { month: '25 Agu', value: 16.5 },
        { month: '31 Agu', value: 17.2 },
    ],
    'Kuartal Ini': [
        { month: 'Juni', value: 10.5 },
        { month: 'Juli', value: 13.8 },
        { month: 'Agustus', value: 17.2 },
    ],
    'Tahun Ini': [
        { month: 'Jan', value: 7.5 }, { month: 'Feb', value: 9.5 }, { month: 'Mar', value: 12.2 }, { month: 'Apr', value: 9.8 },
        { month: 'Mei', value: 8.7 }, { month: 'Jun', value: 10.8 }, { month: 'Jul', value: 13.0 }, { month: 'Agu', value: 11.6 },
        { month: 'Sep', value: 12.5 }, { month: 'Okt', value: 15.2 }, { month: 'Nov', value: 14.6 }, { month: 'Des', value: 17.2 },
    ],
};

const ALL_MAINTENANCE_ASSETS = [
    { id: 'AST-001', name: 'Mesin Produksi A-101', category: 'Mesin', location: 'Pabrik Utama', count: 18, status: 'Selesai', lastDate: '10 Apr 2024', cost: 'Rp 45.000.000' },
    { id: 'AST-002', name: 'Generator G-01', category: 'Mesin', location: 'Site Kaltim', count: 15, status: 'Selesai', lastDate: '08 Apr 2024', cost: 'Rp 28.500.000' },
    { id: 'AST-003', name: 'AC Sentral Gedung 1', category: 'Gedung', location: 'Gedung Bandung', count: 12, status: 'Dalam Proses', lastDate: '12 Apr 2024', cost: 'Rp 14.200.000' },
    { id: 'AST-004', name: 'Forklift FL-05', category: 'Kendaraan', location: 'Gudang Surabaya', count: 11, status: 'Selesai', lastDate: '05 Apr 2024', cost: 'Rp 19.800.000' },
    { id: 'AST-005', name: 'Server Room Rack 2', category: 'Peralatan IT', location: 'Gedung Bandung', count: 10, status: 'Selesai', lastDate: '02 Apr 2024', cost: 'Rp 8.400.000' },
    { id: 'AST-006', name: 'Kompresor K-03', category: 'Mesin', location: 'Pabrik Utama', count: 9, status: 'Dalam Proses', lastDate: '11 Apr 2024', cost: 'Rp 12.000.000' },
    { id: 'AST-007', name: 'Mesin Packaging P-07', category: 'Mesin', location: 'Pabrik Utama', count: 8, status: 'Selesai', lastDate: '28 Mar 2024', cost: 'Rp 16.500.000' },
    { id: 'AST-008', name: 'UPS Unit 1', category: 'Peralatan IT', location: 'Gedung Bandung', count: 7, status: 'Selesai', lastDate: '25 Mar 2024', cost: 'Rp 5.200.000' },
    { id: 'AST-009', name: 'Pompa Air P-02', category: 'Mesin', location: 'Gudang Surabaya', count: 7, status: 'Dalam Proses', lastDate: '09 Apr 2024', cost: 'Rp 7.800.000' },
    { id: 'AST-010', name: 'AC Ruang Server', category: 'Peralatan IT', location: 'Gedung Bandung', count: 6, status: 'Selesai', lastDate: '15 Apr 2024', cost: 'Rp 32.000.000' },
];

const INITIAL_NOTIFICATIONS = [
    { id: 1, title: 'Maintenance Dibutuhkan', message: 'Mesin Produksi A-101 butuh servis berkala 500 jam.', time: '10 menit yang lalu', unread: true, type: 'warning' },
    { id: 2, title: 'Work Order Selesai', message: 'WO-2024-089 (Generator G-01) diselesaikan oleh Tim Teknisi.', time: '1 jam yang lalu', unread: true, type: 'success' },
    { id: 3, title: 'Jadwal Kalibrasi', message: 'UPS Unit 1 memasuki masa kalibrasi dalam 3 hari.', time: '4 jam yang lalu', unread: true, type: 'info' },
];

// Helper: Largest Remainder Method for exact 100% percentage calculation
function withPercent<T extends { value: number }>(items: T[]) {
    const total = items.reduce((sum, i) => sum + i.value, 0);
    if (total === 0) return items.map((item) => ({ ...item, percent: '0%' }));

    const exact = items.map((item) => (item.value / total) * 100);
    const floors = exact.map(Math.floor);
    const remainder = 100 - floors.reduce((a, b) => a + b, 0);

    const indices = exact
        .map((v, i) => ({ i, r: v - Math.floor(v) }))
        .sort((a, b) => b.r - a.r)
        .map((x) => x.i);

    const percents = [...floors];
    for (let k = 0; k < remainder; k++) percents[indices[k]]++;

    return items.map((item, idx) => ({ ...item, percent: `${percents[idx]}%` }));
}

// Custom Tooltip for Charts
const CustomChartTooltip = ({ active, payload, label, unitFormatter }: any) => {
    if (active && payload && payload.length) {
        const data = payload[0];
        return (
            <div className="bg-slate-900/95 dark:bg-slate-950/95 text-white border border-slate-700/80 rounded-xl shadow-2xl p-3 text-xs backdrop-blur-md animate-in fade-in zoom-in-95 duration-150">
                <div className="font-bold text-slate-300 pb-1 mb-1 border-b border-slate-800 flex items-center justify-between gap-4">
                    <span>{data.payload?.name || label}</span>
                    {data.payload?.percent && <span className="text-emerald-400 font-mono text-[11px]">{data.payload.percent}</span>}
                </div>
                <div className="flex items-center gap-2 mt-1">
                    <span className="size-2 rounded-full shrink-0" style={{ backgroundColor: data.color || data.fill || '#3b82f6' }} />
                    <span className="font-extrabold text-sm text-white">
                        {unitFormatter ? unitFormatter(data.value) : `${Number(data.value).toLocaleString('id-ID')}`}
                    </span>
                </div>
            </div>
        );
    }
    return null;
};

// Custom Multiline XAxis Tick for centered, non-colliding category names
const CustomCategoryTick = (props: any) => {
    const { x, y, payload } = props;
    const value = String(payload?.value || '');
    const words = value.split(' ');

    return (
        <g transform={`translate(${x},${y})`}>
            <text x={0} y={10} textAnchor="middle" fill="#64748b" fontSize={8.5} fontWeight={600}>
                {words.map((word, idx) => (
                    <tspan key={idx} x={0} dy={idx === 0 ? 0 : 11}>
                        {word}
                    </tspan>
                ))}
            </text>
        </g>
    );
};

export default function Dashboard() {
    const { auth } = usePage<any>().props;
    const currentTenantName = auth?.membership?.tenant_name ?? 'PKL';
    const isInitialDemoBusiness = true;

    // --- STATE MANAGEMENT ---
    const [searchQuery, setSearchQuery] = useState('');
    const [showNotifications, setShowNotifications] = useState(false);
    const [notifications, setNotifications] = useState(isInitialDemoBusiness ? INITIAL_NOTIFICATIONS : []);

    // Global Single Period Filter in Header
    const [dateRange, setDateRange] = useState('Hari Ini');
    const [showDateRangeMenu, setShowDateRangeMenu] = useState(false);

    // Custom Date Range Modal state
    const [showCustomDateModal, setShowCustomDateModal] = useState(false);
    const [customStartDate, setCustomStartDate] = useState('2026-08-01');
    const [customEndDate, setCustomEndDate] = useState('2026-08-11');

    const [location, setLocation] = useState('Semua Lokasi');
    const [showLocationMenu, setShowLocationMenu] = useState(false);

    const [categoryUnit, setCategoryUnit] = useState('Rp (Juta)');
    const [showCategoryUnitMenu, setShowCategoryUnitMenu] = useState(false);

    // Active KPI filter highlight
    const [focusedKpi, setFocusedKpi] = useState<string | null>(null);

    // Refreshing state
    const [isRefreshing, setIsRefreshing] = useState(false);

    // Hover state for Donut Charts
    const [hoveredKondisi, setHoveredKondisi] = useState<{ name: string; value: number; color: string; percent: string } | null>(null);
    const [hoveredWo, setHoveredWo] = useState<{ name: string; value: number; color: string; percent: string } | null>(null);

    // Modals
    const [showAllMaintenanceModal, setShowAllMaintenanceModal] = useState(false);
    const [selectedAssetDetail, setSelectedAssetDetail] = useState<typeof ALL_MAINTENANCE_ASSETS[0] | null>(null);

    // Toast Alert
    const [toastMessage, setToastMessage] = useState<string | null>(null);
    const [isExportingPdf, setIsExportingPdf] = useState(false);
    const [showExportMenu, setShowExportMenu] = useState(false);

    const showToast = (msg: string) => {
        setToastMessage(msg);
        setTimeout(() => setToastMessage(null), 3500);
    };

    const maintenanceAssets = useMemo(() => {
        return isInitialDemoBusiness ? ALL_MAINTENANCE_ASSETS : [];
    }, [isInitialDemoBusiness]);

    // --- SEARCH FILTERING (Searches Kode, Nama, Kategori, Lokasi, Jenis) ---
    const filteredSearchResults = useMemo(() => {
        const q = searchQuery.trim().toLowerCase();
        if (!q) return [];
        return maintenanceAssets.filter((item) => {
            const kodeMatch = item.id.toLowerCase().includes(q);
            const namaMatch = item.name.toLowerCase().includes(q);
            const kategoriMatch = item.category.toLowerCase().includes(q);
            const lokasiMatch = item.location.toLowerCase().includes(q);
            return kodeMatch || namaMatch || kategoriMatch || lokasiMatch;
        });
    }, [searchQuery, maintenanceAssets]);

    // Compute effective period key from dateRange for global dynamic widget filtering
    const effectivePeriodKey = useMemo(() => {
        if (dateRange.startsWith('Custom')) return '30 Hari Terakhir';
        if (dateRange.includes('Tahun')) return 'Tahun Ini';
        if (dateRange.includes('Kuartal')) return 'Kuartal Ini';
        if (dateRange.includes('30 Hari')) return '30 Hari Terakhir';
        if (dateRange.includes('7 Hari')) return '7 Hari Terakhir';
        return dateRange;
    }, [dateRange]);

    // --- COMPUTED DATA BASED ON FILTERS ---
    const rawKondisi = useMemo(() => {
        if (!isInitialDemoBusiness) return [{ name: 'Belum Ada Data', value: 0, color: '#cbd5e1' }];
        return DOUGHNUT_KONDISI_DATA[location] || DOUGHNUT_KONDISI_DATA['Semua Lokasi'];
    }, [location, isInitialDemoBusiness]);

    const DOUGHNUT_KONDISI = useMemo(() => withPercent(rawKondisi), [rawKondisi]);
    const totalKondisi = useMemo(() => DOUGHNUT_KONDISI.reduce((sum, i) => sum + i.value, 0), [DOUGHNUT_KONDISI]);
    const baikPercent = useMemo(() => DOUGHNUT_KONDISI.find(k => k.name === 'Baik')?.percent || '0%', [DOUGHNUT_KONDISI]);

    const barKategori = useMemo(() => {
        if (!isInitialDemoBusiness) return [];
        return BAR_KATEGORI_DATA[categoryUnit] || BAR_KATEGORI_DATA['Rp (Juta)'];
    }, [categoryUnit, isInitialDemoBusiness]);

    const totalNilaiAssetMiliar = useMemo(() => {
        if (!isInitialDemoBusiness) return '0,0';
        const sumJuta = BAR_KATEGORI_DATA['Rp (Juta)'].reduce((acc, curr) => acc + curr.value, 0);
        return (sumJuta / 1000).toLocaleString('id-ID', { minimumFractionDigits: 1, maximumFractionDigits: 1 });
    }, [isInitialDemoBusiness]);

    const areaBukuHabis = useMemo(() => {
        if (!isInitialDemoBusiness) return [];
        return AREA_BUKU_HABIS_DATA[effectivePeriodKey] || AREA_BUKU_HABIS_DATA['Hari Ini'];
    }, [effectivePeriodKey, isInitialDemoBusiness]);

    const latestBukuHabisValue = useMemo(() => {
        if (!isInitialDemoBusiness || areaBukuHabis.length === 0) return '0,0';
        const lastVal = areaBukuHabis[areaBukuHabis.length - 1]?.value || 0;
        return lastVal.toLocaleString('id-ID', { minimumFractionDigits: 1, maximumFractionDigits: 1 });
    }, [areaBukuHabis, isInitialDemoBusiness]);

    const rawWorkOrder = useMemo(() => {
        if (!isInitialDemoBusiness) return [{ name: 'Belum Ada WO', value: 0, color: '#cbd5e1' }];
        return DOUGHNUT_WORK_ORDER_DATA[effectivePeriodKey] || DOUGHNUT_WORK_ORDER_DATA['Hari Ini'];
    }, [effectivePeriodKey, isInitialDemoBusiness]);

    const DOUGHNUT_WORK_ORDER = useMemo(() => withPercent(rawWorkOrder), [rawWorkOrder]);
    const totalWorkOrder = useMemo(() => DOUGHNUT_WORK_ORDER.reduce((sum, i) => sum + i.value, 0), [DOUGHNUT_WORK_ORDER]);
    const woSelesaiValue = useMemo(() => DOUGHNUT_WORK_ORDER.find(w => w.name === 'Selesai')?.value || 0, [DOUGHNUT_WORK_ORDER]);

    const lineTrend = useMemo(() => {
        if (!isInitialDemoBusiness) return [];
        return LINE_TREND_DATA[effectivePeriodKey] || LINE_TREND_DATA['Hari Ini'];
    }, [effectivePeriodKey, isInitialDemoBusiness]);

    const unreadCount = notifications.filter(n => n.unread).length;

    const handleMarkAllRead = () => {
        setNotifications(prev => prev.map(n => ({ ...n, unread: false })));
        showToast('Semua notifikasi ditandai sebagai dibaca');
    };

    const handleResetAllFilters = () => {
        if (isRefreshing) return;
        setIsRefreshing(true);
        setSearchQuery('');
        setLocation('Semua Lokasi');
        setDateRange('Hari Ini');
        setCategoryUnit('Rp (Juta)');
        setFocusedKpi(null);

        setTimeout(() => {
            setIsRefreshing(false);
            showToast('Data dashboard berhasil di-refresh & filter di-reset');
        }, 600);
    };

    const handleSelectPeriod = (newPeriod: string) => {
        setDateRange(newPeriod);
        showToast(`Periode dashboard diperbarui ke: ${newPeriod}`);
    };

    const handleApplyCustomDate = () => {
        if (!customStartDate || !customEndDate) return;
        const formatted = `${customStartDate.split('-').reverse().slice(0, 2).join('/')} - ${customEndDate.split('-').reverse().slice(0, 2).join('/')}`;
        const customLabel = `Custom (${formatted})`;

        setDateRange(customLabel);
        setShowCustomDateModal(false);
        showToast(`Filter tanggal custom diterapkan: ${customStartDate} s/d ${customEndDate}`);
    };

    const handleExportPdf = async () => {
        showToast('Menyiapkan Laporan PDF Executive Dashboard Asset (1 Halaman)...');
        setIsExportingPdf(true);

        await new Promise(r => setTimeout(r, 250));

        const element = document.getElementById('dashboard-print-root');
        if (!element) {
            setIsExportingPdf(false);
            return;
        }

        try {
            const { toPng } = await import('html-to-image');
            const { jsPDF } = await import('jspdf');

            const dataUrl = await toPng(element, {
                quality: 0.98,
                pixelRatio: 2,
                cacheBust: true,
            });

            const pdf = new jsPDF({
                orientation: 'landscape',
                unit: 'mm',
                format: 'a4',
            });

            const pdfWidth = 297;
            const pdfHeight = 210;
            const marginTopBottom = 10;
            const marginLeftRight = 12;

            const printableW = pdfWidth - marginLeftRight * 2;
            const printableH = pdfHeight - marginTopBottom * 2;

            const img = new Image();
            img.src = dataUrl;
            await new Promise((resolve) => {
                img.onload = resolve;
            });

            const imgRatio = img.width / img.height;
            let finalW = printableW;
            let finalH = printableW / imgRatio;

            if (finalH > printableH) {
                finalH = printableH;
                finalW = printableH * imgRatio;
            }

            const xOffset = marginLeftRight + (printableW - finalW) / 2;
            const yOffset = marginTopBottom + (printableH - finalH) / 2;

            pdf.addImage(dataUrl, 'PNG', xOffset, yOffset, finalW, finalH);

            const now = new Date().toLocaleDateString('id-ID').replace(/\//g, '-');
            pdf.save(`Dashboard-Asset-ERP-${now}.pdf`);
            showToast('Laporan PDF 1 Halaman berhasil diunduh!');
        } catch (err) {
            console.error('PDF export error:', err);
            showToast('Mengakses dialog cetak browser...');
            window.print();
        } finally {
            setIsExportingPdf(false);
        }
    };

    const handleExportPng = async () => {
        showToast('Menyiapkan gambar PNG Executive Dashboard Asset...');
        setIsExportingPdf(true);

        await new Promise(r => setTimeout(r, 250));

        const element = document.getElementById('dashboard-print-root');
        if (!element) {
            setIsExportingPdf(false);
            return;
        }

        try {
            const { toPng } = await import('html-to-image');
            const dataUrl = await toPng(element, {
                quality: 0.98,
                pixelRatio: 2,
                cacheBust: true,
            });

            const link = document.createElement('a');
            const now = new Date().toLocaleDateString('id-ID').replace(/\//g, '-');
            link.download = `Dashboard-Asset-ERP-${now}.png`;
            link.href = dataUrl;
            link.click();
            showToast('Gambar PNG berhasil diunduh!');
        } catch (err) {
            console.error('Export PNG error:', err);
            showToast('Gagal mengunduh gambar PNG, coba lagi.');
        } finally {
            setIsExportingPdf(false);
        }
    };

    const handlePrintDialog = async () => {
        showToast('Menyiapkan pratinjau cetak...');

        const element = document.getElementById('dashboard-print-root');
        if (!element) return;

        try {
            const { toPng } = await import('html-to-image');
            const dataUrl = await toPng(element, {
                quality: 0.98,
                pixelRatio: 1.5,
                cacheBust: true,
            });

            const now = new Date().toLocaleDateString('id-ID', { day: '2-digit', month: 'long', year: 'numeric' });
            const timeStr = new Date().toLocaleString('id-ID');

            const iframe = document.createElement('iframe');
            Object.assign(iframe.style, {
                position: 'fixed', top: '0', left: '0',
                width: '0', height: '0', border: 'none', opacity: '0',
                pointerEvents: 'none', zIndex: '-1',
            });
            document.body.appendChild(iframe);

            const doc = iframe.contentDocument!;
            doc.open();
            doc.write(`<!DOCTYPE html>
<html lang="id"><head><meta charset="UTF-8"/>
<title>Laporan Dashboard ERP</title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
html,body{width:297mm;height:210mm;overflow:hidden;background:#fff;-webkit-print-color-adjust:exact;print-color-adjust:exact}
.page{width:297mm;height:210mm;padding:8mm 12mm 6mm;display:flex;flex-direction:column;gap:2mm}
.hdr{flex-shrink:0;font-family:system-ui,sans-serif;display:flex;justify-content:space-between;align-items:center;padding-bottom:2.5mm;border-bottom:2px solid #1e293b}
.hdr-l .t{font-size:9.5pt;font-weight:900;color:#0f172a;text-transform:uppercase;letter-spacing:.03em}
.hdr-l .s{font-size:6pt;color:#475569;font-weight:600;margin-top:.8mm}
.hdr-r{text-align:right;font-size:6pt;color:#475569;font-family:monospace;line-height:1.7}
.hdr-r b{color:#1e293b}
.img-box{flex:1;min-height:0;overflow:hidden;display:flex;align-items:center;justify-content:center}
.img-box img{max-width:100%;max-height:100%;object-fit:contain;display:block}
.ftr{flex-shrink:0;font-family:system-ui,sans-serif;display:flex;justify-content:space-between;font-size:5.5pt;color:#94a3b8;padding-top:1.5mm;border-top:1px solid #cbd5e1}
@media print{@page{size:A4 landscape;margin:0}html,body{-webkit-print-color-adjust:exact;print-color-adjust:exact}}
</style></head><body>
<div class="page">
  <div class="hdr">
    <div class="hdr-l">
      <div class="t">Laporan Executive Dashboard Asset ERP</div>
      <div class="s">PT CORE ERP INDONESIA &bull; SISTEM MANAJEMEN ASET TERPADU CONTROL PLANE</div>
    </div>
    <div class="hdr-r">
      <div><b>Periode:</b> ${dateRange}</div>
      <div><b>Lokasi:</b> ${location}</div>
      <div><b>Tanggal Cetak:</b> ${now}</div>
    </div>
  </div>
  <div class="img-box"><img src="${dataUrl}" alt="Dashboard"/></div>
  <div class="ftr">
    <span>Dokumen Resmi ERP Control Plane &bull; Rahasia Internal</span>
    <span>Halaman 1 dari 1</span>
    <span>Dicetak: ${timeStr}</span>
  </div>
</div>
</body></html>`);
            doc.close();

            let hasPrinted = false;
            const triggerPrint = () => {
                if (hasPrinted) return;
                hasPrinted = true;
                iframe.contentWindow?.focus();
                iframe.contentWindow?.print();
                iframe.contentWindow?.addEventListener('afterprint', () => {
                    if (document.body.contains(iframe)) {
                        document.body.removeChild(iframe);
                    }
                });
            };

            const imgEl = doc.querySelector('img');
            if (imgEl?.complete) {
                setTimeout(triggerPrint, 200);
            } else {
                imgEl?.addEventListener('load', () => setTimeout(triggerPrint, 100));
                setTimeout(triggerPrint, 600);
            }

            showToast('Dialog cetak siap!');
        } catch (err) {
            console.error('Print dialog error:', err);
            showToast('Gagal cetak, gunakan opsi Unduh PDF.');
        }
    };

    useEffect(() => {
        const handleKeyDown = (e: KeyboardEvent) => {
            if ((e.ctrlKey || e.metaKey) && (e.key.toLowerCase() === 'p' || e.code === 'KeyP' || e.keyCode === 80)) {
                e.preventDefault();
                e.stopPropagation();
                handlePrintDialog();
            }
        };

        window.addEventListener('keydown', handleKeyDown, true);
        return () => window.removeEventListener('keydown', handleKeyDown, true);
    }, []);

    return (
        <>
            <Head title="Dashboard Asset ERP" />

            {/* --- TOAST NOTIFICATION FLOATING --- */}
            {toastMessage && !isExportingPdf && (
                <div className="fixed bottom-6 right-6 z-50 flex items-center gap-3 px-4 py-3 bg-slate-900 text-white dark:bg-white dark:text-slate-900 rounded-2xl shadow-2xl border border-slate-800 dark:border-slate-200 animate-in fade-in slide-in-from-bottom-4 text-xs font-semibold print:hidden">
                    <div className="p-1 rounded-full bg-emerald-500/20 text-emerald-400 dark:text-emerald-600">
                        <CheckCircle2 className="size-4 shrink-0" />
                    </div>
                    <span>{toastMessage}</span>
                    <button onClick={() => setToastMessage(null)} className="ml-2 p-1 text-slate-400 hover:text-white dark:hover:text-slate-900 transition-colors">
                        <X className="size-3.5" />
                    </button>
                </div>
            )}

            <style>{`
                @media print {
                    @page {
                        size: A4 landscape;
                        margin: 4mm 6mm;
                    }
                    html, body {
                        -webkit-print-color-adjust: exact !important;
                        print-color-adjust: exact !important;
                        color-adjust: exact !important;
                        font-size: 9.5px !important;
                        margin: 0 !important;
                        padding: 0 !important;
                    }
                    *, *::before, *::after {
                        animation: none !important;
                        transition: none !important;
                        text-shadow: none !important;
                        -webkit-print-color-adjust: exact !important;
                        print-color-adjust: exact !important;
                        color-adjust: exact !important;
                    }
                    .print\\:hidden {
                        display: none !important;
                    }
                    .print-container {
                        padding: 0 !important;
                        gap: 6px !important;
                        max-width: 100% !important;
                    }
                    .print\\:grid-cols-5 {
                        display: grid !important;
                        grid-template-columns: repeat(5, minmax(0, 1fr)) !important;
                        gap: 6px !important;
                    }
                    .print\\:grid-cols-3 {
                        display: grid !important;
                        grid-template-columns: repeat(3, minmax(0, 1fr)) !important;
                        gap: 6px !important;
                    }
                    .print\\:grid-cols-5 > div {
                        padding: 6px 8px !important;
                    }
                    .print\\:grid-cols-3 > div {
                        padding: 8px 10px !important;
                    }
                    svg, svg * {
                        -webkit-print-color-adjust: exact !important;
                        print-color-adjust: exact !important;
                        opacity: 1 !important;
                        visibility: visible !important;
                    }
                    .recharts-responsive-container {
                        height: 125px !important;
                        width: 100% !important;
                        min-height: 125px !important;
                        display: block !important;
                    }
                    .recharts-wrapper, .recharts-surface {
                        width: 100% !important;
                        height: 125px !important;
                        overflow: visible !important;
                    }
                    .recharts-pie-sector, path.recharts-sector {
                        visibility: visible !important;
                        opacity: 1 !important;
                    }
                    .recharts-bar-rectangle, path.recharts-rectangle, .recharts-bar-rectangles path {
                        fill: #00AFC0 !important;
                        visibility: visible !important;
                        opacity: 1 !important;
                    }
                    .recharts-area-area, path.recharts-area-area {
                        fill: #00AFC0 !important;
                        fill-opacity: 0.35 !important;
                        visibility: visible !important;
                        opacity: 1 !important;
                    }
                    .recharts-area-curve, path.recharts-area-curve {
                        stroke: #00AFC0 !important;
                        stroke-width: 2.5px !important;
                        visibility: visible !important;
                        opacity: 1 !important;
                    }
                    .recharts-line-curve, path.recharts-line-curve {
                        stroke: #00AFC0 !important;
                        stroke-width: 2.5px !important;
                        visibility: visible !important;
                        opacity: 1 !important;
                        stroke-dasharray: none !important;
                        stroke-dashoffset: 0 !important;
                    }
                    .recharts-dot {
                        fill: #00AFC0 !important;
                        opacity: 1 !important;
                    }
                    .grid > div, table, tr {
                        break-inside: avoid !important;
                        page-break-inside: avoid !important;
                    }
                    .print\\:max-w-legend {
                        max-width: 160px !important;
                    }
                    .bg-white {
                        border-color: #cbd5e1 !important;
                        color: #0f172a !important;
                    }
                }
            `}</style>

            {/* =====================================================
                GLOBAL DYNAMIC BACKGROUND PT SANATA SYSTEM (EXACT MATCH PROFILE / SECURITY)
            ====================================================== */}
            <div className="pointer-events-none fixed inset-0 overflow-hidden print:hidden z-0" aria-hidden>
                <div className="absolute inset-0 bg-[linear-gradient(125deg,#E8F5FC_0%,#F6FBFF_38%,#DDFBFC_100%)] dark:bg-[radial-gradient(ellipse_at_top_right,_var(--tw-gradient-stops))] dark:from-[#0B1E36] dark:via-[#070D18] dark:to-[#04070E]" />
                <div className="absolute -right-[140px] -top-[100px] h-[550px] w-[550px] rounded-full bg-[#00B8C8]/15 blur-[120px] dark:bg-[#00C9C8]/15 dark:blur-[140px]" />
                <div className="absolute -left-[180px] top-[140px] h-[520px] w-[520px] rounded-full bg-[#1677FF]/10 blur-[120px] dark:bg-[#005F73]/25 dark:blur-[130px]" />
                <div className="absolute -right-[200px] top-[320px] h-[650px] w-[650px] rounded-full border-[60px] border-[#00B8C8]/10 dark:border-[#00C9C8]/10 dark:blur-sm" />
                <div className="absolute -left-[220px] -bottom-[280px] h-[700px] w-[700px] rounded-full border-[50px] border-[#1677FF]/10 dark:border-[#005F73]/15 dark:blur-sm" />
                <div className="absolute inset-0 opacity-[0.15] dark:opacity-[0.06] [background-image:radial-gradient(circle,rgba(0,201,200,0.35)_1px,transparent_1px)] [background-size:28px_28px]" />
            </div>

            <div id="dashboard-print-root" className={`relative z-10 flex flex-col gap-5 text-slate-800 dark:text-slate-100 w-full max-w-[1600px] mx-auto overflow-x-hidden print-container ${isExportingPdf ? 'p-5' : 'p-3 md:p-6'}`}>
                {/* --- PRINTABLE PDF REPORT HEADER --- */}
                <div className={`${isExportingPdf ? 'block' : 'hidden print:block'} pb-2 mb-1 border-b-2 border-slate-800 text-slate-900`}>
                    <div className="flex items-center justify-between">
                        <div>
                            <h1 className="text-lg font-black tracking-tight text-slate-900 uppercase">
                                LAPORAN EXECUTIVE DASHBOARD ASSET ERP
                            </h1>
                            <p className="text-[10px] text-slate-600 font-semibold">
                                PT CORE ERP INDONESIA • SISTEM MANAJEMEN ASET TERPADU CONTROL PLANE
                            </p>
                        </div>
                        <div className="text-right text-[10px] text-slate-700 font-mono flex items-center gap-4">
                            <div><span className="font-bold">Periode:</span> {dateRange}</div>
                            <div><span className="font-bold">Lokasi:</span> {location}</div>
                            <div><span className="font-bold">Tanggal Cetak:</span> {new Date().toLocaleDateString('id-ID', { day: '2-digit', month: 'long', year: 'numeric' })}</div>
                        </div>
                    </div>
                </div>

                {/* --- HEADER SECTION --- */}
                <div className={`flex flex-col md:flex-row md:items-center justify-between gap-4 pb-3 border-b border-slate-200/60 dark:border-slate-800/60 w-full ${isExportingPdf ? 'hidden' : 'print:hidden'}`}>
                    <div className="flex flex-col gap-1 w-full md:w-auto">
                        <div className="flex items-center gap-2 flex-wrap">
                            <h1 className="text-xl sm:text-2xl font-extrabold tracking-tight text-slate-900 dark:text-slate-100">
                                Dashboard Asset — {currentTenantName}
                            </h1>
                            <span className="px-2.5 py-0.5 text-[10px] font-semibold rounded-full bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 border border-slate-200 dark:border-slate-700 flex items-center gap-1 shrink-0">
                                <Sparkles className="size-3 text-slate-500" /> Live Control
                            </span>
                        </div>
                        <p className="text-xs text-slate-600 dark:text-slate-300 font-medium leading-relaxed">
                            Ringkasan komprehensif kondisi, distribusi nilai, dan pemeliharaan aset untuk workspace <span className="font-bold text-slate-800 dark:text-slate-100">{currentTenantName}</span>
                        </p>
                    </div>

                    <div className="flex flex-wrap sm:flex-nowrap items-center gap-2 shrink-0 justify-start md:justify-end print:hidden py-0.5">
                        {/* 1. Search Input */}
                        <form
                            onSubmit={(e) => {
                                e.preventDefault();
                                if (searchQuery.trim()) {
                                    showToast(`Pencarian: "${searchQuery}" (${filteredSearchResults.length} aset ditemukan)`);
                                }
                            }}
                            className="relative flex-1 sm:flex-initial"
                        >
                            <Search className="absolute left-3.5 top-1/2 -translate-y-1/2 size-3.5 text-slate-400" />
                            <input
                                type="text"
                                placeholder="Cari kode aset, nama"
                                value={searchQuery}
                                onChange={(e) => setSearchQuery(e.target.value)}
                                className="pl-9 pr-7 h-9 text-xs rounded-full border border-slate-200/90 dark:border-slate-800 bg-white dark:bg-slate-900 focus:outline-none focus:ring-2 focus:ring-slate-500/20 focus:border-slate-500 transition-all w-full sm:w-48 md:w-56 shadow-xs"
                            />
                            {searchQuery && (
                                <button
                                    type="button"
                                    onClick={() => setSearchQuery('')}
                                    className="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600 dark:hover:text-slate-200"
                                >
                                    <X className="size-3.5" />
                                </button>
                            )}
                        </form>

                        {/* 2. Global Single Periode Filter Dropdown */}
                        <div className="relative shrink-0">
                            <Button
                                variant="outline"
                                size="sm"
                                type="button"
                                onClick={(e) => {
                                    e.stopPropagation();
                                    const next = !showDateRangeMenu;
                                    setShowExportMenu(false);
                                    setShowNotifications(false);
                                    setShowDateRangeMenu(next);
                                }}
                                className="h-9 gap-1.5 font-medium cursor-pointer"
                            >
                                <Calendar className="size-3.5 text-slate-500 shrink-0" />
                                <span>{dateRange}</span>
                                <ChevronDown className="size-3 text-slate-500 shrink-0 ml-0.5" />
                            </Button>

                            {showDateRangeMenu && (
                                <>
                                    <div className="fixed inset-0 z-40" onClick={() => setShowDateRangeMenu(false)} />
                                    <div className="absolute right-0 mt-2 w-52 bg-white dark:bg-slate-900 rounded-xl shadow-2xl border border-slate-200 dark:border-slate-800 z-50 py-2 text-xs animate-in fade-in slide-in-from-top-2">
                                        <div className="px-4 py-1.5 text-[10px] font-bold tracking-wider text-slate-400 uppercase">Pilih Periode Global</div>
                                        {DASHBOARD_PERIOD_OPTIONS.map((item) => (
                                            <button
                                                type="button"
                                                key={item.key}
                                                onClick={(e) => {
                                                    e.stopPropagation();
                                                    setShowDateRangeMenu(false);
                                                    if (item.isCustom) {
                                                        setShowCustomDateModal(true);
                                                    } else {
                                                        handleSelectPeriod(item.label);
                                                    }
                                                }}
                                                className={`w-full text-left px-4 py-2 hover:bg-slate-100 dark:hover:bg-slate-800 flex items-center justify-between transition-all cursor-pointer ${dateRange === item.label ? 'font-bold bg-slate-100/60 dark:bg-slate-800/60' : 'text-slate-700 dark:text-slate-200'}`}
                                            >
                                                <span>{item.label}</span>
                                                {dateRange === item.label && <Check className="size-3.5" />}
                                            </button>
                                        ))}
                                    </div>
                                </>
                            )}
                        </div>

                        {/* 3. Export / Cetak Dropdown Menu */}
                        <div className="relative shrink-0 print:hidden">
                            <Button
                                variant="default"
                                size="sm"
                                type="button"
                                onClick={(e) => {
                                    e.stopPropagation();
                                    const next = !showExportMenu;
                                    setShowDateRangeMenu(false);
                                    setShowNotifications(false);
                                    setShowExportMenu(next);
                                }}
                                className="h-9 gap-1.5 font-medium cursor-pointer"
                            >
                                <Printer className="size-3.5" />
                                <span>Export / Cetak</span>
                                <ChevronDown className="size-3 opacity-70 ml-0.5" />
                            </Button>

                            {showExportMenu && (
                                <>
                                    <div className="fixed inset-0 z-40" onClick={() => setShowExportMenu(false)} />
                                    <div className="absolute right-0 mt-2 w-72 max-w-[calc(100vw-2rem)] bg-white dark:bg-slate-900 rounded-xl shadow-2xl border border-slate-200 dark:border-slate-800 z-50 p-2 text-xs animate-in fade-in slide-in-from-top-2">
                                        <div className="px-3 py-1.5 text-[10px] font-bold tracking-wider text-slate-400 uppercase">
                                            Pilih Format Export / Cetak
                                        </div>

                                        <button
                                            type="button"
                                            onClick={() => {
                                                setShowExportMenu(false);
                                                handleExportPdf();
                                            }}
                                            className="w-full text-left px-3 py-2.5 hover:bg-slate-100 dark:hover:bg-slate-800 rounded-lg flex items-start gap-3 transition-all text-slate-800 dark:text-slate-100 group cursor-pointer"
                                        >
                                            <div className="p-2 rounded-lg bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-400 group-hover:scale-105 transition-transform mt-0.5">
                                                <FileText className="size-4" />
                                            </div>
                                            <div>
                                                <div className="font-bold text-slate-900 dark:text-white flex items-center gap-1.5">
                                                    <span>Unduh File PDF</span>
                                                    <span className="px-1.5 py-0.5 text-[9px] font-bold bg-slate-100 dark:bg-slate-800 rounded-md">1 Halaman</span>
                                                </div>
                                                <div className="text-[11px] text-slate-500 dark:text-slate-400 leading-snug mt-0.5">
                                                    Simpan langsung PDF A4 Landscape lengkap dengan grafik visual.
                                                </div>
                                            </div>
                                        </button>

                                        <button
                                            type="button"
                                            onClick={() => {
                                                setShowExportMenu(false);
                                                handlePrintDialog();
                                            }}
                                            className="w-full text-left px-3 py-2.5 hover:bg-slate-100 dark:hover:bg-slate-800 rounded-lg flex items-start gap-3 transition-all text-slate-800 dark:text-slate-100 group mt-1 cursor-pointer"
                                        >
                                            <div className="p-2 rounded-lg bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-400 group-hover:scale-105 transition-transform mt-0.5">
                                                <Printer className="size-4" />
                                            </div>
                                            <div>
                                                <div className="font-bold text-slate-900 dark:text-white">
                                                    Cetak / Print Dialog (Ctrl+P)
                                                </div>
                                                <div className="text-[11px] text-slate-500 dark:text-slate-400 leading-snug mt-0.5">
                                                    Pratinjau cetak browser &amp; pilih printer fisik — grafik dijamin tampil.
                                                </div>
                                            </div>
                                        </button>

                                        <button
                                            type="button"
                                            onClick={() => {
                                                setShowExportMenu(false);
                                                handleExportPng();
                                            }}
                                            className="w-full text-left px-3 py-2.5 hover:bg-slate-100 dark:hover:bg-slate-800 rounded-lg flex items-start gap-3 transition-all text-slate-800 dark:text-slate-100 group mt-1 cursor-pointer"
                                        >
                                            <div className="p-2 rounded-lg bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-400 group-hover:scale-105 transition-transform mt-0.5">
                                                <Download className="size-4" />
                                            </div>
                                            <div>
                                                <div className="font-bold text-slate-900 dark:text-white">
                                                    Unduh Gambar (PNG HD)
                                                </div>
                                                <div className="text-[11px] text-slate-500 dark:text-slate-400 leading-snug mt-0.5">
                                                    Simpan gambaran visual dashboard sebagai gambar PNG high-res.
                                                </div>
                                            </div>
                                        </button>
                                    </div>
                                </>
                            )}
                        </div>

                        {/* 4. Notification Dropdown */}
                        <NotificationDropdown />

                        {/* 5. Refresh / Reset Filter Button */}
                        <Button
                            variant="outline"
                            size="icon"
                            type="button"
                            onClick={handleResetAllFilters}
                            title="Refresh Data & Reset Filter"
                            disabled={isRefreshing}
                            className="h-9 w-9 shrink-0 cursor-pointer"
                        >
                            <RefreshCw className={`size-4 ${isRefreshing ? 'animate-spin' : ''}`} />
                        </Button>
                    </div>
                </div>

                {!isInitialDemoBusiness && (
                    <div className="p-5 md:p-6 rounded-2xl bg-slate-900 text-white shadow-xl flex flex-col md:flex-row items-start md:items-center justify-between gap-4 border border-slate-700 my-1">
                        <div className="space-y-1.5 min-w-0">
                            <div className="inline-flex items-center gap-2 px-2.5 py-0.5 rounded-full bg-white/10 text-xs font-semibold backdrop-blur-sm">
                                <Building2 className="size-3.5" />
                                <span>Workspace Aktif: {currentTenantName}</span>
                            </div>
                            <h2 className="text-lg md:text-xl font-bold">Workspace {currentTenantName} Siap Digunakan</h2>
                            <p className="text-xs text-slate-300 max-w-2xl leading-relaxed">
                                Workspace ini masih bersih (belum memiliki aset atau transaksi). Tambahkan aset baru untuk mulai mengelola operasional bisnis ini.
                            </p>
                        </div>
                        <div className="flex flex-wrap items-center gap-2.5 shrink-0">
                            <Link
                                href="/master-data/entitas-aset"
                                className="px-4 py-2.5 text-xs font-bold rounded-full bg-white text-slate-900 hover:bg-slate-100 shadow-md transition-all flex items-center gap-1.5 cursor-pointer"
                            >
                                <Plus className="size-4" />
                                <span>Tambah Entitas Aset</span>
                            </Link>
                        </div>
                    </div>
                )}

                {/* --- INSTANT SEARCH RESULT TABLE CARD --- */}
                {searchQuery.trim() !== '' && (
                    <div className="flex flex-col gap-4 p-5 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-2xl shadow-lg animate-in fade-in slide-in-from-top-2">
                        <div className="flex items-center justify-between pb-3 border-b border-slate-100 dark:border-slate-800">
                            <div className="flex items-center gap-2.5">
                                <Search className="size-4 text-slate-600 dark:text-slate-300" />
                                <div>
                                    <h3 className="text-sm font-bold text-slate-800 dark:text-slate-100">
                                        Hasil Pencarian Aset (Pencarian: Kode, Nama, Kategori, Lokasi)
                                    </h3>
                                    <p className="text-xs text-slate-500 dark:text-slate-400 mt-0.5">
                                        Ditemukan <strong>{filteredSearchResults.length}</strong> aset untuk kata kunci "<span className="font-semibold text-slate-700 dark:text-slate-200">{searchQuery}</span>".
                                    </p>
                                </div>
                            </div>
                            <button
                                onClick={() => setSearchQuery('')}
                                className="px-3 py-1.5 text-xs font-semibold text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800 rounded-xl transition-all flex items-center gap-1"
                            >
                                <X className="size-3.5" />
                                <span>Tutup</span>
                            </button>
                        </div>

                        {filteredSearchResults.length === 0 ? (
                            <div className="py-8 text-center">
                                <Box className="size-10 text-slate-300 dark:text-slate-600 mx-auto mb-2" />
                                <p className="text-xs font-semibold text-slate-600 dark:text-slate-300">Tidak ada aset yang cocok dengan kata kunci "{searchQuery}"</p>
                                <p className="text-[11px] text-slate-400 mt-1">Gunakan pencarian berdasarkan Kode (AST-...), Nama (Mesin, AC, Generator), Kategori, atau Lokasi (Bandung, Surabaya, Kaltim, Jakarta).</p>
                                <Button
                                    variant="outline"
                                    size="sm"
                                    onClick={() => setSearchQuery('')}
                                    className="mt-3"
                                >
                                    Reset Pencarian
                                </Button>
                            </div>
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="w-full text-left border-collapse text-xs">
                                    <thead>
                                        <tr className="border-b border-slate-100 dark:border-slate-800 text-slate-400 uppercase text-[10px] tracking-wider">
                                            <th className="py-2.5 px-3">Kode Asset</th>
                                            <th className="py-2.5 px-3">Nama Asset</th>
                                            <th className="py-2.5 px-3">Kategori</th>
                                            <th className="py-2.5 px-3">Lokasi</th>
                                            <th className="py-2.5 px-3 text-center">Work Order</th>
                                            <th className="py-2.5 px-3">Status</th>
                                            <th className="py-2.5 px-3 text-right">Biaya Servis</th>
                                            <th className="py-2.5 px-3 text-center">Aksi</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-slate-100 dark:divide-slate-800/60">
                                        {filteredSearchResults.map((asset) => (
                                            <tr key={asset.id} className="hover:bg-slate-50/80 dark:hover:bg-slate-800/40 transition-colors">
                                                <td className="py-2.5 px-3 font-mono font-bold text-slate-800 dark:text-slate-100">{asset.id}</td>
                                                <td className="py-2.5 px-3 font-semibold text-slate-800 dark:text-slate-100">{asset.name}</td>
                                                <td className="py-2.5 px-3 text-slate-500">{asset.category}</td>
                                                <td className="py-2.5 px-3">
                                                    <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-md text-[11px] font-medium bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300">
                                                        <MapPin className="size-3 text-slate-400" />
                                                        {asset.location}
                                                    </span>
                                                </td>
                                                <td className="py-2.5 px-3 text-center font-bold">{asset.count} WO</td>
                                                <td className="py-2.5 px-3">
                                                    <span className={`inline-flex items-center gap-1 px-2 py-0.5 rounded-md text-[10px] font-semibold border ${
                                                        asset.status === 'Selesai'
                                                            ? 'bg-slate-100 text-slate-700 border-slate-200'
                                                            : asset.status === 'Dalam Proses'
                                                            ? 'bg-slate-100 text-slate-700 border-slate-200'
                                                            : 'bg-slate-100 text-slate-700 border-slate-200'
                                                    }`}>
                                                        {asset.status}
                                                    </span>
                                                </td>
                                                <td className="py-2.5 px-3 text-right font-semibold text-slate-700 dark:text-slate-200">{asset.cost}</td>
                                                <td className="py-2.5 px-3 text-center">
                                                    <Button
                                                        variant="outline"
                                                        size="xs"
                                                        onClick={() => setSelectedAssetDetail(asset)}
                                                    >
                                                        <Eye className="size-3 mr-1" />
                                                        Detail
                                                    </Button>
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </div>
                )}

                {/* --- 5 SUMMARY KPI CARDS --- */}
                <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-4 print:grid-cols-5">
                    {/* KPI 1: Total Asset */}
                    <div
                        onClick={() => {
                            const next = focusedKpi === 'total' ? null : 'total';
                            setFocusedKpi(next);
                            showToast(next ? 'Menampilkan detail Total Asset' : 'Reset filter KPI');
                        }}
                        className={`relative z-10 p-4 rounded-2xl bg-white dark:bg-slate-900 border transition-all duration-200 cursor-pointer flex flex-col justify-between overflow-hidden group ${focusedKpi === 'total' ? 'ring-2 ring-slate-900 dark:ring-slate-500 border-slate-900 dark:border-slate-500 shadow-md' : 'border-slate-200/80 dark:border-slate-800/80 shadow-xs hover:shadow-lg hover:-translate-y-0.5'}`}
                    >
                        <div className="flex items-center justify-between">
                            <span className="text-xs font-medium text-slate-500 dark:text-slate-400">Total Aset</span>
                            <Box className="size-5 text-slate-600 dark:text-slate-300" />
                        </div>
                        <div className="mt-3">
                            <div className="text-2xl font-black tracking-tight text-slate-900 dark:text-white">
                                {totalKondisi.toLocaleString('id-ID')}
                            </div>
                            <div className="flex items-center gap-1 text-[11px] font-semibold text-slate-600 dark:text-slate-400 mt-1">
                                {isInitialDemoBusiness ? (
                                    <>
                                        <TrendingUp className="size-3" />
                                        <span>+5,4% <span className="font-normal text-slate-400">dibanding bulan lalu</span></span>
                                    </>
                                ) : (
                                    <span className="font-normal text-slate-400">0 aset terdaftar</span>
                                )}
                            </div>
                        </div>
                    </div>

                    {/* KPI 2: Total Nilai Asset */}
                    <div
                        onClick={() => {
                            const next = focusedKpi === 'nilai' ? null : 'nilai';
                            setFocusedKpi(next);
                            showToast(next ? 'Menampilkan detail Nilai Asset' : 'Reset filter KPI');
                        }}
                        className={`relative z-10 p-4 rounded-2xl bg-white dark:bg-slate-900 border transition-all duration-200 cursor-pointer flex flex-col justify-between overflow-hidden group ${focusedKpi === 'nilai' ? 'ring-2 ring-slate-900 dark:ring-slate-500 border-slate-900 dark:border-slate-500 shadow-md' : 'border-slate-200/80 dark:border-slate-800/80 shadow-xs hover:shadow-lg hover:-translate-y-0.5'}`}
                    >
                        <div className="flex items-center justify-between">
                            <span className="text-xs font-medium text-slate-500 dark:text-slate-400">Total Nilai Aset</span>
                            <Wallet className="size-5 text-slate-600 dark:text-slate-300" />
                        </div>
                        <div className="mt-3">
                            <div className="text-2xl font-black tracking-tight text-slate-900 dark:text-white">Rp {totalNilaiAssetMiliar} M</div>
                            <div className="flex items-center gap-1 text-[11px] font-semibold text-slate-600 dark:text-slate-400 mt-1">
                                {isInitialDemoBusiness ? (
                                    <>
                                        <TrendingUp className="size-3" />
                                        <span>+3,2% <span className="font-normal text-slate-400">dibanding bulan lalu</span></span>
                                    </>
                                ) : (
                                    <span className="font-normal text-slate-400">Rp 0 total nilai</span>
                                )}
                            </div>
                        </div>
                    </div>

                    {/* KPI 3: Kondisi Asset (Baik) */}
                    <div
                        onClick={() => {
                            const next = focusedKpi === 'kondisi' ? null : 'kondisi';
                            setFocusedKpi(next);
                            showToast(next ? 'Menampilkan detail Kondisi Baik' : 'Reset filter KPI');
                        }}
                        className={`relative z-10 p-4 rounded-2xl bg-white dark:bg-slate-900 border transition-all duration-200 cursor-pointer flex flex-col justify-between overflow-hidden group ${focusedKpi === 'kondisi' ? 'ring-2 ring-slate-900 dark:ring-slate-500 border-slate-900 dark:border-slate-500 shadow-md' : 'border-slate-200/80 dark:border-slate-800/80 shadow-xs hover:shadow-lg hover:-translate-y-0.5'}`}
                    >
                        <div className="flex items-center justify-between">
                            <span className="text-xs font-medium text-slate-500 dark:text-slate-400">Kondisi Aset (Baik)</span>
                            <ShieldCheck className="size-5 text-slate-600 dark:text-slate-300" />
                        </div>
                        <div className="mt-3">
                            <div className="text-2xl font-black tracking-tight text-slate-900 dark:text-white">
                                {baikPercent}
                            </div>
                            <div className="flex items-center gap-1 text-[11px] font-semibold text-slate-600 dark:text-slate-400 mt-1">
                                {isInitialDemoBusiness ? (
                                    <>
                                        <TrendingDown className="size-3" />
                                        <span>-2,5% <span className="font-normal text-slate-400">dibanding bulan lalu</span></span>
                                    </>
                                ) : (
                                    <span className="font-normal text-slate-400">0% kondisi baik</span>
                                )}
                            </div>
                        </div>
                    </div>

                    {/* KPI 4: Nilai Buku Bersih */}
                    <div
                        onClick={() => {
                            const next = focusedKpi === 'buku' ? null : 'buku';
                            setFocusedKpi(next);
                            showToast(next ? 'Menampilkan detail Nilai Buku Bersih' : 'Reset filter KPI');
                        }}
                        className={`relative z-10 p-4 rounded-2xl bg-white dark:bg-slate-900 border transition-all duration-200 cursor-pointer flex flex-col justify-between overflow-hidden group ${focusedKpi === 'buku' ? 'ring-2 ring-slate-900 dark:ring-slate-500 border-slate-900 dark:border-slate-500 shadow-md' : 'border-slate-200/80 dark:border-slate-800/80 shadow-xs hover:shadow-lg hover:-translate-y-0.5'}`}
                    >
                        <div className="flex items-center justify-between">
                            <span className="text-xs font-medium text-slate-500 dark:text-slate-400">Nilai Buku Bersih</span>
                            <TrendingUp className="size-5 text-slate-600 dark:text-slate-300" />
                        </div>
                        <div className="mt-3">
                            <div className="text-2xl font-black tracking-tight text-slate-900 dark:text-white">Rp {latestBukuHabisValue} M</div>
                            <div className="flex items-center gap-1 text-[11px] font-semibold text-slate-600 dark:text-slate-400 mt-1">
                                {isInitialDemoBusiness ? (
                                    <>
                                        <TrendingUp className="size-3" />
                                        <span>+8,7% <span className="font-normal text-slate-400">dibanding bulan lalu</span></span>
                                    </>
                                ) : (
                                    <span className="font-normal text-slate-400">0 nilai buku</span>
                                )}
                            </div>
                        </div>
                    </div>

                    {/* KPI 5: Work Order Selesai */}
                    <div
                        onClick={() => {
                            const next = focusedKpi === 'wo' ? null : 'wo';
                            setFocusedKpi(next);
                            showToast(next ? 'Menampilkan detail Work Order Selesai' : 'Reset filter KPI');
                        }}
                        className={`relative z-10 p-4 rounded-2xl bg-white dark:bg-slate-900 border transition-all duration-200 cursor-pointer flex flex-col justify-between overflow-hidden group ${focusedKpi === 'wo' ? 'ring-2 ring-slate-900 dark:ring-slate-500 border-slate-900 dark:border-slate-500 shadow-md' : 'border-slate-200/80 dark:border-slate-800/80 shadow-xs hover:shadow-lg hover:-translate-y-0.5'}`}
                    >
                        <div className="flex items-center justify-between">
                            <span className="text-xs font-medium text-slate-500 dark:text-slate-400">Work Order Selesai</span>
                            <Wrench className="size-5 text-slate-600 dark:text-slate-300" />
                        </div>
                        <div className="mt-3">
                            <div className="text-2xl font-black tracking-tight text-slate-900 dark:text-white">
                                {woSelesaiValue.toLocaleString('id-ID')}
                            </div>
                            <div className="flex items-center gap-1 text-[11px] font-semibold text-slate-600 dark:text-slate-400 mt-1">
                                {isInitialDemoBusiness ? (
                                    <>
                                        <TrendingUp className="size-3" />
                                        <span>+12,5% <span className="font-normal text-slate-400">dibanding bulan lalu</span></span>
                                    </>
                                ) : (
                                    <span className="font-normal text-slate-400">0 work order</span>
                                )}
                            </div>
                        </div>
                    </div>
                </div>

                {/* --- BARIS 1 (3 WIDGETS) --- */}
                <div className="grid grid-cols-1 lg:grid-cols-3 gap-5 print:grid-cols-3">
                    {/* WIDGET 1: Distribusi Kondisi Asset */}
                    <div className="relative z-10 p-5 rounded-2xl bg-white dark:bg-slate-900 border border-slate-200/80 dark:border-slate-800/80 shadow-xs flex flex-col justify-between">
                        <div className="flex items-center justify-between mb-2">
                            <div>
                                <h3 className="text-sm font-bold text-slate-900 dark:text-slate-100">Distribusi Kondisi Asset</h3>
                            </div>

                            {/* Location Selector (Filter lokasi diperbolehkan) */}
                            <div className="relative">
                                <Button
                                    variant="outline"
                                    size="xs"
                                    type="button"
                                    onClick={(e) => {
                                        e.stopPropagation();
                                        setShowDateRangeMenu(false);
                                        setShowExportMenu(false);
                                        setShowNotifications(false);
                                        setShowCategoryUnitMenu(false);
                                        setShowLocationMenu(!showLocationMenu);
                                    }}
                                    className="h-8 gap-1.5 font-bold cursor-pointer"
                                >
                                    <MapPin className="size-3.5 text-slate-500 shrink-0" />
                                    <span>{location}</span>
                                    <ChevronDown className="size-3 text-slate-500 shrink-0 ml-0.5" />
                                </Button>

                                {showLocationMenu && (
                                    <>
                                        <div className="fixed inset-0 z-30" onClick={() => setShowLocationMenu(false)} />
                                        <div className="absolute right-0 mt-2 w-52 bg-white dark:bg-slate-900 rounded-2xl shadow-2xl border border-slate-200 dark:border-slate-800 z-40 py-2 text-xs animate-in fade-in slide-in-from-top-2">
                                            {Object.keys(DOUGHNUT_KONDISI_DATA).map((loc) => (
                                                <button
                                                    type="button"
                                                    key={loc}
                                                    onClick={(e) => {
                                                        e.stopPropagation();
                                                        setLocation(loc);
                                                        setShowLocationMenu(false);
                                                        showToast(`Lokasi diganti: ${loc}`);
                                                    }}
                                                    className={`w-full text-left px-4 py-2 hover:bg-slate-100 dark:hover:bg-slate-800 flex items-center justify-between transition-all cursor-pointer ${location === loc ? 'font-bold bg-slate-100/60 dark:bg-slate-800/60' : 'text-slate-700 dark:text-slate-200'}`}
                                                >
                                                    <span>{loc}</span>
                                                    {location === loc && <Check className="size-3.5" />}
                                                </button>
                                            ))}
                                        </div>
                                    </>
                                )}
                            </div>
                        </div>

                        <div className="flex items-center gap-3 py-2">
                            {/* Donut Chart */}
                            <div className="relative h-36 w-36 shrink-0 flex items-center justify-center">
                                <ResponsiveContainer width="100%" height="100%">
                                    <PieChart>
                                        <Pie
                                            data={DOUGHNUT_KONDISI}
                                            innerRadius={46}
                                            outerRadius={64}
                                            paddingAngle={2}
                                            dataKey="value"
                                            startAngle={90}
                                            endAngle={-270}
                                            isAnimationActive={false}
                                            onMouseEnter={(_, idx) => setHoveredKondisi(DOUGHNUT_KONDISI[idx])}
                                            onMouseLeave={() => setHoveredKondisi(null)}
                                        >
                                            {DOUGHNUT_KONDISI.map((entry, index) => (
                                                <Cell
                                                    key={`cell-${index}`}
                                                    fill={entry.color}
                                                    stroke={hoveredKondisi?.name === entry.name ? '#ffffff' : 'none'}
                                                    strokeWidth={2}
                                                    className="transition-all duration-200 cursor-pointer"
                                                />
                                            ))}
                                        </Pie>
                                    </PieChart>
                                </ResponsiveContainer>

                                {/* Center Text Display */}
                                <div className="absolute inset-0 flex flex-col items-center justify-center pointer-events-none text-center px-1">
                                    {hoveredKondisi ? (
                                        <div className="animate-in fade-in zoom-in-95 duration-150">
                                            <span className="text-xl font-extrabold tracking-tight block leading-tight text-slate-900 dark:text-white">
                                                {hoveredKondisi.value.toLocaleString('id-ID')}
                                            </span>
                                            <span className="block text-[10px] font-bold text-slate-700 dark:text-slate-200 truncate max-w-[80px] mx-auto">
                                                {hoveredKondisi.name}
                                            </span>
                                            <span className="text-[10px] font-medium text-slate-400">
                                                ({hoveredKondisi.percent})
                                            </span>
                                        </div>
                                    ) : (
                                        <div>
                                            <span className="text-xl font-extrabold text-slate-900 dark:text-white tracking-tight leading-tight block">
                                                {totalKondisi.toLocaleString('id-ID')}
                                            </span>
                                            <span className="block text-[10px] text-slate-500 font-semibold mt-0.5">
                                                Asset
                                            </span>
                                        </div>
                                    )}
                                </div>
                            </div>

                            {/* Clean Legend */}
                            <div className="flex-1 flex flex-col gap-2 text-xs min-w-0 print:max-w-legend">
                                {DOUGHNUT_KONDISI.map((item) => (
                                    <div
                                        key={item.name}
                                        onMouseEnter={() => setHoveredKondisi(item)}
                                        onMouseLeave={() => setHoveredKondisi(null)}
                                        className={`flex items-center justify-between py-1 px-1.5 rounded-lg transition-all cursor-pointer ${hoveredKondisi?.name === item.name ? 'bg-slate-100 dark:bg-slate-800' : 'hover:bg-slate-50 dark:hover:bg-slate-800/40'}`}
                                    >
                                        <div className="flex items-center gap-2 min-w-0 pr-1">
                                            <span className="size-2.5 rounded-full shrink-0" style={{ backgroundColor: item.color }} />
                                            <span className="text-slate-700 dark:text-slate-200 font-semibold text-xs whitespace-nowrap">{item.name}</span>
                                        </div>
                                        <div className="flex items-center gap-1.5 shrink-0 ml-auto text-xs font-mono">
                                            <span className="font-extrabold text-slate-900 dark:text-slate-100">
                                                {item.value.toLocaleString('id-ID')}
                                            </span>
                                            <span className="text-slate-500 dark:text-slate-400 font-medium">
                                                ({item.percent})
                                            </span>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        </div>
                    </div>

                    {/* WIDGET 2: Nilai Asset Berdasarkan Kategori */}
                    <div className="relative z-10 p-5 rounded-2xl bg-white dark:bg-slate-900 border border-slate-200/80 dark:border-slate-800/80 shadow-xs flex flex-col justify-between">
                        <div className="flex items-center justify-between mb-3">
                            <div>
                                <h3 className="text-sm font-bold text-slate-900 dark:text-slate-100">Nilai Asset Berdasarkan Kategori</h3>
                            </div>

                            {/* Category Unit Selector */}
                            <div className="relative">
                                <Button
                                    variant="outline"
                                    size="xs"
                                    type="button"
                                    onClick={(e) => {
                                        e.stopPropagation();
                                        setShowDateRangeMenu(false);
                                        setShowExportMenu(false);
                                        setShowNotifications(false);
                                        setShowLocationMenu(false);
                                        setShowCategoryUnitMenu(!showCategoryUnitMenu);
                                    }}
                                    className="h-8 gap-1.5 font-bold cursor-pointer"
                                >
                                    <span>{categoryUnit}</span>
                                    <ChevronDown className="size-3 text-slate-500 shrink-0 ml-0.5" />
                                </Button>

                                {showCategoryUnitMenu && (
                                    <>
                                        <div className="fixed inset-0 z-30" onClick={() => setShowCategoryUnitMenu(false)} />
                                        <div className="absolute right-0 mt-2 w-44 bg-white dark:bg-slate-900 rounded-2xl shadow-2xl border border-slate-200 dark:border-slate-800 z-40 py-2 text-xs animate-in fade-in slide-in-from-top-2">
                                            {['Rp (Juta)', 'Rp (Miliar)', 'Jumlah Unit'].map((unit) => (
                                                <button
                                                    type="button"
                                                    key={unit}
                                                    onClick={(e) => {
                                                        e.stopPropagation();
                                                        setCategoryUnit(unit);
                                                        setShowCategoryUnitMenu(false);
                                                        showToast(`Skala grafik diubah ke: ${unit}`);
                                                    }}
                                                    className={`w-full text-left px-4 py-2 hover:bg-slate-100 dark:hover:bg-slate-800 flex items-center justify-between transition-all cursor-pointer ${categoryUnit === unit ? 'font-bold bg-slate-100/60 dark:bg-slate-800/60' : 'text-slate-700 dark:text-slate-200'}`}
                                                >
                                                    <span>{unit}</span>
                                                    {categoryUnit === unit && <Check className="size-3.5" />}
                                                </button>
                                            ))}
                                        </div>
                                    </>
                                )}
                            </div>
                        </div>

                        {/* Bar Chart */}
                        <div className="h-52 mt-1">
                            <ResponsiveContainer width="100%" height="100%">
                                <BarChart data={barKategori} margin={{ top: 25, right: 5, left: -10, bottom: 25 }}>
                                    <XAxis
                                        dataKey="name"
                                        tick={<CustomCategoryTick />}
                                        axisLine={false}
                                        tickLine={false}
                                        interval={0}
                                        height={45}
                                    />
                                    <YAxis
                                        tick={{ fontSize: 10, fill: '#94a3b8' }}
                                        axisLine={false}
                                        tickLine={false}
                                        tickFormatter={(val) => {
                                            if (categoryUnit === 'Rp (Juta)') return val === 0 ? '0' : `${val / 1000}k`;
                                            return `${val}`;
                                        }}
                                    />
                                    <RechartsTooltip
                                        cursor={{ fill: 'rgba(148, 163, 184, 0.1)' }}
                                        content={<CustomChartTooltip unitFormatter={(v: any) => categoryUnit === 'Jumlah Unit' ? `${v} Unit` : `Rp ${v} ${categoryUnit.includes('Juta') ? 'Juta' : 'Miliar'}`} />}
                                    />
                                    <Bar dataKey="value" fill="#334155" radius={[4, 4, 0, 0]} barSize={24} isAnimationActive={false}>
                                        <LabelList dataKey="displayVal" position="top" fill="#334155" fontSize={11} fontWeight={800} offset={6} />
                                    </Bar>
                                </BarChart>
                            </ResponsiveContainer>
                        </div>
                    </div>

                    {/* WIDGET 3: 10 Aset dengan Work Order Terbanyak */}
                    <div className="relative z-10 p-5 rounded-2xl bg-white dark:bg-slate-900 border border-slate-200/80 dark:border-slate-800/80 shadow-xs flex flex-col justify-between">
                        <div className="flex items-center justify-between mb-2">
                            <h3 className="text-sm font-bold text-slate-900 dark:text-slate-100">10 Aset dengan Work Order Terbanyak</h3>
                            <button
                                type="button"
                                onClick={() => setShowAllMaintenanceModal(true)}
                                className="text-xs font-bold text-slate-700 dark:text-slate-200 hover:underline cursor-pointer transition-colors"
                            >
                                Lihat Semua
                            </button>
                        </div>

                        <div className="overflow-y-auto max-h-52 pr-1 custom-scrollbar">
                            <table className="w-full text-left text-xs border-collapse">
                                <thead>
                                    <tr className="text-slate-400 dark:text-slate-500 border-b border-slate-100 dark:border-slate-800 text-[10px] uppercase tracking-wider">
                                        <th className="pb-2 font-semibold">Asset</th>
                                        <th className="pb-2 font-semibold text-center">Work Order</th>
                                        <th className="pb-2 font-semibold text-right">Status</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100 dark:divide-slate-800/60">
                                    {ALL_MAINTENANCE_ASSETS.slice(0, 10).map((item) => (
                                        <tr
                                            key={item.id}
                                            onClick={() => setSelectedAssetDetail(item)}
                                            className="hover:bg-slate-50 dark:hover:bg-slate-800/50 cursor-pointer transition-all group"
                                        >
                                            <td className="py-2 pr-2 font-semibold text-slate-800 dark:text-slate-200 truncate max-w-[130px] group-hover:text-slate-900">
                                                {item.name}
                                            </td>
                                            <td className="py-2 px-2 text-center font-bold text-slate-700 dark:text-slate-300 font-mono text-xs">
                                                {item.count}
                                            </td>
                                            <td className="py-2 pl-2 text-right shrink-0">
                                                <span className="inline-block px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-slate-100 text-slate-700 border border-slate-200">
                                                    {item.status}
                                                </span>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                {/* --- BARIS 2 (3 WIDGETS) --- */}
                <div className="grid grid-cols-1 lg:grid-cols-3 gap-5 print:grid-cols-3">
                    {/* WIDGET 4: Trend Nilai Buku Bersih */}
                    <div className="relative z-10 p-5 rounded-2xl bg-white dark:bg-slate-900 border border-slate-200/80 dark:border-slate-800/80 shadow-xs flex flex-col justify-between">
                        <div className="flex items-center justify-between mb-1">
                            <h3 className="text-sm font-bold text-slate-900 dark:text-slate-100">Trend Nilai Buku Bersih</h3>
                            <span className="text-[11px] font-semibold text-slate-400">(Rp Miliar)</span>
                        </div>

                        <div className="h-44 mt-1">
                            <ResponsiveContainer width="100%" height="100%">
                                <AreaChart data={areaBukuHabis} margin={{ top: 10, right: 10, left: -20, bottom: 0 }}>
                                    <defs>
                                        <linearGradient id="blueGradientBuku" x1="0" y1="0" x2="0" y2="1">
                                            <stop offset="5%" stopColor="#00AFC0" stopOpacity={0.35} />
                                            <stop offset="95%" stopColor="#00AFC0" stopOpacity={0.02} />
                                        </linearGradient>
                                    </defs>
                                    <XAxis dataKey="month" tick={{ fontSize: 10, fill: '#94a3b8' }} axisLine={false} tickLine={false} />
                                    <YAxis tick={{ fontSize: 10, fill: '#94a3b8' }} axisLine={false} tickLine={false} domain={[0, 4]} />
                                    <RechartsTooltip content={<CustomChartTooltip unitFormatter={(v: any) => `Rp ${v} Miliar`} />} />
                                    <Area type="monotone" dataKey="value" stroke="#00AFC0" strokeWidth={2.5} fillOpacity={1} fill="url(#blueGradientBuku)" dot={{ r: 3.5, fill: '#00AFC0', stroke: '#ffffff', strokeWidth: 1.5 }} activeDot={{ r: 5, fill: '#008B9B' }} isAnimationActive={false} />
                                </AreaChart>
                            </ResponsiveContainer>
                        </div>
                        <div className="flex items-center gap-2 mt-2 text-[11px] text-slate-500 font-medium">
                            <span className="size-2 rounded-full bg-[#00AFC0]" />
                            <span>Nilai Buku Bersih (Rp Miliar)</span>
                        </div>
                    </div>

                    {/* WIDGET 5: Persentase Penyelesaian Work Order */}
                    <div className="relative z-10 p-5 rounded-2xl bg-white dark:bg-slate-900 border border-slate-200/80 dark:border-slate-800/80 shadow-xs flex flex-col justify-between">
                        <div className="flex items-center justify-between mb-2">
                            <h3 className="text-sm font-bold text-slate-900 dark:text-slate-100">Persentase Penyelesaian Work Order</h3>
                        </div>

                        <div className="flex items-center gap-3 py-2">
                            {/* Donut Chart */}
                            <div className="relative h-36 w-36 shrink-0 flex items-center justify-center">
                                <ResponsiveContainer width="100%" height="100%">
                                    <PieChart>
                                        <Pie
                                            data={DOUGHNUT_WORK_ORDER}
                                            innerRadius={46}
                                            outerRadius={64}
                                            paddingAngle={2}
                                            dataKey="value"
                                            startAngle={90}
                                            endAngle={-270}
                                            isAnimationActive={false}
                                            onMouseEnter={(_, idx) => setHoveredWo(DOUGHNUT_WORK_ORDER[idx])}
                                            onMouseLeave={() => setHoveredWo(null)}
                                        >
                                            {DOUGHNUT_WORK_ORDER.map((entry, index) => (
                                                <Cell
                                                    key={`cell-wo-${index}`}
                                                    fill={entry.color}
                                                    stroke={hoveredWo?.name === entry.name ? '#ffffff' : 'none'}
                                                    strokeWidth={2}
                                                    className="transition-all duration-200 cursor-pointer"
                                                />
                                            ))}
                                        </Pie>
                                    </PieChart>
                                </ResponsiveContainer>

                                {/* Center Display */}
                                <div className="absolute inset-0 flex flex-col items-center justify-center pointer-events-none text-center px-1">
                                    {hoveredWo ? (
                                        <div className="animate-in fade-in zoom-in-95 duration-150">
                                            <span className="text-xl font-extrabold tracking-tight block leading-tight" style={{ color: hoveredWo.color }}>
                                                {hoveredWo.value.toLocaleString('id-ID')}
                                            </span>
                                            <span className="block text-[10px] font-bold text-slate-700 dark:text-slate-200 truncate max-w-[80px] mx-auto">
                                                {hoveredWo.name}
                                            </span>
                                            <span className="text-[10px] font-medium text-slate-400">
                                                ({hoveredWo.percent})
                                            </span>
                                        </div>
                                    ) : (
                                        <div>
                                            <span className="text-xl font-extrabold text-slate-900 dark:text-white tracking-tight leading-tight block">
                                                {totalWorkOrder.toLocaleString('id-ID')}
                                            </span>
                                            <span className="block text-[10px] text-slate-500 font-semibold mt-0.5">
                                                Total
                                            </span>
                                        </div>
                                    )}
                                </div>
                            </div>

                            {/* Clean Legend */}
                            <div className="flex-1 flex flex-col gap-2 text-xs min-w-0 print:max-w-legend">
                                {DOUGHNUT_WORK_ORDER.map((item) => (
                                    <div
                                        key={item.name}
                                        onMouseEnter={() => setHoveredWo(item)}
                                        onMouseLeave={() => setHoveredWo(null)}
                                        className={`flex items-center justify-between py-1 px-1.5 rounded-lg transition-all cursor-pointer ${hoveredWo?.name === item.name ? 'bg-slate-100 dark:bg-slate-800' : 'hover:bg-slate-50 dark:hover:bg-slate-800/40'}`}
                                    >
                                        <div className="flex items-center gap-2 min-w-0 pr-1">
                                            <span className="size-2.5 rounded-full shrink-0" style={{ backgroundColor: item.color }} />
                                            <span className="text-slate-700 dark:text-slate-200 font-semibold text-xs whitespace-nowrap">{item.name}</span>
                                        </div>
                                        <div className="flex items-center gap-1.5 shrink-0 ml-auto text-xs font-mono">
                                            <span className="font-extrabold text-slate-900 dark:text-slate-100">
                                                {item.value.toLocaleString('id-ID')}
                                            </span>
                                            <span className="text-slate-500 dark:text-slate-400 font-medium">
                                                ({item.percent})
                                            </span>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        </div>
                    </div>

                    {/* WIDGET 6: Trend Pengadaan Asset */}
                    <div className="relative z-10 p-5 rounded-2xl bg-white dark:bg-slate-900 border border-slate-200/80 dark:border-slate-800/80 shadow-xs flex flex-col justify-between">
                        <div className="flex items-center justify-between mb-1">
                            <h3 className="text-sm font-bold text-slate-900 dark:text-slate-100">Trend Pengadaan Asset</h3>
                            <span className="text-[11px] font-semibold text-slate-400">(Rp Juta)</span>
                        </div>

                        <div className="h-44 mt-1">
                            <ResponsiveContainer width="100%" height="100%">
                                <AreaChart data={lineTrend} margin={{ top: 10, right: 10, left: -20, bottom: 0 }}>
                                    <defs>
                                        <linearGradient id="blueGradientPengadaan" x1="0" y1="0" x2="0" y2="1">
                                            <stop offset="5%" stopColor="#00AFC0" stopOpacity={0.35} />
                                            <stop offset="95%" stopColor="#00AFC0" stopOpacity={0.02} />
                                        </linearGradient>
                                    </defs>
                                    <XAxis dataKey="month" tick={{ fontSize: 10, fill: '#94a3b8' }} axisLine={false} tickLine={false} />
                                    <YAxis tick={{ fontSize: 10, fill: '#94a3b8' }} axisLine={false} tickLine={false} domain={[0, 35]} />
                                    <RechartsTooltip content={<CustomChartTooltip unitFormatter={(v: any) => `Rp ${v} Juta`} />} />
                                    <Area type="monotone" dataKey="value" stroke="#00AFC0" strokeWidth={2.5} fillOpacity={1} fill="url(#blueGradientPengadaan)" dot={{ r: 3.5, fill: '#00AFC0', stroke: '#ffffff', strokeWidth: 1.5 }} activeDot={{ r: 5, fill: '#008B9B' }} isAnimationActive={false} />
                                </AreaChart>
                            </ResponsiveContainer>
                        </div>
                        <div className="flex items-center gap-2 mt-2 text-[11px] text-slate-500 font-medium">
                            <span className="size-2 rounded-full bg-[#00AFC0]" />
                            <span>Nilai Pengadaan (Rp Juta)</span>
                        </div>
                    </div>
                </div>

                {/* --- PRINTABLE PDF REPORT FOOTER --- */}
                <div className={`${isExportingPdf ? 'flex' : 'hidden print:flex'} items-center justify-between pt-4 mt-6 border-t border-slate-300 text-[10px] text-slate-500 font-mono`}>
                    <div>Dokumen Resmi ERP Control Plane • Rahasia Internal</div>
                    <div>Halaman 1 dari 1</div>
                    <div>Dicetak Otomatis pada {new Date().toLocaleString('id-ID')}</div>
                </div>
            </div>

            {/* --- MODAL: CUSTOM DATE RANGE PICKER --- */}
            {showCustomDateModal && !isExportingPdf && (
                <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-xs animate-in fade-in">
                    <div className="bg-white dark:bg-slate-900 w-full max-w-md rounded-2xl shadow-2xl border border-slate-200 dark:border-slate-800 p-6 flex flex-col gap-4">
                        <div className="flex items-center justify-between pb-3 border-b border-slate-100 dark:border-slate-800">
                            <div className="flex items-center gap-2">
                                <div className="p-2 rounded-xl bg-[#EAFBFC] text-[#00AFC0]">
                                    <Calendar className="size-5" />
                                </div>
                                <h3 className="text-base font-bold text-slate-800 dark:text-slate-100">Pilih Rentang Tanggal Custom</h3>
                            </div>
                            <button
                                onClick={() => setShowCustomDateModal(false)}
                                className="p-1.5 rounded-xl hover:bg-slate-100 dark:hover:bg-slate-800 text-slate-400"
                            >
                                <X className="size-4" />
                            </button>
                        </div>

                        <div className="flex flex-col gap-3 text-xs">
                            <div>
                                <label className="block text-slate-600 dark:text-slate-400 font-semibold mb-1">Tanggal Mulai</label>
                                <input
                                    type="date"
                                    value={customStartDate}
                                    onChange={(e) => setCustomStartDate(e.target.value)}
                                    className="w-full px-3 py-2 rounded-xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 text-xs focus:border-[#00AFC0] focus:ring-[#00AFC0]/20"
                                />
                            </div>
                            <div>
                                <label className="block text-slate-600 dark:text-slate-400 font-semibold mb-1">Tanggal Selesai</label>
                                <input
                                    type="date"
                                    value={customEndDate}
                                    onChange={(e) => setCustomEndDate(e.target.value)}
                                    className="w-full px-3 py-2 rounded-xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 text-xs focus:border-[#00AFC0] focus:ring-[#00AFC0]/20"
                                />
                            </div>
                        </div>

                        <div className="flex items-center justify-end gap-2 pt-3 border-t border-slate-100 dark:border-slate-800">
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={() => setShowCustomDateModal(false)}
                            >
                                Batal
                            </Button>
                            <Button
                                type="button"
                                variant="default"
                                size="sm"
                                onClick={handleApplyCustomDate}
                            >
                                Terapkan Filter
                            </Button>
                        </div>
                    </div>
                </div>
            )}

            {/* --- MODAL: LIHAT SEMUA ASET TERJADWAL MAINTENANCE --- */}
            {showAllMaintenanceModal && !isExportingPdf && (
                <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-xs animate-in fade-in">
                    <div className="bg-white dark:bg-slate-900 w-full max-w-4xl rounded-2xl shadow-2xl border border-slate-200 dark:border-slate-800 flex flex-col max-h-[85vh] overflow-hidden">
                        <div className="flex items-center justify-between px-6 py-4 border-b border-slate-100 dark:border-slate-800">
                            <div>
                                <h3 className="text-base font-bold text-slate-800 dark:text-slate-100">Semua Asset Maintenance</h3>
                                <p className="text-xs text-slate-500 dark:text-slate-400">Daftar lengkap riwayat dan frekuensi maintenance seluruh aset</p>
                            </div>
                            <button
                                onClick={() => setShowAllMaintenanceModal(false)}
                                className="p-2 rounded-xl hover:bg-slate-100 dark:hover:bg-slate-800 text-slate-400"
                            >
                                <X className="size-5" />
                            </button>
                        </div>

                        <div className="overflow-y-auto p-6 flex-1">
                            <table className="w-full text-left text-xs">
                                <thead>
                                    <tr className="text-slate-400 border-b border-slate-200 dark:border-slate-800 uppercase text-[10px] tracking-wider">
                                        <th className="pb-3 font-semibold">ID Aset</th>
                                        <th className="pb-3 font-semibold">Nama Asset</th>
                                        <th className="pb-3 font-semibold">Kategori</th>
                                        <th className="pb-3 font-semibold">Lokasi</th>
                                        <th className="pb-3 font-semibold text-center">Total WO</th>
                                        <th className="pb-3 font-semibold text-right">Status</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                                    {ALL_MAINTENANCE_ASSETS.map((item) => (
                                        <tr key={item.id} className="hover:bg-slate-50 dark:hover:bg-slate-800/40 transition-all">
                                            <td className="py-3 font-mono text-[#00AFC0] dark:text-cyan-400 font-bold">{item.id}</td>
                                            <td className="py-3 font-semibold text-slate-800 dark:text-slate-100">{item.name}</td>
                                            <td className="py-3 text-slate-500">{item.category}</td>
                                            <td className="py-3 text-slate-500">{item.location}</td>
                                            <td className="py-3 text-center font-extrabold text-slate-800 dark:text-slate-100">{item.count}</td>
                                            <td className="py-3 text-right">
                                                <span className={`inline-block px-2.5 py-0.5 rounded-full text-[10px] font-bold ${
                                                    item.status === 'Selesai'
                                                        ? 'bg-[#EAFBFC] text-[#00AFC0] border border-[#00AFC0]/20'
                                                        : item.status === 'Dalam Proses'
                                                        ? 'bg-amber-50 text-amber-600 dark:bg-amber-950/60 dark:text-amber-400 border border-amber-100 dark:border-amber-900/60'
                                                        : 'bg-red-50 text-red-500 dark:bg-red-950/60 dark:text-red-400 border border-red-100 dark:border-red-900/60'
                                                }`}>
                                                    {item.status}
                                                </span>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>

                        <div className="flex items-center justify-between px-6 py-4 border-t border-slate-100 dark:border-slate-800 bg-slate-50 dark:bg-slate-900/60 text-xs">
                            <span className="text-slate-500">Menampilkan {ALL_MAINTENANCE_ASSETS.length} asset</span>
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={() => setShowAllMaintenanceModal(false)}
                            >
                                Tutup
                            </Button>
                        </div>
                    </div>
                </div>
            )}

            {/* --- MODAL: DETAIL WORK ORDER ASET --- */}
            {selectedAssetDetail && !isExportingPdf && (
                <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-xs animate-in fade-in">
                    <div className="bg-white dark:bg-slate-900 w-full max-w-lg rounded-2xl shadow-2xl border border-slate-200 dark:border-slate-800 p-6 flex flex-col gap-5">
                        <div className="flex items-start justify-between">
                            <div>
                                <span className="inline-block px-2.5 py-0.5 rounded-full bg-[#EAFBFC] text-[#00AFC0] font-mono text-[11px] font-bold mb-1 border border-[#00AFC0]/20">
                                    {selectedAssetDetail.id}
                                </span>
                                <h3 className="text-lg font-bold text-slate-800 dark:text-slate-100">{selectedAssetDetail.name}</h3>
                                <p className="text-xs text-slate-500 dark:text-slate-400">{selectedAssetDetail.category} • {selectedAssetDetail.location}</p>
                            </div>
                            <button
                                onClick={() => setSelectedAssetDetail(null)}
                                className="p-1.5 rounded-xl hover:bg-slate-100 dark:hover:bg-slate-800 text-slate-400"
                            >
                                <X className="size-5" />
                            </button>
                        </div>

                        <div className="grid grid-cols-2 gap-3 text-xs bg-slate-50 dark:bg-slate-800/40 p-4 rounded-2xl border border-slate-100 dark:border-slate-800">
                            <div>
                                <span className="text-slate-400 block text-[10px] uppercase tracking-wider font-semibold">Total Work Order</span>
                                <span className="font-extrabold text-slate-800 dark:text-slate-100 text-sm">{selectedAssetDetail.count} kali</span>
                            </div>
                            <div>
                                <span className="text-slate-400 block text-[10px] uppercase tracking-wider font-semibold">Status Perbaikan</span>
                                <span className={`font-semibold ${
                                    selectedAssetDetail.status === 'Selesai'
                                        ? 'text-[#00AFC0] font-bold'
                                        : selectedAssetDetail.status === 'Dalam Proses'
                                        ? 'text-amber-600 dark:text-amber-400'
                                        : 'text-red-500 dark:text-red-400'
                                }`}>
                                    {selectedAssetDetail.status}
                                </span>
                            </div>
                        </div>

                        <div className="flex flex-col gap-2">
                            <Button
                                type="button"
                                variant="default"
                                onClick={() => {
                                    setSelectedAssetDetail(null);
                                    showToast(`Work Order baru berhasil dibuat untuk: ${selectedAssetDetail.name}`);
                                }}
                            >
                                <Plus className="size-4 mr-2" />
                                <span>Buat Work Order Baru</span>
                            </Button>
                        </div>
                    </div>
                </div>
            )}
        </>
    );
}

Dashboard.layout = {
    breadcrumbs: [
        {
            title: 'Dashboard',
            href: dashboard(),
        },
    ],
};
