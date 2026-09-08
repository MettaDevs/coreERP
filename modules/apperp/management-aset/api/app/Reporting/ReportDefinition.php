<?php

namespace App\Reporting;

use App\Reporting\Layouts\BuiltinLayout;

/**
 * Satu laporan, dalam arti Business Central: dataset yang ditentukan developer, dipakai
 * oleh banyak layout yang boleh diubah tenant. Kelas ini hanya memikul datasetnya —
 * kolom apa yang tersedia, dari mana datanya dibaca, dan siapa yang boleh membacanya.
 * Tampilannya ada di layout, dan pemilihan layout ada di {@see Layouts\LayoutStore}.
 */
interface ReportDefinition
{
    /** Kode stabil yang dipakai di URL, database, dan nama folder layout bawaan. */
    public function code(): string;

    public function name(): string;

    public function description(): string;

    /**
     * Permission bisnis yang wajib dimiliki di samping hak menjalankan laporan. Ini yang
     * menjamin orang yang tidak boleh membuka work order juga tidak dapat mencetaknya.
     */
    public function permission(): string;

    /** @return list<BuiltinLayout> Layout yang ikut release app; yang pertama menjadi default. */
    public function builtinLayouts(): array;

    /** @return array<string, list<mixed>> Aturan validasi Laravel untuk parameter permintaan. */
    public function parameterRules(): array;

    /**
     * Daftar placeholder yang boleh dipakai layout, setara "Available Fields" BC. Kolom
     * tabel diberi nama tabelnya supaya pembuat layout tahu mana yang berulang per baris.
     *
     * @return list<array{key: string, label: string, table: ?string}>
     */
    public function fields(): array;

    /** @param array<string, mixed> $parameters Parameter yang sudah lolos {@see parameterRules()}. */
    public function data(ReportContext $context, array $parameters): ReportData;
}
