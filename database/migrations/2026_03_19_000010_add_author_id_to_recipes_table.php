<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class() extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
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

        Schema::table('recipes', static function (Blueprint $table): void {
            $table->foreignIdFor(User::class, 'author_id')
                ->nullable()
                ->after('label_id')
                ->constrained('users')
                ->nullOnDelete();
        });

        DB::table('recipes')->whereNull('author_id')->update(['author_id' => $hellofreshUser->id]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('recipes', static function (Blueprint $table): void {
            $table->dropConstrainedForeignId('author_id');
        });
    }
};
