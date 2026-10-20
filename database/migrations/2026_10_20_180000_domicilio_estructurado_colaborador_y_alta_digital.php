<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Domicilio ESTRUCTURADO (DomicilioService): calle, número exterior y
 * número interior en el colaborador (colonia/municipio/estado/C.P. ya
 * existían) y los siete campos en el alta digital, para que pasen tal cual
 * al convertirla en colaborador.
 *
 * `domicilio` (texto único) se conserva y se ensancha a 500: ahora se
 * compone con todos los campos y puede rebasar 191 caracteres. Los datos
 * existentes NO se parten adivinando: un registro con solo el texto queda
 * pendiente de capturar sus campos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('colaboradores', function (Blueprint $table) {
            $table->string('domicilio', 500)->nullable()->change();
            $table->string('domicilio_calle', 150)->nullable()->after('domicilio');
            $table->string('domicilio_numero_exterior', 20)->nullable()->after('domicilio_calle');
            $table->string('domicilio_numero_interior', 20)->nullable()->after('domicilio_numero_exterior');
        });

        Schema::table('altas_digitales', function (Blueprint $table) {
            $table->string('domicilio', 500)->nullable()->change();
            $table->string('domicilio_calle', 150)->nullable()->after('domicilio');
            $table->string('domicilio_numero_exterior', 20)->nullable()->after('domicilio_calle');
            $table->string('domicilio_numero_interior', 20)->nullable()->after('domicilio_numero_exterior');
            $table->string('domicilio_colonia', 120)->nullable()->after('domicilio_numero_interior');
            $table->string('domicilio_cp', 10)->nullable()->after('domicilio_colonia');
            $table->string('domicilio_municipio', 120)->nullable()->after('domicilio_cp');
            $table->string('domicilio_estado', 80)->nullable()->after('domicilio_municipio');
        });
    }

    public function down(): void
    {
        Schema::table('altas_digitales', function (Blueprint $table) {
            $table->dropColumn([
                'domicilio_calle', 'domicilio_numero_exterior', 'domicilio_numero_interior',
                'domicilio_colonia', 'domicilio_cp', 'domicilio_municipio', 'domicilio_estado',
            ]);
        });

        Schema::table('colaboradores', function (Blueprint $table) {
            $table->dropColumn(['domicilio_calle', 'domicilio_numero_exterior', 'domicilio_numero_interior']);
        });
    }
};
