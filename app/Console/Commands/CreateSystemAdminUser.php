<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class CreateSystemAdminUser extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'admin:create {--email=system-admin@example.test} {--password=password} {--force}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create a platform admin user for system administration';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $email = $this->option('email');
        $password = $this->option('password');
        $force = $this->option('force');

        // Check if user already exists
        $existingUser = User::where('email', $email)->first();
        if ($existingUser && !$force) {
            $this->error("User with email {$email} already exists. Use --force to overwrite.");
            return 1;
        }

        // Delete existing user if force flag is set
        if ($existingUser && $force) {
            $existingUser->delete();
            $this->info("Deleted existing user with email {$email}");
        }

        // Create the platform admin user
        $user = User::create([
            'name' => 'System Administrator',
            'first_name' => 'System',
            'last_name' => 'Administrator',
            'email' => $email,
            'password' => Hash::make($password),
            'actor_type' => 'platform_support',
            'status' => 'active',
            'tenant_id' => null, // Platform admin has no tenant
        ]);

        $this->info("Platform admin user created successfully!");
        $this->line("Email: {$email}");
        $this->line("Password: {$password}");
        $this->line("Actor Type: platform_support");
        $this->line("Status: active");

        return 0;
    }
}
