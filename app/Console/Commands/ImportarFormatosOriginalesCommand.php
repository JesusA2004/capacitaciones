<?php

namespace App\Console\Commands;

use App\Enums\EstadoVersionFormato;
use App\Enums\TipoFormatoOficial;
use App\Models\OfficialFormat;
use App\Models\OfficialFormatVersion;
use App\Services\Formatos\PlantillaOficialService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Siembra/actualiza el catalogo de formatos oficiales (docs/FORMATOS_OFICIALES.md)
 * desde los PDFs reales que RH coloca en config('formatos_oficiales.origen_local')
 * (claude/formatos/originales/ por defecto) — esa carpeta NO se sube a Git
 * (ver claude/formatos/README.md), asi que este comando solo encuentra
 * archivos donde alguien ya los coloco a mano (normalmente el workspace
 * local de quien administra el sistema, o el VPS si se copian ahi antes de
 * correr el comando).
 *
 * Idempotente: cada archivo conocido se mapea a un slug fijo (ver
 * self::CATALOGO). Un PDF que ya existe (mismo hash) no hace nada; uno
 * modificado crea una VERSIÓN nueva en borrador (nunca sobrescribe la
 * vigente, ver docs/FORMATOS_OFICIALES.md). Un archivo nuevo que no este en el
 * catalogo se reporta como "sin mapear" y se omite (no se adivina el
 * tipo/nombre de un PDF desconocido).
 */
class ImportarFormatosOriginalesCommand extends Command
{
    protected $signature = 'formatos:importar-originales';

    protected $description = 'Importa los PDFs oficiales de MR. LANA desde la carpeta local al storage privado y crea/actualiza su registro';

    /**
     * Mapeo fijo "nombre de archivo real" -> definicion del formato. Se
     * matchea por igualdad exacta del nombre de archivo (sin distinguir
     * mayusculas), asi que renombrar el PDF en `originales/` rompe el
     * mapeo a proposito: obliga a decidir conscientemente el slug/tipo del
     * archivo nuevo en vez de adivinarlo.
     *
     * @var array<string, array{slug: string, nombre: string, tipo: string}>
     */
    private const CATALOGO = [
        '1. formato de vacaciones[1].pdf' => [
            'slug' => 'formato-vacaciones',
            'nombre' => 'Formato de vacaciones',
            'tipo' => 'vacaciones',
        ],
        '1.-lista de documentos mr. lana.pdf' => [
            'slug' => 'lista-documentos-mr-lana',
            'nombre' => 'Lista de documentos MR. LANA',
            'tipo' => 'documentos',
        ],
        '2. solicitud de empleo para gestor 2025.pdf' => [
            'slug' => 'solicitud-empleo-gestor',
            'nombre' => 'Solicitud de empleo (Gestor)',
            'tipo' => 'solicitud_empleo',
        ],
        '2. solicitud de empleo.pdf' => [
            'slug' => 'solicitud-empleo',
            'nombre' => 'Solicitud de empleo',
            'tipo' => 'solicitud_empleo',
        ],
        '4. estudio socioecónomico mr. lana.pdf' => [
            'slug' => 'estudio-socioeconomico',
            'nombre' => 'Estudio socioeconómico',
            'tipo' => 'otro',
        ],
        'contrato de crédito para colaboradores.pdf' => [
            'slug' => 'contrato-credito-colaboradores',
            'nombre' => 'Contrato de crédito para colaboradores',
            'tipo' => 'contrato',
        ],
        'formato para baja de personal (1).pdf' => [
            'slug' => 'formato-baja-personal',
            'nombre' => 'Formato para baja de personal',
            'tipo' => 'baja',
        ],
        'formato permiso mr. lana.pdf' => [
            'slug' => 'formato-permiso',
            'nombre' => 'Formato de permiso',
            'tipo' => 'permiso',
        ],
        'lista de documentos corporativo.pdf' => [
            'slug' => 'lista-documentos-corporativo',
            'nombre' => 'Lista de documentos corporativo',
            'tipo' => 'documentos',
        ],
        'notificacion_embarazo_mrlana.pdf' => [
            'slug' => 'notificacion-embarazo',
            'nombre' => 'Notificación de embarazo',
            'tipo' => 'embarazo',
        ],
        'periodo de lactancia.pdf' => [
            'slug' => 'periodo-lactancia',
            'nombre' => 'Periodo de lactancia',
            'tipo' => 'lactancia',
        ],
        'registro de capacitación_mr lana.pdf' => [
            'slug' => 'registro-capacitacion',
            'nombre' => 'Registro de capacitación',
            'tipo' => 'capacitacion',
        ],
    ];

