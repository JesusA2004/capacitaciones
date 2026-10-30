<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Administración de salarios (PDF Etapa 1, págs. 18-19): tabulador por puesto con
 * versiones y matriz de mérito con historial. Ninguna de las dos tablas guarda ni
 * modifica el sueldo real de nadie: ese vive en colaboradores + historial_salarios.
 */
return new class extends Migration
{
    /** @var array<string, list<string>> */
    private const PERMISOS_POR_ROL = [
        'super_admin' => ['salarios.ver', 'salarios.editar', 'salarios.exportar', 'salarios.importar'],
        'rh_admin' => ['salarios.ver', 'salarios.editar', 'salarios.exportar'],
        'direccion' => ['salarios.ver', 'salarios.exportar'],
    ];

    public function up(): void
    {
        Schema::create('tabuladores_salariales', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('puesto_id')->constrained('puestos')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->decimal('minimo', 10, 2);
            $table->decimal('medio', 10, 2);
            $table->decimal('maximo', 10, 2);
            $table->string('fuente', 160);
            $table->date('fecha_actualizacion');
            $table->date('vigente_desde');
            $table->date('vigente_hasta')->nullable();
            $table->string('origen', 20)->default('captura');
            $table->string('motivo', 400)->nullable();
            $table->foreignId('importacion_id')->nullable()->constrained('importaciones_excel')->nullOnDelete();
            $table->foreignId('usuario_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['puesto_id', 'version'], 'tabulador_puesto_version_unique');
            $table->index(['puesto_id', 'vigente_hasta'], 'tabulador_puesto_vigencia_index');
        });

        Schema::create('matrices_merito', function (Blueprint $table): void {
            $table->id();
            $table->json('celdas');
            $table->date('vigente_desde');
            $table->date('vigente_hasta')->nullable();
            $table->string('motivo', 400)->nullable();
            $table->foreignId('usuario_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // Matriz inicial propuesta por RH en el PDF (pág. 18); RH la edita después.
        DB::table('matrices_merito')->insert([
            'celdas' => json_encode([
                'sobresaliente' => ['bajo_minimo' => 10, 'minimo_a_medio' => 8, 'medio_a_maximo' => 5, 'sobre_maximo' => 'bono'],
                'satisfactorio' => ['bajo_minimo' => 7, 'minimo_a_medio' => 5, 'medio_a_maximo' => 3, 'sobre_maximo' => 0],
                'bajo' => ['bajo_minimo' => 0, 'minimo_a_medio' => 0, 'medio_a_maximo' => 0, 'sobre_maximo' => 0],
            ]),
            'vigente_desde' => now()->toDateString(),
            'motivo' => 'Matriz inicial de la propuesta de mejoras de RH (Etapa 1).',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->sincronizarPermisos();
    }

    public function down(): void
    {
        Schema::dropIfExists('matrices_merito');
        Schema::dropIfExists('tabuladores_salariales');

        Permission::query()->where('name', 'like', 'salarios.%')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function sincronizarPermisos(): void
    {
        foreach (self::PERMISOS_POR_ROL['super_admin'] as $permiso) {
            Permission::query()->firstOrCreate(['name' => $permiso, 'guard_name' => 'web']);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::PERMISOS_POR_ROL as $rol => $permisos) {
            Role::query()->where('name', $rol)->where('guard_name', 'web')->first()?->givePermissionTo($permisos);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
