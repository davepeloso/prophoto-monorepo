<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Extends the existing image_interactions table with session tracking.
     */
    public function up(): void
    {
        Schema::table('image_interactions', function (Blueprint $table) {
            // Session tracking for analytics
            $table->string('session_id')->nullable()->after('ip_address');
            $table->text('user_agent')->nullable()->after('session_id');

            // Indexes for better query performance
            $table->index('user_id', 'idx_interactions_user');
            $table->index('created_at', 'idx_interactions_created');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('image_interactions', function (Blueprint $table) {
            $table->dropIndex('idx_interactions_user');
            $table->dropIndex('idx_interactions_created');
            $table->dropColumn(['session_id', 'user_agent']);
        });
    }
};
