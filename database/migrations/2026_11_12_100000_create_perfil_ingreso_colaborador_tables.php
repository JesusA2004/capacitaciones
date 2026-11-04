<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Solicitud de empleo + Estudio socioeconómico generados por PEOPLE
 * (docs/SOLICITUD_EMPLEO_Y_SOCIOECONOMICO.md). Solo AGREGA: nada existente
 * se borra ni se reescribe.
 *
 *  - colaboradores: teléfono alterno y último grado de estudios (datos
 *    personales genéricos y estables).
 *  - colaborador_datos_medicos: tipo sanguíneo (cifrado, junto a la
 *    condición médica y alergias que ya viven ahí).
 *  - colaborador_perfiles_ingreso (1:1): domicilio ampliado, vivienda,
 *    competencias, perfil humano y vacante. La situación económica
 *    (egresos, inmueble, vehículo, bienes) y el folio de identificación
 *    van cifrados.
 *  - colaborador_dependientes / colaborador_empleos_anteriores /
 *    colaborador_referencias_personales: listas del formato.
 *  - document_types: «Estudio socioeconómico» (el de la solicitud de
 *    empleo, `solicitud_empleo`, ya existe y se reutiliza).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('colaboradores', function (Blueprint $table) {
            if (! Schema::hasColumn('colaboradores', 'telefono_alterno')) {
                $table->string('telefono_alterno', 20)->nullable()->after('telefono');
            }

            if (! Schema::hasColumn('colaboradores', 'escolaridad')) {
                $table->string('escolaridad', 30)->nullable()->after('profesion');
            }
        });

        if (! Schema::hasColumn('colaborador_datos_medicos', 'tipo_sanguineo')) {
            Schema::table('colaborador_datos_medicos', function (Blueprint $table) {
                // Cifrado (cast encrypted): por eso texto y no un varchar corto.
                $table->text('tipo_sanguineo')->nullable()->after('alergias');
            });
        }

        if (! Schema::hasTable('colaborador_perfiles_ingreso')) {
            Schema::create('colaborador_perfiles_ingreso', function (Blueprint $table) {
                $table->id();
                $table->foreignId('colaborador_id')->constrained('colaboradores')->cascadeOnDelete();
                // Datos personales complementarios.
                $table->unsignedTinyInteger('numero_hijos')->nullable();
                $table->string('conyuge_nombre', 191)->nullable();
                $table->string('estado_salud', 20)->nullable();
                $table->boolean('padece_enfermedad_cronica')->nullable();
                $table->boolean('proceso_legal_laboral')->nullable();
                $table->string('identificacion_tipo', 20)->nullable();
                $table->string('identificacion_otro', 80)->nullable();
                $table->string('identificacion_institucion', 150)->nullable();
                $table->text('identificacion_folio')->nullable();
                // Domicilio ampliado (croquis, arraigo, traslado).
                $table->unsignedTinyInteger('tiempo_residencia_anios')->nullable();
                $table->unsignedTinyInteger('tiempo_residencia_meses')->nullable();
                $table->string('con_quien_vive', 150)->nullable();
                $table->unsignedSmallInteger('tiempo_traslado_minutos')->nullable();
                $table->string('entre_calle_1', 120)->nullable();
                $table->string('entre_calle_2', 120)->nullable();
                $table->string('calle_posterior', 120)->nullable();
                $table->string('calle_lateral', 120)->nullable();
                $table->text('referencias_domicilio')->nullable();
                // Listas declaradas vacías a propósito («No tengo…»).
                $table->boolean('sin_dependientes')->default(false);
                $table->boolean('sin_experiencia_laboral')->default(false);
                // Vivienda.
                $table->string('vivienda_tipo', 20)->nullable();
                $table->string('vivienda_tenencia', 20)->nullable();
                $table->string('paredes', 20)->nullable();
                $table->string('paredes_otro', 80)->nullable();
                $table->unsignedTinyInteger('numero_cuartos')->nullable();
                $table->unsignedTinyInteger('numero_banos')->nullable();
                $table->boolean('tiene_patio')->nullable();
                $table->string('construccion_otros', 120)->nullable();
                $table->json('servicios_vivienda')->nullable();
                $table->string('servicios_vivienda_otro', 120)->nullable();
                $table->unsignedTinyInteger('personas_habitan')->nullable();
                // Situación económica (egresos, INFONAVIT, inmueble, vehículo,
                // bienes, servicio médico): JSON cifrado.
                $table->longText('situacion_economica')->nullable();
                // Escolaridad y competencias.
                $table->string('area_cursos', 255)->nullable();
                $table->boolean('licencia_moto')->nullable();
                $table->unsignedTinyInteger('anios_manejo_moto')->nullable();
                $table->boolean('exp_ventas')->nullable();
                $table->unsignedTinyInteger('exp_ventas_anios')->nullable();
                $table->boolean('exp_cobranza')->nullable();
                $table->unsignedTinyInteger('exp_cobranza_anios')->nullable();
                $table->boolean('exp_creditos')->nullable();
                $table->string('exp_creditos_donde', 120)->nullable();
                $table->boolean('prospeccion_campo')->nullable();
                $table->boolean('apps_moviles')->nullable();
                $table->boolean('comisiones_objetivos')->nullable();
                $table->json('estilos_trabajo')->nullable();
                // Perfil humano y vacante.
                $table->string('fuente_vacante', 30)->nullable();
                $table->string('fuente_vacante_otro', 80)->nullable();
                $table->boolean('aplico_antes')->nullable();
                $table->boolean('familiares_empresa')->nullable();
                $table->string('familiares_empresa_nombre', 150)->nullable();
                $table->string('tres_palabras', 120)->nullable();
                $table->text('fortalezas')->nullable();
                $table->text('logro_profesional')->nullable();
                $table->text('situacion_dificil')->nullable();
                $table->text('areas_oportunidad')->nullable();
                $table->text('como_te_describen')->nullable();
                // Campos que la persona declaró «No aplica» y bitácora por sección.
                $table->json('no_aplica')->nullable();
                $table->json('secciones')->nullable();
                $table->timestamps();

                $table->unique('colaborador_id', 'col_perfil_ingreso_colaborador_unico');
            });
        }

        if (! Schema::hasTable('colaborador_dependientes')) {
            Schema::create('colaborador_dependientes', function (Blueprint $table) {
                $table->id();
                $table->foreignId('colaborador_id')->constrained('colaboradores')->cascadeOnDelete();
                $table->unsignedTinyInteger('orden')->default(1);
                $table->string('nombre', 120);
                $table->string('apellido_paterno', 80);
                $table->string('apellido_materno', 80)->nullable();
                $table->string('parentesco', 20);
                $table->string('parentesco_otro', 60)->nullable();
                $table->date('fecha_nacimiento')->nullable();
                $table->unsignedTinyInteger('edad')->nullable();
                $table->string('escolaridad_ocupacion', 120);
                $table->timestamps();

                $table->index(['colaborador_id', 'orden'], 'col_dependientes_orden_idx');
            });
        }

        if (! Schema::hasTable('colaborador_empleos_anteriores')) {
            Schema::create('colaborador_empleos_anteriores', function (Blueprint $table) {
                $table->id();
                $table->foreignId('colaborador_id')->constrained('colaboradores')->cascadeOnDelete();
                $table->unsignedTinyInteger('orden')->default(1);
                $table->string('empresa', 150);
                $table->string('puesto', 120);
                $table->date('desde');
                $table->date('hasta')->nullable();
                $table->text('funciones');
                $table->string('motivo_salida', 191);
                $table->string('telefono', 20);
                $table->timestamps();

                $table->index(['colaborador_id', 'orden'], 'col_empleos_orden_idx');
            });
        }

        if (! Schema::hasTable('colaborador_referencias_personales')) {
            Schema::create('colaborador_referencias_personales', function (Blueprint $table) {
                $table->id();
                $table->foreignId('colaborador_id')->constrained('colaboradores')->cascadeOnDelete();
                $table->unsignedTinyInteger('orden')->default(1);
                $table->string('nombre', 150);
                $table->string('relacion', 60);
                $table->string('telefono', 20);
                $table->unsignedTinyInteger('anios_conocerlo')->nullable();
                $table->timestamps();

                $table->index(['colaborador_id', 'orden'], 'col_referencias_orden_idx');
            });
        }

        if (Schema::hasTable('document_types') && ! DB::table('document_types')->where('clave', 'estudio_socioeconomico')->exists()) {
            DB::table('document_types')->insert([
                'clave' => 'estudio_socioeconomico',
                'nombre' => 'Estudio socioeconómico (Formato interno)',
                'categoria' => 'personales',
                'requerido' => false,
                'aplica_alta' => false,
                'activo' => true,
                'orden' => 100,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('colaborador_referencias_personales');
        Schema::dropIfExists('colaborador_empleos_anteriores');
        Schema::dropIfExists('colaborador_dependientes');
        Schema::dropIfExists('colaborador_perfiles_ingreso');

        if (Schema::hasColumn('colaborador_datos_medicos', 'tipo_sanguineo')) {
            Schema::table('colaborador_datos_medicos', function (Blueprint $table) {
                $table->dropColumn('tipo_sanguineo');
            });
        }

        Schema::table('colaboradores', function (Blueprint $table) {
            foreach (['telefono_alterno', 'escolaridad'] as $columna) {
                if (Schema::hasColumn('colaboradores', $columna)) {
                    $table->dropColumn($columna);
                }
            }
        });

        // El tipo documental no se borra: employee_documents puede referenciarlo.
    }
};
