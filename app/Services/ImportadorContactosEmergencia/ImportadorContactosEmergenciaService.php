<?php

namespace App\Services\ImportadorContactosEmergencia;

use App\Models\Colaborador;
use App\Models\ColaboradorDatosMedicos;
use App\Models\ImportacionExcel;
use App\Models\Sucursal;
use App\Models\User;
use App\Services\AlcanceOrganizacionalService;
use App\Services\Auditoria\AuditoriaService;
use App\Services\Colaboradores\BuscadorColaboradoresService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Throwable;

/**
 * Importador del Excel de CONTACTOS DE EMERGENCIA del colaborador (export de
 * Google Forms): rellena en bloque teléfono personal, contacto de emergencia
 * completo, condición médica y alergias sobre colaboradores YA existentes —
 * nunca crea personas nuevas.
 *
 * El negocio considera al contacto de emergencia como beneficiario del
 * contrato (art. 501 LFT): el motor documental lo usa de respaldo cuando no
 * hay un beneficiario capturado aparte (DatosDocumentoService::resolver()).
 *
 * Flujo en dos pasos (CLAUDE.md): analizar = VISTA PREVIA que nunca escribe
 * en colaboradores; aplicar = confirma solo las filas válidas/elegidas dentro
 * de DB::transaction y deja el resumen (actualizados, sin cambios, omitidos
 * con motivo) en la propia importación, que es el registro consultable de
 * quién importó qué archivo y cuándo.
 *
 * Match: nombre completo EXACTO normalizado (sin acentos, mayúsculas,
 * espacios repetidos) + sucursal para desambiguar homónimos. Ambiguo o no
 * encontrado se marca y RH elige al colaborador a mano; nunca se adivina.
 * Solo colaboradores activos dentro del alcance del módulo «Expedientes» de
 * quien importa, y nunca su propio registro (nadie modifica sus propios
 * datos: solicita una corrección).
 *
 * La condición médica y las alergias son datos sensibles (cifrados en
 * colaborador_datos_medicos): en el detalle de la importación también se
 * guardan cifrados y solo se muestran a quien tiene
 * `expedientes.datos_medicos.ver`.
 */
class ImportadorContactosEmergenciaService
{
    public const TIPO = 'contactos_emergencia';

    /**
     * Encabezados oficiales del formato (fila 1), en orden. Son también los
     * de la plantilla vacía y TODOS son obligatorios en el archivo.
     *
     * @var array<string, string>
     */
    public const COLUMNAS = [
        'nombre_completo' => 'Nombre completo del colaborador (no apodos, ni diminutivos)',
        'sucursal' => 'Sucursal',
        'telefono' => 'Número de teléfono personal del colaborador',
        'condicion_medica' => '¿Tienes alguna condición medica crónica?,  en caso de que sí menciona cuál.',
        'alergias' => '¿Tienes alguna alergia?(medicamentos, alimentos o picaduras), en caso de que sí menciona cuál.',
        'contacto_nombre' => 'Nombre de contacto de emergencia',
        'parentesco' => 'Parentesco',
        'contacto_telefono' => 'Número telefónico del contacto de emergencia',
        'contacto_direccion' => 'Dirección del contacto de emergencia',
    ];

    /** Columna de la ficha (o de datos médicos) que actualiza cada campo del Excel. */
    private const DESTINOS = [
        'telefono' => 'telefono',
        'contacto_nombre' => 'contacto_emergencia_nombre',
        'parentesco' => 'contacto_emergencia_parentesco',
        'contacto_telefono' => 'contacto_emergencia_telefono',
        'contacto_direccion' => 'contacto_emergencia_direccion',
        'condicion_medica' => 'condicion_medica',
        'alergias' => 'alergias',
    ];

    private const MEDICOS = ['condicion_medica', 'alergias'];

    private const TELEFONOS = ['telefono', 'contacto_emergencia_telefono'];

    /** Respuestas que significan «no tengo» (se guardan como «No» explícito, distinto de «no capturado»). */
    private const NEGATIVAS = ['NO', 'NINGUNA', 'NINGUNO', 'NINGUN', 'NA', 'N A', 'NADA', 'NO TENGO', 'NO TENGO NINGUNA', 'NO TENGO NINGUNO', 'SIN', 'NINGUNA ALERGIA', 'NINGUNA CONDICION'];

    public function __construct(
        private readonly AuditoriaService $auditoria,
        private readonly AlcanceOrganizacionalService $alcance,
    ) {}

    /**
     * Plantilla vacía (.xlsx) con los encabezados oficiales en la fila 1.
     */
    public function plantilla(): string
    {
        $libro = new Spreadsheet;
        $hoja = $libro->getActiveSheet();
        $hoja->setTitle('Contactos de emergencia');
        $hoja->fromArray(array_values(self::COLUMNAS), null, 'A1');
        $hoja->getStyle('A1:I1')->getFont()->setBold(true);
        $hoja->getStyle('A1:I1')->getAlignment()->setWrapText(true);

        foreach (range('A', 'I') as $columna) {
            $hoja->getColumnDimension($columna)->setWidth(32);
        }

        $ruta = (string) tempnam(sys_get_temp_dir(), 'plantilla-contactos-');
        (new Xlsx($libro))->save($ruta);
        $contenido = (string) file_get_contents($ruta);
        @unlink($ruta);

        return $contenido;
    }

