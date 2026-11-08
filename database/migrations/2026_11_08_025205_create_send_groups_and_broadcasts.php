<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Saved recipient groups (managed by the owner) and a log of every message sent through the bot.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('send_groups', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->timestamps();
        });

        Schema::create('send_group_member', function (Blueprint $table) {
            $table->foreignId('send_group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('member_id')->constrained()->cascadeOnDelete();
            $table->primary(['send_group_id', 'member_id']);
        });

        Schema::create('broadcasts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('sender_telegram_id');
            $table->string('audience');
            $table->string('kind');
            $table->text('text')->nullable();
            $table->unsignedInteger('recipients');
            $table->unsignedInteger('delivered');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('broadcasts');
        Schema::dropIfExists('send_group_member');
        Schema::dropIfExists('send_groups');
    }
};
