<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Registro manual de aviso de privacidad / consentimiento de datos para
 * colaboradores que nunca pasaron por el flujo de Alta digital (altas_digitales,
 * ver App\Models\AltaDigital) — la mayoría de la plantilla que se sembró
 * directamente, no via reclutamiento. Es información distinta y con menor
 * peso probatorio que la de altas_digitales (ahí queda firma + IP + fecha del
 * propio colaborador); aquí es RH quien declara explícitamente "ya se lo di
 * a firmar en papel / se lo mandé por correo", por eso se audita quién lo
 * registró (avisos_registrado_por_id) y nunca se muestra si ya existe un
 * alta digital real para ese colaborador (ver ExpedienteController).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('aviso_privacidad_aceptado')->default(false)->after('contacto_emergencia_telefono');
            $table->timestamp('aviso_privacidad_aceptado_en')->nullable()->after('aviso_privacidad_aceptado');
            $table->boolean('consentimiento_datos_aceptado')->default(false)->after('aviso_privacidad_aceptado_en');
            $table->timestamp('consentimiento_datos_aceptado_en')->nullable()->after('consentimiento_datos_aceptado');
            $table->foreignId('avisos_registrado_por_id')->nullable()->after('consentimiento_datos_aceptado_en')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('avisos_registrado_por_id');
            $table->dropColumn([
                'aviso_privacidad_aceptado',
                'aviso_privacidad_aceptado_en',
                'consentimiento_datos_aceptado',
                'consentimiento_datos_aceptado_en',
            ]);
        });
    }
};
