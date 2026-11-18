<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * A brand's practice domain is used only after a DNS record proves the partner owns it (beta #159).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('brands', function (Blueprint $table): void {
            $table->string('domain_token', 40)->nullable()->after('practice_domain');
            $table->timestamp('domain_verified_at')->nullable()->after('domain_token');
        });
        foreach (DB::table('brands')->whereNotNull('practice_domain')->pluck('id') as $id) {
            DB::table('brands')->where('id', $id)->update(['domain_token' => 'cf-'.Str::lower(Str::random(32))]);
        }
    }

    public function down(): void
    {
        Schema::table('brands', function (Blueprint $table): void {
            $table->dropColumn(['domain_token', 'domain_verified_at']);
        });
    }
};
