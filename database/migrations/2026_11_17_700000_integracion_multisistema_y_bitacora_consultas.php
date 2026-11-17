<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * API de integración multi-sistema (docs/API_INTEGRACION_SISTEMAS.md):
 *
 *  1. Cada cliente de integración (Auditoría, Reportes, los que sigan) puede
 *     tener su propio límite de solicitudes por minuto (`limite_por_minuto`;
 *     null = el de config/integraciones.php) y una descripción para soporte.
 *  2. Bitácora de consultas (`consultas_integracion`): quién pidió qué, con
 *     qué filtros, qué respondió y cuánto tardó. Nunca guarda el token ni el
 *     cuerpo de la respuesta.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clientes_integracion', function (Blueprint $table) {
            $table->string('descripcion', 255)->nullable()->after('nombre');
            $table->unsignedInteger('limite_por_minuto')->nullable()->after('empresas_permitidas');
        });

        Schema::create('consultas_integracion', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cliente_integracion_id')->nullable()->constrained('clientes_integracion')->nullOnDelete();
            $table->string('metodo', 10);
            $table->string('ruta', 120)->nullable()->comment('Nombre de la ruta de Laravel');
            $table->string('url', 255);
            $table->json('parametros')->nullable();
            $table->unsignedSmallInteger('estado');
            $table->unsignedInteger('registros')->nullable();
            $table->unsignedInteger('duracion_ms')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['cliente_integracion_id', 'created_at'], 'consultas_integracion_cliente_fecha_idx');
            $table->index('created_at', 'consultas_integracion_fecha_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consultas_integracion');

        Schema::table('clientes_integracion', function (Blueprint $table) {
            $table->dropColumn(['descripcion', 'limite_por_minuto']);
        });
    }
};
