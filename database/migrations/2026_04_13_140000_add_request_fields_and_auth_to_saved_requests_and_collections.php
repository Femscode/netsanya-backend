<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('collections', function (Blueprint $table) {
            $table->string('auth_type', 20)->nullable()->after('name');
            $table->json('auth_config')->nullable()->after('auth_type');
        });

        Schema::table('saved_requests', function (Blueprint $table) {
            $table->json('query')->nullable()->after('url');
            $table->string('body_type', 20)->default('json')->after('headers');
            $table->longText('body_text')->nullable()->after('body_type');
            $table->json('body_form')->nullable()->after('body_text');
            $table->string('auth_type', 20)->nullable()->after('body_form');
            $table->json('auth_config')->nullable()->after('auth_type');
        });
    }

    public function down(): void
    {
        Schema::table('saved_requests', function (Blueprint $table) {
            $table->dropColumn([
                'query',
                'body_type',
                'body_text',
                'body_form',
                'auth_type',
                'auth_config',
            ]);
        });

        Schema::table('collections', function (Blueprint $table) {
            $table->dropColumn(['auth_type', 'auth_config']);
        });
    }
};