    /**
     * VISTA PREVIA: lee y resuelve el archivo (match + cambios por fila) SIN
     * escribir nada en colaboradores; solo crea/actualiza el registro de la
     * importación. El mismo archivo ya aplicado se puede volver a analizar
     * (saldrá «sin cambios» lo que ya se guardó): aplicar es idempotente.
     */
    public function analizar(UploadedFile $archivo, User $actor): ImportacionExcel
    {
        $hashContenido = (string) hash_file('sha256', (string) $archivo->getRealPath());
        [$filas, $columnas, $ignoradas] = $this->leer($archivo);

        $contexto = $this->contexto($actor);
        $detalle = [];

        foreach ($filas as $numeroFila => $fila) {
            $detalle[] = $this->resolverFila($numeroFila, $fila, $contexto, $actor);
        }

        $detalle = $this->marcarRepetidas($detalle);

        $existente = $this->previaDelMismoArchivo($hashContenido);
        $yaAplicada = ImportacionExcel::query()->where('tipo', self::TIPO)->where('estado', 'aplicado')
            ->get(['id', 'archivo_hash', 'metadatos', 'aplicado_en'])
            ->first(fn (ImportacionExcel $i): bool => $this->hashDe($i) === $hashContenido);

        $metadatos = [
            'hash_contenido' => $hashContenido,
            'columnas' => $columnas,
            'columnas_ignoradas' => $ignoradas,
            'reanalisis_de' => $yaAplicada?->id,
        ];

        $atributos = [
            'nombre_archivo' => $archivo->getClientOriginalName() ?: 'CONTACTOS_EMERGENCIA.xlsx',
            'tipo' => self::TIPO,
            'subido_por' => $actor->id,
            'estado' => 'previsualizado',
            'filas_aplicadas' => 0,
            'detalle' => $detalle,
            'metadatos' => $metadatos,
            ...$this->contadores($detalle),
        ];

        if ($existente !== null) {
            $existente->update($atributos);
            $importacion = $existente;
        } else {
            // `archivo_hash` es único en la tabla: si este contenido ya tiene
            // una importación aplicada, la nueva vista previa lleva un hash
            // derivado y el del contenido queda en metadatos.
            $hashArchivo = ImportacionExcel::query()->where('archivo_hash', $hashContenido)->exists()
                ? hash('sha256', $hashContenido.'|'.Str::uuid()->toString())
                : $hashContenido;

            $importacion = ImportacionExcel::query()->create(['archivo_hash' => $hashArchivo, ...$atributos]);
        }

        $this->auditar('importacion_contactos_emergencia_analizada', $actor, [
            'importacion_id' => $importacion->id,
            'archivo' => $importacion->nombre_archivo,
            'total_filas' => $importacion->total_filas,
        ]);

        return $importacion;
    }

    /**
     * Fila ambigua o no encontrada: RH elige al colaborador a mano (buscador)
     * y se recalculan los cambios de esa fila. Sigue siendo vista previa.
     */
    public function asignarColaborador(ImportacionExcel $importacion, int $numeroFila, int $colaboradorId, User $actor): void
    {
        $this->exigirPrevia($importacion);

        $colaborador = Colaborador::query()->where('id', $colaboradorId)->where('estatus', 'activo')->first();

        if ($colaborador === null) {
            throw ValidationException::withMessages(['colaborador_id' => 'El colaborador elegido no existe o no está activo.']);
        }

        if ($actor->colaborador_id === $colaborador->id) {
            throw ValidationException::withMessages(['colaborador_id' => 'No puedes cargar tus propios datos por esta vía: solicita una corrección de tu expediente.']);
        }

        if (! $this->alcance->alcanzaColaboradorEnModulo($actor, $colaborador, 'expedientes')) {
            throw ValidationException::withMessages(['colaborador_id' => 'Ese colaborador está fuera de tu alcance.']);
        }

        DB::transaction(function () use ($importacion, $numeroFila, $colaborador, $actor): void {
            $importacion = ImportacionExcel::query()->lockForUpdate()->findOrFail($importacion->id);
            $this->exigirPrevia($importacion);

            /** @var list<array<string, mixed>> $detalle */
            $detalle = $importacion->detalle ?? [];
            $indice = $this->indiceDeFila($detalle, $numeroFila);

            foreach ($detalle as $otra) {
                if ((int) $otra['fila'] !== $numeroFila && (int) ($otra['colaborador_id'] ?? 0) === $colaborador->id && in_array($otra['estado'], ['aplicable', 'sin_cambios'], true)) {
                    throw ValidationException::withMessages(['colaborador_id' => sprintf('%s ya está en la fila %d de este archivo.', $colaborador->nombreCompleto(), (int) $otra['fila'])]);
                }
            }

            $fila = $detalle[$indice];
            $base = $this->base($fila);
            /** @var array<string, string|null> $valores */
            $valores = $fila['valores'] ?? [];
            $advertencias = array_values(array_filter((array) ($fila['advertencias_fila'] ?? []), 'is_string'));
            $colaborador->loadMissing(['datosMedicos', 'sucursalPrincipal:id,nombre']);

            $detalle[$indice] = [
                ...$this->calcularCambios($base, $colaborador, $valores, $advertencias),
                'match' => 'manual',
                'asignado_por' => $actor->id,
                'asignado_en' => now()->toIso8601String(),
            ];

            $importacion->update(['detalle' => $detalle, ...$this->contadores($detalle)]);
        });

        $this->auditar('importacion_contactos_emergencia_fila_asignada', $actor, [
            'importacion_id' => $importacion->id,
            'fila' => $numeroFila,
            'colaborador_id' => $colaborador->id,
        ], $colaborador);
    }

