<?php

namespace App\Services\Reportes;

use App\Models\Colaborador;
use App\Models\ConfiguracionSistema;
use App\Models\User;
use App\Services\AlcanceOrganizacionalService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

/**
 * Altas para NOI: reporte intermedio con datos reales de People y exportador
 * configurable. El layout oficial de Aspel NOI no se ha entregado, así que el
 * archivo nunca se presenta como una importación validada. Nada se completa con
 * datos inventados: lo que falta se reporta por colaborador antes de exportar.
 *
 * @phpstan-type ColumnaLayout array{campo: string, encabezado: string}
 * @phpstan-type Layout array{formato: string, columnas: list<ColumnaLayout>}
 */
class AltasNoiService
{
    private const CLAVE_LAYOUT = 'reportes.noi_layout';

    public const PENDIENTE = 'Pendiente de homologar con el layout oficial de Aspel NOI.';

    public function __construct(private readonly AlcanceOrganizacionalService $alcance) {}

    /**
     * @return Layout
     */
    public function layout(): array
    {
        $guardado = ConfiguracionSistema::query()->where('clave', self::CLAVE_LAYOUT)->value('valor');
        $datos = is_string($guardado) ? json_decode($guardado, true) : null;

        if (is_array($datos) && is_array($datos['columnas'] ?? null)) {
            $validas = [];

            foreach ($datos['columnas'] as $columna) {
                $campo = is_array($columna) ? ($columna['campo'] ?? null) : null;

                // Un campo que salió del catálogo no vuelve a exportarse.
                if (is_string($campo) && isset($this->campos()[$campo])) {
                    $validas[] = ['campo' => $campo, 'encabezado' => (string) ($columna['encabezado'] ?? $this->campos()[$campo]['etiqueta'])];
                }
            }

            return ['formato' => ($datos['formato'] ?? 'xlsx') === 'csv' ? 'csv' : 'xlsx', 'columnas' => $validas];
        }

        $inicial = (array) config('reportes.noi.layout_inicial', []);
        $columnas = [];

        foreach ((array) ($inicial['columnas'] ?? []) as $campo) {
            $columnas[] = ['campo' => (string) $campo, 'encabezado' => $this->campos()[(string) $campo]['etiqueta'] ?? (string) $campo];
        }

        return ['formato' => 'xlsx', 'columnas' => $columnas];
    }

    /**
     * @param  array<int|string, mixed>  $columnas
     * @return Layout
     *
     * @throws ValidationException
     */
    public function guardarLayout(User $usuario, array $columnas, string $formato): array
    {
        $validas = [];

        foreach ($columnas as $columna) {
            $campo = is_array($columna) ? ($columna['campo'] ?? null) : null;

            if (! is_string($campo) || ! isset($this->campos()[$campo])) {
                throw ValidationException::withMessages(['columnas' => 'El layout incluye un dato que no existe en People.']);
            }

            $encabezado = trim(is_scalar($columna['encabezado'] ?? null) ? (string) $columna['encabezado'] : '');
            $validas[$campo] = ['campo' => $campo, 'encabezado' => mb_substr($encabezado === '' ? $this->campos()[$campo]['etiqueta'] : $encabezado, 0, 80)];
        }

        if ($validas === []) {
            throw ValidationException::withMessages(['columnas' => 'El layout necesita al menos una columna.']);
        }

        $layout = ['formato' => $formato === 'csv' ? 'csv' : 'xlsx', 'columnas' => array_values($validas)];

        ConfiguracionSistema::query()->updateOrCreate(['clave' => self::CLAVE_LAYOUT], [
            'valor' => (string) json_encode($layout, JSON_UNESCAPED_UNICODE),
            'tipo' => 'json',
            'grupo' => 'reportes',
            'descripcion' => 'Layout del exportador de Altas para NOI.',
            'actualizado_por' => $usuario->id,
        ]);

        activity('reportes')->causedBy($usuario)->withProperties(['columnas' => array_keys($validas), 'formato' => $layout['formato']])->log('Actualizó el layout de Altas para NOI');

        return $layout;
    }

    /**
     * Catálogo de datos para configurar el layout.
     *
     * @return array<string, array{etiqueta: string, sensible?: bool, salario?: bool, sin_fuente?: bool, opcional?: bool}>
     */
    public function campos(): array
    {
        /** @var array<string, array{etiqueta: string, sensible?: bool, salario?: bool, sin_fuente?: bool, opcional?: bool}> $campos */
        $campos = (array) config('reportes.noi.campos', []);

        return $campos;
    }

