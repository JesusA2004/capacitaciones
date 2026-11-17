<?php

namespace App\Services\Integraciones\Sistemas;

use App\Enums\EstadoUsuario;
use App\Enums\TipoContratacion;
use App\Models\ClienteIntegracion;
use App\Services\Integraciones\Auditoria\ColaboradoresAuditoriaService;
use App\Support\NombrePersona;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Plantilla de colaboradores para la API multi-sistema (/colaboradores).
 *
 * Lista blanca a nivel SQL: por omisión sólo identidad laboral (número de
 * empleado, nombre, estatus, fechas de ingreso y baja, empresa, sucursal,
 * puesto, departamento, tipo de contratación). La identificación oficial
 * (CURP, RFC, NSS) y el salario sólo se SELECCIONAN cuando el cliente tiene
 * la habilidad separada y además los pide (`incluir=`); nunca domicilio,
 * teléfonos, correos, contactos, rutas de foto/expediente ni beneficiarios.
 *
 * Reglas reutilizadas (no se reinventan): sucursal vigente = la principal,
 * activo = sin borrado lógico y estatus vigente (EstadoUsuario), marca de
 * cambio = la misma expresión efectiva del contrato de Auditoría
 * (ColaboradoresAuditoriaService::expresionEfectiva).
 */
class PlantillaIntegracionService
{
    public const INCLUIR_IDENTIFICACION = 'identificacion';

    public const INCLUIR_SALARIO = 'salario';

    public const ESTATUS_ACTIVOS = 'activos';

    public const ESTATUS_BAJAS = 'bajas';

    public const ESTATUS_TODOS = 'todos';

    private const MINIMO = '1970-01-01 00:00:00';

    public function __construct(private readonly ColaboradoresAuditoriaService $auditoria) {}

    /**
     * @param  array{empresa_id: int|null, sucursal_id: int|null, estatus: string, actualizado_desde: CarbonImmutable|null, numero_empleado: string|null}  $filtros
     * @param  list<string>  $incluir
     * @param  array{u: string, i: int}|null  $cursor
     * @return array{filas: list<array<string, mixed>>, siguiente: array{u: string, i: int}|null}
     */
    public function pagina(ClienteIntegracion $cliente, array $filtros, array $incluir, ?array $cursor, int $porPagina): array
    {
        $efectivo = $this->auditoria->expresionEfectiva();
        $consulta = $this->base($cliente, $incluir);

        if ($filtros['empresa_id'] !== null) {
            $consulta->where('s.empresa_id', $filtros['empresa_id']);
        }

        if ($filtros['sucursal_id'] !== null) {
            $consulta->where('c.sucursal_principal_id', $filtros['sucursal_id']);
        }

        if ($filtros['numero_empleado'] !== null) {
            $consulta->where('c.numero_empleado', $filtros['numero_empleado']);
        }

        if ($filtros['estatus'] === self::ESTATUS_ACTIVOS) {
            $consulta->whereNull('c.deleted_at')->whereIn('c.estatus', EstadoUsuario::valoresVigentes());
        } elseif ($filtros['estatus'] === self::ESTATUS_BAJAS) {
            $consulta->where(fn (Builder $q) => $q->whereNotNull('c.deleted_at')->orWhereNotIn('c.estatus', EstadoUsuario::valoresVigentes()));
        }

        if ($filtros['actualizado_desde'] !== null) {
            $consulta->whereRaw($efectivo.' >= ?', [$filtros['actualizado_desde']->utc()->format('Y-m-d H:i:s')]);
        }

        if ($cursor !== null) {
            $consulta->where(function (Builder $q) use ($efectivo, $cursor): void {
                $q->whereRaw($efectivo.' > ?', [$cursor['u']])
                    ->orWhere(function (Builder $q) use ($efectivo, $cursor): void {
                        $q->whereRaw($efectivo.' = ?', [$cursor['u']])->where('c.id', '>', $cursor['i']);
                    });
            });
        }

        $filas = $consulta->orderByRaw($efectivo.' asc')->orderBy('c.id')->limit($porPagina + 1)->get()->all();
        $hayMas = count($filas) > $porPagina;
        $filas = array_slice($filas, 0, $porPagina);
        $ultima = $filas === [] ? null : $filas[count($filas) - 1];

        return [
            'filas' => array_map(fn (stdClass $f): array => $this->transformar($f, $incluir), $filas),
            'siguiente' => $hayMas && $ultima !== null ? ['u' => $this->texto($ultima->cambio_efectivo) ?? self::MINIMO, 'i' => (int) $ultima->id] : null,
        ];
    }

    /**
     * @param  list<string>  $incluir
     * @return array<string, mixed>|null
     */
    public function uno(ClienteIntegracion $cliente, int $id, array $incluir): ?array
    {
        $fila = $this->base($cliente, $incluir)->where('c.id', $id)->first();

        return $fila instanceof stdClass ? $this->transformar($fila, $incluir) : null;
    }

