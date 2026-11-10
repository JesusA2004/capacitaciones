<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El correo «Alta en sistemas» ya no sale al entrar a Contratación (llegaba
 * vacío: el nuevo ingreso apenas tenía su QR) sino cuando captura sus datos
 * en la app. Esta migración acomoda a quienes están EN TRÁNSITO con el flujo
 * anterior: avisos de origen «candidato» que Sistemas aún no atiende y cuyo
 * colaborador sigue sin sus datos imprescindibles pasan a «esperando_datos»
 * (y se limpia enviado_en), para que reciban UNA vez el correo ya poblado en
 * cuanto los capturen (AvisoAltaSistemasService::liberarSiDatosCompletos).
 *
 * No toca: los que ya tienen sus datos (su correo ya salió; no se duplica),
 * los atendidos, los «sin_enviar» (regularización sin correo) ni los de
 * reingreso / alta directa. Solo actualiza filas; no cambia el esquema.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('avisos_alta_sistemas')) {
            return;
        }

        // Se parte de los avisos (pocos), no de toda la plantilla.
        $enTransito = DB::table('avisos_alta_sistemas')
            ->where('origen_tipo', 'candidato')
            ->whereIn('estado', ['pendiente', 'enviado', 'fallido'])
            ->whereNull('atendido_en')
            ->pluck('colaborador_id', 'id');

        foreach ($enTransito->chunk(500, true) as $lote) {
            $sinDatos = DB::table('colaboradores')
                ->whereIn('id', $lote->values()->unique()->all())
                ->where(function ($q): void {
                    foreach (['curp', 'rfc', 'nss', 'genero', 'telefono'] as $campo) {
                        $q->orWhereNull($campo)->orWhere($campo, '');
                    }

                    $q->orWhereNull('fecha_nacimiento');
                })
                ->pluck('id')
                ->all();

            if ($sinDatos === []) {
                continue;
            }

            DB::table('avisos_alta_sistemas')
                ->whereIn('id', $lote->keys()->all())
                ->whereIn('colaborador_id', $sinDatos)
                ->update(['estado' => 'esperando_datos', 'enviado_en' => null]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('avisos_alta_sistemas')) {
            return;
        }

        // Sin correo enviado: vuelven a «sin_enviar» (se pueden mandar a mano).
        DB::table('avisos_alta_sistemas')->where('estado', 'esperando_datos')->update(['estado' => 'sin_enviar']);
    }
};
