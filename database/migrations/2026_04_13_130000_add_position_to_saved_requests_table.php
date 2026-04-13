<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('saved_requests', function (Blueprint $table) {
            $table->unsignedInteger('position')->default(0)->after('collection_id');
            $table->index(['user_id', 'collection_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::table('saved_requests', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'collection_id', 'position']);
            $table->dropColumn('position');
        });
    }
};
