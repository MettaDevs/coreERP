<?php

namespace App\Http\Requests\Settings;

use DateTimeZone;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Zona waktu dan tanggal kerja di My Profile. Masing-masing hanya diubah bila dikirim, supaya pengingat di
 * Shell dapat mengembalikan tanggal kerja tanpa menyentuh zona waktu.
 */
class DateTimeSettingsRequest extends FormRequest
{
    /**
     * Zona kosong berarti ikut entitas legal aktif; tanggal kerja kosong berarti hari ini.
     *
     * Batas tahun tanggal kerja menangkap salah ketik seperti `0226` atau `20266`, yang lolos sebagai
     * tanggal sah tetapi hampir pasti bukan maksud pengguna.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'timezone' => ['sometimes', 'nullable', 'string', Rule::in(DateTimeZone::listIdentifiers())],
            'work_date' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:1900-01-01', 'before_or_equal:2999-12-31'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'timezone.in' => 'Pilih zona waktu dari daftar.',
            'work_date.date_format' => 'Isi tanggal kerja dengan tanggal yang benar.',
            'work_date.after_or_equal' => 'Tanggal kerja terlalu jauh ke belakang. Periksa tahunnya.',
            'work_date.before_or_equal' => 'Tanggal kerja terlalu jauh ke depan. Periksa tahunnya.',
        ];
    }
}
