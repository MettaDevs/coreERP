{{--
    Lembar label aset, lihat AssetLabelController.

    Ukurannya milimeter tetap untuk kertas label A4 3 × 8 (63,5 × 33,9 mm, margin atas 12,9 mm,
    margin samping 7,2 mm, jarak antarkolom 2,5 mm). Margin halaman @page nol supaya posisi label
    ditentukan lembar ini, bukan bawaan peramban; karena itu skala cetak harus 100%.
--}}
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Label aset</title>
    <style>
        @page { size: A4 portrait; margin: 0; }
        * { box-sizing: border-box; }
        html, body { margin: 0; }
        body {
            background: #e5e7eb;
            color: #111827;
            font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif;
        }
        .toolbar {
            position: sticky; top: 0; z-index: 1;
            display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 12px;
            padding: 12px 16px; background: #fff; border-bottom: 1px solid #d1d5db;
        }
        .toolbar h1 { margin: 0; font-size: 16px; font-weight: 600; }
        .toolbar p { margin: 2px 0 0; font-size: 13px; color: #4b5563; }
        .toolbar button {
            font: inherit; font-size: 14px; font-weight: 500; cursor: pointer;
            padding: 8px 16px; border-radius: 6px; border: 1px solid #111827; background: #111827; color: #fff;
        }
        .message { max-width: 560px; margin: 48px auto; padding: 24px; background: #fff; border-radius: 8px; font-size: 14px; line-height: 1.5; }
        /* Auto margin, bukan flex center: lembar 210 mm yang lebih lebar dari layar tetap dapat digulir sampai tepi kirinya. */
        .sheets { padding: 16px; }
        .sheet {
            width: 210mm; height: 297mm; margin: 0 auto 16px; padding: 12.9mm 7.2mm 0; background: #fff;
            display: grid; grid-template-columns: repeat(3, 63.5mm); grid-auto-rows: 33.9mm; column-gap: 2.5mm; align-content: start;
            box-shadow: 0 1px 3px rgb(0 0 0 / 0.2);
        }
        .label { display: flex; align-items: center; gap: 2mm; padding: 2mm 2.5mm; overflow: hidden; outline: 1px dashed #d1d5db; outline-offset: -1px; }
        /*
         * Tinggi isi label 29,9 mm, sedangkan kode + nama + lokasi + unit dalam satu baris masing-masing
         * hanya memakai separuhnya. Karena itu nama boleh empat baris dan lokasi serta unit dua baris:
         * kasus terpanjang (kode 4 mm, nama 4 × 3,2 mm, lokasi dan unit 2 × 2 × 2,8 mm, jarak 1,8 mm)
         * masih 29,6 mm, jadi teks memakai ruang atas-bawah yang ada tanpa pernah keluar dari label.
         * QR 25 mm masih jauh di atas ukuran pindai untuk kode sependek ini, dan 2 mm yang dilepasnya
         * menjadi lebar teks.
         */
        .qr { flex: none; width: 25mm; height: 25mm; }
        .qr svg { display: block; width: 100%; height: 100%; }
        .text { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 0.6mm; }
        .code { font-size: 9.5pt; font-weight: 700; letter-spacing: 0.02em; word-break: break-all; }
        .name, .meta { display: -webkit-box; -webkit-box-orient: vertical; overflow: hidden; overflow-wrap: anywhere; }
        .name { font-size: 7.5pt; line-height: 1.2; -webkit-line-clamp: 4; }
        .meta { font-size: 6.5pt; line-height: 1.2; color: #374151; -webkit-line-clamp: 2; }
        @media print {
            body { background: #fff; }
            .toolbar { display: none; }
            .sheets { padding: 0; }
            .sheet { margin: 0; box-shadow: none; break-after: page; }
            .sheet:last-child { break-after: auto; }
            .label { outline: none; }
        }
    </style>
</head>
<body>
@if ($message !== null)
    <div class="message">
        <strong>Label belum dapat dicetak.</strong>
        <p>{{ $message }}</p>
    </div>
@else
    <div class="toolbar">
        <div>
            <h1>Label aset</h1>
            <p>{{ $total }} label di {{ count($sheets) }} lembar A4 (24 label per lembar, 63,5 × 33,9 mm). Saat mencetak, pilih skala 100% atau ukuran sebenarnya agar label tepat di kertasnya.</p>
        </div>
        <button type="button" onclick="window.print()">Cetak</button>
    </div>
    <main class="sheets">
        @foreach ($sheets as $sheet)
            <section class="sheet">
                @foreach ($sheet as $label)
                    <div class="label">
                        <div class="qr" aria-hidden="true">{!! $label['qr'] !!}</div>
                        <div class="text">
                            <div class="code">{{ $label['kode'] }}</div>
                            <div class="name">{{ $label['nama'] }}</div>
                            @if ($label['lokasi'] !== null)
                                <div class="meta">{{ $label['lokasi'] }}</div>
                            @endif
                            @if ($label['unit'] !== null)
                                <div class="meta">{{ $label['unit'] }}</div>
                            @endif
                        </div>
                    </div>
                @endforeach
            </section>
        @endforeach
    </main>
@endif
</body>
</html>
