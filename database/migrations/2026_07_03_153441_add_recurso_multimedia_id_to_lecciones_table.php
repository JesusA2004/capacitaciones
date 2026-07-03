<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lecciones', function (Blueprint $table) {
            $table->foreignId('recurso_multimedia_id')->nullable()->after('url')->constrained('recursos_multimedia')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('lecciones', function (Blueprint $table) {
            $table->dropConstrainedForeignId('recurso_multimedia_id');
        });
    }
};
