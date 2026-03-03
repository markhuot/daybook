<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('timezone')->nullable();
            $table->text('weekly_summary')->nullable();
            $table->timestamp('weekly_summary_at')->nullable();
            $table->text('monthly_summary')->nullable();
            $table->timestamp('monthly_summary_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'timezone',
                'weekly_summary',
                'weekly_summary_at',
                'monthly_summary',
                'monthly_summary_at',
            ]);
        });
    }
};