    /**
     * CONFIRMAR: aplica solo las filas «aplicable» (y, si se manda,
     * únicamente las elegidas) dentro de una transacción. Un teléfono que no
     * tiene 10 dígitos solo se guarda tal cual si RH confirmó esa fila. Cada
     * valor se vuelve a comparar contra lo que el colaborador tiene HOY: lo
     * que ya coincide no se reescribe (idempotente). Deja el resumen final
     * en la importación.
     *
     * @param  list<int>|null  $filasSeleccionadas
     * @param  list<int>  $filasConfirmadas  filas cuyo teléfono no válido RH confirmó guardar tal cual
     * @return array{actualizados: int, sin_cambios: int, omitidos: int, errores: list<string>}
     */
    public function aplicar(ImportacionExcel $importacion, User $actor, ?array $filasSeleccionadas = null, array $filasConfirmadas = []): array
    {
        $this->exigirPrevia($importacion);

        $resumen = DB::transaction(function () use ($importacion, $actor, $filasSeleccionadas, $filasConfirmadas): array {
            $importacion = ImportacionExcel::query()->lockForUpdate()->findOrFail($importacion->id);
            $this->exigirPrevia($importacion);

            /** @var list<array<string, mixed>> $detalle */
            $detalle = $importacion->detalle ?? [];
            $resumen = ['actualizados' => 0, 'sin_cambios' => 0, 'omitidos' => 0, 'errores' => []];

            foreach ($detalle as &$fila) {
                $numero = (int) $fila['fila'];
                [$resultado, $motivo, $campos] = $this->aplicarFila($fila, $actor, $filasSeleccionadas, in_array($numero, $filasConfirmadas, true), $importacion->id);

                $fila['resultado'] = $resultado;
                $fila['motivo_resultado'] = $motivo;
                $fila['campos_aplicados'] = $campos;
                $fila['aplicado'] = $resultado === 'actualizado';

                match ($resultado) {
                    'actualizado' => $resumen['actualizados']++,
                    'sin_cambios' => $resumen['sin_cambios']++,
                    default => $resumen['omitidos']++,
                };

                if ($resultado === 'error') {
                    $resumen['errores'][] = sprintf('Fila %d (%s): %s', $numero, (string) $fila['nombre_excel'], (string) $motivo);
                }
            }
            unset($fila);

            $importacion->update([
                'detalle' => $detalle,
                'estado' => 'aplicado',
                'filas_aplicadas' => $resumen['actualizados'],
                'aplicado_en' => now(),
                'aplicado_por' => $actor->id,
                'metadatos' => [...($importacion->metadatos ?? []), 'resumen' => [
                    'actualizados' => $resumen['actualizados'],
                    'sin_cambios' => $resumen['sin_cambios'],
                    'omitidos' => $resumen['omitidos'],
                ]],
            ]);

            return $resumen;
        });

        $this->auditar('importacion_contactos_emergencia_aplicada', $actor, [
            'importacion_id' => $importacion->id,
            'archivo' => $importacion->nombre_archivo,
            'actualizados' => $resumen['actualizados'],
            'sin_cambios' => $resumen['sin_cambios'],
            'omitidos' => $resumen['omitidos'],
        ]);

        return $resumen;
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
        $this->auditar('importacion_contactos_emergencia_eliminada', $actor, $datos);
    }

    /**
     * Detalle listo para la pantalla de revisión: descifra condición médica
     * y alergias solo si quien mira tiene `expedientes.datos_medicos.ver`;
     * si no, las muestra como confidenciales.
     *
     * @return list<array<string, mixed>>
     */
    public function detalleParaMostrar(ImportacionExcel $importacion, User $usuario): array
    {
        $puedeVerMedicos = $usuario->can('expedientes.datos_medicos.ver');
        $filas = [];

        foreach ($importacion->detalle ?? [] as $fila) {
            $cambios = [];

            foreach ((array) ($fila['cambios'] ?? []) as $columna => $cambio) {
                $cambio = (array) $cambio;

                if (($cambio['cifrado'] ?? false) === true) {
                    $cambio['antes'] = $puedeVerMedicos ? $this->descifrar($cambio['antes'] ?? null) : (($cambio['antes'] ?? null) === null ? null : '(confidencial)');
                    $cambio['despues'] = $puedeVerMedicos ? $this->descifrar($cambio['despues'] ?? null) : '(confidencial)';
                }

                $cambios[$columna] = $cambio;
            }

            unset($fila['valores']);
            $filas[] = [...$fila, 'cambios' => $cambios];
        }

        return $filas;
    }

