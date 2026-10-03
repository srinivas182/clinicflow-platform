<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Patient portal sign-in codes, legacy patient imports, consent capture for imported patients.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portal_login_challenges', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('cell', 10)->index();
            $table->string('code_hash');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('patient_imports', function (Blueprint $table): void {
            $table->id();
            $table->string('file_name');
            $table->unsignedInteger('rows_total')->default(0);
            $table->unsignedInteger('rows_imported')->default(0);
            $table->unsignedInteger('rows_skipped')->default(0);
            $table->json('problems');
            $table->unsignedBigInteger('imported_by')->nullable();
            $table->timestamps();
        });

        Schema::table('patients', function (Blueprint $table): void {
            $table->boolean('needs_consent')->default(false)->after('registered_by');
            $table->unsignedBigInteger('patient_import_id')->nullable()->after('needs_consent');
        });
    }

    public function down(): void
    {
        Schema::table('patients', function (Blueprint $table): void {
            $table->dropColumn(['needs_consent', 'patient_import_id']);
        });
        Schema::dropIfExists('patient_imports');
        Schema::dropIfExists('portal_login_challenges');
    }
};
