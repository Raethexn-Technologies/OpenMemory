<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class CreateOpenMemoryUser extends Command
{
    protected $signature = 'openmemory:user:create {email} {--name= : Display name for the local account}';

    protected $description = 'Create an OpenMemory login through trusted local administration';

    public function handle(): int
    {
        $email = strtolower(trim((string) $this->argument('email')));
        $name = trim((string) ($this->option('name') ?: $email));
        if (Validator::make(['email' => $email, 'name' => $name], [
            'email' => 'required|email|max:255|unique:users,email',
            'name' => 'required|string|max:255',
        ])->fails()) {
            $this->error('Supply a valid, unused email and a display name of at most 255 characters.');

            return self::FAILURE;
        }
        $password = $this->secret('Password (at least 12 characters)', false);
        $confirmation = $this->secret('Confirm password', false);
        if (! is_string($password) || mb_strlen($password) < 12 || strlen($password) > 72 || $password !== $confirmation) {
            $this->error('Passwords must match and contain at least 12 characters and at most 72 bytes.');

            return self::FAILURE;
        }
        $user = DB::transaction(fn () => User::create([
            'name' => $name, 'email' => $email, 'password' => $password,
        ]));
        $this->info('Created the local OpenMemory account.');
        $this->line('Import owner key: '.$user->owner_uuid);

        return self::SUCCESS;
    }
}
