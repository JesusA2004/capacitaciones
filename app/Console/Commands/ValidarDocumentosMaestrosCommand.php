<?php

namespace App\Console\Commands;

use App\Services\DocumentosMaestros\Calidad\DiagnosticoFuentesService;
use App\Services\DocumentosMaestros\DocumentosMaestrosAdminService;
use Illuminate\Console\Command;

/**
 * Vuelve a correr el QA visual de TODAS las versiones operativas pendientes
 * o fallidas, con el mismo servicio que usa "Probar diseño" (y el botón
 * "Revalidar pendientes" de Documentos maestros).
 *
 *   php artisan people:validar-documentos-maestros
 *
 * Pensado para correrse después de instalar una fuente en el servidor (o
 * por cron): limpia solo el caché de familias instaladas —nunca
 * `cache:clear` global— y NO activa versiones ni modifica el ORIGINAL ni el
 * master; solo actualiza el resultado del QA (idempotente: correrlo varias
 * veces con el mismo servidor da el mismo resultado).
 */
class ValidarDocumentosMaestrosCommand extends Command
{
    protected $signature = 'people:validar-documentos-maestros';

    protected $description = 'Vuelve a validar el diseño de las versiones maestras operativas pendientes/fallidas (sin activar nada)';

    public function handle(DiagnosticoFuentesService $fuentes, DocumentosMaestrosAdminService $admin): int
    {
        $fuentes->invalidarCache();

        $resultados = $admin->revalidarPendientes();

        if ($resultados === []) {
            $this->info('No hay versiones operativas pendientes o fallidas: nada que validar.');

            return self::SUCCESS;
        }

        $this->table(['Familia', 'Versión', 'Resultado', 'Motivo'], array_map(
            fn (array $r): array => [$r['familia'], $r['version'], mb_strtoupper($r['estado']), $r['motivo']],
            $resultados,
        ));

        $fallidas = count(array_filter($resultados, fn (array $r): bool => $r['estado'] === 'fallido'));
        $this->line(sprintf('%d revisada(s), %d fallida(s).', count($resultados), $fallidas));

        return $fallidas > 0 ? self::FAILURE : self::SUCCESS;
    }
}
