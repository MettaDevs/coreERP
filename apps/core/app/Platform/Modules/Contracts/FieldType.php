<?php

declare(strict_types=1);

namespace App\Platform\Modules\Contracts;

/**
 * Jenis nilai sebuah kolom yang boleh difilter pengguna (K-30). Jenisnya menentukan cara isian filter
 * dibaca oleh {@see FieldFilterExpression}: teks, angka, tanggal, dan tanggal-jam diisi sebagai ekspresi
 * filter gaya Business Central; ya/tidak, pilihan, dan rujukan diisi lewat pemilih berupa daftar nilai.
 *
 * Nilai string-nya tersimpan di katalog dan preset tenant; menggantinya memutus data yang sudah ada.
 */
enum FieldType: string
{
    case Text = 'text';
    case Number = 'number';
    case Date = 'date';
    case DateTime = 'datetime';
    case Boolean = 'boolean';
    case Option = 'option';
    case Reference = 'reference';
}
