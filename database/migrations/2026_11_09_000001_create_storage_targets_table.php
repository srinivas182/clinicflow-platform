<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where Clinic Flow keeps files (uploads, documents, lab reports, media), chosen by the super admin.
 * One target is active at a time; each practice's files sit in their own folder within it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('storage_targets', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 80);
            $table->string('driver', 8);
            $table->string('provider', 20)->nullable();
            $table->string('bucket')->nullable();
            $table->string('region', 40)->nullable();
            $table->string('endpoint')->nullable();
            $table->boolean('path_style')->default(false);
            $table->boolean('encrypt')->default(true);
            $table->text('credentials')->nullable();
            $table->string('root', 120)->nullable();
            $table->boolean('active')->default(false);
            $table->timestamp('verified_at')->nullable();
            $table->string('last_error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('storage_targets');
    }
};
