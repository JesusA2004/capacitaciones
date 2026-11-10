<?php

namespace App\Services\Integraciones\Auditoria;

use App\Enums\EstadoUsuario;
use App\Enums\TipoIdentificacion;
use App\Models\ClienteIntegracion;
use App\Models\Colaborador;
use App\Models\ColaboradorPerfilIngreso;
use App\Models\Sucursal;
use App\Models\User;
use App\Support\NombrePersona;
use Illuminate\Database\Eloquent\Builder;

/**
 * Contexto del ACTA DE CONSTANCIA DE AUDITORÍA para el sistema Auditoría
 * (contrato «people-auditoria/2», sólo estos dos recursos; el v1 no cambia).
 *
 * Minimización: únicamente lo que el formato jurídico del acta imprime
 * (domicilio de la sucursal, nombre/puesto/edad/domicilio de gestor y
 * gerente) y la designación del responsable o gerente que Auditoría necesita
 * para identificar al firmante. Las columnas se eligen una por una: nunca
 * salen salario, CURP/RFC/NSS, clave de elector (sólo si existe, como
 * booleano), teléfonos, correos, datos médicos, contactos, contraseñas,
 * tokens ni archivos del expediente.
 *
 * Reglas de People reutilizadas:
 *  - Empresa de un colaborador = la de su sucursal principal; el cliente sólo
 *    ve sus empresas permitidas (otra empresa = no existe).
 *  - Domicilio estructurado de DomicilioService: si hay calle capturada, el
 *    texto `domicilio` es una composición de los campos y no se envía; si no
 *    hay calle, el texto legado va tal cual en `texto` (nunca se parte).
 *  - Responsable de la sucursal = `sucursales.responsable_id` (una cuenta);
 *    se entrega la persona de esa cuenta, si la tiene.
 *  - Gerente del colaborador = `colaboradores.gerente_id` capturado
 *    explícitamente; el «jefe del jefe» de JerarquiaColaboradorService es una
 *    deducción y no se entrega como gerente.
 */
