<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saved_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('collection_id')->nullable()->constrained('collections')->nullOnDelete();
            $table->string('name')->nullable();
            $table->string('method', 10);
            $table->text('url');
            $table->json('headers')->nullable();
            $table->json('body')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'collection_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_requests');
    }
};