    /**
     * Reporte en pantalla: los datos sensibles van enmascarados sin permiso.
     *
     * @param  array<string, mixed>  $filtros
     * @return array{titulo: string, columnas: array<int, string>, filas: array<int, array<int, string|int|float|null>>, avisos: list<string>, faltantes: int}
     */
    public function reporte(User $usuario, array $filtros): array
    {
        [$desde, $hasta] = $this->periodo($filtros);
        $layout = $this->layout()['columnas'];
        $conFaltantes = 0;
        $filas = [];

        foreach ($this->colaboradores($usuario, $filtros, $desde, $hasta) as $colaborador) {
            $datos = $this->datos($colaborador);
            $faltantes = $this->faltantes($datos);
            $conFaltantes += $faltantes === [] ? 0 : 1;
            $fila = array_map(fn (array $c) => $this->visible($c['campo'], $datos[$c['campo']] ?? null, $usuario), $layout);
            $fila[] = $faltantes === [] ? 'Completo' : implode(', ', $faltantes);
            $filas[] = $fila;
        }

        return [
            'titulo' => sprintf('Altas para NOI del %s al %s', $desde->format('d/m/Y'), $hasta->format('d/m/Y')),
            'columnas' => [...array_map(fn (array $c): string => $c['encabezado'], $layout), 'Datos faltantes'],
            'filas' => $filas,
            'avisos' => $this->avisos($usuario),
            'faltantes' => $conFaltantes,
        ];
    }

    /**
     * Archivo del exportador. Exige los permisos completos: sin ellos no se
     * entrega un layout con datos enmascarados que parezca bueno.
     *
     * @param  array<string, mixed>  $filtros
     */
    public function exportar(User $usuario, array $filtros): Spreadsheet
    {
        [$desde, $hasta] = $this->periodo($filtros);
        $layout = $this->layout()['columnas'];
        $libro = new Spreadsheet;
        $hoja = $libro->getActiveSheet();
        $hoja->setTitle('Altas');
        $faltantes = [];
        $fila = 2;

        foreach ($layout as $i => $columna) {
            $hoja->setCellValueExplicit(Coordinate::stringFromColumnIndex($i + 1).'1', $columna['encabezado'], DataType::TYPE_STRING);
            $hoja->getColumnDimensionByColumn($i + 1)->setAutoSize(true);
        }

        $hoja->getStyle('1:1')->getFont()->setBold(true);

        foreach ($this->colaboradores($usuario, $filtros, $desde, $hasta) as $colaborador) {
            $datos = $this->datos($colaborador);

            foreach ($layout as $i => $columna) {
                $valor = $this->visible($columna['campo'], $datos[$columna['campo']] ?? null, $usuario);

                if ($valor !== null) {
                    $hoja->setCellValueExplicit(Coordinate::stringFromColumnIndex($i + 1).$fila, (string) $valor, DataType::TYPE_STRING);
                }
            }

            $faltan = $this->faltantes($datos);

            if ($faltan !== []) {
                $faltantes[] = [$colaborador->nombreCompleto(), implode(', ', $faltan)];
            }

            $fila++;
        }

        $control = $libro->createSheet();
        $control->setTitle('Control');
        $control->setCellValueExplicit('A1', self::PENDIENTE, DataType::TYPE_STRING);
        $control->getStyle('A1')->getFont()->setBold(true);
        $control->setCellValueExplicit('A2', sprintf('Periodo: %s a %s', $desde->format('d/m/Y'), $hasta->format('d/m/Y')), DataType::TYPE_STRING);
        $control->setCellValueExplicit('A4', 'Colaborador', DataType::TYPE_STRING);
        $control->setCellValueExplicit('B4', 'Datos faltantes', DataType::TYPE_STRING);
        $control->getStyle('A4:B4')->getFont()->setBold(true);

        foreach ($faltantes as $n => [$nombre, $datosFaltantes]) {
            $control->setCellValueExplicit('A'.($n + 5), $nombre, DataType::TYPE_STRING);
            $control->setCellValueExplicit('B'.($n + 5), $datosFaltantes, DataType::TYPE_STRING);
        }

        $control->getColumnDimension('A')->setAutoSize(true);
        $control->getColumnDimension('B')->setAutoSize(true);
        $libro->setActiveSheetIndex(0);

        activity('reportes')->causedBy($usuario)
            ->withProperties(['desde' => $desde->toDateString(), 'hasta' => $hasta->toDateString(), 'registros' => $fila - 2])
            ->log('Exportó el layout de Altas para NOI');

        return $libro;
    }

    /**
     * @return list<string>
     */
    private function avisos(User $usuario): array
    {
        $sinFuente = [];

        foreach ($this->layout()['columnas'] as $columna) {
            if ($this->campos()[$columna['campo']]['sin_fuente'] ?? false) {
                $sinFuente[] = $this->campos()[$columna['campo']]['etiqueta'];
            }
        }

        $avisos = [self::PENDIENTE];

        if ($sinFuente !== []) {
            $avisos[] = sprintf('People todavía no captura: %s. Esas columnas salen vacías.', implode(', ', $sinFuente));
        }

        if (! $usuario->can('reportes_rh.datos_sensibles')) {
            $avisos[] = 'RFC, CURP, NSS y domicilio aparecen enmascarados porque no tienes el permiso de datos sensibles.';
        }

        if (! $usuario->can('salarios.ver')) {
            $avisos[] = 'Los salarios aparecen como «Restringido» porque no tienes el permiso de salarios.';
        }

        return $avisos;
    }

