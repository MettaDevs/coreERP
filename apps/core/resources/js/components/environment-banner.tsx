import { usePage } from '@inertiajs/react';

type SharedProps = {
    environment: { kind: string; name: string } | null;
};

const COPY: Record<string, { label: string; sentence: string }> = {
    demo: {
        label: 'Demo',
        sentence:
            'Ini tempat peragaan. Angkanya bukan angka perusahaan Anda, dan tidak ada email, webhook, maupun laporan terjadwal yang dikirim dari sini.',
    },
    sandbox: {
        label: 'Sandbox',
        sentence:
            'Ini salinan untuk mencoba-coba. Perubahan di sini tidak pernah sampai ke tempat kerja Anda yang sebenarnya, dan tidak ada email, webhook, maupun laporan terjadwal yang dikirim dari sini.',
    },
};

/**
 * Memberi tahu pengguna bahwa ia sedang tidak berada di tempat kerja yang sebenarnya.
 *
 * Bukan hiasan, dan bukan sekadar kerapian. Seluruh alasan lingkungan terpisah ada adalah supaya
 * salinan produksi tidak dapat menghubungi pelanggan produksi — tetapi pelucutan itu tidak
 * melindungi siapa pun dari kesalahan yang paling mahal: **pengguna yang tidak tahu ia di sandbox
 * akan memperlakukan angka sandbox sebagai angka sungguhan, lalu mengambil keputusan di atasnya.**
 *
 * Karena itu ia permanen dan tidak dapat ditutup. Spanduk yang bisa dibuang adalah spanduk yang
 * dibuang orang pada hari pertama, lalu tidak pernah terlihat lagi justru ketika ia dibutuhkan.
 *
 * Bunyinya sehari-hari dan menyebut akibatnya, bukan istilah teknis: "pengiriman keluar dinonaktifkan"
 * tidak memberi tahu siapa pun bahwa tagihan yang ia kirim dari sini tidak akan pernah sampai.
 */
export default function EnvironmentBanner() {
    const { environment } = usePage<SharedProps>().props;

    if (!environment) {
        return null;
    }

    const copy = COPY[environment.kind] ?? {
        label: environment.kind,
        sentence:
            'Ini bukan tempat kerja Anda yang sebenarnya. Tidak ada email, webhook, maupun laporan terjadwal yang dikirim dari sini.',
    };

    return (
        <div
            role="status"
            className="flex flex-wrap items-baseline gap-x-2 gap-y-1 border-b border-amber-300 bg-amber-100 px-4 py-2 text-sm text-amber-950"
        >
            <span className="rounded bg-amber-300 px-1.5 py-0.5 text-xs font-semibold tracking-wide uppercase">
                {copy.label}
            </span>
            <span className="font-medium">{environment.name}</span>
            <span className="text-amber-900">{copy.sentence}</span>
        </div>
    );
}
