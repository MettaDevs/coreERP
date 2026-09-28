<?php

namespace App\Support\Modules\Contracts;

/**
 * Riwayat perubahan satu record dari log perubahan Core, siap ditampilkan.
 *
 * Module membuka riwayat record miliknya lewat rutenya sendiri, setelah memeriksa hak dan cakupan
 * organisasi atas record itu dengan aturannya sendiri; kontrak ini hanya membaca. Karena itu riwayat tidak
 * pernah lebih terbuka daripada record-nya.
 *
 * Tiap entri membawa nama pelaku (kosong berarti diubah sistem), nama field untuk layar bila didaftarkan
 * lewat {@see ChangeLogDefaults}, dan nilai tampilan dari {@see ChangeLogValueResolver} bila ada.
 */
interface ChangeHistory
{
    public const PER_PAGE = 50;

    /**
     * Terbaru lebih dulu.
     *
     * @return array{
     *     data: list<array{
     *         id: int,
     *         changed_at: string,
     *         user_id: int|null,
     *         user_name: string|null,
     *         field_name: string,
     *         field_caption: string|null,
     *         change_type: string,
     *         old_value: string|null,
     *         new_value: string|null,
     *         old_display: string|null,
     *         new_display: string|null
     *     }>,
     *     next_page: int|null
     * }
     */
    public function forRecord(string $tenantId, string $table, string $recordId, int $page = 1): array;
}
