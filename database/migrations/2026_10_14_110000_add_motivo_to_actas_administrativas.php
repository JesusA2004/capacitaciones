<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Motivo del acta (PRO-ADP-08): lo escribe quien levanta el acta y va en el
| encabezado del formato oficial («ACTA ADMINISTRATIVA POR …»). Es texto
| libre: no hay catálogo cerrado de motivos.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('actas_administrativas', function (Blueprint $table) {
            $table->string('motivo', 191)->nullable()->after('tipo');
        });
    }

    public function down(): void
    {
        Schema::table('actas_administrativas', function (Blueprint $table) {
            $table->dropColumn('motivo');
        });
    }
};
