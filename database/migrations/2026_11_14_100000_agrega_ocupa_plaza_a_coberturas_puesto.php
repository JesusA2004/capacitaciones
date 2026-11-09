<?php

use App\Services\Headcount\PuestosPlantillaService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Una persona en dos puestos, por decisión de RH en cada caso
 * (docs/HEADCOUNT_Y_VACANTES.md → «Una persona en dos puestos»):
 *  - ocupa_plaza = true: «Ocupa ambos puestos (sin vacante)». La segunda
 *    plaza cuenta como ocupada y no abre vacante.
 *  - ocupa_plaza = false: «Cubre mientras se contrata (con vacante)». La
 *    plaza sigue vacante (comportamiento de siempre).
 *
 * Las coberturas vigentes de puestos de empresa ya evitaban la vacante
 * (regla anterior de HeadcountService::plantillaDePar()), así que se marcan
 * como «ocupa ambos» para que nada cambie al desplegar.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('coberturas_puesto', 'ocupa_plaza')) {
            Schema::table('coberturas_puesto', function (Blueprint $table) {
                $table->boolean('ocupa_plaza')->default(false)->after('activa');
            });
        }

        $puestos = app(PuestosPlantillaService::class);

        $ids = DB::table('coberturas_puesto')
            ->where('activa', true)
            ->where('ocupa_plaza', false)
            ->get(['id', 'puesto_id'])
            ->filter(fn (object $fila): bool => $puestos->esDeEmpresa((int) $fila->puesto_id))
            ->pluck('id');

        if ($ids->isNotEmpty()) {
            DB::table('coberturas_puesto')->whereIn('id', $ids)->update(['ocupa_plaza' => true]);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('coberturas_puesto', 'ocupa_plaza')) {
            Schema::table('coberturas_puesto', function (Blueprint $table) {
                $table->dropColumn('ocupa_plaza');
            });
        }
    }
};
