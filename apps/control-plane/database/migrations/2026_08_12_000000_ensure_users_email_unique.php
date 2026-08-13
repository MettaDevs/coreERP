<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Trim and lowercase emails
        DB::table('users')->update([
            'email' => DB::raw('LOWER(TRIM(email))'),
        ]);

        // 2. Safely merge duplicate user records if any exist
        $duplicateEmails = DB::table('users')
            ->select('email')
            ->groupBy('email')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('email');

        foreach ($duplicateEmails as $email) {
            $users = DB::table('users')
                ->where('email', $email)
                ->orderBy('id', 'asc')
                ->get();

            $primaryUser = $users->first();
            $duplicateUserIds = $users->slice(1)->pluck('id');

            foreach ($duplicateUserIds as $duplicateId) {
                $duplicateMemberships = DB::table('tenant_memberships')
                    ->where('user_id', $duplicateId)
                    ->get();

                foreach ($duplicateMemberships as $membership) {
                    $exists = DB::table('tenant_memberships')
                        ->where('tenant_id', $membership->tenant_id)
                        ->where('user_id', $primaryUser->id)
                        ->exists();

                    if (! $exists) {
                        DB::table('tenant_memberships')
                            ->where('id', $membership->id)
                            ->update(['user_id' => $primaryUser->id]);
                    } else {
                        DB::table('tenant_memberships')
                            ->where('id', $membership->id)
                            ->delete();
                    }
                }

                DB::table('sessions')->where('user_id', $duplicateId)->update(['user_id' => $primaryUser->id]);

                if (Schema::hasTable('passkeys')) {
                    DB::table('passkeys')->where('user_id', $duplicateId)->update(['user_id' => $primaryUser->id]);
                }

                if (Schema::hasTable('provider_access')) {
                    DB::table('provider_access')->where('user_id', $duplicateId)->update(['user_id' => $primaryUser->id]);
                }

                DB::table('users')->where('id', $duplicateId)->delete();
            }
        }

        // 3. Add UNIQUE constraint to users.email if not present
        Schema::table('users', function (Blueprint $table) {
            $indexes = Schema::getConnection()->getSchemaBuilder()->getIndexes('users');
            $hasUniqueEmail = collect($indexes)->contains(function ($index) {
                return in_array('email', $index['columns'], true) && ! empty($index['unique']);
            });

            if (! $hasUniqueEmail) {
                $table->unique('email');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['email']);
        });
    }
};
