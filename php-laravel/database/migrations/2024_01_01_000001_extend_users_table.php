<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Laravel scaffolds a basic users table — we extend it with the
        // fields our auth flow needs to bridge with the Go API.
        Schema::table('users', function (Blueprint $table): void {
            if (! Schema::hasColumn('users', 'full_name')) {
                $table->string('full_name')->after('id');
            }
            if (! Schema::hasColumn('users', 'phone')) {
                $table->string('phone', 20)->nullable()->after('full_name');
            }
            if (! Schema::hasColumn('users', 'role')) {
                $table->string('role', 20)->default('public')->after('phone');
            }
            if (! Schema::hasColumn('users', 'county')) {
                $table->string('county')->nullable()->after('role');
            }
            if (! Schema::hasColumn('users', 'api_token')) {
                // JWT access token from the Go API — refreshed on each login
                $table->text('api_token')->nullable()->after('county');
            }
            if (! Schema::hasColumn('users', 'refresh_token')) {
                $table->text('refresh_token')->nullable()->after('api_token');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(array_filter([
                Schema::hasColumn('users', 'full_name')     ? 'full_name'     : null,
                Schema::hasColumn('users', 'phone')         ? 'phone'         : null,
                Schema::hasColumn('users', 'role')          ? 'role'          : null,
                Schema::hasColumn('users', 'county')        ? 'county'        : null,
                Schema::hasColumn('users', 'api_token')     ? 'api_token'     : null,
                Schema::hasColumn('users', 'refresh_token') ? 'refresh_token' : null,
            ]));
        });
    }
};