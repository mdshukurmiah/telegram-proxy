<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('webhook_targets', function (Blueprint $table): void {
            $table->id();
            $table->string('bot_id', 20);
            $table->text('target_url');
            $table->text('target_secret')->nullable();
            $table->char('route_secret', 32)->unique();
            $table->text('telegram_secret');
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index(['bot_id', 'active']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('webhook_targets');
    }
};
