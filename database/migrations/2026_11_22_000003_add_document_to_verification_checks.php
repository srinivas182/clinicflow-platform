<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A practice uploads the document behind each registration check (HPCSA, BHF, company
 * registration); the file is virus-scanned and kept in the practice's own file store (decision on
 * beta #18). Only its name and when it was uploaded are recorded here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('verification_checks', function (Blueprint $table): void {
            $table->string('document_path')->nullable()->after('reference');
            $table->string('document_name', 200)->nullable()->after('document_path');
            $table->timestamp('document_uploaded_at')->nullable()->after('document_name');
        });
    }

    public function down(): void
    {
        Schema::table('verification_checks', function (Blueprint $table): void {
            $table->dropColumn(['document_path', 'document_name', 'document_uploaded_at']);
        });
    }
};
