<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'central';

    public function up(): void
    {
        $source = DB::connection('mysql');
        $central = DB::connection('central');

        $sourceColumns = Schema::connection('mysql')
            ->getColumnListing('users');

        $centralColumns = Schema::connection('central')
            ->getColumnListing('users');

        $fields = [
            'id',
            'name',
            'email',
            'phone_number',
            'email_verified_at',
            'password',
            'remember_token',
            'created_at',
            'updated_at',
            'two_factor_enabled',
            'two_factor_type',
            'two_factor_secret',
            'two_factor_otp',
            'two_factor_otp_expires_at',
            'two_factor_confirmed',
            'userRole',
        ];

        $fields = array_values(array_intersect(
            $fields,
            $sourceColumns,
            $centralColumns
        ));

        $existingPhones = [];

        foreach (
            $central->table('users')->pluck('phone_number') as $phone
        ) {
            if ($phone !== null && trim((string) $phone) !== '') {
                $existingPhones[(string) $phone] = true;
            }
        }

        $staffUsers = $source->table('users')
            ->where('userRole', 1)
            ->orderBy('id')
            ->get($fields);

        foreach ($staffUsers as $user) {
            $user = (array) $user;

            // Never overwrite an existing central identity.
            if (
                $central->table('users')->where('id', $user['id'])->exists()
                || $central->table('users')
                    ->where('email', $user['email'])->exists()
            ) {
                continue;
            }

            // Normalize missing phone numbers.
            $phone = trim((string) ($user['phone_number'] ?? ''));

            if ($phone === '') {
                $user['phone_number'] = null;
            } elseif (isset($existingPhones[$phone])) {
                // Preserve the original legacy record; avoid a central
                // unique-phone conflict by leaving this copy without it.
                $user['phone_number'] = null;
            } else {
                $existingPhones[$phone] = true;
            }

            $central->table('users')->insert($user);
        }
    }

    public function down(): void
    {
        // Intentionally non-destructive.
        // Do not delete central identities during rollback.
    }
};
