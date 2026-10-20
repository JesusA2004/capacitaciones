<?php

namespace App\Services\ImportadorContactosEmergencia;

use App\Models\Colaborador;
use App\Models\ColaboradorDatosMedicos;
use App\Models\ImportacionExcel;
use App\Models\Sucursal;
use App\Models\User;
use App\Services\Auditoria\AuditoriaService;
use App\Services\Expedientes\MigracionInicial\Normalizador;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Throwable;

/**
 * Importador del Excel de CONTACTOS DE EMERGENCIA del colaborador: rellena
 * en bloque datos que la importación de vacaciones no trajo (teléfono
 * personal, contacto de emergencia completo, condición médica y alergias)
 * sobre colaboradores YA existentes — nunca crea personas nuevas.
 *
 * El negocio considera al contacto de emergencia como beneficiario del
 * contrato (art. 501 LFT): el motor documental lo usa de respaldo cuando no
 * hay un beneficiario capturado aparte (DatosDocumentoService::resolver()),
 * así que subir este archivo también destraba la cláusula de beneficiarios.
 *
 * Mismo flujo obligatorio que el resto de importadores (CLAUDE.md): subir →
 * analizar (preview, NUNCA escribe en colaboradores) → RH revisa → aplicar.
 * El match es por nombre EXACTO normalizado (la sucursal solo desambigua
 * homónimos); un nombre que no cuadra se reporta, nunca se adivina. El mismo
 * archivo (por hash) no se aplica dos veces.
 *
 * Los encabezados se reconocen por PALABRAS CLAVE (no por posición ni texto
 * exacto), porque el formato del negocio trae preguntas largas
 * («¿Tienes alguna condición médica crónica?…») en vez de títulos cortos.
 */
class ImportadorContactosEmergenciaService
{
    public const TIPO = 'contactos_emergencia';

    public function __construct(private readonly AuditoriaService $auditoria) {}

    /**
     * Lee y resuelve el archivo (match + cambios por fila) SIN escribir nada
     * en colaboradores: solo crea/actualiza el registro de previsualización.
     */
    public function analizar(UploadedFile $archivo, User $actor): ImportacionExcel
    {
        $hash = hash_file('sha256', $archivo->getRealPath());

        $yaAplicado = ImportacionExcel::query()->where('archivo_hash', $hash)->where('estado', 'aplicado')->first();

        if ($yaAplicado !== null) {
            throw ValidationException::withMessages([
                'archivo' => "Este mismo archivo ya se aplicó el {$yaAplicado->aplicado_en?->toDateString()} (importación #{$yaAplicado->id}). No se vuelve a aplicar.",
            ]);
        }

        $filas = $this->leer($archivo);
        $sucursales = Sucursal::query()->where('activo', true)->get(['id', 'nombre']);
        $porNombre = $this->agruparColaboradores();

        $detalle = [];
        $contadores = ['aplicable' => 0, 'revision' => 0, 'sin_cambios' => 0, 'no_encontrado' => 0, 'error' => 0];

        foreach ($filas as $numeroFila => $fila) {
            $resultado = $this->resolverFila($numeroFila, $fila, $sucursales, $porNombre);
            $detalle[] = $resultado;
            $contadores[$resultado['estado']]++;
        }

        return ImportacionExcel::query()->updateOrCreate(['archivo_hash' => $hash], [
            'nombre_archivo' => $archivo->getClientOriginalName() ?: 'CONTACTOS_EMERGENCIA.xlsx',
            'tipo' => self::TIPO,
            'subido_por' => $actor->id,
            'estado' => 'previsualizado',
            'total_filas' => count($detalle),
            'filas_aplicadas' => 0,
            'filas_revision' => $contadores['revision'],
            'filas_sin_cambios' => $contadores['sin_cambios'],
            'filas_no_encontradas' => $contadores['no_encontrado'],
            'filas_error' => $contadores['error'],
            'detalle' => $detalle,
        ]);
    }

