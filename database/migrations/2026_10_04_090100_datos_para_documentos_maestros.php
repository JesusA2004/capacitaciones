<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Datos que los formatos jurídicos oficiales piden y que PEOPLE no tenía
 * (ver docs/INVENTARIO_FORMATOS_JURIDICOS.md): generales del colaborador
 * (nacionalidad, estado civil, lugar de nacimiento, clave de elector,
 * profesión, beneficiario art. 501 LFT, domicilio desglosado), domicilio y
 * representante de la empresa, domicilio desglosado de la sucursal y el
 * grupo documental del puesto (qué variante de contrato le toca).
 *
 * Todo nullable: nunca se inventa un valor; si un documento lo exige y
 * falta, el motor pide completarlo antes de generar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('colaboradores', function (Blueprint $table): void {
            $table->string('nacionalidad', 60)->nullable();
            $table->string('estado_civil', 20)->nullable();
            $table->string('lugar_nacimiento', 120)->nullable();
            $table->string('clave_elector', 30)->nullable();
            $table->string('profesion', 120)->nullable();
            $table->string('beneficiario_nombre', 191)->nullable();
            $table->string('beneficiario_parentesco', 60)->nullable();
            $table->string('domicilio_colonia', 120)->nullable();
            $table->string('domicilio_municipio', 120)->nullable();
            $table->string('domicilio_estado', 80)->nullable();
            $table->string('domicilio_cp', 10)->nullable();
        });

        Schema::table('empresas', function (Blueprint $table): void {
            $table->string('domicilio_fiscal', 255)->nullable();
            $table->string('ciudad_firma', 120)->nullable();
            $table->string('representante_legal_nombre', 191)->nullable();
            $table->string('representante_legal_cargo', 120)->nullable();
        });

        Schema::table('sucursales', function (Blueprint $table): void {
            $table->string('colonia', 120)->nullable();
            $table->string('municipio', 120)->nullable();
            $table->string('codigo_postal', 10)->nullable();
        });

        Schema::table('puestos', function (Blueprint $table): void {
            $table->string('grupo_documental', 40)->nullable()->index();
        });

        // Grupo documental inicial por nombre de puesto (editable en el
        // catálogo de puestos). Los administrativos confianza/no confianza
        // se toman de la redacción de sus contratos: "implica manejo de
        // información confidencial, apoyo directo a la Dirección General y
        // funciones de coordinación administrativa".
        foreach ((array) config('documentos_maestros.grupos_por_puesto', []) as $nombre => $grupo) {
            DB::table('puestos')->where('nombre', $nombre)->whereNull('grupo_documental')->update(['grupo_documental' => $grupo]);
        }
    }

    public function down(): void
    {
        Schema::table('puestos', function (Blueprint $table): void {
            $table->dropIndex(['grupo_documental']);
            $table->dropColumn('grupo_documental');
        });

        Schema::table('sucursales', function (Blueprint $table): void {
            $table->dropColumn(['colonia', 'municipio', 'codigo_postal']);
        });

        Schema::table('empresas', function (Blueprint $table): void {
            $table->dropColumn(['domicilio_fiscal', 'ciudad_firma', 'representante_legal_nombre', 'representante_legal_cargo']);
        });

        Schema::table('colaboradores', function (Blueprint $table): void {
            $table->dropColumn([
                'nacionalidad', 'estado_civil', 'lugar_nacimiento', 'clave_elector', 'profesion', 'beneficiario_nombre',
                'beneficiario_parentesco', 'domicilio_colonia', 'domicilio_municipio', 'domicilio_estado', 'domicilio_cp',
            ]);
        });
    }
};