    /**
     * Buscador del selector manual: activos de su alcance (módulo
     * «Expedientes»), sin mínimo de letras — con el término vacío devuelve la
     * lista inicial en orden alfabético. Nunca incluye a quien busca.
     *
     * @return list<array{id: int, nombre: string, numero_empleado: string|null, puesto: string|null, sucursal: string|null}>
     */
    public function buscarColaboradores(User $actor, string $termino): array
    {
        $consulta = Colaborador::query()
            ->where('estatus', 'activo')
            ->when($actor->colaborador_id !== null, fn ($q) => $q->where('id', '!=', $actor->colaborador_id))
            ->with(['puesto:id,nombre', 'sucursalPrincipal:id,nombre']);
        $this->alcance->limitarPorModulo($consulta, $actor, 'expedientes', 'id');

        // Buscador único: nombre, número, puesto, sucursal o departamento.
        return array_values($consulta->buscar($termino)->orderBy('name')->orderBy('apellidos')
            ->limit(BuscadorColaboradoresService::LIMITE_LISTA)
            ->get()
            ->map(fn (Colaborador $c): array => [
                'id' => $c->id,
                'nombre' => $c->nombreCompleto(),
                'numero_empleado' => $c->numero_empleado,
                'puesto' => $c->puesto?->nombre,
                'sucursal' => $c->sucursalPrincipal?->nombre,
            ])
            ->all());
    }

    /**
     * @param  array<string, mixed>  $fila
     * @param  list<int>|null  $filasSeleccionadas
     * @return array{0: string, 1: string|null, 2: list<string>}
     */
    private function aplicarFila(array $fila, User $actor, ?array $filasSeleccionadas, bool $confirmada, int $importacionId): array
    {
        if ($fila['estado'] === 'sin_cambios') {
            return ['sin_cambios', 'Ya tenía estos datos.', []];
        }

        if ($fila['estado'] !== 'aplicable') {
            return ['omitido', (string) ($fila['motivo'] ?? 'La fila no se pudo resolver.'), []];
        }

        if ($filasSeleccionadas !== null && ! in_array((int) $fila['fila'], $filasSeleccionadas, true)) {
            return ['omitido', 'Excluida en la vista previa.', []];
        }

        $colaborador = Colaborador::query()->where('id', (int) $fila['colaborador_id'])->where('estatus', 'activo')->with('datosMedicos')->first();

        if ($colaborador === null) {
            return ['omitido', 'El colaborador ya no existe o ya no está activo.', []];
        }

        if ($actor->colaborador_id === $colaborador->id) {
            return ['omitido', 'Es tu propio registro: solicita una corrección de tu expediente.', []];
        }

        if (! $this->alcance->alcanzaColaboradorEnModulo($actor, $colaborador, 'expedientes')) {
            return ['omitido', 'El colaborador está fuera de tu alcance.', []];
        }

        $deColaborador = [];
        $medicos = [];
        $sinConfirmar = [];

        foreach ((array) ($fila['cambios'] ?? []) as $columna => $cambio) {
            $cambio = (array) $cambio;
            $nuevo = ($cambio['cifrado'] ?? false) === true ? $this->descifrar($cambio['despues'] ?? null) : (is_string($cambio['despues'] ?? null) ? $cambio['despues'] : null);

            if ($nuevo === null || $nuevo === '') {
                continue;
            }

            if (($cambio['invalido'] ?? false) === true && ! $confirmada) {
                $sinConfirmar[] = $this->etiqueta((string) $columna);

                continue;
            }

            $actual = in_array($columna, self::MEDICOS, true)
                ? (string) $colaborador->datosMedicos?->getAttribute((string) $columna)
                : (string) $colaborador->getAttribute((string) $columna);

            if ($this->iguales((string) $columna, $nuevo, $actual)) {
                continue;
            }

            if (in_array($columna, self::MEDICOS, true)) {
                $medicos[(string) $columna] = $nuevo;
            } else {
                $deColaborador[(string) $columna] = $nuevo;
            }
        }

        $notaSinConfirmar = $sinConfirmar === [] ? null : sprintf('No se guardó %s: no tiene 10 dígitos y no se confirmó.', implode(' ni ', $sinConfirmar));

        if ($deColaborador === [] && $medicos === []) {
            return $sinConfirmar === []
                ? ['sin_cambios', 'Ya tenía estos datos.', []]
                : ['omitido', $notaSinConfirmar, []];
        }

        try {
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
        } catch (Throwable $e) {
            report($e);

            return ['error', 'No se pudo guardar: '.$e->getMessage(), []];
        }

        $campos = [...array_keys($deColaborador), ...array_keys($medicos)];

        $this->auditar('importacion_contactos_emergencia_fila_aplicada', $actor, [
            'importacion_id' => $importacionId,
            'fila' => (int) $fila['fila'],
            'campos' => $campos,
        ], $colaborador);

        return ['actualizado', $notaSinConfirmar, $campos];
    }

