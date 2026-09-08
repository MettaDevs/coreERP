<?php

namespace App\Models\master;

use App\Models\MasterData;

/**
 * Bidang keahlian yang dibutuhkan sebuah pekerjaan; padanan "Trade" pada modul Asset
 * management Dynamics 365 F&O. Sebelumnya berupa pilihan yang ditulis di UI, sehingga
 * tenant tidak dapat menambah keahlian tanpa merilis ulang aplikasi.
 */
class Trade extends MasterData
{
    protected $table = 'm_trade';
}
