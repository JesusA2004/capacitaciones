<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Permite que una extraccion venga de un ExpedienteHistorico (PDF unico
     * de la migracion inicial, App\Models\ExpedienteHistorico) ademas de un
     * EmployeeDocument — nunca ambos a la vez. employee_document_id deja de
     * ser obligatorio: la invariante real pasa a ser "exactamente una de las
     * dos FKs tiene valor", que aplica App\Services\Documentos\DocumentExtractionService
     * al crear la fila (nunca se valida con un CHECK de BD para no complicar
     * el esquema — ver CLAUDE.md "no arquitectura enorme"). Filas existentes
     * siguen teniendo employee_document_id, así que la invariante ya se
     * cumple para ellas sin backfill.
     *
     * De paso, user_id también pasa a nullable: un ExpedienteHistorico puede
     * estar vinculado a un Colaborador que nunca tuvo cuenta de acceso (muy
     * común en colaboradores migrados), igual que ya documentaba
     * EmployeeDocument::user_id ("un colaborador sin cuenta de acceso no
     * tiene user_id") sin que esta tabla lo respetara.
     *
     * MySQL/MariaDB no deja soltar un índice mientras una FK todavía lo
     * necesita para existir ("Cannot drop index ... needed in a foreign key
     * constraint") — a diferencia de SQLite (por eso la suite de pruebas no
     * lo detectó). Por eso aquí se suelta primero la FK de
     * employee_document_id, luego su índice único, y se vuelven a crear
     * ambos al final, ya con la columna nullable.
     */
    public function up(): void
    {
        Schema::table('document_extractions', function (Blueprint $table) {
            $table->dropForeign(['employee_document_id']);
            $table->dropUnique(['employee_document_id']);
            $table->foreignId('employee_document_id')->nullable()->change();
            $table->foreignId('user_id')->nullable()->change();
            $table->foreignId('expediente_historico_id')->nullable()->after('employee_document_id')
                ->constrained('expedientes_historicos')->cascadeOnDelete();
        });

        Schema::table('document_extractions', function (Blueprint $table) {
            $table->foreign('employee_document_id')->references('id')->on('employee_documents')->cascadeOnDelete();
            $table->unique('employee_document_id');
            $table->unique('expediente_historico_id');
        });
    }

    public function down(): void
    {
        Schema::table('document_extractions', function (Blueprint $table) {
            $table->dropUnique(['expediente_historico_id']);
            $table->dropUnique(['employee_document_id']);
            $table->dropForeign(['employee_document_id']);
            $table->dropConstrainedForeignId('expediente_historico_id');
        });

        Schema::table('document_extractions', function (Blueprint $table) {
            $table->foreignId('employee_document_id')->nullable(false)->change();
            $table->foreignId('user_id')->nullable(false)->change();
            $table->foreign('employee_document_id')->references('id')->on('employee_documents')->cascadeOnDelete();
            $table->unique('employee_document_id');
        });
    }
};
