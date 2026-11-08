<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feedback', function (Blueprint $table) {
            $table->id();
            $table->foreignId('from_member_id')->constrained('members');
            $table->foreignId('to_member_id')->constrained('members');
            $table->string('kind');
            $table->text('description');
            $table->string('status')->default('pending')->index();
            $table->integer('season')->index();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feedback');
    }
};
