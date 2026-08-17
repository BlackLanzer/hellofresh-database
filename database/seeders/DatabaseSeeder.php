<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {

        $hellofreshUser = User::firstOrCreate(
            ['email' => 'hellofresh@system.local'],
            [
                'name' => 'HelloFresh',
                'password' => Hash::make(Str::random(64)),
            ]
        );

        if ($hellofreshUser->email_verified_at === null) {
            $hellofreshUser->email_verified_at = now();
            $hellofreshUser->save();
        }
    }
}
