<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Support tickets, practice-approved support access, and the public status page.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_tickets', function (Blueprint $table): void {
            $table->id();
            $table->string('tenant_id')->index();
            $table->foreignId('user_id')->constrained();
            $table->string('subject');
            $table->string('status', 10)->default('open');
            $table->timestamps();
        });
        Schema::create('support_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('support_ticket_id')->constrained()->cascadeOnDelete();
            $table->string('author', 8);
            $table->foreignId('user_id')->constrained();
            $table->text('body');
            $table->timestamp('created_at');
        });
        Schema::create('support_grants', function (Blueprint $table): void {
            $table->id();
            $table->string('tenant_id')->index();
            $table->foreignId('granted_by')->constrained('users');
            $table->unsignedBigInteger('support_ticket_id')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
        Schema::table('workspace_handoffs', function (Blueprint $table): void {
            $table->unsignedBigInteger('support_grant_id')->nullable();
        });

        Schema::create('status_components', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 20)->unique();
            $table->string('name');
            $table->string('status', 12)->default('operational');
            $table->boolean('manual')->default(false);
            $table->string('detail')->nullable();
            $table->timestamp('checked_at')->nullable();
        });
        Schema::create('status_incidents', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->string('kind', 12)->default('incident');
            $table->string('status', 14);
            $table->string('impact', 10)->default('minor');
            $table->json('components');
            $table->timestamp('scheduled_for')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });
        Schema::create('status_updates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('status_incident_id')->constrained()->cascadeOnDelete();
            $table->string('status', 14);
            $table->text('body');
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        foreach (['status_updates', 'status_incidents', 'status_components', 'support_grants', 'support_messages', 'support_tickets'] as $t) {
            Schema::dropIfExists($t);
        }
        Schema::table('workspace_handoffs', fn (Blueprint $t) => $t->dropColumn('support_grant_id'));
    }
};
