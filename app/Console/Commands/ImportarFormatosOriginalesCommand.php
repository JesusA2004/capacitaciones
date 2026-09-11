<?php

namespace App\Console\Commands;

use App\Enums\TipoFormatoOficial;
use App\Models\OfficialFormat;
use App\Services\Formatos\OfficialFormatStorageService;
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
 * self::CATALOGO), asi que correrlo varias veces actualiza el mismo
 * registro/archivo en vez de duplicarlo. Un archivo nuevo que no este en el
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

    public function handle(OfficialFormatStorageService $storage): int
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
        $actualizados = 0;
        $sinMapear = [];

        foreach ($archivos as $rutaArchivo) {
            $nombreArchivo = basename($rutaArchivo);
            $definicion = self::CATALOGO[Str::lower($nombreArchivo)] ?? null;

            if ($definicion === null) {
                $sinMapear[] = $nombreArchivo;

                continue;
            }

            $rutaDestino = $storage->rutaOriginal($definicion['slug']);
            $storage->guardarContenido($rutaDestino, file_get_contents($rutaArchivo) ?: '');

            $existente = OfficialFormat::query()->where('slug', $definicion['slug'])->first();

            OfficialFormat::query()->updateOrCreate(
                ['slug' => $definicion['slug']],
                [
                    'nombre' => $definicion['nombre'],
                    'tipo' => TipoFormatoOficial::from($definicion['tipo'])->value,
                    'source_disk' => config('formatos_oficiales.disk'),
                    'source_path' => $rutaDestino,
                    'original_filename' => $nombreArchivo,
                    'file_type' => 'pdf',
                    'is_active' => true,
                ],
            );

            $existente === null ? $creados++ : $actualizados++;
        }

        $this->info("Formatos oficiales importados: {$creados} creados, {$actualizados} actualizados.");

        if ($sinMapear !== []) {
            $this->warn('Archivos sin mapear en el catálogo (se omitieron): '.implode(', ', $sinMapear));
            $this->warn('Agrega su entrada en ImportarFormatosOriginalesCommand::CATALOGO para importarlos.');
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
