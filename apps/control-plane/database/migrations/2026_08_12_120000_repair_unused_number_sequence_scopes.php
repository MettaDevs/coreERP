<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private array $numberingStateTables = [
        'number_sequence_counters',
        'number_sequence_allocations',
        'number_sequence_reservations',
        'number_sequence_reusable_numbers',
        'number_sequence_continuous_pool',
        'number_sequence_issues',
        'number_sequence_audit_events',
    ];

    public function up(): void
    {
        DB::table('app_number_sequence_references')
            ->select(['id', 'allowed_scopes'])
            ->orderBy('id')
            ->get()
            ->each(function (object $reference): void {
                $scope = $this->singleAllowedScope($reference->allowed_scopes);
                if ($scope === null) {
                    return;
                }

                DB::table('tenant_number_sequences')
                    ->where('reference_id', $reference->id)
                    ->where('scope_type', '<>', $scope)
                    ->select('id')
                    ->orderBy('id')
                    ->get()
                    ->each(function (object $sequence) use ($scope): void {
                        // Once any numbering state exists, changing the scope can
                        // split an old counter and create a duplicate formatted number.
                        if ($this->hasNumberingState($sequence->id)) {
                            return;
                        }

                        DB::table('tenant_number_sequences')->where('id', $sequence->id)->update([
                            'scope_type' => $scope,
                            'updated_at' => now(),
                        ]);
                    });
            });
    }

    private function singleAllowedScope(mixed $allowedScopes): ?string
    {
        if (is_string($allowedScopes)) {
            $allowedScopes = json_decode($allowedScopes, true, 512, JSON_THROW_ON_ERROR);
        }

        if (! is_array($allowedScopes) || count($allowedScopes) !== 1) {
            return null;
        }

        $scope = $allowedScopes[0] ?? null;

        return is_string($scope) && in_array($scope, ['tenant', 'legal_entity', 'operating_unit'], true)
            ? $scope
            : null;
    }

    private function hasNumberingState(string $sequenceId): bool
    {
        foreach ($this->numberingStateTables as $table) {
            if (DB::table($table)->where('sequence_id', $sequenceId)->exists()) {
                return true;
            }
        }

        return false;
    }

    public function down(): void
    {
        // This is a one-way data repair. Reverting the scope could invalidate
        // numbers issued after the repair, so rollback must not rewrite it.
    }
};
