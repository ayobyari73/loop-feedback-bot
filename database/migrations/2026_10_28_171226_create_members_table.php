<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('members', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('telegram_id')->nullable()->unique();
            $table->string('username')->unique();
            $table->string('name')->nullable();
            $table->boolean('active')->default(true);
            $table->string('state')->default('idle');
            $table->json('draft')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('members');
    }
};
