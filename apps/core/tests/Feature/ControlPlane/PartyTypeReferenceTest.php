<?php

namespace Tests\Feature\ControlPlane;

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
            'legal_entity' => 'Legal entity',
            'operating_unit' => 'Operating unit',
            'organization' => 'Organization',
            'person' => 'Person',
            'team' => 'Team',
        ], $types);
        $this->assertArrayNotHasKey('any', $types);
    }
}
