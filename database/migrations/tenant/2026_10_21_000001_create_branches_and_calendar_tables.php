<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Branches inside a practice (one database, shared patients) and doctors'
 * calendar connections, synced events, imported busy times and iCal feeds.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branches', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('address')->nullable();
            $table->string('phone', 20)->nullable();
            $table->boolean('is_main')->default(false);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
        DB::table('branches')->insert(['name' => 'Main branch', 'is_main' => true, 'active' => true, 'created_at' => now(), 'updated_at' => now()]);

        Schema::create('branch_staff', function (Blueprint $table): void {
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('staff_id');
            $table->primary(['branch_id', 'staff_id']);
        });

        foreach (['visits', 'appointments', 'invoices', 'cash_ups', 'stock_batches', 'roster_sessions'] as $t) {
            Schema::table($t, function (Blueprint $table): void {
                $table->unsignedBigInteger('branch_id')->nullable()->index();
            });
        }

        Schema::create('stock_transfers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('stock_item_id')->constrained();
            $table->unsignedBigInteger('from_branch_id');
            $table->unsignedBigInteger('to_branch_id');
            $table->unsignedInteger('quantity');
            $table->unsignedBigInteger('by');
            $table->timestamp('created_at');
        });

        Schema::create('calendar_connections', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('staff_id')->unique();
            $table->string('driver', 10)->nullable();
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->boolean('show_initials')->default(false);
            $table->boolean('import_busy')->default(false);
            $table->string('ical_token', 48)->unique();
            $table->string('last_error')->nullable();
            $table->timestamps();
        });

        Schema::create('calendar_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('appointment_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('external_id');
            $table->timestamps();
        });

        Schema::create('calendar_busy', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('staff_id')->index();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
        });
    }

    public function down(): void
    {
        foreach (['calendar_busy', 'calendar_events', 'calendar_connections', 'stock_transfers', 'branch_staff'] as $t) {
            Schema::dropIfExists($t);
        }
        foreach (['visits', 'appointments', 'invoices', 'cash_ups', 'stock_batches', 'roster_sessions'] as $t) {
            Schema::table($t, fn (Blueprint $table) => $table->dropColumn('branch_id'));
        }
        Schema::dropIfExists('branches');
    }
};