    /**
     * @param  array<string, mixed>  $fila
     * @param  array{sucursales: Collection<int, Sucursal>, porNombre: array<string, list<Colaborador>>, enAlcance: array<int, true>}  $contexto
     * @return array<string, mixed>
     */
    private function resolverFila(int $numeroFila, array $fila, array $contexto, User $actor): array
    {
        $nombreExcel = Str::squish((string) $this->texto($fila['nombre_completo'] ?? null));
        $sucursalExcel = Str::squish((string) $this->texto($fila['sucursal'] ?? null));
        [$valores, $advertencias] = $this->normalizarValores($fila);

        $base = [
            'fila' => $numeroFila,
            'nombre_excel' => $nombreExcel,
            'sucursal_excel' => $sucursalExcel,
            'valores' => $valores,
            'advertencias_fila' => $advertencias,
        ];
        $base = $this->base($base);

        if ($nombreExcel === '') {
            return [...$base, 'estado' => 'error', 'motivo' => 'La fila no trae el nombre del colaborador.', 'advertencias' => $advertencias];
        }

        $sucursal = $sucursalExcel !== ''
            ? $contexto['sucursales']->first(fn (Sucursal $s): bool => $this->normalizar($s->nombre) === $this->normalizar($sucursalExcel))
            : null;

        if ($sucursalExcel !== '' && $sucursal === null) {
            $advertencias[] = sprintf('La sucursal «%s» no existe en el catálogo.', $sucursalExcel);
        }

        $candidatos = $contexto['porNombre'][$this->normalizar($nombreExcel)] ?? [];

        if (count($candidatos) > 1 && $sucursal !== null) {
            $deLaSucursal = array_values(array_filter($candidatos, fn (Colaborador $c): bool => (int) $c->sucursal_principal_id === $sucursal->id));

            if (count($deLaSucursal) === 1) {
                $candidatos = $deLaSucursal;
            }
        }

        if (count($candidatos) === 0) {
            return [...$base, 'estado' => 'no_encontrado', 'motivo' => sprintf('Ningún colaborador activo se llama exactamente «%s». Elige al colaborador a mano.', $nombreExcel), 'advertencias' => $advertencias];
        }

        if (count($candidatos) > 1) {
            return [
                ...$base,
                'estado' => 'revision',
                'motivo' => sprintf('Nombre ambiguo: «%s» coincide con %d colaboradores activos. Elige cuál es.', $nombreExcel, count($candidatos)),
                'advertencias' => $advertencias,
                'sugerencias' => array_map(fn (Colaborador $c): array => [
                    'id' => $c->id,
                    'nombre' => $c->nombreCompleto(),
                    'sucursal' => $c->sucursalPrincipal?->nombre,
                ], $candidatos),
            ];
        }

        $colaborador = $candidatos[0];

        if ($actor->colaborador_id === $colaborador->id) {
            return [...$base, 'colaborador_id' => $colaborador->id, 'nombre_sistema' => $colaborador->nombreCompleto(), 'estado' => 'omitido', 'motivo' => 'Es tu propio registro: nadie modifica sus propios datos por esta vía; solicita una corrección.', 'advertencias' => $advertencias];
        }

        if (! isset($contexto['enAlcance'][$colaborador->id])) {
            return [...$base, 'estado' => 'omitido', 'motivo' => 'El colaborador está fuera de tu alcance.', 'advertencias' => $advertencias];
        }

        return [...$this->calcularCambios($base, $colaborador, $valores, $advertencias), 'match' => 'automatico'];
    }

    /**
     * Cambios propuestos para UN colaborador ya identificado: solo celdas con
     * valor que DIFIERE de lo que ya tiene (nunca se borra un dato con una
     * celda vacía). Los datos médicos se guardan cifrados en el detalle.
     *
     * @param  array<string, mixed>  $base
     * @param  array<string, string|null>  $valores
     * @param  list<string>  $advertencias
     * @return array<string, mixed>
     */
    private function calcularCambios(array $base, Colaborador $colaborador, array $valores, array $advertencias): array
    {
        $base['colaborador_id'] = $colaborador->id;
        $base['nombre_sistema'] = $colaborador->nombreCompleto();
        $base['sucursal_sistema'] = $colaborador->sucursalPrincipal?->nombre;

        $sucursalExcel = (string) ($base['sucursal_excel'] ?? '');

        if ($sucursalExcel !== '' && $colaborador->sucursalPrincipal !== null && $this->normalizar($colaborador->sucursalPrincipal->nombre) !== $this->normalizar($sucursalExcel)) {
            $advertencias[] = sprintf('El archivo dice sucursal «%s» y en el sistema está en «%s».', $sucursalExcel, $colaborador->sucursalPrincipal->nombre);
        }

        $cambios = [];
        $requiereConfirmacion = false;

        foreach (self::DESTINOS as $campo => $columna) {
            $nuevo = in_array($columna, self::MEDICOS, true) ? $this->descifrar($valores[$campo] ?? null) : ($valores[$campo] ?? null);

            // Celda vacía: no se toca lo que ya tiene (nunca se borra con blanco).
            if ($nuevo === null || $nuevo === '') {
                continue;
            }

            $actual = in_array($columna, self::MEDICOS, true)
                ? (string) $colaborador->datosMedicos?->getAttribute($columna)
                : (string) $colaborador->getAttribute($columna);

            if ($this->iguales($columna, $nuevo, $actual)) {
                continue;
            }

            $cambio = ['antes' => trim($actual) === '' ? null : $actual, 'despues' => $nuevo];

            if (in_array($columna, self::TELEFONOS, true) && ! $this->telefonoValido($nuevo)) {
                $cambio['invalido'] = true;
                $requiereConfirmacion = true;
            }

            if (in_array($columna, self::MEDICOS, true)) {
                $cambio = [
                    'antes' => $cambio['antes'] === null ? null : Crypt::encryptString($cambio['antes']),
                    'despues' => Crypt::encryptString($nuevo),
                    'cifrado' => true,
                ];
            }

            $cambios[$columna] = $cambio;
        }

        return [
            ...$base,
            'cambios' => $cambios,
            'advertencias' => $advertencias,
            'requiere_confirmacion' => $requiereConfirmacion,
            'sugerencias' => [],
            'estado' => $cambios === [] ? 'sin_cambios' : 'aplicable',
            'motivo' => $cambios === [] ? 'Ya tiene estos mismos datos.' : null,
        ];
    }

