<?php

namespace App\Services\Integraciones\Auditoria;

use App\Enums\EstadoUsuario;
use App\Models\ClienteIntegracion;
use App\Models\Empresa;
use App\Models\Sucursal;
use App\Support\NombrePersona;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Lectura de colaboradores para el sistema Auditoría (contrato
 * «people-auditoria/1»). Sólo lectura y con lista blanca de columnas a nivel
 * SQL: nunca se seleccionan salario, CURP/RFC/NSS, domicilio, teléfonos,
 * correos, contactos, rutas de foto/expediente ni nada de otras tablas
 * sensibles.
 *
 * Reglas reutilizadas de People (no se reinventan):
 *  - Empresa = la de la sucursal principal (Colaborador::empresa(), no hay
 *    colaboradores.empresa_id; docs/MULTIEMPRESA.md).
 *  - Sucursal vigente = sucursal_principal_id. `sucursal_colaborador` son
 *    sucursales ADICIONALES autorizadas y no se exponen.
 *  - Puesto vigente = colaboradores.puesto_id.
 *  - Activo = sin soft-delete y estatus en EstadoUsuario::valoresVigentes()
 *    (activo, en_incorporacion), la misma regla de plantilla/headcount. La
 *    baja (BajaColaboradorService) deja estatus=inactivo, fecha_baja y
 *    soft-delete: aquí se sigue listando con activo=false.
 *
 * Marca de cambio efectiva: el máximo de updated_at del colaborador (que ya
 * avanza en baja, soft-delete, restore, reingreso y cambio de sucursal o
 * puesto, porque todos escriben por Eloquent sobre colaboradores), de su
 * sucursal principal, de la empresa de ésta y de su puesto (renombres o
 * cambio de empresa de la sucursal). Se calcula en la consulta, así que
 * ninguna ruta de escritura puede olvidarse de «tocar» al colaborador.
 */
class ColaboradoresAuditoriaService
{
    public const POR_PAGINA = 100;

    public const POR_PAGINA_MAXIMO = 200;

    private const MINIMO = '1970-01-01 00:00:00';

    private const TERMINOS_EFECTIVOS = "(COALESCE(c.updated_at, c.created_at, '1970-01-01 00:00:00'), COALESCE(s.updated_at, '1970-01-01 00:00:00'), COALESCE(e.updated_at, '1970-01-01 00:00:00'), COALESCE(p.updated_at, '1970-01-01 00:00:00'))";

