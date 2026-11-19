<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Recepción documental RH: el catálogo de «lo que recibo» deja de ser una
 * entrada fija por regla y admite ENTRADAS AGREGADAS POR RH
 * (docs/RECEPCION_DOCUMENTAL_RH.md → «Catálogo de lo que se recibe»).
 *
 *  - clave: identificador único de la entrada. Para las del sistema es el
 *    valor de la regla (se rellena aquí); para las agregadas,
 *    «personalizado.<slug>» y es el tipo_documento de sus renglones.
 *  - modo_calculo (App\Enums\ModoCalculoRecepcion): regla del sistema,
 *    cantidad fija al mes, por sucursal, por colaborador activo o por evento.
 *  - cantidad: papeles (fijo), por sucursal, por colaborador o por evento.
 *  - regla_base: regla del sistema cuyos eventos cuentan (modo por_evento).
 *  - bloque: regla bajo cuyo bloque aparece en el corte (vacío = la suya).
 *  - departamento_ids: solo ciertas áreas (por colaborador / por evento).
 *  - copias: copias por documento de una entrada del sistema ({clave: n}).
 *
 * El índice único pasa de `regla` a `clave` (varias entradas agregadas
 * comparten la regla «personalizada»). Aditiva: no toca cortes ni renglones.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('recepcion_documental_catalogo') || Schema::hasColumn('recepcion_documental_catalogo', 'clave')) {
            return;
        }

        Schema::table('recepcion_documental_catalogo', function (Blueprint $table) {
            $table->string('clave', 60)->nullable()->after('id');
            $table->string('modo_calculo', 30)->default('regla')->after('regla');
            $table->unsignedSmallInteger('cantidad')->default(1)->after('modo_calculo');
            $table->string('regla_base', 30)->nullable()->after('cantidad');
            $table->string('bloque', 30)->nullable()->after('regla_base');
            $table->json('departamento_ids')->nullable()->after('empresa_ids');
            $table->json('copias')->nullable()->after('documentos');
        });

        DB::table('recepcion_documental_catalogo')->whereNull('clave')->update(['clave' => DB::raw('regla')]);

        Schema::table('recepcion_documental_catalogo', function (Blueprint $table) {
            $table->dropUnique('rec_doc_catalogo_regla_unico');
        });

        Schema::table('recepcion_documental_catalogo', function (Blueprint $table) {
            $table->unique('clave', 'rec_doc_catalogo_clave_unico');
            $table->index('regla', 'rec_doc_catalogo_regla_idx');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('recepcion_documental_catalogo', 'clave')) {
            return;
        }

        // Las entradas agregadas por RH no caben en el esquema anterior (una por regla).
        DB::table('recepcion_documental_catalogo')->where('modo_calculo', '!=', 'regla')->delete();

        Schema::table('recepcion_documental_catalogo', function (Blueprint $table) {
            $table->dropUnique('rec_doc_catalogo_clave_unico');
            $table->dropIndex('rec_doc_catalogo_regla_idx');
        });

        Schema::table('recepcion_documental_catalogo', function (Blueprint $table) {
            $table->dropColumn(['clave', 'modo_calculo', 'cantidad', 'regla_base', 'bloque', 'departamento_ids', 'copias']);
            $table->unique('regla', 'rec_doc_catalogo_regla_unico');
        });
    }
};
