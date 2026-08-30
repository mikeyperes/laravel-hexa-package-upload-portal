<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const INDEX = 'uploaded_files_context_owner_status_index';

    public function up(): void
    {
        if (Schema::hasTable('uploaded_files') && ! Schema::hasIndex('uploaded_files', self::INDEX)) {
            Schema::table('uploaded_files', function (Blueprint $table): void {
                $table->index(['context', 'context_id', 'uploaded_by', 'status'], self::INDEX);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('uploaded_files') && Schema::hasIndex('uploaded_files', self::INDEX)) {
            Schema::table('uploaded_files', function (Blueprint $table): void {
                $table->dropIndex(self::INDEX);
            });
        }
    }
};
