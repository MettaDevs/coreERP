<?php

declare(strict_types=1);

namespace Modules\PenerbitContoh\ChangeMe\Models;

use App\Support\Modules\Contracts\MilikTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Model contoh: bentuk minimal satu model module, dan ketiga trait di bawah wajib ada.
 *
 * - `MilikTenant` menyaring baca **dan** membatalkan tulis ke tenant lain. Semua tenant
 *   berada di satu tabel sekarang, jadi satu model yang lupa memakainya membocorkan baris
 *   milik pelanggan lain — dan kebocoran itu tidak pernah gagal dengan sendirinya, ia hanya
 *   tampak seperti daftar yang isinya kebetulan banyak. Ada penjaga batas yang menolak model
 *   module tanpa trait ini.
 * - `SoftDeletes` karena tidak ada baris module yang dihapus fisik.
 * - `HasUlids` supaya kunci primernya tidak bisa ditebak berurutan.
 *
 * `tenant_id` sengaja tetap ada di `$fillable` walau module tidak pernah menuliskannya:
 * `MilikTenant` yang mengisinya dari tenant aktif, dan nilai yang tidak ditulis tidak bisa
 * salah tulis.
 *
 * Properti didaftarkan supaya analisa statis tahu bentuk barisnya. Tanpa itu, setiap
 * pembacaan kolom dilaporkan sebagai properti yang tidak ada — laporan seperti itu mudah
 * dianggap kebisingan, lalu penemuan yang sungguhan ikut diabaikan.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $kode
 * @property string $nama
 */
final class Contoh extends Model
{
    use HasUlids;
    use MilikTenant;
    use SoftDeletes;

    protected $table = 'change_me_m_contoh';

    protected $fillable = ['tenant_id', 'kode', 'nama'];
}