    /**
     * Normaliza los valores de una fila y junta sus advertencias. Teléfonos
     * a 10 dígitos (quita espacios, guiones, +52); los que no quedan en 10 se
     * conservan tal cual con advertencia. Respuestas negativas de salud =
     * «No». Parentesco con mayúscula inicial (no hay catálogo de parentescos
     * en el sistema: se guarda tal cual lo escribieron).
     *
     * @param  array<string, mixed>  $fila
     * @return array{0: array<string, string|null>, 1: list<string>}
     */
    private function normalizarValores(array $fila): array
    {
        $advertencias = [];

        $telefono = $this->telefono($this->texto($fila['telefono'] ?? null));
        $contactoTelefono = $this->telefono($this->texto($fila['contacto_telefono'] ?? null));
        $contactoNombre = $this->texto($fila['contacto_nombre'] ?? null);
        $parentesco = $this->texto($fila['parentesco'] ?? null);
        $condicion = $this->respuestaSalud($this->texto($fila['condicion_medica'] ?? null));
        $alergias = $this->respuestaSalud($this->texto($fila['alergias'] ?? null));

        if ($telefono === null) {
            $advertencias[] = 'No trae teléfono personal (se conserva el que tenga).';
        } elseif (! $this->telefonoValido($telefono)) {
            $advertencias[] = sprintf('El teléfono personal «%s» no tiene 10 dígitos: solo se guarda si lo confirmas.', $telefono);
        }

        if ($contactoNombre === null) {
            $advertencias[] = 'No trae nombre del contacto de emergencia.';
        }

        if ($parentesco === null) {
            $advertencias[] = 'El parentesco está vacío.';
        }

        if ($contactoTelefono === null) {
            $advertencias[] = 'No trae teléfono del contacto de emergencia.';
        } elseif (! $this->telefonoValido($contactoTelefono)) {
            $advertencias[] = sprintf('El teléfono del contacto «%s» no tiene 10 dígitos: solo se guarda si lo confirmas.', $contactoTelefono);
        }

        if ($condicion === null) {
            $advertencias[] = 'No respondió la condición médica (queda como no capturada).';
        }

        if ($alergias === null) {
            $advertencias[] = 'No respondió alergias (queda como no capturado).';
        }

        return [[
            'telefono' => $telefono,
            'contacto_nombre' => $contactoNombre === null ? null : $this->titulo($contactoNombre),
            'parentesco' => $parentesco === null ? null : $this->mayusculaInicial($parentesco),
            'contacto_telefono' => $contactoTelefono,
            'contacto_direccion' => $this->texto($fila['contacto_direccion'] ?? null),
            'condicion_medica' => $condicion === null ? null : Crypt::encryptString($condicion),
            'alergias' => $alergias === null ? null : Crypt::encryptString($alergias),
        ], $advertencias];
    }

    /**
     * Respuestas repetidas (Google Forms deja varias si alguien lo llenó dos
     * veces): se usa la MÁS RECIENTE (la fila más abajo) y las anteriores
     * quedan omitidas con motivo explícito.
     *
     * @param  list<array<string, mixed>>  $detalle
     * @return list<array<string, mixed>>
     */
    private function marcarRepetidas(array $detalle): array
    {
        $ultimaPorColaborador = [];

        foreach ($detalle as $indice => $fila) {
            if (in_array($fila['estado'], ['aplicable', 'sin_cambios'], true) && $fila['colaborador_id'] !== null) {
                $ultimaPorColaborador[(int) $fila['colaborador_id']] = $indice;
            }
        }

        foreach ($detalle as $indice => $fila) {
            if (! in_array($fila['estado'], ['aplicable', 'sin_cambios'], true) || $fila['colaborador_id'] === null) {
                continue;
            }

            $ultima = $ultimaPorColaborador[(int) $fila['colaborador_id']];

            if ($ultima !== $indice) {
                $detalle[$indice]['estado'] = 'omitido';
                $detalle[$indice]['motivo'] = sprintf('Respuesta repetida: se usa la fila %d (la más reciente).', (int) $detalle[$ultima]['fila']);
            }
        }

        return $detalle;
    }

    /**
     * @return array{sucursales: Collection<int, Sucursal>, porNombre: array<string, list<Colaborador>>, enAlcance: array<int, true>}
     */
    private function contexto(User $actor): array
    {
        $colaboradores = Colaborador::query()
            ->where('estatus', 'activo')
            ->with(['datosMedicos', 'sucursalPrincipal:id,nombre'])
            ->get(['id', 'name', 'apellidos', 'sucursal_principal_id', 'telefono', 'contacto_emergencia_nombre', 'contacto_emergencia_parentesco', 'contacto_emergencia_telefono', 'contacto_emergencia_direccion']);

        $porNombre = [];

        foreach ($colaboradores as $colaborador) {
            $porNombre[$this->normalizar($colaborador->nombreCompleto())][] = $colaborador;
        }

        $enAlcance = [];
        $consulta = Colaborador::query()->where('estatus', 'activo');

        foreach ($this->alcance->limitarPorModulo($consulta, $actor, 'expedientes', 'id')->pluck('id') as $id) {
            $enAlcance[(int) $id] = true;
        }

        return [
            'sucursales' => Sucursal::query()->get(['id', 'nombre']),
            'porNombre' => $porNombre,
            'enAlcance' => $enAlcance,
        ];
    }