    /**
     * Aplica las filas con match confiable y cambios reales (estado
     * 'aplicable'); `$filasSeleccionadas` (números de fila del Excel) deja
     * que RH excluya filas puntuales. Nunca reaplica una importación aplicada.
     *
     * @param  list<int>|null  $filasSeleccionadas
     * @return array{filas_aplicadas: int, errores: list<string>}
     */
    public function aplicar(ImportacionExcel $importacion, User $actor, ?array $filasSeleccionadas = null): array
    {
        if ($importacion->estado === 'aplicado') {
            throw ValidationException::withMessages(['importacion' => 'Esta importación ya fue aplicada.']);
        }

        return DB::transaction(function () use ($importacion, $actor, $filasSeleccionadas): array {
            $importacion = ImportacionExcel::query()->lockForUpdate()->findOrFail($importacion->id);

            if ($importacion->estado === 'aplicado') {
                throw ValidationException::withMessages(['importacion' => 'Esta importación ya fue aplicada.']);
            }

            /** @var list<array<string, mixed>> $detalle */
            $detalle = $importacion->detalle ?? [];
            $aplicadas = 0;
            $errores = [];

            foreach ($detalle as &$fila) {
                if ($fila['estado'] !== 'aplicable') {
                    continue;
                }

                if ($filasSeleccionadas !== null && ! in_array((int) $fila['fila'], $filasSeleccionadas, true)) {
                    continue;
                }

                $colaborador = Colaborador::query()->where('id', $fila['colaborador_id'])->first();

                if ($colaborador === null) {
                    $errores[] = "Fila {$fila['fila']}: el colaborador ya no existe.";

                    continue;
                }

                try {
                    /** @var array<string, array{antes: string|null, despues: string}> $cambios */
                    $cambios = $fila['cambios'] ?? [];
                    $deColaborador = [];
                    $medicos = [];

                    foreach ($cambios as $columna => $valor) {
                        if (in_array($columna, ['condicion_medica', 'alergias'], true)) {
                            $medicos[$columna] = $valor['despues'];
                        } else {
                            $deColaborador[$columna] = $valor['despues'];
                        }
                    }

                    DB::transaction(function () use ($colaborador, $deColaborador, $medicos, $actor): void {
                        if ($deColaborador !== []) {
                            $colaborador->update($deColaborador);
                        }

                        if ($medicos !== []) {
                            ColaboradorDatosMedicos::query()->updateOrCreate(
                                ['colaborador_id' => $colaborador->id],
                                [...$medicos, 'fuente' => 'excel_contactos_emergencia', 'actualizado_por' => $actor->id],
                            );
                        }
                    });

                    $fila['aplicado'] = true;
                    $aplicadas++;

                    try {
                        $this->auditoria->registrar('importacion_contactos_emergencia_fila_aplicada', $colaborador, $actor, [
                            'importacion_id' => $importacion->id,
                            'fila' => $fila['fila'],
                            'campos' => array_keys($cambios),
                        ]);
                    } catch (Throwable $e) {
                        // La auditoría nunca deshace la aplicación de la fila.
                        report($e);
                    }
                } catch (Throwable $e) {
                    $errores[] = "Fila {$fila['fila']} ({$fila['nombre_excel']}): {$e->getMessage()}";
                }
            }
            unset($fila);

            $importacion->update([
                'detalle' => $detalle,
                'estado' => 'aplicado',
                'filas_aplicadas' => $aplicadas,
                'aplicado_en' => now(),
                'aplicado_por' => $actor->id,
            ]);

            return ['filas_aplicadas' => $aplicadas, 'errores' => $errores];
        });
    }

    /**
     * Quita una importación que se analizó pero ya no se aplicará (no tocó a
     * ningún colaborador). Una aplicada no se quita: es historial.
     */
    public function eliminar(ImportacionExcel $importacion, User $actor): void
    {
        if ($importacion->estado === 'aplicado') {
            throw ValidationException::withMessages(['importacion' => 'Esta importación ya se aplicó: se conserva como historial de los cambios que hizo.']);
        }

        $datos = ['importacion_id' => $importacion->id, 'archivo' => $importacion->nombre_archivo, 'total_filas' => $importacion->total_filas];
        $importacion->delete();
        $this->auditoria->registrar('importacion_contactos_emergencia_eliminada', null, $actor, $datos);
    }

