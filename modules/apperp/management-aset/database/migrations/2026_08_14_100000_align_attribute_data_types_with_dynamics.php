<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('m_tipe_atribut', function (Blueprint $table): void {
            $table->boolean('data_type_locked')->default(false)->after('data_type');
        });

        DB::table('m_tipe_atribut')->whereIn('data_type', ['text', 'fixed_list'])->update(['data_type' => 'string']);
        DB::table('m_tipe_atribut')->whereIn('data_type', ['number', 'value_range'])->update(['data_type' => 'decimal']);

        DB::table('m_tipe_atribut')
            ->whereExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('tr_aset_atribut')
                    ->whereColumn('tr_aset_atribut.tenant_id', 'm_tipe_atribut.tenant_id')
                    ->whereColumn('tr_aset_atribut.tipe_atribut_id', 'm_tipe_atribut.id');
            })
            ->update(['data_type_locked' => true]);
    }

    public function down(): void
    {
        DB::table('m_tipe_atribut')->orderBy('id')->each(function (object $type): void {
            $legacy = match ($type->data_type) {
                'string' => DB::table('m_tipe_atribut_nilai')
                    ->where(['tenant_id' => $type->tenant_id, 'tipe_atribut_id' => $type->id])
                    ->whereNull('deleted_at')
                    ->exists() ? 'fixed_list' : 'text',
                'decimal' => $type->min_value !== null && $type->max_value !== null ? 'value_range' : 'number',
                'integer' => 'number',
                default => $type->data_type,
            };

            DB::table('m_tipe_atribut')->where('id', $type->id)->update(['data_type' => $legacy]);
        });

        Schema::table('m_tipe_atribut', function (Blueprint $table): void {
            $table->dropColumn('data_type_locked');
        });
    }
};
