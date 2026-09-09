import { ReactNode } from 'react';

/**
 * Perisai mode baca untuk kontrol yang tidak mengenal `readOnly`, yaitu Select dan Switch.
 *
 * `disabled` sengaja tidak dipakai: elemen disabled tidak memancarkan klik dan keluar dari
 * urutan tab, sehingga "klik nilainya untuk mulai menyunting" menjadi mustahil. Sebagai
 * gantinya kontrolnya tetap terlihat utuh tetapi mati sentuhan, dan sebuah tombol
 * transparan menutupinya. Tombol itu nyata, jadi tetap terjangkau papan ketik.
 *
 * Kliknya ditelan tombol, tidak diteruskan. Itu disengaja: satu klik yang sekaligus
 * membuka mode sunting dan membalik sebuah Switch akan mengubah data tanpa diminta.
 */
export default function EditShield({
    active,
    label,
    onActivate,
    children,
}: {
    active: boolean;
    label: string;
    onActivate: () => void;
    children: ReactNode;
}) {
    if (!active) return <>{children}</>;

    return (
        <div className="relative">
            <div className="pointer-events-none">{children}</div>
            <button
                type="button"
                aria-label={`Ubah ${label.toLowerCase()}`}
                className="focus-visible:ring-ring/50 absolute inset-0 z-10 cursor-text rounded-md focus-visible:outline-none focus-visible:ring-2"
                onClick={onActivate}
            />
        </div>
    );
}
