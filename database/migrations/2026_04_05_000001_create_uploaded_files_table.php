<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('uploaded_files')) {
            Schema::create('uploaded_files', function (Blueprint $table) {
                $table->id();
                $table->string('filename');
                $table->string('original_name');
                $table->string('path');
                $table->string('disk')->default('local');
                $table->unsignedBigInteger('size')->default(0);
                $table->string('mime_type')->nullable();
                $table->string('context', 50)->index();
                $table->unsignedBigInteger('context_id')->index();
                $table->unsignedBigInteger('uploaded_by')->nullable();
                $table->enum('status', ['temp', 'permanent', 'deleted'])->default('temp');
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->index(['context', 'context_id', 'status']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('uploaded_files');
    }
};
