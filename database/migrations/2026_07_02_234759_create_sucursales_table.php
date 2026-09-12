<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sucursales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->nullable()->constrained('empresas')->nullOnDelete();
            $table->string('nombre');
            $table->string('clave')->unique();
            $table->string('direccion')->nullable();
            $table->string('ciudad')->nullable();
            $table->string('estado')->nullable();
            $table->string('telefono')->nullable();
            // Sin constraint de DB a proposito: sucursales se crea antes que
            // users (users.sucursal_principal_id depende de sucursales, asi
            // que el sentido inverso no puede ser una FK real sin crear un
            // ciclo). La relacion sigue existiendo a nivel Eloquent.
            $table->unsignedBigInteger('responsable_id')->nullable()->index();
            $table->boolean('activo')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sucursales');
    }
};