    /**
     * @param  list<string>  $incluir
     */
    private function base(ClienteIntegracion $cliente, array $incluir): Builder
    {
        $permitidas = $cliente->empresasPermitidas();
        $columnas = [
            'c.id', 'c.numero_empleado', 'c.clave_legacy', 'c.name', 'c.apellidos', 'c.estatus', 'c.tipo_contratacion',
            'c.fecha_ingreso', 'c.fecha_baja', 'c.deleted_at',
            's.id as sucursal_id', 's.clave as sucursal_clave', 's.nombre as sucursal_nombre',
            'e.id as empresa_id', 'e.nombre as empresa_nombre',
            'p.id as puesto_id', 'p.nombre as puesto_nombre',
            'd.id as departamento_id', 'd.nombre as departamento_nombre',
        ];

        if (in_array(self::INCLUIR_IDENTIFICACION, $incluir, true)) {
            array_push($columnas, 'c.curp', 'c.rfc', 'c.nss');
        }

        if (in_array(self::INCLUIR_SALARIO, $incluir, true)) {
            array_push($columnas, 'c.salario_diario', 'c.salario_diario_integrado', 'c.sueldo_mensual');
        }

        return DB::table('colaboradores as c')
            ->leftJoin('sucursales as s', 's.id', '=', 'c.sucursal_principal_id')
            ->leftJoin('empresas as e', 'e.id', '=', 's.empresa_id')
            ->leftJoin('puestos as p', 'p.id', '=', 'c.puesto_id')
            ->leftJoin('departamentos as d', 'd.id', '=', 'p.departamento_id')
            ->when($permitidas !== null, fn (Builder $q) => $q->whereIn('s.empresa_id', $permitidas ?? []))
            ->select($columnas)
            ->selectRaw($this->auditoria->expresionEfectiva().' as cambio_efectivo');
    }

    /**
     * @param  list<string>  $incluir
     * @return array<string, mixed>
     */
    private function transformar(stdClass $f, array $incluir): array
    {
        $nombre = $this->texto($f->name) ?? '';
        $apellidos = $this->texto($f->apellidos);
        $estatus = EstadoUsuario::tryFrom((string) $this->texto($f->estatus));
        $tipo = TipoContratacion::tryFrom((string) $this->texto($f->tipo_contratacion));
        $eliminado = $f->deleted_at !== null;

        $fila = [
            'id' => (int) $f->id,
            'numero_empleado' => $this->texto($f->numero_empleado),
            'clave_noi' => $this->texto($f->clave_legacy),
            'nombres' => NombrePersona::sinApellidos($nombre, $apellidos),
            'apellidos' => $apellidos,
            'nombre_completo' => NombrePersona::completo($nombre, $apellidos),
            'estatus' => $estatus !== null ? ['valor' => $estatus->value, 'etiqueta' => $estatus->etiqueta()] : null,
            'activo' => ! $eliminado && $estatus !== null && in_array($estatus->value, EstadoUsuario::valoresVigentes(), true),
            'fecha_ingreso' => $this->fecha($f->fecha_ingreso),
            'fecha_baja' => $this->fecha($f->fecha_baja),
            'tipo_contratacion' => $tipo !== null ? ['valor' => $tipo->value, 'etiqueta' => $tipo->etiqueta()] : null,
            'empresa' => $f->empresa_id !== null ? ['id' => (int) $f->empresa_id, 'nombre' => $this->texto($f->empresa_nombre) ?? ''] : null,
            'sucursal' => $f->sucursal_id !== null ? ['id' => (int) $f->sucursal_id, 'clave' => $this->texto($f->sucursal_clave), 'nombre' => $this->texto($f->sucursal_nombre) ?? ''] : null,
            'puesto' => $f->puesto_id !== null ? ['id' => (int) $f->puesto_id, 'nombre' => $this->texto($f->puesto_nombre) ?? ''] : null,
            'departamento' => $f->departamento_id !== null ? ['id' => (int) $f->departamento_id, 'nombre' => $this->texto($f->departamento_nombre) ?? ''] : null,
            'actualizado_en' => CarbonImmutable::parse($this->texto($f->cambio_efectivo) ?? self::MINIMO, 'UTC')->toIso8601String(),
        ];

        if (in_array(self::INCLUIR_IDENTIFICACION, $incluir, true)) {
            $fila['identificacion'] = ['curp' => $this->texto($f->curp ?? null), 'rfc' => $this->texto($f->rfc ?? null), 'nss' => $this->texto($f->nss ?? null)];
        }

        if (in_array(self::INCLUIR_SALARIO, $incluir, true)) {
            $fila['salario'] = [
                'salario_diario' => $this->decimal($f->salario_diario ?? null),
                'salario_diario_integrado' => $this->decimal($f->salario_diario_integrado ?? null),
                'sueldo_mensual' => $this->decimal($f->sueldo_mensual ?? null),
            ];
        }

        return $fila;
    }

    private function decimal(mixed $valor): ?string
    {
        return is_numeric($valor) ? number_format((float) $valor, 2, '.', '') : null;
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
