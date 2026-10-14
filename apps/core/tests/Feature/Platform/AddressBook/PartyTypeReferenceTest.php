<?php

namespace Tests\Feature\Platform\AddressBook;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PartyTypeReferenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_core_seeds_the_standard_party_types(): void
    {
        $types = DB::table('party_types')
            ->orderBy('code')
            ->pluck('name', 'code')
            ->all();

        $this->assertSame([
            'legal_entity' => 'Entitas legal',
            'operating_unit' => 'Unit operasional',
            'organization' => 'Organisasi',
            'person' => 'Perorangan',
            'team' => 'Tim',
        ], $types);
        $this->assertArrayNotHasKey('any', $types);
    }
}
