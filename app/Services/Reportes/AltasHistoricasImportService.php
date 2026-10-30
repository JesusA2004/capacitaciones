<?php

namespace App\Services\Reportes;

use App\Enums\TipoMovimientoLaboral;
use App\Models\AltaHistorica;
use App\Models\Colaborador;
use App\Models\ImportacionExcel;
use App\Models\MovimientoLaboral;
use App\Models\Puesto;
use App\Models\Sucursal;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Throwable;

/**
 * Carga auditable de altas de periodos anteriores al sistema, a partir de un
 * Excel que entrega RH: «Nombre | Fecha de alta | Sucursal | Puesto | Número de
 * empleado». analizar() solo guarda la vista previa; aplicar() escribe en
 * altas_historicas. Nunca cambia colaboradores.fecha_ingreso, nunca deduce una
 * alta a partir de una baja y una fila que no cuadra se reporta y bloquea.
 *
 * @phpstan-type FilaAlta array{fila: int, nombre: string, fecha: string|null, sucursal: string, sucursal_id: int|null, puesto: string, puesto_id: int|null, numero_empleado: string|null, colaborador_id: int|null, estado: string, motivo: string|null}
 */
class AltasHistoricasImportService
{
    public const TIPO = 'altas_historicas';

    private const ENCABEZADOS = ['nombre', 'fecha de alta', 'sucursal', 'puesto'];

    public function analizar(UploadedFile $archivo, User $usuario): ImportacionExcel
    {
        $hash = (string) hash_file('sha256', (string) $archivo->getRealPath());

        if (ImportacionExcel::query()->where('archivo_hash', $hash)->where('estado', 'aplicado')->exists()) {
            throw ValidationException::withMessages(['archivo' => 'Este mismo archivo ya se aplicó antes.']);
        }

        try {
            $renglones = array_values(IOFactory::load((string) $archivo->getRealPath())->getSheet(0)->toArray(null, false, false, false));
        } catch (Throwable) {
            throw ValidationException::withMessages(['archivo' => 'No se pudo leer el archivo. Verifica que sea un Excel válido.']);
        }

        $encabezado = array_map(fn ($c) => $this->normalizar($c), (array) ($renglones[0] ?? []));

        foreach (self::ENCABEZADOS as $requerido) {
            if (! in_array($requerido, $encabezado, true)) {
                throw ValidationException::withMessages(['archivo' => sprintf('Falta la columna «%s». Descarga el formato y úsalo como base.', Str::ucfirst($requerido))]);
            }
        }

        $filas = $this->resolver(array_slice($renglones, 1), array_flip($encabezado));

        if ($filas === []) {
            throw ValidationException::withMessages(['archivo' => 'El archivo no trae filas de altas.']);
        }

        $cuenta = fn (string $estado): int => count(array_filter($filas, fn (array $f): bool => $f['estado'] === $estado));

        return ImportacionExcel::query()->updateOrCreate(['archivo_hash' => $hash], [
            'nombre_archivo' => mb_substr($archivo->getClientOriginalName(), 0, 255),
            'tipo' => self::TIPO,
            'subido_por' => $usuario->id,
            'estado' => 'previsualizado',
            'total_filas' => count($filas),
            'filas_aplicadas' => 0,
            'filas_revision' => $cuenta('nuevo'),
            'filas_sin_cambios' => $cuenta('duplicado') + $cuenta('ya_en_sistema'),
            'filas_no_encontradas' => 0,
            'filas_error' => $cuenta('error'),
            'detalle' => $filas,
            'metadatos' => ['periodo' => $this->periodo($filas)],
        ]);
    }

