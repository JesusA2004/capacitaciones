<?php

namespace App\Services\Integraciones\Auditoria;

use App\Http\Resources\Api\V1\Integraciones\ColaboradorActaAuditoriaResource;
use App\Http\Resources\Api\V1\Integraciones\SucursalActaAuditoriaResource;
use App\Models\ClienteIntegracion;
use App\Services\Integraciones\TokenIntegracionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Diagnóstico de SÓLO LECTURA del contexto del acta de Auditoría
 * (contrato «people-auditoria/2»): por cada dato que el acta imprime, de qué
 * tabla/columna sale, si el valor crudo existe en la base (sí/no y longitud,
 * nunca el valor salvo petición explícita) y si la respuesta v2 que recibe
 * Auditoría lo incluye. Además señala otras fuentes que NO son autoridad del
 * acta (texto legado en la cuenta, alta digital, sugerencias OCR sin aplicar)
 * para que RH sepa dónde está el dato y lo capture en el expediente.
 *
 * Nunca escribe, nunca devuelve CURP/RFC/NSS ni la clave de elector.
 */
class DiagnosticoActaAuditoriaService
{
    /** dato => [columna en sucursales, ruta en la respuesta v2]. */
    public const SUCURSAL = [
        'direccion' => ['direccion', 'domicilio.direccion'],
        'colonia' => ['colonia', 'domicilio.colonia'],
        'municipio' => ['municipio', 'domicilio.municipio'],
        'ciudad' => ['ciudad', 'domicilio.ciudad'],
        'estado' => ['estado', 'domicilio.estado'],
        'codigo_postal' => ['codigo_postal', 'domicilio.codigo_postal'],
        'responsable_id' => ['responsable_id', 'responsable.id'],
    ];

    /** dato => [tabla.columna, ruta en la respuesta v2]. */
    public const PERSONA = [
        'fecha_nacimiento' => ['colaboradores.fecha_nacimiento', 'fecha_nacimiento'],
        'domicilio_texto' => ['colaboradores.domicilio', 'domicilio.texto'],
        'calle' => ['colaboradores.domicilio_calle', 'domicilio.calle'],
        'numero_exterior' => ['colaboradores.domicilio_numero_exterior', 'domicilio.numero_exterior'],
        'numero_interior' => ['colaboradores.domicilio_numero_interior', 'domicilio.numero_interior'],
        'colonia' => ['colaboradores.domicilio_colonia', 'domicilio.colonia'],
        'municipio' => ['colaboradores.domicilio_municipio', 'domicilio.municipio'],
        'estado' => ['colaboradores.domicilio_estado', 'domicilio.estado'],
        'codigo_postal' => ['colaboradores.domicilio_cp', 'domicilio.codigo_postal'],
        'puesto' => ['puestos.nombre (colaboradores.puesto_id)', 'puesto'],
        'gerente_id' => ['colaboradores.gerente_id', 'gerente.id'],
        'clave_elector' => ['colaboradores.clave_elector', 'identificacion.clave_elector_registrada'],
    ];

    /** Datos cuyo valor nunca se muestra, ni con --mostrar. */
    private const SECRETOS = ['clave_elector'];

    /** Llaves de domicilio que el OCR de documentos puede proponer (DocumentExtractionService). */
    private const OCR_DOMICILIO = ['domicilio', 'colonia', 'municipio', 'estado_domicilio', 'codigo_postal'];

    public function __construct(private readonly ContextoActaAuditoriaService $contexto) {}

    public function cliente(): ?ClienteIntegracion
    {
        return ClienteIntegracion::query()->where('clave', TokenIntegracionService::CLAVE_AUDITORIA)->first();
    }

    /**
     * Ids de sucursal por id o clave exacta.
     *
     * @return list<int>
     */
    public function buscarSucursales(string $valor): array
    {
        $ids = DB::table('sucursales')
            ->where(fn ($q) => ctype_digit($valor) ? $q->where('id', (int) $valor)->orWhere('clave', $valor) : $q->where('clave', $valor))
            ->orderBy('id')->pluck('id')->all();

        return array_values(array_map(intval(...), $ids));
    }

    /**
     * Ids de colaborador por id o número de empleado exacto.
     *
     * @return list<int>
     */
    public function buscarColaboradores(string $valor): array
    {
        $ids = DB::table('colaboradores')
            ->where(fn ($q) => ctype_digit($valor) ? $q->where('id', (int) $valor)->orWhere('numero_empleado', $valor) : $q->where('numero_empleado', $valor))
            ->orderBy('id')->pluck('id')->all();

        return array_values(array_map(intval(...), $ids));
    }

    /**
     * @return array{id: int, v2: string, filas: list<array<string, mixed>>, otras: list<string>}
     */
    public function sucursal(int $id, bool $mostrar = false): array
    {
        $fila = (array) DB::table('sucursales')->where('id', $id)->first();
        $v2 = $this->v2Sucursal($id);
        $filas = [];
        foreach (self::SUCURSAL as $dato => [$columna, $ruta]) {
            $filas[] = $this->fila($dato, 'sucursales.'.$columna, $fila[$columna] ?? null, $v2['datos'], $ruta, $mostrar);
        }
        $otras = [];
        if (($fila['responsable_id'] ?? null) !== null) {
            $colaborador = DB::table('users')->where('id', $fila['responsable_id'])->value('colaborador_id');
            $otras[] = $colaborador === null
                ? 'El responsable de la sucursal es una cuenta sin colaborador: Auditoría no puede tomarlo como gerente.'
                : "Responsable de la sucursal = colaborador {$colaborador} (diagnostícalo con --colaborador={$colaborador}).";
        }

        return ['id' => $id, 'v2' => $v2['estado'], 'filas' => $filas, 'otras' => $otras];
    }

