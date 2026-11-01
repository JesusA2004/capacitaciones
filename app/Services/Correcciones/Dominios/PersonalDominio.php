<?php

namespace App\Services\Correcciones\Dominios;

use App\Enums\EstadoCivil;
use App\Enums\Genero;
use App\Enums\ModoCorreccion;
use App\Models\Colaborador;
use App\Models\User;
use App\Rules\NombreSinApellidos;
use App\Services\AlcanceOrganizacionalService;
use App\Services\Expedientes\DatosExpedienteService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Datos personales y de contacto: delega en DatosExpedienteService. Los médicos
 * solo existen para quien tiene su permiso y se guardan cifrados en el historial.
 */
class PersonalDominio extends DominioColaborador
{
    public function __construct(AlcanceOrganizacionalService $alcance, private readonly DatosExpedienteService $datos)
    {
        parent::__construct($alcance);
    }

    public function clave(): string
    {
        return 'personal';
    }

    protected function valor(Model $sujeto, string $campo): mixed
    {
        if ($sujeto instanceof Colaborador && in_array($campo, DatosExpedienteService::CAMPOS_MEDICOS, true)) {
            return $sujeto->datosMedicos?->getAttribute($campo);
        }

        return parent::valor($sujeto, $campo);
    }

    protected function esVisible(Model $sujeto, string $campo, User $actor): bool
    {
        return ! in_array($campo, DatosExpedienteService::CAMPOS_MEDICOS, true) || $actor->can('expedientes.datos_medicos.ver');
    }

    protected function opcionesDe(string $campo): array
    {
        return match ($campo) {
            'genero' => array_map(fn (Genero $g): array => ['valor' => $g->value, 'etiqueta' => $g->etiqueta()], Genero::cases()),
            'estado_civil' => array_map(fn (EstadoCivil $e): array => ['valor' => $e->value, 'etiqueta' => $e->etiqueta()], EstadoCivil::cases()),
            default => [],
        };
    }

    public function normalizar(Model $sujeto, array $valores, array $opciones, User $actor): array
    {
        $resultado = parent::normalizar($sujeto, $valores, $opciones, $actor);

        // Mismas reglas de objeto que el formulario del expediente.
        if (array_key_exists('name', $resultado)) {
            Validator::make($resultado, ['name' => [new NombreSinApellidos]])->validate();
        }

        foreach (['genero' => Genero::class, 'estado_civil' => EstadoCivil::class] as $campo => $enum) {
            if (($resultado[$campo] ?? null) !== null && $enum::tryFrom((string) $resultado[$campo]) === null) {
                throw ValidationException::withMessages(['valores.'.$campo => 'Elige una opción de la lista.']);
            }
        }

        return $resultado;
    }

    public function afectados(Model $sujeto, array $valores, array $opciones): array
    {
        $criticos = array_intersect(array_keys($valores), DatosExpedienteService::CAMPOS_CRITICOS);

        if ($criticos === []) {
            return [];
        }

        return [[
            'tipo' => 'documento',
            'etiqueta' => 'Documentos firmados y recibos emitidos',
            'detalle' => 'Conservan el dato con el que se firmaron o emitieron. Revisa si IMSS u otras instancias requieren aviso: el sistema no lo envía (pendiente de validación).',
            'accion' => 'intacto',
        ]];
    }

    public function aplicar(Model $sujeto, array $valores, string $motivo, array $opciones, User $actor): array
    {
        if (! $sujeto instanceof Colaborador) {
            throw ValidationException::withMessages(['valores' => 'Registro no válido.']);
        }

        $this->datos->actualizarPersonales($sujeto, $valores, $actor, $motivo, 'correcciones');

        return ['modo' => ModoCorreccion::Directo->value];
    }
}