    /**
     * @throws ValidationException
     */
    public function aplicar(ImportacionExcel $importacion, User $usuario, string $motivo): int
    {
        if ($importacion->tipo !== self::TIPO || $importacion->estado !== 'previsualizado') {
            throw ValidationException::withMessages(['importacion' => 'Esta importación ya no se puede aplicar.']);
        }

        if ($importacion->filas_error > 0) {
            throw ValidationException::withMessages(['importacion' => 'Corrige las filas con error y vuelve a subir el archivo.']);
        }

        /** @var list<array<string, mixed>> $filas */
        $filas = (array) $importacion->detalle;

        return DB::transaction(function () use ($importacion, $usuario, $motivo, $filas): int {
            $aplicadas = 0;

            foreach ($filas as $fila) {
                if ($fila['estado'] !== 'nuevo') {
                    continue;
                }

                $llave = $this->llave((string) $fila['nombre'], (string) $fila['fecha']);

                // Otra importación pudo cargarla entre la vista previa y este momento.
                if (AltaHistorica::query()->where('llave', $llave)->exists()) {
                    continue;
                }

                AltaHistorica::query()->create([
                    'importacion_excel_id' => $importacion->id,
                    'nombre' => $fila['nombre'],
                    'llave' => $llave,
                    'numero_empleado' => $fila['numero_empleado'],
                    'colaborador_id' => $fila['colaborador_id'],
                    'fecha_alta' => $fila['fecha'],
                    'sucursal_id' => $fila['sucursal_id'],
                    'puesto_id' => $fila['puesto_id'],
                    'registrado_por' => $usuario->id,
                ]);
                $aplicadas++;
            }

            $importacion->update([
                'estado' => 'aplicado',
                'filas_aplicadas' => $aplicadas,
                'aplicado_en' => now(),
                'aplicado_por' => $usuario->id,
                'metadatos' => [...(array) $importacion->metadatos, 'motivo' => $motivo],
            ]);

            activity('reportes')->causedBy($usuario)->performedOn($importacion)
                ->withProperties(['tipo' => self::TIPO, 'filas' => $aplicadas, 'motivo' => $motivo])
                ->log('Aplicó una importación de altas históricas');

            return $aplicadas;
        });
    }

    public function plantillaExcel(): Spreadsheet
    {
        $libro = new Spreadsheet;
        $hoja = $libro->getActiveSheet();
        $hoja->setTitle('Altas históricas');
        $hoja->fromArray(['Nombre', 'Fecha de alta', 'Sucursal', 'Puesto', 'Número de empleado']);
        $hoja->getStyle('A1:E1')->getFont()->setBold(true);

        foreach (range('A', 'E') as $columna) {
            $hoja->getColumnDimension($columna)->setWidth(26);
        }

        return $libro;
    }

    /**
     * Rangos de meses con información confirmada por una carga: fuera de ellos,
     * un cero sería una afirmación sin sustento.
     *
     * @param  list<FilaAlta>  $filas
     * @return array{desde: string|null, hasta: string|null}
     */
    private function periodo(array $filas): array
    {
        $fechas = array_values(array_filter(array_map(fn (array $f): ?string => $f['estado'] === 'error' ? null : $f['fecha'], $filas)));

        return ['desde' => $fechas === [] ? null : min($fechas), 'hasta' => $fechas === [] ? null : max($fechas)];
    }

