<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Parte B de la separación Usuario/Colaborador (ver docblock de
 * 2026_09_17_090300_add_colaborador_id_a_tablas_persona.php, que dejó esta
 * parte pendiente a propósito): repunta a `colaborador_id` las tablas
 * restantes cuyo `user_id` (u otra FK a `users`) identifica una PERSONA, no
 * un actor.
 *
 * Mismo patrón que la Parte A: se agrega la columna nueva nullable junto a
 * la vieja (que se conserva sin borrar, rollback seguro) — el código todavía
 * no se cambia para escribirla ni leerla en este commit, eso vive en un
 * cambio posterior de Services/Controllers; ver ColaboradoresBackfillDesdeUsersCommand
 * para el backfill de datos existentes.
 *
 * `finiquito_calculos.colaborador_id` queda fuera a propósito: esa columna
 * ya se llama `colaborador_id` pero hoy referencia `users.id` (se creó antes
 * de que existiera App\Models\Colaborador) — repuntarla requiere renombrarla
 * y actualizar FiniquitoService en el mismo cambio, no solo la migración.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('solicitudes_internas', function (Blueprint $table) {
            // Persona solicitante (antes user_id) y persona objetivo de una
            // baja (antes colaborador_objetivo_id, que a pesar del nombre
            // apunta hoy a users.id).
            $table->foreignId('colaborador_id')->nullable()->after('user_id')
                ->constrained('colaboradores')->nullOnDelete();
            $table->foreignId('objetivo_colaborador_id')->nullable()->after('colaborador_objetivo_id')
                ->constrained('colaboradores')->nullOnDelete();
        });

        Schema::table('solicitudes_vacaciones', function (Blueprint $table) {
            $table->foreignId('colaborador_id')->nullable()->after('user_id')
                ->constrained('colaboradores')->nullOnDelete();
        });

        Schema::table('altas_digitales', function (Blueprint $table) {
            // Se llena cuando el alta ya generó (o se enlazó a) un
            // Colaborador — puede seguir null mientras el alta está en
            // proceso, ver App\Services\AltaDigital\ConversionColaboradorService.
            $table->foreignId('colaborador_id')->nullable()->after('user_id')
                ->constrained('colaboradores')->nullOnDelete();
        });

        Schema::table('incorporacion_invitaciones', function (Blueprint $table) {
            // Una invitación puede existir para un Colaborador SIN User
            // todavía (ver sección 9 del encargo) — user_id se sigue
            // llenando solo al completar el registro.
            $table->foreignId('colaborador_id')->nullable()->after('user_id')
                ->constrained('colaboradores')->nullOnDelete();
        });

        Schema::table('generated_documents', function (Blueprint $table) {
            $table->foreignId('colaborador_id')->nullable()->after('user_id')
                ->constrained('colaboradores')->nullOnDelete();
        });

        Schema::table('official_format_generations', function (Blueprint $table) {
            $table->foreignId('colaborador_id')->nullable()->after('user_id')
                ->constrained('colaboradores')->nullOnDelete();
        });

        Schema::table('document_extractions', function (Blueprint $table) {
            $table->foreignId('colaborador_id')->nullable()->after('user_id')
                ->constrained('colaboradores')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('document_extractions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('colaborador_id');
        });

        Schema::table('official_format_generations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('colaborador_id');
        });

        Schema::table('generated_documents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('colaborador_id');
        });

        Schema::table('incorporacion_invitaciones', function (Blueprint $table) {
            $table->dropConstrainedForeignId('colaborador_id');
        });

        Schema::table('altas_digitales', function (Blueprint $table) {
            $table->dropConstrainedForeignId('colaborador_id');
        });

        Schema::table('solicitudes_vacaciones', function (Blueprint $table) {
            $table->dropConstrainedForeignId('colaborador_id');
        });

        Schema::table('solicitudes_internas', function (Blueprint $table) {
            $table->dropConstrainedForeignId('objetivo_colaborador_id');
            $table->dropConstrainedForeignId('colaborador_id');
        });
    }
};
