<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class CreateOwner extends Command
{
    protected $signature = 'disco:owner {email : Owner email address} {--name=Owner : Display name}';

    protected $description = 'Create the one and only Disco owner account';

    public function handle(): int
    {
        if (User::query()->exists()) {
            $this->error('An owner already exists. Disco never permits a second account.');

            return self::FAILURE;
        }

        $email = mb_strtolower(trim((string) $this->argument('email')));
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $this->error('Provide a valid owner email address.');

            return self::FAILURE;
        }

        $password = $this->secret('Password');
        $confirmation = $this->secret('Confirm password');

        if (! is_string($password) || strlen($password) < 12) {
            $this->error('Use a password of at least 12 characters.');

            return self::FAILURE;
        }

        if (! hash_equals($password, (string) $confirmation)) {
            $this->error('Passwords do not match.');

            return self::FAILURE;
        }

        DB::transaction(function () use ($email, $password): void {
            $owner = User::query()->create([
                'name' => (string) $this->option('name'),
                'email' => $email,
                'password' => Hash::make($password),
                'timezone' => (string) config('app.timezone', 'UTC'),
            ]);

            DB::table('app.instances')->insert([
                'id' => 1,
                'owner_user_id' => $owner->id,
                'name' => 'Disco',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $this->info('Disco owner created.');

        return self::SUCCESS;
    }
}
