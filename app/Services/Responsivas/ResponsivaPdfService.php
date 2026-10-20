<?php

namespace App\Services\Responsivas;

use App\Enums\AccesorioEquipoElectronico;
use App\Enums\AccesorioVehiculo;
use App\Enums\EstadoConservacion;
use App\Enums\EstadoFisicoResponsiva;
use App\Enums\MotorPdf;
use App\Enums\NivelCombustible;
use App\Enums\TipoBienMueble;
use App\Enums\TipoEquipoElectronico;
use App\Enums\TipoResponsiva;
use App\Models\Responsiva;
use App\Services\DocumentosMaestros\FirmaPatronService;
use App\Services\Pdf\OpcionesPdf;
use App\Services\Pdf\PdfRendererException;
use App\Services\Pdf\PdfRendererFactory;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * PDF de la responsiva, impreso por Chrome (Browsershot) con la misma
 * configuración que actas y reportes (PdfRendererFactory). El contenido y el
 * diseño son fieles a los formatos oficiales en docs/responsiva/*.docx: mismo
 * encabezado (PRODUCTOS Y SERVICIOS MR LANA, S.A.P.I. DE C.V.), fundamento
 * (artículos 132, 134 y 135 de la Ley Federal del Trabajo), declaración y
 * firmas (razón social y «Representante legal» / trabajador; ver
 * FirmaPatronService). Cada familia (bien mueble, equipo
 * electrónico, vehículo) imprime sus propias secciones.
 *
 * Todos los datos salen del snapshot congelado y de `datos` (ambos
 * inmutables desde que se genera), nunca del estado actual del colaborador.
 *
 * Paleta institucional: petróleo #315B59, verde #3F7558, dorado #C7A66B,
 * crema #FBF8F2. Tamaño carta.
 */
class ResponsivaPdfService
{
    public function __construct(
        private readonly PdfRendererFactory $pdf,
        private readonly FirmaPatronService $firmaPatron,
    ) {}

