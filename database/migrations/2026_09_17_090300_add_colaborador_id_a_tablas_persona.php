<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Repunta a `colaborador_id` las tablas cuyo `user_id` identifica "de quién
 * es este registro" (persona), no "quién hizo la acción" (actor), y que
 * necesitan funcionar para un Colaborador sin cuenta de acceso:
 *
 * - employee_documents.user_id  → expediente de un colaborador
 * - movimientos_laborales.user_id → altas/bajas/cambios de un colaborador
 * - birthday_greetings.user_id  → cumpleaños de un colaborador
 * - nodos_comerciales.responsable_user_id → gestor de un nodo (matriz)
 * - user_nodo_comercial.user_id → historial de cobertura (matriz)
 *
 * `user_id` (y `responsable_user_id`) en estas tablas era NOT NULL — se
 * relaja a nullable porque un colaborador sin cuenta de acceso no tiene
 * ningún `users.id` que poner ahí. `colaborador_id` es la columna real desde
 * ahora; `user_id` se conserva sin borrar (rollback seguro, regla del repo
 * de nunca destruir datos de producción a ciegas) pero el código deja de
 * escribirla, salvo cuando de casualidad el colaborador sí tiene cuenta.
 *
 * El resto de las FKs "persona" identificadas en la auditoría
 * (solicitudes_internas, solicitudes_vacaciones, finiquito_calculos,
 * altas_digitales, incorporacion_invitaciones, generated_documents,
 * official_format_generations, document_extractions) se deja pendiente a
 * propósito (Parte B) — ver plan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_documents', function (Blueprint $table) {
            $table->foreignId('colaborador_id')->nullable()->after('user_id')
                ->constrained('colaboradores')->cascadeOnDelete();
            $table->unsignedBigInteger('user_id')->nullable()->change();
            $table->index(['colaborador_id', 'document_type_id'], 'employee_documents_colaborador_document_type_idx');
        });

        Schema::table('movimientos_laborales', function (Blueprint $table) {
            $table->foreignId('colaborador_id')->nullable()->after('user_id')
                ->constrained('colaboradores')->cascadeOnDelete();
            $table->foreignId('jefe_anterior_colaborador_id')->nullable()->after('jefe_anterior_id')
                ->constrained('colaboradores')->nullOnDelete();
            $table->foreignId('jefe_nuevo_colaborador_id')->nullable()->after('jefe_nuevo_id')
                ->constrained('colaboradores')->nullOnDelete();
            $table->unsignedBigInteger('user_id')->nullable()->change();
            $table->index(['colaborador_id', 'fecha_movimiento'], 'movimientos_laborales_colaborador_fecha_idx');
        });

        Schema::table('birthday_greetings', function (Blueprint $table) {
            $table->foreignId('colaborador_id')->nullable()->after('user_id')
                ->constrained('colaboradores')->cascadeOnDelete();
            $table->unsignedBigInteger('user_id')->nullable()->change();

            // El único índice que cubre `user_id` es el unique(['user_id',
            // 'fecha']) original: MySQL en modo estricto (a diferencia de
            // MariaDB, donde esto sí corrió sin problema) rechaza
            // dropUnique() aquí porque ese índice sigue siendo necesario
            // para la FK de user_id -> users.id. Se crea primero un índice
            // simple sobre user_id para que la FK se apoye en ese, y
            // entonces sí se puede quitar el compuesto.
            $table->index('user_id', 'birthday_greetings_user_id_idx');
            $table->dropUnique(['user_id', 'fecha']);
            $table->unique(['colaborador_id', 'fecha'], 'birthday_greetings_colaborador_fecha_unico');
        });

        Schema::table('nodos_comerciales', function (Blueprint $table) {
            $table->foreignId('responsable_colaborador_id')->nullable()->after('responsable_user_id')
                ->constrained('colaboradores')->nullOnDelete();
        });

        Schema::table('user_nodo_comercial', function (Blueprint $table) {
            $table->foreignId('colaborador_id')->nullable()->after('user_id')
                ->constrained('colaboradores')->cascadeOnDelete();
            $table->unsignedBigInteger('user_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('user_nodo_comercial', function (Blueprint $table) {
            $table->dropConstrainedForeignId('colaborador_id');
            $table->unsignedBigInteger('user_id')->nullable(false)->change();
        });

        Schema::table('nodos_comerciales', function (Blueprint $table) {
            $table->dropConstrainedForeignId('responsable_colaborador_id');
        });

        Schema::table('birthday_greetings', function (Blueprint $table) {
            $table->dropUnique('birthday_greetings_colaborador_fecha_unico');
            $table->dropConstrainedForeignId('colaborador_id');
            $table->unsignedBigInteger('user_id')->nullable(false)->change();
            $table->unique(['user_id', 'fecha']);
            $table->dropIndex('birthday_greetings_user_id_idx');
        });

        Schema::table('movimientos_laborales', function (Blueprint $table) {
            $table->dropIndex('movimientos_laborales_colaborador_fecha_idx');
            $table->dropConstrainedForeignId('jefe_nuevo_colaborador_id');
            $table->dropConstrainedForeignId('jefe_anterior_colaborador_id');
            $table->dropConstrainedForeignId('colaborador_id');
            $table->unsignedBigInteger('user_id')->nullable(false)->change();
        });

        Schema::table('employee_documents', function (Blueprint $table) {
            $table->dropIndex('employee_documents_colaborador_document_type_idx');
            $table->dropConstrainedForeignId('colaborador_id');
            $table->unsignedBigInteger('user_id')->nullable(false)->change();
        });
    }
};