    /**
     * Lee el Excel y normaliza cada fila a [campo => valor], reconociendo la
     * fila de encabezados por palabras clave (no por posición). Si falta una
     * columna obligatoria, error claro con su nombre.
     *
     * @return array{0: array<int, array<string, mixed>>, 1: array<string, string>, 2: list<string>}
     */
    private function leer(UploadedFile $archivo): array
    {
        try {
            $libro = IOFactory::load((string) $archivo->getRealPath());
            $hoja = $libro->getActiveSheet()->toArray(null, true, false, false);
        } catch (Throwable) {
            throw ValidationException::withMessages(['archivo' => 'No se pudo leer el archivo. Debe ser un Excel (.xlsx) de contactos de emergencia.']);
        }

        [$indiceEncabezados, $mapa, $textos, $ignoradas] = $this->mapaEncabezados($hoja);

        $faltantes = array_values(array_diff_key(self::COLUMNAS, $mapa));

        if ($faltantes !== []) {
            throw ValidationException::withMessages([
                'archivo' => sprintf('Al archivo le falta(n) la(s) columna(s): %s. Descarga la plantilla para ver el formato esperado.', implode(' · ', array_map(fn (string $c): string => sprintf('«%s»', Str::squish($c)), $faltantes))),
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

        if ($filas === []) {
            throw ValidationException::withMessages(['archivo' => 'El archivo no trae ninguna fila de colaboradores debajo de los encabezados.']);
        }

        return [$filas, $textos, $ignoradas];
    }

    /**
     * Busca en las primeras filas el renglón que reconoce más columnas por
     * palabra clave. Devuelve [índice, campo => columna, campo => encabezado
     * original, encabezados ignorados (no reconocidos o repetidos)].
     *
     * @param  array<int, mixed>  $hoja
     * @return array{0: int, 1: array<string, int>, 2: array<string, string>, 3: list<string>}
     */
    private function mapaEncabezados(array $hoja): array
    {
        $mejor = [0, [], [], []];

        foreach (array_slice($hoja, 0, 10, true) as $indice => $renglon) {
            if (! is_array($renglon)) {
                continue;
            }

            $asignados = [];
            $textos = [];
            $ignoradas = [];

            foreach ($renglon as $columna => $celda) {
                if (! is_scalar($celda) || trim((string) $celda) === '') {
                    continue;
                }

                $encabezado = Str::squish((string) $celda);
                $campo = $this->campoDe($encabezado);

                if ($campo !== null && ! isset($asignados[$campo])) {
                    $asignados[$campo] = (int) $columna;
                    $textos[$campo] = $encabezado;
                } else {
                    $ignoradas[] = $campo === null ? $encabezado : sprintf('%s (repetida)', $encabezado);
                }
            }

            if (count($asignados) > count($mejor[1])) {
                $mejor = [(int) $indice, $asignados, $textos, $ignoradas];
            }
        }

        return $mejor;
    }

    /**
     * Campo al que corresponde un encabezado, por palabras clave. El orden
     * importa: «alergia (medicamentos…)» contiene «MEDICA», por eso alergias
     * va antes que condición médica; parentesco antes que «contacto».
     */
    private function campoDe(string $encabezado): ?string
    {
        $n = $this->normalizar($encabezado);
        $esContacto = str_contains($n, 'CONTACTO') || str_contains($n, 'EMERGENCIA');

        return match (true) {
            str_contains($n, 'PARENTESCO') => 'parentesco',
            str_contains($n, 'ALERGIA') => 'alergias',
            $esContacto && str_contains($n, 'TELEF') => 'contacto_telefono',
            $esContacto && (str_contains($n, 'DIRECC') || str_contains($n, 'DOMICILIO')) => 'contacto_direccion',
            $esContacto => 'contacto_nombre',
            str_contains($n, 'TELEF') && (str_contains($n, 'PERSONAL') || str_contains($n, 'COLABORADOR') || str_contains($n, 'CELULAR')) => 'telefono',
            str_contains($n, 'CONDICION') || str_contains($n, 'CRONICA') => 'condicion_medica',
            str_contains($n, 'NOMBRE') && (str_contains($n, 'COMPLETO') || str_contains($n, 'COLABORADOR')) => 'nombre_completo',
            str_contains($n, 'SUCURSAL') => 'sucursal',
            default => null,
        };
    }

    private function previaDelMismoArchivo(string $hashContenido): ?ImportacionExcel
    {
        return ImportacionExcel::query()->where('tipo', self::TIPO)->where('estado', 'previsualizado')
            ->orderByDesc('id')
            ->get()
            ->first(fn (ImportacionExcel $i): bool => $this->hashDe($i) === $hashContenido);
    }

    private function hashDe(ImportacionExcel $importacion): string
    {
        $hash = $importacion->metadatos['hash_contenido'] ?? null;

        return is_string($hash) ? $hash : $importacion->archivo_hash;
    }

    private function exigirPrevia(ImportacionExcel $importacion): void
    {
        if ($importacion->estado === 'aplicado') {
            throw ValidationException::withMessages(['importacion' => 'Esta importación ya fue aplicada. Para volver a cargar el archivo, súbelo de nuevo: solo se aplicará lo que cambió.']);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $detalle
     */
    private function indiceDeFila(array $detalle, int $numeroFila): int
    {
        foreach ($detalle as $indice => $fila) {
            if ((int) $fila['fila'] === $numeroFila) {
                return $indice;
            }
        }

        throw ValidationException::withMessages(['fila' => sprintf('La fila %d no existe en esta importación.', $numeroFila)]);
    }

    /**
     * Campos comunes de una fila del detalle (sin resultado de match).
     *
     * @param  array<string, mixed>  $fila
     * @return array<string, mixed>
     */
    private function base(array $fila): array
    {
        return [
            'fila' => (int) $fila['fila'],
            'nombre_excel' => (string) ($fila['nombre_excel'] ?? ''),
            'sucursal_excel' => (string) ($fila['sucursal_excel'] ?? ''),
            'valores' => $fila['valores'] ?? [],
            'advertencias_fila' => $fila['advertencias_fila'] ?? [],
            'colaborador_id' => null,
            'nombre_sistema' => null,
            'sucursal_sistema' => null,
            'match' => null,
            'cambios' => [],
            'advertencias' => [],
            'requiere_confirmacion' => false,
            'sugerencias' => [],
            'aplicado' => false,
            'resultado' => null,
            'motivo_resultado' => null,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $detalle
     * @return array{total_filas: int, filas_revision: int, filas_sin_cambios: int, filas_no_encontradas: int, filas_error: int}
     */
    private function contadores(array $detalle): array
    {
        $cuenta = fn (string $estado): int => count(array_filter($detalle, fn (array $f): bool => $f['estado'] === $estado));

        return [
            'total_filas' => count($detalle),
            'filas_revision' => $cuenta('revision'),
            'filas_sin_cambios' => $cuenta('sin_cambios'),
            'filas_no_encontradas' => $cuenta('no_encontrado'),
            'filas_error' => $cuenta('error'),
        ];
    }

    /**
     * Teléfonos y textos se comparan «como dato», no como formato: mismo
     * número con otros espacios no es un cambio.
     */
    private function iguales(string $columna, string $nuevo, string $actual): bool
    {
        if (in_array($columna, self::TELEFONOS, true)) {
            $digitos = fn (string $v): string => (string) preg_replace('/\D+/', '', $v);

            return $digitos($nuevo) !== '' && $digitos($nuevo) === $digitos($actual);
        }

        return Str::squish($nuevo) === Str::squish($actual);
    }

    /**
     * 10 dígitos si se puede (quita espacios, guiones, paréntesis y lada +52 /
     * 521); si no quedan 10, el texto original para que RH decida.
     */
    private function telefono(?string $valor): ?string
    {
        if ($valor === null) {
            return null;
        }

        $digitos = (string) preg_replace('/\D+/', '', $valor);

        if (strlen($digitos) === 13 && str_starts_with($digitos, '521')) {
            $digitos = substr($digitos, 3);
        } elseif (strlen($digitos) === 12 && str_starts_with($digitos, '52')) {
            $digitos = substr($digitos, 2);
        }

        return strlen($digitos) === 10 ? $digitos : Str::squish($valor);
    }

    private function telefonoValido(string $valor): bool
    {
        return preg_match('/^\d{10}$/', $valor) === 1;
    }

    private function respuestaSalud(?string $valor): ?string
    {
        if ($valor === null) {
            return null;
        }

        $clave = Str::of($valor)->ascii()->upper()->replaceMatches('/[^A-Z ]+/', ' ')->squish()->toString();

        if (in_array($clave, self::NEGATIVAS, true)) {
            return 'No';
        }

        return $this->mayusculaInicial(Str::squish($valor));
    }

    private function mayusculaInicial(string $valor): string
    {
        $valor = Str::squish($valor);

        return mb_strtoupper(mb_substr($valor, 0, 1, 'UTF-8'), 'UTF-8').mb_strtolower(mb_substr($valor, 1, null, 'UTF-8'), 'UTF-8');
    }

    private function etiqueta(string $columna): string
    {
        return match ($columna) {
            'telefono' => 'el teléfono personal',
            'contacto_emergencia_telefono' => 'el teléfono del contacto',
            default => $columna,
        };
    }

    private function descifrar(mixed $valor): ?string
    {
        if (! is_string($valor) || $valor === '') {
            return null;
        }

        try {
            return Crypt::decryptString($valor);
        } catch (Throwable) {
            return null;
        }
    }

    private function texto(mixed $valor): ?string
    {
        if (! is_scalar($valor)) {
            return null;
        }

        $texto = is_float($valor) && floor($valor) === $valor ? sprintf('%.0f', $valor) : trim((string) $valor);

        return $texto === '' ? null : $texto;
    }

    private function titulo(string $valor): string
    {
        return mb_convert_case(mb_strtolower(Str::squish($valor), 'UTF-8'), MB_CASE_TITLE, 'UTF-8');
    }

    /**
     * Nombre/sucursal normalizados para comparar: mayúsculas, sin acentos, sin
     * espacios repetidos. Comparación EXACTA, nunca «parecido» (CLAUDE.md).
     */
    private function normalizar(string $valor): string
    {
        return Str::of($valor)->ascii()->upper()->squish()->toString();
    }

    /**
     * La auditoría nunca deshace la acción principal (CLAUDE.md).
     *
     * @param  array<string, mixed>  $datos
     */
    private function auditar(string $accion, User $actor, array $datos, ?Colaborador $sujeto = null): void
    {
        try {
            $this->auditoria->registrar($accion, $sujeto, $actor, $datos);
        } catch (Throwable $e) {
            Log::warning('No se pudo auditar la importación de contactos de emergencia.', ['accion' => $accion, 'error' => $e->getMessage()]);
        }
    }
}
