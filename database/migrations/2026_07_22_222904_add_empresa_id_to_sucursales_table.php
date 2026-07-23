<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * empresa_id se agrega NULLABLE a proposito (sin doctrine/dbal en el
 * proyecto no es seguro forzar NOT NULL con ->change() despues del
 * backfill). Toda sucursal nueva debe capturar su empresa desde el
 * formulario (ver StoreSucursalRequest), pero a nivel de base de datos la
 * columna queda nullable para no arriesgar una migracion destructiva.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sucursales', function (Blueprint $table) {
            $table->foreignId('empresa_id')->nullable()->after('id')->constrained('empresas')->nullOnDelete();
        });

        if (DB::table('sucursales')->whereNull('empresa_id')->exists()) {
            $empresaPorDefectoId = DB::table('empresas')->where('nombre', 'Mr. Lana')->value('id');

            if ($empresaPorDefectoId === null) {
                $empresaPorDefectoId = DB::table('empresas')->insertGetId([
                    'nombre' => 'Mr. Lana',
                    'activo' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            DB::table('sucursales')->whereNull('empresa_id')->update(['empresa_id' => $empresaPorDefectoId]);
        }
    }

    public function down(): void
    {
        Schema::table('sucursales', function (Blueprint $table) {
            $table->dropConstrainedForeignId('empresa_id');
        });
    }
};