    public function handle(PlantillaOficialService $plantillas): int
    {
        $carpetaConfigurada = (string) config('formatos_oficiales.origen_local');
        $carpeta = $this->esRutaAbsoluta($carpetaConfigurada) ? $carpetaConfigurada : base_path($carpetaConfigurada);

        if (! is_dir($carpeta)) {
            $this->info("No existe la carpeta local {$carpeta}. Nada que importar.");

            return self::SUCCESS;
        }

        $archivos = glob($carpeta.DIRECTORY_SEPARATOR.'*.pdf') ?: [];

        if ($archivos === []) {
            $this->info('No hay PDFs en la carpeta local. Nada que importar.');

            return self::SUCCESS;
        }

        $creados = 0;
        $versionados = 0;
        $sinCambios = 0;
        $sinMapear = [];

        foreach ($archivos as $rutaArchivo) {
            $nombreArchivo = basename($rutaArchivo);
            $definicion = self::CATALOGO[Str::lower($nombreArchivo)] ?? null;

            if ($definicion === null) {
                $sinMapear[] = $nombreArchivo;

                continue;
            }

            $formato = OfficialFormat::query()->where('slug', $definicion['slug'])->first();
            $hash = hash_file('sha256', $rutaArchivo);

            if ($formato === null) {
                $formato = OfficialFormat::query()->create([
                    'slug' => $definicion['slug'],
                    'nombre' => $definicion['nombre'],
                    'tipo' => TipoFormatoOficial::from($definicion['tipo'])->value,
                    'aplica_a' => 'colaborador',
                    'file_type' => 'pdf',
                    'is_active' => true,
                ]);
                $plantillas->nuevaVersionDesdeRuta($formato, $rutaArchivo, $nombreArchivo, null, 'Importado desde claude/formatos/originales.');
                $creados++;

                continue;
            }

            // Nunca se sobrescribe una versión: si el PDF cambió, queda como
            // borrador nuevo para que RH revise el mapeo y lo publique.
            $existe = OfficialFormatVersion::query()->where('official_format_id', $formato->id)->where('source_hash', $hash)->exists();
            $borrador = OfficialFormatVersion::query()->where('official_format_id', $formato->id)->where('estado', EstadoVersionFormato::Borrador->value)->exists();

            if ($existe || $borrador) {
                $sinCambios++;

                continue;
            }

            $plantillas->nuevaVersionDesdeRuta($formato, $rutaArchivo, $nombreArchivo, null, 'PDF actualizado en claude/formatos/originales.');
            $versionados++;
        }

        $this->info("Formatos oficiales: {$creados} nuevos, {$versionados} con versión nueva en borrador, {$sinCambios} sin cambios.");

        if ($sinMapear !== []) {
            $this->warn('Archivos sin mapear en el catálogo (se omitieron): '.implode(', ', $sinMapear));
            $this->warn('Agrega su entrada en ImportarFormatosOriginalesCommand::CATALOGO para importarlos, o súbelos desde Formatos → Nueva plantilla.');
        }

        return self::SUCCESS;
    }

    /**
     * `config('formatos_oficiales.origen_local')` normalmente es relativa
     * (`claude/formatos/originales`, resuelta con base_path()), pero admitir
     * una ruta absoluta permite apuntarla fuera del repo (o, en pruebas,
     * a una carpeta temporal) sin que base_path() la concatene mal.
     */
    private function esRutaAbsoluta(string $ruta): bool
    {
        return str_starts_with($ruta, '/') || preg_match('#^[A-Za-z]:[\\\\/]#', $ruta) === 1;
    }
}
