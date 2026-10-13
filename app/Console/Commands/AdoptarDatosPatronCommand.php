<?php

namespace App\Console\Commands;

use App\Models\Empresa;
use Illuminate\Console\Command;

/**
 * php artisan people:adoptar-datos-patron [--simular]
 *
 * Los documentos legales ya NO usan valores predeterminados ocultos
 * (config documentos_maestros.empresa_defecto): el representante legal y
 * el domicilio fiscal salen SOLO de la empresa (Configuración → Empresa) y,
 * si faltan, el documento se bloquea pidiendo capturarlos.
 *
 * Para no frenar la operación al desplegar, este comando copia esos valores
 * —los que Jurídico aprobó y que hasta hoy se imprimían— a las empresas de
 * MR. LANA que los tengan vacíos, a la vista: quedan editables en la
 * empresa y el reporte dice exactamente qué se llenó. Nunca sobrescribe un
 * dato ya capturado ni toca empresas de otro grupo. Idempotente.
 */
class AdoptarDatosPatronCommand extends Command
{
    protected $signature = 'people:adoptar-datos-patron {--simular : Solo reporta; no guarda nada}';

    protected $description = 'Copia el representante legal y domicilio fiscal aprobados a las empresas MR. LANA que no los tienen (idempotente, visible y editable).';

    public function handle(): int
    {
        $defecto = (array) config('documentos_maestros.empresa_defecto', []);
        $valores = array_filter([
            'representante_legal_nombre' => trim((string) ($defecto['representante_legal_nombre'] ?? '')),
            'representante_legal_cargo' => trim((string) ($defecto['representante_legal_cargo'] ?? '')),
            'domicilio_fiscal' => trim((string) ($defecto['domicilio'] ?? '')),
        ]);

        $filas = [];

        foreach (Empresa::query()->orderBy('id')->get() as $empresa) {
            $grupo = str_contains(mb_strtolower(sprintf('%s %s', $empresa->nombre, $empresa->razon_social)), 'lana');

            if (! $grupo) {
                $filas[] = [$empresa->id, $empresa->nombre, '—', 'Otra empresa: se captura en Configuración → Empresa'];

                continue;
            }

            $llenar = [];

            foreach ($valores as $columna => $valor) {
                if (trim((string) $empresa->getAttribute($columna)) === '') {
                    $llenar[$columna] = $valor;
                }
            }

            if ($llenar !== [] && ! $this->option('simular')) {
                $empresa->update($llenar);
            }

            $filas[] = [$empresa->id, $empresa->nombre, $llenar === [] ? 'ya capturado' : implode(', ', array_keys($llenar)), $llenar === [] ? '' : ($this->option('simular') ? 'se llenaría' : 'llenado')];
        }

        $this->table(['ID', 'Empresa', 'Campos', 'Resultado'], $filas);

        return self::SUCCESS;
    }
}
