<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Al aplicar una baja, el colaborador queda Inactivo / «Baja» en ese mismo
 * momento y se cierran sus procesos pendientes (contratación, inducción, QR
 * de registro, alta en sistemas). `reversion_baja` guarda qué había antes
 * (estatus, estado del alta, fecha de baja y cada proceso cerrado) para que
 * «No procede» lo regrese tal cual — ver App\Services\Colaboradores\EstatusLaboralService.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('cierres_laborales', 'reversion_baja')) {
            return;
        }

        Schema::table('cierres_laborales', function (Blueprint $table): void {
            $table->json('reversion_baja')->nullable()->after('accesos_cancelados_en');
            $table->timestamp('estatus_laboral_aplicado_en')->nullable()->after('reversion_baja');
        });
    }

    public function down(): void
    {
        Schema::table('cierres_laborales', function (Blueprint $table): void {
            $table->dropColumn(['reversion_baja', 'estatus_laboral_aplicado_en']);
        });
    }
};