    /**
     * @return array{pdf: string, motor: MotorPdf}
     */
    public function renderizar(Responsiva $responsiva, bool $borrador = false): array
    {
        $datos = $this->datos($responsiva);
        $opciones = fn (MotorPdf $m): OpcionesPdf => new OpcionesPdf(
            tamano: 'letter',
            numerarPaginas: true,
            textoPie: $borrador ? 'Vista previa · sin validez' : sprintf('Responsiva %s', $datos['folio']),
            colorPie: '#5b6b73',
            margenInferiorMm: 25.4,
        );

        try {
            $render = $this->pdf->renderizarCon(
                MotorPdf::porDefecto(),
                fn (MotorPdf $m): string => $this->html($datos, $m, $borrador),
                $opciones,
            );
        } catch (PdfRendererException $e) {
            Log::warning('Responsiva: no se pudo imprimir el PDF.', ['folio' => $datos['folio'], 'error' => $e->getPrevious()?->getMessage() ?? $e->getMessage()]);

            throw ValidationException::withMessages(['responsiva' => $e->getMessage().' Intenta de nuevo en unos minutos; si continúa, avisa a sistemas.']);
        }

        return ['pdf' => $render['pdf'], 'motor' => $render['motor']];
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    public function html(array $datos, MotorPdf $motor = MotorPdf::Browsershot, bool $borrador = false): string
    {
        return view('pdf.responsivas.responsiva', ['d' => $datos, 'motor' => $motor->value, 'borrador' => $borrador])->render();
    }

    /**
     * Solo texto para imprimir: ningún ID ni dato técnico.
     *
     * @return array<string, mixed>
     */
    public function datos(Responsiva $responsiva): array
    {
        $s = $responsiva->snapshot;
        $texto = fn (mixed $v): ?string => is_string($v) && trim($v) !== '' ? trim($v) : null;
        $receptor = is_array($s['receptor'] ?? null) ? $s['receptor'] : [];
        $otorgante = is_array($s['otorgante'] ?? null) ? $s['otorgante'] : [];
        $empresa = is_array($s['empresa'] ?? null) ? $s['empresa'] : [];

        return [
            'folio' => $responsiva->folio ?? 'Sin folio',
            'tipo' => $responsiva->tipo->value,
            'titulo' => $responsiva->tipo->titulo(),
            'denominacion' => $responsiva->tipo->denominacion(),
            'lugar' => $responsiva->lugar,
            'fecha' => $responsiva->fecha_entrega->copy()->settings(['locale' => 'es'])->isoFormat('DD [de] MMMM [de] YYYY'),
            // Razón social legal del patrón (nunca el nombre corto del
            // catálogo): va ARRIBA de la línea de firma y en el encabezado.
            'empresa' => $this->firmaPatron->razonSocial($texto($empresa['nombre'] ?? null), $texto($empresa['razon_social'] ?? null)),
            'firma_patron_cargo' => mb_strtoupper($this->firmaPatron->cargo(), 'UTF-8'),
            'logo' => $this->logo($texto($empresa['logo_path'] ?? null)),
            'receptor' => [
                'nombre' => mb_strtoupper((string) ($receptor['nombre'] ?? ''), 'UTF-8'),
                'numero_empleado' => $texto($receptor['numero_empleado'] ?? null),
                'puesto' => $texto($receptor['puesto'] ?? null),
                'area' => $texto($receptor['departamento'] ?? null),
                'sucursal' => $texto($receptor['sucursal'] ?? null),
            ],
            'otorgante' => [
                'nombre' => mb_strtoupper((string) ($otorgante['nombre'] ?? ''), 'UTF-8'),
                'puesto' => $texto($otorgante['puesto'] ?? null) !== null ? mb_strtoupper((string) $otorgante['puesto'], 'UTF-8') : null,
            ],
            'secciones' => $this->secciones($responsiva),
            'declaracion' => $this->declaracion($responsiva->tipo),
            'observaciones' => $texto($responsiva->observaciones),
        ];
    }

    /**
     * Secciones específicas de la familia, en el orden del formato oficial.
     * Cada sección tiene un número, un título y una lista de bloques
     * («campos», «opciones» o «texto») que el Blade imprime sin más lógica.
     *
     * @return list<array<string, mixed>>
     */
    private function secciones(Responsiva $responsiva): array
    {
        $datos = is_array($responsiva->datos) ? $responsiva->datos : [];

        return match ($responsiva->tipo) {
            TipoResponsiva::BienMueble => $this->seccionesBienMueble($datos),
            TipoResponsiva::EquipoElectronico => $this->seccionesEquipo($datos),
            TipoResponsiva::Vehiculo => $this->seccionesVehiculo($datos),
        };
    }

    /**
     * @param  array<string, mixed>  $datos
     * @return list<array<string, mixed>>
     */
    private function seccionesBienMueble(array $datos): array
    {
        $v = $this->lector($datos);
        $otro = $v('tipo_mueble') === TipoBienMueble::Otro->value ? $v('tipo_mueble_otro') : null;

        return [
            [
                'numero' => 2, 'titulo' => 'IDENTIFICACIÓN DEL MOBILIARIO', 'bloques' => [
                    $this->bloqueOpciones(
                        array_map(fn (TipoBienMueble $c): array => ['value' => $c->value, 'etiqueta' => $c->etiqueta()], TipoBienMueble::cases()),
                        $v('tipo_mueble'), false, $otro
                    ),
                    $this->bloqueCampos([
                        ['Marca, si tiene', $v('marca')],
                        ['Modelo, si tiene', $v('modelo')],
                        ['Número de inventario', $v('numero_inventario')],
                        ['Número de serie, si tiene', $v('numero_serie')],
                    ]),
                ],
            ],
            [
                'numero' => 3, 'titulo' => 'DESCRIPCIÓN DEL MUEBLE', 'bloques' => [
                    $this->bloqueCampos([
                        ['Color', $v('color')],
                        ['Material', $v('material')],
                        ['Tamaño / características', $v('tamano')],
                    ]),
                ],
            ],
            [
                'numero' => 4, 'titulo' => 'ESTADO DEL MUEBLE', 'bloques' => [
                    $this->bloqueOpciones(
                        array_map(fn (EstadoFisicoResponsiva $c): array => ['value' => $c->value, 'etiqueta' => $c->etiqueta()], EstadoFisicoResponsiva::cases()),
                        $v('estado'), false, null
                    ),
                    $this->bloqueTexto('Daños o detalles', $v('danos')),
                ],
            ],
            [
                'numero' => 5, 'titulo' => 'UBICACIÓN DEL MUEBLE', 'bloques' => [
                    $this->bloqueCampos([
                        ['Lugar donde quedará', $v('ubicacion_lugar')],
                        ['Sucursal / Oficina / Área', $v('ubicacion_area')],
                    ]),
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $datos
     * @return list<array<string, mixed>>
     */
    private function seccionesEquipo(array $datos): array
    {
        $v = $this->lector($datos);
        $l = $this->lectorLista($datos);
        $otroTipo = $v('tipo_equipo') === TipoEquipoElectronico::Otro->value ? $v('tipo_equipo_otro') : null;
        $esCelular = in_array($v('tipo_equipo'), [TipoEquipoElectronico::Celular->value, TipoEquipoElectronico::Tablet->value], true);

        return [
            [
                'numero' => 2, 'titulo' => 'IDENTIFICACIÓN DEL EQUIPO', 'bloques' => [
                    $this->bloqueOpciones(
                        array_map(fn (TipoEquipoElectronico $c): array => ['value' => $c->value, 'etiqueta' => $c->etiqueta()], TipoEquipoElectronico::cases()),
                        $v('tipo_equipo'), false, $otroTipo
                    ),
                    $this->bloqueCampos([
                        ['Marca', $v('marca')],
                        ['Modelo', $v('modelo')],
                        ['Número de serie', $v('numero_serie')],
                        ['Número de inventario', $v('numero_inventario')],
                    ]),
                ],
            ],
            [
                'numero' => 3, 'titulo' => 'SI ES CELULAR O TABLET', 'bloques' => [
                    $this->bloqueCampos([
                        ['IMEI 1', $esCelular ? $v('imei1') : 'NO APLICA'],
                        ['IMEI 2', $esCelular ? $v('imei2') : 'NO APLICA'],
                        ['Número telefónico', $esCelular ? $v('numero_telefonico') : 'NO APLICA'],
                    ]),
                ],
            ],
            [
                'numero' => 4, 'titulo' => 'ESTADO DEL EQUIPO', 'bloques' => [
                    $this->bloqueOpciones(
                        array_map(fn (EstadoFisicoResponsiva $c): array => ['value' => $c->value, 'etiqueta' => $c->etiqueta()], EstadoFisicoResponsiva::cases()),
                        $v('estado'), false, null
                    ),
                    $this->bloqueTexto('Daños o detalles', $v('danos')),
                ],
            ],
            [
                'numero' => 5, 'titulo' => 'ACCESORIOS ENTREGADOS', 'bloques' => [
                    $this->bloqueOpciones(
                        array_map(fn (AccesorioEquipoElectronico $c): array => ['value' => $c->value, 'etiqueta' => $c->etiqueta()], AccesorioEquipoElectronico::cases()),
                        $l('accesorios'), true, $v('accesorios_otro')
                    ),
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $datos
     * @return list<array<string, mixed>>
     */
    private function seccionesVehiculo(array $datos): array
    {
        $v = $this->lector($datos);
        $l = $this->lectorLista($datos);

        return [
            [
                'numero' => 2, 'titulo' => 'DATOS DEL VEHÍCULO', 'bloques' => [
                    $this->bloqueCampos([
                        ['Marca', $v('marca')],
                        ['Submarca / Tipo', $v('submarca')],
                        ['Modelo / Año', $v('modelo_anio')],
                        ['Color', $v('color')],
                        ['NIV / VIN', $v('niv')],
                        ['Número de motor', $v('numero_motor')],
                        ['Placas', $v('placas')],
                    ]),
                ],
            ],
            [
                'numero' => 3, 'titulo' => 'ESTADO AL RECIBIR EL VEHÍCULO', 'bloques' => [
                    $this->bloqueCampos([
                        ['Kilometraje', $v('kilometraje') !== null ? sprintf('%s km', $v('kilometraje')) : null],
                    ]),
                    $this->bloqueOpciones(
                        array_map(fn (NivelCombustible $c): array => ['value' => $c->value, 'etiqueta' => $c->etiqueta()], NivelCombustible::cases()),
                        $v('combustible'), false, null, 'Combustible'
                    ),
                    $this->bloqueOpciones(
                        array_map(fn (EstadoConservacion $c): array => ['value' => $c->value, 'etiqueta' => $c->etiqueta()], EstadoConservacion::cases()),
                        $v('estado_exterior'), false, null, 'Estado exterior'
                    ),
                    $this->bloqueOpciones(
                        array_map(fn (EstadoConservacion $c): array => ['value' => $c->value, 'etiqueta' => $c->etiqueta()], EstadoConservacion::cases()),
                        $v('estado_interior'), false, null, 'Estado interior'
                    ),
                    $this->bloqueTexto('Daños o detalles existentes', $v('danos')),
                ],
            ],
            [
                'numero' => 4, 'titulo' => 'ACCESORIOS Y DOCUMENTOS RECIBIDOS', 'bloques' => [
                    $this->bloqueOpciones(
                        array_map(fn (AccesorioVehiculo $c): array => ['value' => $c->value, 'etiqueta' => $c->etiqueta()], AccesorioVehiculo::cases()),
                        $l('accesorios'), true, $v('accesorios_otro')
                    ),
                ],
            ],
        ];
    }

    /**
     * Lector de un campo de texto de `datos` (null si viene vacío).
     *
     * @param  array<string, mixed>  $datos
     * @return \Closure(string): ?string
     */
    private function lector(array $datos): \Closure
    {
        return function (string $clave) use ($datos): ?string {
            $valor = $datos[$clave] ?? null;

            if (is_int($valor)) {
                return (string) $valor;
            }

            return is_string($valor) && trim($valor) !== '' ? trim($valor) : null;
        };
    }

    /**
     * Lector de una lista de valores (checkbox múltiple) de `datos`.
     *
     * @param  array<string, mixed>  $datos
     * @return \Closure(string): list<string>
     */
    private function lectorLista(array $datos): \Closure
    {
        return function (string $clave) use ($datos): array {
            $valor = $datos[$clave] ?? null;

            return is_array($valor) ? array_values(array_filter($valor, 'is_string')) : [];
        };
    }

    /**
     * Bloque de opciones tipo casilla (una o varias seleccionadas). Recibe el
     * catálogo ya mapeado a {value, etiqueta} (con tipo concreto en cada
     * llamada) para no perder el tipo del enum. «Otro» muestra el texto libre.
     *
     * @param  list<array{value: string, etiqueta: string}>  $casos
     * @param  string|list<string>|null  $seleccion
     * @return array<string, mixed>
     */
    private function bloqueOpciones(array $casos, string|array|null $seleccion, bool $multiple, ?string $otro, ?string $etiqueta = null): array
    {
        $seleccionadas = is_array($seleccion) ? $seleccion : ($seleccion !== null ? [$seleccion] : []);

        $opciones = array_map(function (array $caso) use ($seleccionadas, $otro): array {
            $marcada = in_array($caso['value'], $seleccionadas, true);
            $etq = $caso['etiqueta'];

            if ($marcada && $caso['value'] === 'otro' && $otro !== null) {
                $etq = sprintf('%s: %s', $etq, $otro);
            }

            return ['etiqueta' => $etq, 'marcada' => $marcada];
        }, $casos);

        return ['tipo' => 'opciones', 'etiqueta' => $etiqueta, 'multiple' => $multiple, 'opciones' => $opciones];
    }

    /**
     * @param  list<array{0: string, 1: string|null}>  $campos
     * @return array<string, mixed>
     */
    private function bloqueCampos(array $campos): array
    {
        $filas = [];

        foreach ($campos as [$etiqueta, $valor]) {
            $filas[] = ['etiqueta' => $etiqueta, 'valor' => $valor !== null && trim($valor) !== '' ? trim($valor) : '—'];
        }

        return ['tipo' => 'campos', 'campos' => $filas];
    }

    /**
     * @return array<string, mixed>
     */
    private function bloqueTexto(string $etiqueta, ?string $valor): array
    {
        return ['tipo' => 'texto', 'etiqueta' => $etiqueta, 'valor' => $valor !== null && trim($valor) !== '' ? trim($valor) : 'Sin observaciones.'];
    }

    /** Declaración final, literal del formato oficial de cada familia. */
    private function declaracion(TipoResponsiva $tipo): string
    {
        return match ($tipo) {
            TipoResponsiva::BienMueble => 'Declaro que recibí físicamente el mobiliario identificado en esta responsiva y que los datos y descripción corresponden al artículo que me fue entregado. Asimismo, manifiesto que revisé su estado físico. Me comprometo a conservarlo y utilizarlo adecuadamente, así como a devolverlo o ponerlo a disposición de la empresa cuando sea requerido.',
            TipoResponsiva::EquipoElectronico => 'Declaro que recibí físicamente el equipo identificado en esta responsiva y que los datos asentados corresponden al equipo que me fue entregado. Manifiesto haber revisado el estado del equipo y recibido los accesorios señalados. Me comprometo a conservarlo, utilizarlo adecuadamente y devolverlo cuando sea requerido por PRODUCTOS Y SERVICIOS MR LANA, S.A.P.I. DE C.V.',
            TipoResponsiva::Vehiculo => 'Declaro que recibí físicamente el vehículo identificado en esta responsiva y que los datos asentados corresponden al vehículo que me fue entregado. Asimismo, manifiesto que revisé su estado físico, kilometraje, nivel de combustible, accesorios y documentos señalados. Me comprometo a utilizarlo adecuadamente, conservarlo y devolverlo cuando sea requerido por PRODUCTOS Y SERVICIOS MR LANA, S.A.P.I. DE C.V.',
        };
    }

    /** Logo como data URI (igual en Chrome y DomPDF, sin depender de la red). */
    private function logo(?string $logoEmpresa): ?string
    {
        if ($logoEmpresa !== null && Storage::disk('public')->exists($logoEmpresa)) {
            $mime = Storage::disk('public')->mimeType($logoEmpresa) ?: 'image/png';

            return sprintf('data:%s;base64,%s', $mime, base64_encode((string) Storage::disk('public')->get($logoEmpresa)));
        }

        $ruta = resource_path('documentos/logo-mr-lana.png');

        return is_file($ruta) ? 'data:image/png;base64,'.base64_encode((string) file_get_contents($ruta)) : null;
    }
}