    /**
     * @return array{filas: list<array<string, mixed>>, siguiente: array{u: string, i: int}|null}
     */
    public function pagina(ClienteIntegracion $cliente, ?int $empresaId, ?CarbonImmutable $desde, ?string $cursorMarca, ?int $cursorId, int $porPagina): array
    {
        $efectivo = $this->expresionEfectiva();
        $consulta = $this->base($cliente);

        if ($empresaId !== null) {
            $consulta->where('s.empresa_id', $empresaId);
        }

        if ($desde !== null) {
            $consulta->whereRaw($efectivo.' >= ?', [$desde->utc()->format('Y-m-d H:i:s')]);
        }

        if ($cursorMarca !== null && $cursorId !== null) {
            $consulta->where(function (Builder $q) use ($efectivo, $cursorMarca, $cursorId): void {
                $q->whereRaw($efectivo.' > ?', [$cursorMarca])
                    ->orWhere(function (Builder $q) use ($efectivo, $cursorMarca, $cursorId): void {
                        $q->whereRaw($efectivo.' = ?', [$cursorMarca])->where('c.id', '>', $cursorId);
                    });
            });
        }

        $filas = $consulta
            ->orderByRaw($efectivo.' asc')
            ->orderBy('c.id')
            ->limit($porPagina + 1)
            ->get()
            ->all();

        $hayMas = count($filas) > $porPagina;
        $filas = array_slice($filas, 0, $porPagina);
        $ultima = $filas === [] ? null : $filas[count($filas) - 1];

        return [
            'filas' => array_map(fn (stdClass $f) => $this->transformar($f), $filas),
            'siguiente' => $hayMas && $ultima !== null ? ['u' => $this->texto($ultima->cambio_efectivo) ?? self::MINIMO, 'i' => (int) $ultima->id] : null,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function uno(ClienteIntegracion $cliente, int $id): ?array
    {
        $fila = $this->base($cliente)->where('c.id', $id)->first();

        return $fila instanceof stdClass ? $this->transformar($fila) : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function sucursales(ClienteIntegracion $cliente, ?int $empresaId): array
    {
        $permitidas = $cliente->empresasPermitidas();

        return array_values(Sucursal::withTrashed()
            ->when($permitidas !== null, fn ($q) => $q->whereIn('empresa_id', $permitidas ?? []))
            ->when($empresaId !== null, fn ($q) => $q->where('empresa_id', $empresaId))
            ->orderBy('id')
            ->get(['id', 'empresa_id', 'clave', 'nombre', 'activo', 'updated_at', 'deleted_at'])
            ->map(fn (Sucursal $s) => [
                'id' => $s->id,
                'empresa_id' => $s->empresa_id,
                'clave' => $this->texto($s->clave),
                'nombre' => $s->nombre,
                'activo' => $s->activo && ! $s->trashed(),
                'updated_at' => $s->updated_at?->toIso8601String(),
            ])
            ->all());
    }

    /**
     * @return list<array{id: int, nombre: string, razon_social: string|null, activo: bool}>
     */
    public function empresas(ClienteIntegracion $cliente): array
    {
        $permitidas = $cliente->empresasPermitidas();

        return array_values(Empresa::query()
            ->when($permitidas !== null, fn ($q) => $q->whereIn('id', $permitidas ?? []))
            ->orderBy('id')
            ->get(['id', 'nombre', 'razon_social', 'activo'])
            ->map(fn (Empresa $e) => [
                'id' => $e->id,
                'nombre' => $e->nombre,
                'razon_social' => $e->razon_social,
                'activo' => $e->activo,
            ])
            ->all());
    }

    public function empresaVisible(ClienteIntegracion $cliente, int $empresaId): bool
    {
        return $cliente->puedeVerEmpresa($empresaId) && Empresa::query()->whereKey($empresaId)->exists();
    }

    private function base(ClienteIntegracion $cliente): Builder
    {
        $permitidas = $cliente->empresasPermitidas();

        return DB::table('colaboradores as c')
            ->leftJoin('sucursales as s', 's.id', '=', 'c.sucursal_principal_id')
            ->leftJoin('empresas as e', 'e.id', '=', 's.empresa_id')
            ->leftJoin('puestos as p', 'p.id', '=', 'c.puesto_id')
            ->when($permitidas !== null, fn (Builder $q) => $q->whereIn('s.empresa_id', $permitidas ?? []))
            ->select([
                'c.id', 'c.numero_empleado', 'c.clave_legacy', 'c.name', 'c.apellidos', 'c.estatus',
                'c.fecha_ingreso', 'c.fecha_baja', 'c.deleted_at',
                's.id as sucursal_id', 's.clave as sucursal_clave', 's.nombre as sucursal_nombre',
                'e.id as empresa_id', 'e.nombre as empresa_nombre',
                'p.id as puesto_id', 'p.nombre as puesto_nombre',
            ])
            ->selectRaw($this->expresionEfectiva().' as cambio_efectivo');
    }

    /**
     * GREATEST en MySQL/MariaDB/PostgreSQL; MAX escalar en SQLite (pruebas).
     * Cada término con COALESCE: en MySQL un NULL anularía el GREATEST.
     *
     * @return literal-string
     */
    private function expresionEfectiva(): string
    {
        $funcion = DB::connection()->getDriverName() === 'sqlite' ? 'MAX' : 'GREATEST';

        return $funcion.self::TERMINOS_EFECTIVOS;
    }

    /**
     * @return array<string, mixed>
     */
    private function transformar(stdClass $f): array
    {
        $nombre = $this->texto($f->name) ?? '';
        $apellidos = $this->texto($f->apellidos);
        $estatus = $this->texto($f->estatus);
        $eliminado = $f->deleted_at !== null;

        return [
            'id' => (int) $f->id,
            'numero_empleado' => $this->texto($f->numero_empleado),
            'clave_noi' => $this->texto($f->clave_legacy),
            'nombres' => NombrePersona::sinApellidos($nombre, $apellidos),
            // People guarda los apellidos en un solo campo: no se parten
            // (apellidos compuestos como «De la Cruz») para no adivinar.
            'apellido_paterno' => null,
            'apellido_materno' => null,
            'apellidos' => $apellidos,
            'nombre_completo' => NombrePersona::completo($nombre, $apellidos),
            'empresa' => $f->empresa_id !== null ? ['id' => (int) $f->empresa_id, 'nombre' => $this->texto($f->empresa_nombre) ?? ''] : null,
            'sucursal' => $f->sucursal_id !== null ? ['id' => (int) $f->sucursal_id, 'clave' => $this->texto($f->sucursal_clave), 'nombre' => $this->texto($f->sucursal_nombre) ?? ''] : null,
            'puesto' => $f->puesto_id !== null ? ['id' => (int) $f->puesto_id, 'nombre' => $this->texto($f->puesto_nombre) ?? ''] : null,
            'estatus' => $estatus,
            'activo' => ! $eliminado && in_array($estatus, EstadoUsuario::valoresVigentes(), true),
            'fecha_alta' => $this->fecha($f->fecha_ingreso),
            'fecha_baja' => $this->fecha($f->fecha_baja),
            'updated_at' => CarbonImmutable::parse($this->texto($f->cambio_efectivo) ?? self::MINIMO, 'UTC')->toIso8601String(),
        ];
    }

    private function fecha(mixed $valor): ?string
    {
        $texto = $this->texto($valor);

        return $texto === null ? null : CarbonImmutable::parse($texto)->format('Y-m-d');
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
