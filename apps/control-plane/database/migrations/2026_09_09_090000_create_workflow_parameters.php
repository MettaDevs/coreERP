<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Parameter workflow per tenant, satu baris per parameter.
     *
     * Sebelumnya "pengaju tidak boleh menyetujui dokumennya sendiri" adalah aturan mati di
     * dalam mesin: satu baris `abort_if` yang tidak bisa dimatikan siapa pun. Itu keliru untuk
     * produk yang dipakai banyak organisasi. Rangkap jabatan adalah keadaan biasa di lapangan —
     * satu orang mengajukan sekaligus menyetujui karena memang tidak ada orang kedua — dan
     * mesin ini tidak punya delegasi maupun eskalasi, jadi tugas yang jatuh ke pengajunya
     * sendiri akan **menggantung selamanya**. Aturan yang ketat berubah menjadi jalan buntu.
     *
     * Bentuk parameternya mengikuti Dynamics 365 Finance & Operations, yang menaruhnya sebagai
     * parameter (`System administration > Workflow > Workflow parameters > General > Approver`,
     * `Disallow approval by submitter`) dan bukan sebagai aturan mesin.
     *
     * **Penyimpanannya tidak mengikuti D365, dan itu disengaja.** Tabel parameter di F&O lebar:
     * satu kolom per parameter. Bentuk itu masuk akal di sana karena generator kode dan
     * perancang formulirnya membuat biaya satu field mendekati nol. Di sini biayanya tiga
     * suntingan — migration, method pembaca, dan satu blok layar — untuk setiap parameter baru.
     * Baris per kode memindahkan biaya itu ke satu entri pada `DefinisiParameterWorkflow`, dan
     * dengan begitu penambahan parameter berhenti menyentuh skema sama sekali. Itu penting
     * untuk produk yang pembaruannya dijalankan admin pelanggan sendiri: migration yang tidak
     * ada tidak bisa gagal di server orang lain.
     *
     * Nilainya disimpan sebagai JSON supaya tipenya bertahan bulat-bulat — `false` kembali
     * sebagai `false`, bukan sebagai string `"0"` yang bernilai benar. Yang memiliki tipe dan
     * bawaannya adalah registry, bukan kolom ini.
     *
     * `jsonb`, bukan `json`. Bedanya bukan gaya: tipe `json` di PostgreSQL tidak punya operator
     * kesetaraan sama sekali, sehingga pertanyaan pemeriksa yang paling wajar — "tenant mana saja
     * yang melarangnya" — gagal dengan "operator does not exist". Ini ditemukan sebuah test,
     * bukan pembacaan kode; kalimat yang mengklaim kolom ini bisa disaring sempat ditulis lebih
     * dulu dan ternyata keliru.
     */
    public function up(): void
    {
        Schema::create('workflow_parameters', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('code', 80);
            $table->jsonb('value');
            $table->timestamps();
            $table->unique(['tenant_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_parameters');
    }
};
