<?php

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
        if (! Schema::hasColumn('application_settings', 'is_release_deploy_enabled')) {
            Schema::table('application_settings', function (Blueprint $table) {
                $table->boolean('is_release_deploy_enabled')->default(false)->after('is_auto_deploy_enabled');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('application_settings', 'is_release_deploy_enabled')) {
            Schema::table('application_settings', function (Blueprint $table) {
                $table->dropColumn('is_release_deploy_enabled');
            });
        }
    }
};
