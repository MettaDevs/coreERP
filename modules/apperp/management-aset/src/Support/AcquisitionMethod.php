<?php

namespace Modules\Apperp\ManagementAset\Support;

/**
 * Cara perolehan aset (K-12, TODO 8.2): pembelian, hibah, atau saldo awal aset lama saat cutover.
 *
 * Nilainya disimpan di dokumen penerimaan (TODO 9.1.1) dan ikut menentukan akun jurnal
 * perolehan lewat `AssetPostingAccounts::acquisitionAccount()`. Hari ini ketiganya memakai akun
 * harga perolehan yang sama, sesuai keputusan konsultan: di sektor swasta hibah tetap masuk nilai
 * perolehan. Di sektor pemerintah keduanya dibedakan, dan itulah sebabnya caranya sudah dicatat.
 */
final class AcquisitionMethod
{
    public const PURCHASE = 'pembelian';

    public const GRANT = 'hibah';

    public const OPENING_BALANCE = 'saldo_awal';

    public const ALL = [self::PURCHASE, self::GRANT, self::OPENING_BALANCE];
}
