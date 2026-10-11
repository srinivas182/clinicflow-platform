<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Translations with human review (Sprint W8a). English is the source and always on. Only
 * translations approved by a native speaker (and, for medical wording, also by a clinical
 * reviewer) are published to the message templates the app sends; anything else falls back
 * to English.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('languages', function (Blueprint $table): void {
            $table->string('code', 8)->primary();
            $table->string('name', 60);
            $table->string('native_name', 60);
            $table->boolean('enabled')->default(false);
            $table->timestamp('enabled_at')->nullable();
            $table->timestamps();
        });

        Schema::create('translations', function (Blueprint $table): void {
            $table->id();
            $table->string('language', 8)->index();
            $table->string('unit', 120); // e.g. message:booking.confirmation:sms
            $table->text('source');      // the English this translation was made from
            $table->text('text')->nullable();
            $table->string('status', 20)->default('missing'); // missing, ai_draft, draft, approved
            $table->boolean('medical')->default(false);
            $table->foreignId('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('clinical_approved_by')->nullable();
            $table->timestamp('clinical_approved_at')->nullable();
            $table->foreignId('updated_by')->nullable();
            $table->timestamps();
            $table->unique(['language', 'unit']);
        });

        Schema::create('translator_languages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->index();
            $table->string('language', 8);
            $table->boolean('clinical')->default(false); // may give the clinical approval
            $table->timestamps();
            $table->unique(['user_id', 'language']);
        });

        Schema::create('translator_invitations', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120);
            $table->string('email', 190)->nullable();
            $table->string('phone', 20)->nullable();
            $table->json('languages');
            $table->boolean('clinical')->default(false);
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->foreignId('invited_by')->nullable();
            $table->timestamps();
        });

        $now = now();
        DB::table('languages')->insert(array_map(fn (array $l) => ['code' => $l[0], 'name' => $l[1], 'native_name' => $l[2], 'enabled' => $l[0] === 'en',
            'enabled_at' => $l[0] === 'en' ? $now : null, 'created_at' => $now, 'updated_at' => $now], [
                ['en', 'English', 'English'], ['zu', 'isiZulu', 'isiZulu'], ['xh', 'isiXhosa', 'isiXhosa'], ['af', 'Afrikaans', 'Afrikaans'],
                ['nso', 'Sepedi', 'Sepedi'], ['tn', 'Setswana', 'Setswana'], ['st', 'Sesotho', 'Sesotho'], ['ts', 'Xitsonga', 'Xitsonga'],
                ['ss', 'siSwati', 'siSwati'], ['ve', 'Tshivenda', 'Tshivenḓa'], ['nr', 'isiNdebele', 'isiNdebele'],
            ]));
    }

    public function down(): void
    {
        Schema::dropIfExists('translator_invitations');
        Schema::dropIfExists('translator_languages');
        Schema::dropIfExists('translations');
        Schema::dropIfExists('languages');
    }
};