    /**
     * @param  array<string, mixed>  $fila
     * @param  Collection<int, Sucursal>  $sucursales
     * @param  array<string, list<Colaborador>>  $porNombre
     * @return array<string, mixed>
     */
    private function resolverFila(int $numeroFila, array $fila, Collection $sucursales, array $porNombre): array
    {
        $nombreExcel = trim((string) ($fila['nombre_completo'] ?? ''));
        $sucursalExcel = trim((string) ($fila['sucursal'] ?? ''));

        $base = [
            'fila' => $numeroFila,
            'nombre_excel' => $nombreExcel,
            'sucursal_excel' => $sucursalExcel,
            'colaborador_id' => null,
            'nombre_sistema' => null,
            'cambios' => [],
            'aplicado' => false,
            'sugerencias' => [],
        ];

        if ($nombreExcel === '') {
            return [...$base, 'estado' => 'error', 'motivo' => 'La fila no trae el nombre del colaborador.'];
        }

        $sucursal = $sucursalExcel !== '' ? $sucursales->first(fn (Sucursal $s): bool => $this->normalizar($s->nombre) === $this->normalizar($sucursalExcel)) : null;

        $nombreNormalizado = $this->normalizar($nombreExcel);
        $candidatos = $porNombre[$nombreNormalizado] ?? [];

        if (count($candidatos) > 1 && $sucursal !== null) {
            $deLaSucursal = array_values(array_filter($candidatos, fn (Colaborador $c): bool => (int) $c->sucursal_principal_id === $sucursal->id));

            if (count($deLaSucursal) === 1) {
                $candidatos = $deLaSucursal;
            }
        }

        if (count($candidatos) === 0) {
            return [...$base, 'estado' => 'no_encontrado', 'motivo' => "Ningún colaborador activo coincide con «{$nombreExcel}»."];
        }

        if (count($candidatos) > 1) {
            return [...$base, 'estado' => 'revision', 'motivo' => "Nombre ambiguo: «{$nombreExcel}» coincide con ".count($candidatos).' colaboradores activos.'];
        }

        return $this->calcularCambios($base, $candidatos[0], $fila);
    }

    /**
     * Cambios propuestos para UN colaborador ya identificado: solo celdas con
     * valor que DIFIERE de lo que ya tiene (nunca se borra un dato con una
     * celda vacía).
     *
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $fila
     * @return array<string, mixed>
     */
    private function calcularCambios(array $base, Colaborador $colaborador, array $fila): array
    {
        $colaborador->loadMissing('datosMedicos');
        $base['colaborador_id'] = $colaborador->id;
        $base['nombre_sistema'] = $colaborador->nombreCompleto();

        $telefono = Normalizador::telefono($this->texto($fila['telefono'] ?? null));
        $nuevos = [
            'telefono' => [$telefono, (string) $colaborador->telefono],
            'contacto_emergencia_nombre' => [$this->titulo($this->texto($fila['contacto_nombre'] ?? null)), (string) $colaborador->contacto_emergencia_nombre],
            'contacto_emergencia_parentesco' => [$this->texto($fila['parentesco'] ?? null), (string) $colaborador->contacto_emergencia_parentesco],
            'contacto_emergencia_telefono' => [Normalizador::telefono($this->texto($fila['contacto_telefono'] ?? null)), (string) $colaborador->contacto_emergencia_telefono],
            'contacto_emergencia_direccion' => [$this->texto($fila['contacto_direccion'] ?? null), (string) $colaborador->contacto_emergencia_direccion],
            'condicion_medica' => [$this->texto($fila['condicion_medica'] ?? null), (string) $colaborador->datosMedicos?->condicion_medica],
            'alergias' => [$this->texto($fila['alergias'] ?? null), (string) $colaborador->datosMedicos?->alergias],
        ];

        $cambios = [];

        foreach ($nuevos as $columna => [$nuevo, $actual]) {
            $nuevo = $nuevo === null ? '' : trim((string) $nuevo);

            // Celda vacía: no se toca lo que ya tiene (nunca se borra con blanco).
            if ($nuevo === '' || $nuevo === trim($actual)) {
                continue;
            }

            $cambios[$columna] = ['antes' => $actual === '' ? null : $actual, 'despues' => $nuevo];
        }

        $base['cambios'] = $cambios;

        return [...$base, 'estado' => $cambios === [] ? 'sin_cambios' : 'aplicable', 'motivo' => null];
    }

    /**
     * @return array<string, list<Colaborador>>
     */
    private function agruparColaboradores(): array
    {
        $colaboradores = Colaborador::query()
            ->where('estatus', 'activo')
            ->with('datosMedicos')
            ->get(['id', 'name', 'apellidos', 'sucursal_principal_id', 'telefono', 'contacto_emergencia_nombre', 'contacto_emergencia_parentesco', 'contacto_emergencia_telefono', 'contacto_emergencia_direccion']);

        $porNombre = [];

        foreach ($colaboradores as $colaborador) {
            $porNombre[$this->normalizar($colaborador->nombreCompleto())][] = $colaborador;
        }

        return $porNombre;
    }