    /**
     * @param  array<int, array<int, mixed>>  $renglones
     * @param  array<string, int>  $mapa
     * @return list<FilaAlta>
     */
    private function resolver(array $renglones, array $mapa): array
    {
        $sucursales = Sucursal::query()->get(['id', 'nombre'])->mapWithKeys(fn (Sucursal $s) => [$this->normalizar($s->nombre) => (int) $s->id]);
        $puestos = Puesto::query()->get(['id', 'nombre'])->mapWithKeys(fn (Puesto $p) => [$this->normalizar($p->nombre) => (int) $p->id]);
        $vistas = [];
        $filas = [];

        foreach ($renglones as $i => $renglon) {
            $renglon = (array) $renglon;
            $nombre = trim((string) ($renglon[$mapa['nombre']] ?? ''));
            $sucursal = trim((string) ($renglon[$mapa['sucursal']] ?? ''));
            $puesto = trim((string) ($renglon[$mapa['puesto']] ?? ''));
            $crudaFecha = $renglon[$mapa['fecha de alta']] ?? null;

            if ($nombre === '' && $sucursal === '' && $puesto === '' && ($crudaFecha === null || $crudaFecha === '')) {
                continue;
            }

            $fecha = $this->fecha($crudaFecha);
            $sucursalId = $sucursales[$this->normalizar($sucursal)] ?? null;
            $puestoId = $puestos[$this->normalizar($puesto)] ?? null;
            $numero = isset($mapa['numero de empleado']) ? trim((string) ($renglon[$mapa['numero de empleado']] ?? '')) : '';
            $colaboradorId = $this->colaborador($numero, $nombre);

            [$estado, $motivo] = match (true) {
                $nombre === '' => ['error', 'Falta el nombre.'],
                $fecha === null => ['error', 'La fecha de alta no es válida.'],
                $fecha > now('America/Mexico_City')->toDateString() => ['error', 'La fecha de alta está en el futuro.'],
                $sucursalId === null => ['error', sprintf('La sucursal «%s» no existe en el catálogo.', $sucursal)],
                $puestoId === null => ['error', sprintf('El puesto «%s» no existe en el catálogo.', $puesto)],
                isset($vistas[$this->llave($nombre, $fecha)]) => ['error', 'La misma alta viene repetida en el archivo.'],
                AltaHistorica::query()->where('llave', $this->llave($nombre, $fecha))->exists() => ['duplicado', 'Ya estaba cargada.'],
                $colaboradorId !== null && $this->yaEnSistema($colaboradorId, $fecha) => ['ya_en_sistema', 'El sistema ya registra esta alta.'],
                default => ['nuevo', null],
            };

            if ($fecha !== null && $nombre !== '') {
                $vistas[$this->llave($nombre, $fecha)] = true;
            }

            $filas[] = [
                'fila' => (int) $i + 2,
                'nombre' => $nombre,
                'fecha' => $fecha,
                'sucursal' => $sucursal,
                'sucursal_id' => $sucursalId,
                'puesto' => $puesto,
                'puesto_id' => $puestoId,
                'numero_empleado' => $numero === '' ? null : $numero,
                'colaborador_id' => $colaboradorId,
                'estado' => $estado,
                'motivo' => $motivo,
            ];
        }

        return $filas;
    }

    private function colaborador(string $numero, string $nombre): ?int
    {
        if ($numero !== '') {
            $id = Colaborador::withTrashed()->where('numero_empleado', $numero)->value('id');

            if ($id !== null) {
                return (int) $id;
            }
        }

        // Por nombre solo cuando la coincidencia es exacta y única: nunca adivina.
        $coincidencias = Colaborador::withTrashed()->get(['id', 'name', 'apellidos'])
            ->filter(fn (Colaborador $c): bool => $this->normalizar($c->nombreCompleto()) === $this->normalizar($nombre));

        return $coincidencias->count() === 1 ? (int) $coincidencias->first()?->id : null;
    }

    private function yaEnSistema(int $colaboradorId, string $fecha): bool
    {
        return MovimientoLaboral::query()
            ->where('colaborador_id', $colaboradorId)
            ->where('tipo_movimiento', TipoMovimientoLaboral::Alta->value)
            ->whereDate('fecha_movimiento', $fecha)
            ->exists();
    }

    private function fecha(mixed $valor): ?string
    {
        try {
            if (is_numeric($valor)) {
                return CarbonImmutable::instance(Date::excelToDateTimeObject((float) $valor))->toDateString();
            }

            $texto = trim((string) $valor);

            if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $texto, $m) === 1) {
                return checkdate((int) $m[2], (int) $m[1], (int) $m[3]) ? sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]) : null;
            }

            return preg_match('/^\d{4}-\d{2}-\d{2}$/', $texto) === 1 ? CarbonImmutable::parse($texto)->toDateString() : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function llave(string $nombre, string $fecha): string
    {
        return hash('sha256', sprintf('%s|%s', $this->normalizar($nombre), $fecha));
    }

    private function normalizar(mixed $texto): string
    {
        return Str::of(is_scalar($texto) ? (string) $texto : '')->ascii()->lower()->squish()->toString();
    }
}
