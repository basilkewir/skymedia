<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class ResetAdminPassword extends Command
{
    protected $signature = 'admin:reset-password {--email= : Admin email address} {--password= : New password (omit to auto-generate)}';
    protected $description = 'Reset the admin user password';

    public function handle(): int
    {
        $email = $this->option('email');

        $user = $email
            ? User::where('email', $email)->first()
            : User::orderBy('id')->first();

        if (!$user) {
            $this->error('No user found.');
            return self::FAILURE;
        }

        $password = $this->option('password') ?: Str::random(16);

        $user->forceFill(['password' => Hash::make($password)])->save();

        $this->info("Password reset for: {$user->email}");
        $this->line("New password: <fg=yellow>{$password}</>");

        return self::SUCCESS;
    }
}