    /**
     * Lee el Excel y normaliza cada fila a [campo => valor], reconociendo la
     * fila de encabezados por palabras clave en las primeras filas.
     *
     * @return array<int, array<string, mixed>>
     */
    private function leer(UploadedFile $archivo): array
    {
        try {
            $libro = IOFactory::load((string) $archivo->getRealPath());
            $hoja = $libro->getActiveSheet()->toArray(null, true, false, false);
        } catch (Throwable) {
            throw ValidationException::withMessages(['archivo' => 'No se pudo leer el archivo. Debe ser un Excel (.xlsx) de contactos de emergencia.']);
        }

        [$indiceEncabezados, $mapa] = $this->mapaEncabezados($hoja);

        if (! isset($mapa['nombre_completo'], $mapa['contacto_nombre'])) {
            throw ValidationException::withMessages([
                'archivo' => 'El archivo no tiene la estructura esperada. Debe incluir al menos las columnas «Nombre completo del colaborador» y «Nombre de contacto de emergencia».',
            ]);
        }

        $filas = [];

        foreach ($hoja as $indice => $valores) {
            if ($indice <= $indiceEncabezados || ! is_array($valores)) {
                continue;
            }

            if (count(array_filter($valores, fn ($v): bool => $v !== null && trim((string) $v) !== '')) === 0) {
                continue;
            }

            $fila = [];

            foreach ($mapa as $campo => $columna) {
                $valor = $valores[$columna] ?? null;
                $fila[$campo] = is_string($valor) ? trim($valor) : $valor;
            }

            // +1: el índice de la hoja empieza en 0; la fila 1 del Excel es índice 0.
            $filas[(int) $indice + 1] = $fila;
        }

        return $filas;
    }

    /**
     * Busca en las primeras filas el renglón que reconoce más columnas por
     * palabra clave y devuelve [índice de ese renglón, campo => columna].
     *
     * @param  array<int, mixed>  $hoja
     * @return array{0: int, 1: array<string, int>}
     */
    private function mapaEncabezados(array $hoja): array
    {
        $mejor = [0, []];

        foreach (array_slice($hoja, 0, 10, true) as $indice => $renglon) {
            if (! is_array($renglon)) {
                continue;
            }

            $asignados = [];

            foreach ($renglon as $columna => $celda) {
                if (! is_scalar($celda) || trim((string) $celda) === '') {
                    continue;
                }

                $campo = $this->campoDe((string) $celda);

                if ($campo !== null && ! isset($asignados[$campo])) {
                    $asignados[$campo] = (int) $columna;
                }
            }

            if (count($asignados) > count($mejor[1])) {
                $mejor = [(int) $indice, $asignados];
            }
        }

        return $mejor;
    }

    /**
     * Campo al que corresponde un encabezado, por palabras clave (los de
     * contacto de emergencia se prueban antes que el teléfono/nombre del
     * propio colaborador para que «teléfono del contacto» no caiga en
     * «teléfono personal»).
     */
    private function campoDe(string $encabezado): ?string
    {
        $n = $this->normalizar($encabezado);
        $esContacto = str_contains($n, 'CONTACTO') || str_contains($n, 'EMERGENCIA');

        return match (true) {
            $esContacto && str_contains($n, 'TELEF') => 'contacto_telefono',
            $esContacto && (str_contains($n, 'DIRECC') || str_contains($n, 'DOMICILIO')) => 'contacto_direccion',
            $esContacto && str_contains($n, 'NOMBRE') => 'contacto_nombre',
            $esContacto => 'contacto_nombre',
            str_contains($n, 'PARENTESCO') => 'parentesco',
            str_contains($n, 'TELEF') && (str_contains($n, 'PERSONAL') || str_contains($n, 'COLABORADOR') || str_contains($n, 'CELULAR')) => 'telefono',
            str_contains($n, 'CONDICION') || str_contains($n, 'MEDICA') || str_contains($n, 'CRONICA') => 'condicion_medica',
            str_contains($n, 'ALERGIA') => 'alergias',
            str_contains($n, 'NOMBRE') && (str_contains($n, 'COMPLETO') || str_contains($n, 'COLABORADOR')) => 'nombre_completo',
            str_contains($n, 'SUCURSAL') || str_contains($n, 'PLAZA') => 'sucursal',
            default => null,
        };
    }

    private function texto(mixed $valor): ?string
    {
        if (! is_scalar($valor)) {
            return null;
        }

        $texto = trim((string) $valor);

        return $texto === '' ? null : $texto;
    }

    private function titulo(?string $valor): ?string
    {
        return $valor === null ? null : mb_convert_case(mb_strtolower($valor), MB_CASE_TITLE, 'UTF-8');
    }

    /**
     * Nombre/sucursal normalizados para comparar: mayúsculas, sin acentos, sin
     * espacios repetidos. Comparación EXACTA, nunca «parecido» (CLAUDE.md).
     */
    private function normalizar(string $valor): string
    {
        return Str::of($valor)->ascii()->upper()->squish()->toString();
    }
}
