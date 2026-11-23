<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indexes for the most frequent lookups:
 * - cafés listed by status (every customer home screen and admin dashboard)
 * - users filtered by role (admin user list, notifications to all admins)
 * - a customer's active visit (every check-in and checkout)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cafes', function (Blueprint $table) {
            $table->index(['status', 'created_at']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->index('role');
        });

        Schema::table('cafe_visits', function (Blueprint $table) {
            $table->index(['user_id', 'check_out_at', 'check_in_at']);
        });
    }

    public function down(): void
    {
        Schema::table('cafes', function (Blueprint $table) {
            $table->dropIndex(['status', 'created_at']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['role']);
        });

        Schema::table('cafe_visits', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'check_out_at', 'check_in_at']);
        });
    }
};