class ContextoActaAuditoriaService
{
    /**
     * @return array<string, mixed>|null
     */
    public function sucursal(ClienteIntegracion $cliente, int $id): ?array
    {
        $sucursal = Sucursal::withTrashed()
            ->where('id', $id)
            ->first(['id', 'empresa_id', 'clave', 'nombre', 'direccion', 'ciudad', 'colonia', 'municipio', 'estado', 'codigo_postal', 'responsable_id', 'activo', 'updated_at', 'deleted_at']);

        if ($sucursal === null || ! $cliente->puedeVerEmpresa($sucursal->empresa_id)) {
            return null;
        }

        [$estado, $responsable] = $this->responsable($cliente, $sucursal->responsable_id);

        return [
            'id' => $sucursal->id,
            'empresa_id' => $sucursal->empresa_id,
            'clave' => $this->texto($sucursal->clave),
            'nombre' => $sucursal->nombre,
            'activo' => $sucursal->activo && ! $sucursal->trashed(),
            'domicilio' => [
                'direccion' => $this->texto($sucursal->direccion),
                'colonia' => $this->texto($sucursal->colonia),
                'municipio' => $this->texto($sucursal->municipio),
                'ciudad' => $this->texto($sucursal->ciudad),
                'estado' => $this->texto($sucursal->estado),
                'codigo_postal' => $this->texto($sucursal->codigo_postal),
            ],
            'responsable_estado' => $estado,
            'responsable' => $responsable,
            'updated_at' => $sucursal->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function colaborador(ClienteIntegracion $cliente, int $id): ?array
    {
        $c = $this->colaboradores()->where('id', $id)->first([
            'id', 'numero_empleado', 'name', 'apellidos', 'estatus', 'sucursal_principal_id', 'puesto_id', 'gerente_id',
            'fecha_nacimiento', 'domicilio', 'domicilio_calle', 'domicilio_numero_exterior', 'domicilio_numero_interior',
            'domicilio_colonia', 'domicilio_municipio', 'domicilio_estado', 'domicilio_cp', 'clave_elector', 'updated_at', 'deleted_at',
        ]);

        if ($c === null || ! $cliente->puedeVerEmpresa($c->sucursalPrincipal?->empresa_id)) {
            return null;
        }

        $estructurado = $this->texto($c->domicilio_calle) !== null;
        $gerente = $c->gerente_id === null ? null : $this->persona($cliente, $c->gerente_id);

        return [
            ...$this->ficha($c),
            'fecha_nacimiento' => $c->fecha_nacimiento?->format('Y-m-d'),
            'domicilio' => [
                'estructurado' => $estructurado,
                'calle' => $estructurado ? $this->texto($c->domicilio_calle) : null,
                'numero_exterior' => $this->texto($c->domicilio_numero_exterior),
                'numero_interior' => $this->texto($c->domicilio_numero_interior),
                'colonia' => $this->texto($c->domicilio_colonia),
                'municipio' => $this->texto($c->domicilio_municipio),
                'estado' => $this->texto($c->domicilio_estado),
                'codigo_postal' => $this->texto($c->domicilio_cp),
                // Texto legado sólo cuando no hay calle capturada (no se parte).
                'texto' => $estructurado ? null : $this->texto($c->domicilio),
            ],
            // v2: sólo si People tiene una clave de elector capturada (el
            // recurso del v2 descarta lo demás). v3: además el tipo de
            // identificación del estudio socioeconómico y, únicamente con la
            // bandera del propietario encendida, el número de la clave.
            'identificacion' => $this->identificacion($c),
            'gerente_estado' => $c->gerente_id === null ? 'sin_asignar' : ($gerente === null ? 'fuera_de_alcance' : 'asignado'),
            'gerente' => $gerente,
            'updated_at' => $c->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Identificación oficial: la clave de elector de `colaboradores` y el tipo
     * que la persona declaró en su perfil de ingreso (estudio socioeconómico).
     * El folio de otra identificación (`identificacion_folio`) nunca sale.
     *
     * @return array<string, mixed>
     */
    private function identificacion(Colaborador $c): array
    {
        $clave = $this->texto($c->clave_elector);
        $perfil = ColaboradorPerfilIngreso::query()->where('colaborador_id', $c->id)->first(['id', 'colaborador_id', 'identificacion_tipo', 'identificacion_otro', 'identificacion_institucion']);
        $tipo = $perfil->identificacion_tipo ?? ($clave !== null ? TipoIdentificacion::Ine : null);

        return [
            'clave_elector_registrada' => $clave !== null,
            'tipo' => $tipo?->value,
            'tipo_etiqueta' => $tipo === TipoIdentificacion::Otro ? ($this->texto($perfil?->identificacion_otro) ?? $tipo->etiqueta()) : $tipo?->etiqueta(),
            'institucion' => $tipo === TipoIdentificacion::Ine ? 'Instituto Nacional Electoral' : $this->texto($perfil?->identificacion_institucion),
            'clave_elector' => config('integraciones.auditoria.enviar_clave_elector') === true ? $clave : null,
        ];
    }

    /**
     * @return array{0: string, 1: array<string, mixed>|null}
     */
    private function responsable(ClienteIntegracion $cliente, ?int $usuarioId): array
    {
        if ($usuarioId === null) {
            return ['sin_asignar', null];
        }

        $colaboradorId = User::withTrashed()->where('id', $usuarioId)->value('colaborador_id');

        if (! is_int($colaboradorId)) {
            return ['sin_colaborador', null];
        }

        $persona = $this->persona($cliente, $colaboradorId);

        return $persona === null ? ['fuera_de_alcance', null] : ['asignado', $persona];
    }

    /**
     * Nombre, número, puesto, sucursal y si está vigente; nada más.
     *
     * @return array<string, mixed>|null
     */
    private function persona(ClienteIntegracion $cliente, int $id): ?array
    {
        $c = $this->colaboradores()->where('id', $id)->first(['id', 'numero_empleado', 'name', 'apellidos', 'estatus', 'sucursal_principal_id', 'puesto_id', 'deleted_at']);

        if ($c === null || ! $cliente->puedeVerEmpresa($c->sucursalPrincipal?->empresa_id)) {
            return null;
        }

        return $this->ficha($c);
    }

    /**
     * @return Builder<Colaborador>
     */
    private function colaboradores(): Builder
    {
        return Colaborador::withTrashed()->with(['sucursalPrincipal:id,empresa_id,clave,nombre', 'puesto:id,nombre']);
    }

    /**
     * @return array<string, mixed>
     */
    private function ficha(Colaborador $c): array
    {
        $estatus = $c->estatus->value;
        $sucursal = $c->sucursalPrincipal;

        return [
            'id' => $c->id,
            'numero_empleado' => $this->texto($c->numero_empleado),
            'nombre_completo' => NombrePersona::completo((string) $c->name, $c->apellidos),
            'puesto' => $c->puesto?->nombre,
            'empresa_id' => $sucursal?->empresa_id,
            'sucursal' => $sucursal === null ? null : ['id' => $sucursal->id, 'clave' => $this->texto($sucursal->clave), 'nombre' => $sucursal->nombre],
            'activo' => ! $c->trashed() && in_array($estatus, EstadoUsuario::valoresVigentes(), true),
        ];
    }

    private function texto(mixed $valor): ?string
    {
        if (! is_scalar($valor)) {
            return null;
        }

        $texto = trim((string) $valor);

        return $texto === '' ? null : $texto;
    }
}
