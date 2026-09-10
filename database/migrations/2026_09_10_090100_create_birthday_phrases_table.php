<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('birthday_phrases', function (Blueprint $table) {
            $table->id();
            $table->text('texto');
            $table->string('categoria')->nullable();
            $table->boolean('activo')->default(true);
            $table->unsignedInteger('orden')->nullable();
            $table->unsignedInteger('usado_count')->default(0);
            $table->timestamp('ultimo_uso_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('birthday_phrases');
    }
};
