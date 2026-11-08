<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Owner/admin roles, no more admin approval, owner-defined scoring and soft-deletable feedback.
 *
 * Existing data is kept: pending feedback becomes regular feedback, rejected feedback is soft-deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->boolean('is_admin')->default(false)->after('active');
            $table->timestamp('state_updated_at')->nullable()->after('draft');
        });

        Schema::table('feedback', function (Blueprint $table) {
            $table->softDeletes();
            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->index('created_at');
        });

        DB::table('feedback')->where('status', 'rejected')->update(['deleted_at' => now()]);

        Schema::table('feedback', function (Blueprint $table) {
            $table->dropIndex(['status']);
            $table->dropColumn(['status', 'reviewed_at']);
        });

        Schema::create('score_rules', function (Blueprint $table) {
            $table->id();
            $table->string('kind')->unique();
            $table->integer('giver_points')->default(0);
            $table->integer('receiver_points')->default(0);
            $table->timestamps();
        });

        DB::table('score_rules')->insert(array_map(fn (array $rule) => [...$rule, 'created_at' => now(), 'updated_at' => now()], [
            ['kind' => 'network', 'giver_points' => 1, 'receiver_points' => 1],
            ['kind' => 'knowledge', 'giver_points' => 1, 'receiver_points' => 1],
            ['kind' => 'experience', 'giver_points' => 1, 'receiver_points' => 1],
            ['kind' => 'negative', 'giver_points' => 0, 'receiver_points' => 0],
        ]));
    }

    public function down(): void
    {
        Schema::dropIfExists('score_rules');

        Schema::table('feedback', function (Blueprint $table) {
            $table->string('status')->default('approved')->index();
            $table->timestamp('reviewed_at')->nullable();
        });

        DB::table('feedback')->whereNotNull('deleted_at')->update(['status' => 'rejected']);

        Schema::table('feedback', function (Blueprint $table) {
            $table->dropIndex(['created_at']);
            $table->dropColumn(['deleted_at', 'deleted_by']);
        });

        Schema::table('members', function (Blueprint $table) {
            $table->dropColumn(['is_admin', 'state_updated_at']);
        });
    }
};