    /**
     * @return array{id: int, v2: string, filas: list<array<string, mixed>>, otras: list<string>}
     */
    public function colaborador(int $id, bool $mostrar = false): array
    {
        $fila = (array) DB::table('colaboradores')->where('id', $id)->first();
        $fila['puesto'] = ($fila['puesto_id'] ?? null) === null ? null : DB::table('puestos')->where('id', $fila['puesto_id'])->value('nombre');
        $v2 = $this->v2Colaborador($id);
        $columnas = [
            'fecha_nacimiento' => 'fecha_nacimiento', 'domicilio_texto' => 'domicilio', 'calle' => 'domicilio_calle',
            'numero_exterior' => 'domicilio_numero_exterior', 'numero_interior' => 'domicilio_numero_interior',
            'colonia' => 'domicilio_colonia', 'municipio' => 'domicilio_municipio', 'estado' => 'domicilio_estado',
            'codigo_postal' => 'domicilio_cp', 'puesto' => 'puesto', 'gerente_id' => 'gerente_id', 'clave_elector' => 'clave_elector',
        ];
        $filas = [];
        foreach (self::PERSONA as $dato => [$origen, $ruta]) {
            $f = $this->fila($dato, $origen, $fila[$columnas[$dato]] ?? null, $v2['datos'], $ruta, $mostrar && ! in_array($dato, self::SECRETOS, true));
            if ($dato === 'domicilio_texto' && $f['en_bd'] && ! $f['en_v2'] && ($v2['datos']['domicilio']['estructurado'] ?? false) === true) {
                $f['nota'] = 'compuesto de los campos: viaja desglosado';
            }
            $filas[] = $f;
        }

        return ['id' => $id, 'v2' => $v2['estado'], 'filas' => $filas, 'otras' => $this->otrasFuentes($id)];
    }

    /**
     * Fuentes que no son la autoridad del acta: sólo se dice si existen.
     *
     * @return list<string>
     */
    private function otrasFuentes(int $id): array
    {
        $otras = [];
        $cuenta = DB::table('users')->where('colaborador_id', $id)->first(['id', 'domicilio', 'fecha_nacimiento']);
        if ($cuenta !== null && $this->presente($cuenta->domicilio)) {
            $otras[] = 'users.domicilio (texto legado de la cuenta) tiene valor y colaboradores no lo usa: captúralo en el expediente.';
        }
        if (Schema::hasTable('altas_digitales')) {
            $alta = DB::table('altas_digitales')->where('colaborador_id', $id)
                ->when($cuenta !== null, fn ($q) => $q->orWhere('user_id', $cuenta->id))
                ->whereNull('deleted_at')->latest('id')->first(['domicilio', 'domicilio_calle']);
            if ($alta !== null && ($this->presente($alta->domicilio) || $this->presente($alta->domicilio_calle))) {
                $otras[] = 'altas_digitales tiene un domicilio capturado por la persona.';
            }
        }
        if (Schema::hasTable('document_extractions')) {
            $sugerencias = DB::table('document_extractions')->where('colaborador_id', $id)->where('status', '!=', 'reviewed')->pluck('extracted_data');
            foreach ($sugerencias as $json) {
                $datos = is_string($json) ? json_decode($json, true) : null;
                if (is_array($datos) && array_filter(array_intersect_key($datos, array_flip(self::OCR_DOMICILIO)), fn ($v) => $this->presente($v)) !== []) {
                    $otras[] = 'Hay una sugerencia OCR con domicilio sin revisar por RH (document_extractions): no es dato aprobado.';
                    break;
                }
            }
        }

        return $otras;
    }

    /**
     * @param  array<string, mixed>|null  $v2
     * @return array<string, mixed>
     */
    private function fila(string $dato, string $origen, mixed $valor, ?array $v2, string $ruta, bool $mostrar): array
    {
        $texto = $valor === null ? '' : trim((string) $valor);
        $enV2 = $v2 === null ? null : $this->presente(data_get($v2, $ruta)) && data_get($v2, $ruta) !== false;

        return [
            'dato' => $dato,
            'origen' => $origen,
            'en_bd' => $texto !== '',
            'longitud' => mb_strlen($texto),
            'valor' => $mostrar && $texto !== '' ? $texto : null,
            'v2' => $ruta,
            'en_v2' => $enV2,
            'nota' => null,
        ];
    }

    private function presente(mixed $valor): bool
    {
        return $valor !== null && (! is_string($valor) || trim($valor) !== '');
    }

    /**
     * @return array{estado: string, datos: array<string, mixed>|null}
     */
    private function v2Sucursal(int $id): array
    {
        $cliente = $this->cliente();
        if ($cliente === null) {
            return ['estado' => 'sin cliente de Auditoría', 'datos' => null];
        }
        $fila = $this->contexto->sucursal($cliente, $id);

        return $fila === null
            ? ['estado' => 'no visible para el cliente (404)', 'datos' => null]
            : ['estado' => 'visible', 'datos' => (new SucursalActaAuditoriaResource($fila))->resolve(Request::create('/'))];
    }

    /**
     * @return array{estado: string, datos: array<string, mixed>|null}
     */
    private function v2Colaborador(int $id): array
    {
        $cliente = $this->cliente();
        if ($cliente === null) {
            return ['estado' => 'sin cliente de Auditoría', 'datos' => null];
        }
        $fila = $this->contexto->colaborador($cliente, $id);

        return $fila === null
            ? ['estado' => 'no visible para el cliente (404)', 'datos' => null]
            : ['estado' => 'visible', 'datos' => (new ColaboradorActaAuditoriaResource($fila))->resolve(Request::create('/'))];
    }
}
