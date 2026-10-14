<?php

namespace Tests\Unit;

use App\Foundation\Vendor\Support\SupplierCsvImporter;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SupplierCsvImporterTest extends TestCase
{
    public function test_parses_export_with_quoted_name_and_preserves_source_identity(): void
    {
        $rows = (new SupplierCsvImporter)->parse("\xEF\xBB\xBFsource,external_id,code,name,active\r\nserver/db,974,SUP001,\"Supplier, Satu\",1\r\n");
        $this->assertSame('974', $rows[0]['external_id']);
        $this->assertSame('Supplier, Satu', $rows[0]['name']);
        $this->assertSame('server/db', $rows[0]['source']);
    }

    public function test_accepts_semicolon_export_and_inactive_supplier(): void
    {
        $rows = (new SupplierCsvImporter)->parse("source;external_id;code;name;active\nserver/db;12;S12;Supplier Dua;0\n");
        $this->assertSame('0', $rows[0]['active']);
    }

    public function test_rejects_duplicate_identity_before_database_access(): void
    {
        $this->expectException(ValidationException::class);
        (new SupplierCsvImporter)->parse("source,external_id,code,name,active\na,1,A,Satu,1\na,1,B,Dua,1\n");
    }

    public function test_rejects_mixed_bo_sources(): void
    {
        $this->expectException(ValidationException::class);
        (new SupplierCsvImporter)->parse("source,external_id,code,name,active\na,1,A,Satu,1\nb,2,B,Dua,1\n");
    }

    public function test_rejects_duplicate_names_without_guessing_party_identity(): void
    {
        $this->expectException(ValidationException::class);
        (new SupplierCsvImporter)->parse("source,external_id,code,name,active\na,1,A,Satu,1\na,2,B,SATU,1\n");
    }

    public function test_rejects_coa_file_and_invalid_supplier_status(): void
    {
        foreach (["external_id,code,name,type,active\n1,A,Satu,balance_sheet,1", "source,external_id,code,name,active\na,1,A,Satu,yes"] as $contents) {
            try {
                (new SupplierCsvImporter)->parse($contents);
                $this->fail('Invalid CSV was accepted.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('file', $exception->errors());
            }
        }
    }
}
