<?php

namespace App\Console\Commands;

use App\Models\Empresa;
use Illuminate\Console\Command;

/**
 * php artisan people:datos-fiscales-formatos {empresa} [--sobrescribir]
 *
 * Aplica a UNA empresa (la que indiques por id) los datos patronales que
 * traen los formatos oficiales de RH (docs/formatosRH/*.docx): razón social,
 * RFC, registro patronal IMSS, domicilio fiscal y C.P. de expedición. No
 * adivina a qué empresa pertenecen: tú eliges el id. Por defecto solo llena
 * los campos vacíos; con --sobrescribir reemplaza lo capturado.
 */
class AplicarDatosFiscalesFormatosCommand extends Command
{
    /** Datos tal cual vienen en el encabezado de los tres formatos oficiales. */
    public const DATOS_FORMATOS = [
        'razon_social' => 'PRODUCTOS Y SERVICIOS MR LANA SOCIEDAD ANONIMA PROMOTORA DE INVERSION DE CAPITAL VARIABLE',
        'rfc' => 'PSM2311289J2',
        'registro_patronal' => 'D1564784109',
        'domicilio_fiscal' => 'Subida al club 114 Col. Reforma, Cuernavaca, Morelos',
        'codigo_postal_fiscal' => '62260',
    ];

    protected $signature = 'people:datos-fiscales-formatos {empresa : Id de la empresa patronal} {--sobrescribir : Reemplaza también los campos ya capturados}';

    protected $description = 'Aplica a una empresa los datos patronales de los formatos oficiales de RH (razón social, RFC, registro patronal, domicilio y C.P.)';

    public function handle(): int
    {
        $empresa = Empresa::query()->whereKey((int) $this->argument('empresa'))->first();

        if ($empresa === null) {
            $this->error('No existe una empresa con ese id. Empresas: '.Empresa::query()->orderBy('id')->get(['id', 'nombre'])->map(fn (Empresa $e) => "{$e->id} = {$e->nombre}")->implode(', '));

            return self::FAILURE;
        }

        $cambios = [];

        foreach (self::DATOS_FORMATOS as $campo => $valor) {
            $actual = (string) ($empresa->getAttribute($campo) ?? '');

            if ($actual === $valor || ($actual !== '' && ! $this->option('sobrescribir'))) {
                continue;
            }

            $cambios[$campo] = $valor;
        }

        if ($cambios === []) {
            $this->info(sprintf('«%s» ya tiene los datos patronales; no se cambió nada.', $empresa->nombre));

            return self::SUCCESS;
        }

        $empresa->update($cambios);

        foreach ($cambios as $campo => $valor) {
            $this->line(sprintf(' - %s: %s', $campo, $valor));
        }

        $this->info(sprintf('Datos patronales aplicados a «%s» (%d campo(s)).', $empresa->nombre, count($cambios)));

        return self::SUCCESS;
    }
}
