<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pactivasign_webhook_events', function (Blueprint $table): void {
            $table->id();
            $table->string('event_id', 64)->unique();
            $table->string('type', 64)->index();
            $table->uuid('envelope_id')->nullable();
            $table->unsignedBigInteger('envelope_version')->nullable();
            $table->boolean('stale')->default(false);
            $table->longText('payload');
            $table->timestamp('received_at')->index();
            $table->timestamp('processed_at')->nullable();

            $table->index(['envelope_id', 'envelope_version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pactivasign_webhook_events');
    }
};
