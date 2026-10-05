<?php

namespace App\Console\Commands;

use App\Models\DocumentTemplate;
use App\Services\DocumentosMaestros\LayoutDocumentoService;
use Illuminate\Console\Command;
use Throwable;

/**
 * php artisan documentos:instalar-fondo-indeterminado [--solo-regional] [--ruta=]
 *
 * Registra `docs/imgBG/bgDocs.png` como fondo administrado («MR. LANA —
 * Fondo contrato indeterminado», por SHA-256, sin duplicar), crea el preset
 * «Contrato indeterminado MR. LANA» y lo asigna a las familias
 * `contrato_indeterminado.*` (o solo a la Regional). Idempotente: no toca
 * familias que ya tengan un diseño asignado.
 */
class InstalarFondoIndeterminadoCommand extends Command
{
    protected $signature = 'documentos:instalar-fondo-indeterminado
                            {--solo-regional : Asigna el preset solo a contrato_indeterminado.regional}
                            {--ruta= : Ruta del PNG (por defecto docs/imgBG/bgDocs.png)}';

    protected $description = 'Registra el fondo oficial de contratos indeterminados y su preset de diseño (idempotente)';

    public function handle(LayoutDocumentoService $diseno): int
    {
        $ruta = (string) ($this->option('ruta') ?: base_path('docs/imgBG/bgDocs.png'));
        $familias = $this->option('solo-regional')
            ? ['contrato_indeterminado.regional']
            : DocumentTemplate::query()->where('familia', 'like', 'contrato_indeterminado.%')->distinct()->orderBy('familia')->pluck('familia')->filter()->values()->all();

        try {
            $resultado = $diseno->instalarPresetIndeterminado($ruta, array_values(array_map('strval', $familias)));
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf('Fondo: %s (v%d, sha256 %s…)', $resultado['fondo']->nombre, $resultado['fondo']->version, substr($resultado['fondo']->sha256, 0, 12)));
        $this->info(sprintf('Preset: %s', $resultado['preset']->nombre));
        $this->table(['Familia'], array_map(fn (string $f) => [$f], $resultado['familias']));

        return self::SUCCESS;
    }
}