    /**
     * @param  array<string, mixed>  $filtros
     * @return Collection<int, Colaborador>
     */
    private function colaboradores(User $usuario, array $filtros, CarbonImmutable $desde, CarbonImmutable $hasta): Collection
    {
        return $this->alcance->limitarColaboradoresPorAlcance(Colaborador::query(), $usuario)
            ->whereBetween('fecha_ingreso', [$desde->toDateString(), $hasta->toDateString()])
            ->when($filtros['sucursal_id'] ?? null, fn ($q, $v) => $q->where('sucursal_principal_id', $v))
            ->when($filtros['puesto_id'] ?? null, fn ($q, $v) => $q->where('puesto_id', $v))
            ->with(['sucursalPrincipal.empresa', 'departamento', 'puesto'])
            ->orderBy('fecha_ingreso')
            ->orderBy('name')
            ->get();
    }

    /**
     * @return array<string, string|float|null>
     */
    private function datos(Colaborador $c): array
    {
        $calle = trim(implode(' ', array_filter([$c->domicilio_calle, $c->domicilio_numero_exterior, $c->domicilio_numero_interior])));
        $partes = array_filter([$calle, $c->domicilio_colonia, $c->domicilio_municipio, $c->domicilio_estado]);

        return [
            'nombre' => $c->name,
            'apellidos' => $c->apellidos,
            'rfc' => $c->rfc,
            'curp' => $c->curp,
            'nss' => $c->nss,
            'fecha_nacimiento' => $c->fecha_nacimiento?->format('d/m/Y'),
            'sexo' => $c->genero?->etiqueta(),
            'domicilio' => $partes !== [] ? implode(', ', $partes) : $c->domicilio,
            'codigo_postal' => $c->domicilio_cp,
            'fecha_ingreso' => $c->fecha_ingreso?->format('d/m/Y'),
            'fecha_alta_imss' => $c->fecha_alta_imss?->format('d/m/Y'),
            'puesto' => $c->puesto?->nombre,
            'departamento' => $c->departamento?->nombre,
            'sucursal' => $c->sucursalPrincipal?->nombre,
            'registro_patronal' => $c->sucursalPrincipal?->empresa?->registro_patronal,
            'salario_diario' => $c->salario_diario === null ? null : (float) $c->salario_diario,
            'salario_diario_integrado' => $c->salario_diario_integrado === null ? null : (float) $c->salario_diario_integrado,
            'tipo_contrato' => $c->tipo_contratacion?->etiqueta(),
            'correo' => $c->correo_corporativo ?: $c->correo_personal,
        ];
    }

    /**
     * Los datos que People aún no captura se avisan una sola vez, no por persona.
     *
     * @param  array<string, string|float|null>  $datos
     * @return list<string>
     */
    private function faltantes(array $datos): array
    {
        $faltan = [];

        foreach ($this->campos() as $campo => $definicion) {
            if (($definicion['sin_fuente'] ?? false) || ($definicion['opcional'] ?? false)) {
                continue;
            }

            if (($datos[$campo] ?? null) === null || ($datos[$campo] ?? null) === '') {
                $faltan[] = $definicion['etiqueta'];
            }
        }

        return $faltan;
    }

    private function visible(string $campo, string|float|null $valor, User $usuario): string|float|null
    {
        if ($valor === null || $valor === '') {
            return null;
        }

        $definicion = $this->campos()[$campo] ?? [];

        if (($definicion['salario'] ?? false) && ! $usuario->can('salarios.ver')) {
            return 'Restringido';
        }

        if (($definicion['sensible'] ?? false) && ! $usuario->can('reportes_rh.datos_sensibles')) {
            return in_array($campo, ['rfc', 'curp', 'nss'], true) ? ReportesNominalesService::enmascarar((string) $valor) : 'Restringido';
        }

        return $valor;
    }

    /**
     * @param  array<string, mixed>  $filtros
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function periodo(array $filtros): array
    {
        $hoy = CarbonImmutable::now('America/Mexico_City');

        return [
            CarbonImmutable::parse(is_string($filtros['fecha_inicio'] ?? null) && $filtros['fecha_inicio'] !== '' ? $filtros['fecha_inicio'] : $hoy->startOfMonth()->toDateString()),
            CarbonImmutable::parse(is_string($filtros['fecha_fin'] ?? null) && $filtros['fecha_fin'] !== '' ? $filtros['fecha_fin'] : $hoy->endOfMonth()->toDateString()),
        ];
    }
}
