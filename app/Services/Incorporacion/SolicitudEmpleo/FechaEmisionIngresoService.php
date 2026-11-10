<?php

namespace App\Services\Incorporacion\SolicitudEmpleo;

use App\Models\Colaborador;
use App\Models\ColaboradorPerfilIngreso;
use App\Models\User;
use App\Services\Auditoria\AuditoriaService;
use App\Services\Expedientes\RequisitoDocumentoGeneradoService;
use App\Support\FechaMexico;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * «Fecha de emisión» de la Solicitud de empleo y del Estudio socioeconómico
 * (independientes): RH la elige en la web (o la app de RH) porque suele
 * llenarlos antes de contratar. Esa fecha se imprime en TODAS las fechas del
 * documento (día/mes/año del encabezado, «fecha_documento_larga», edad a la
 * fecha del documento); sin fecha elegida, el documento sale con la fecha del
 * día en que se genera, como siempre.
 *
 * Reglas (calendario de Ciudad de México):
 *  - No anterior a hoy − 1 año (un formato con más antigüedad ya no
 *    describe la situación actual de la persona).
 *  - No posterior a hoy + 30 días, o a la fecha de ingreso si es más lejana
 *    (RH lo prepara antes de contratar y lo fecha el día del ingreso).
 *  - Posterior a la fecha de ingreso: se permite, con aviso (normalmente se
 *    llenan antes de contratar).
 *
 * La lee DocumentoProcesoService::extraPorClave() como `fecha_documento`
 * del contexto: vista previa, generación y regeneración usan la misma.
 */
class FechaEmisionIngresoService
{
    public const DIAS_FUTURO = 30;

    public const MESES_PASADO = 12;

    /** @var array<string, string> */
    public const COLUMNAS = [
        CatalogoSolicitudEmpleo::SOLICITUD => 'fecha_emision_solicitud',
        CatalogoSolicitudEmpleo::ESTUDIO => 'fecha_emision_estudio',
    ];

    public function __construct(private readonly AuditoriaService $auditoria) {}

    public static function aplica(string $clave): bool
    {
        return isset(self::COLUMNAS[$clave]);
    }

    /**
     * Fecha que RH eligió (null = sin elegir: el documento sale con la de hoy).
     */
    public function elegida(Colaborador $colaborador, string $clave): ?CarbonImmutable
    {
        $columna = self::COLUMNAS[$clave] ?? null;

        if ($columna === null) {
            return null;
        }

        $perfil = ColaboradorPerfilIngreso::query()->where('colaborador_id', $colaborador->id)->first();
        $fecha = $perfil?->getAttribute($columna);

        return $fecha instanceof DateTimeInterface ? CarbonImmutable::parse($fecha->format('Y-m-d'), FechaMexico::ZONA) : null;
    }

    /**
     * La que se imprimirá: la elegida o la de hoy.
     */
    public function fecha(Colaborador $colaborador, string $clave): CarbonImmutable
    {
        return $this->elegida($colaborador, $clave) ?? $this->hoy();
    }

    /**
     * @return array{minimo: CarbonImmutable, maximo: CarbonImmutable}
     */
    public function limites(Colaborador $colaborador): array
    {
        $hoy = $this->hoy();
        $maximo = $hoy->addDays(self::DIAS_FUTURO);
        $ingreso = $this->fechaIngreso($colaborador);

        if ($ingreso !== null && $ingreso->greaterThan($maximo)) {
            $maximo = $ingreso;
        }

        return ['minimo' => $hoy->subMonthsNoOverflow(self::MESES_PASADO), 'maximo' => $maximo];
    }

    /**
     * Bloque `emision` de la respuesta de RH (web y API v1), por documento.
     *
     * @return list<array{clave: string, nombre: string, fecha: string, elegida: bool, minimo: string, maximo: string, fecha_ingreso: string|null, aviso: string|null, generado_con: string|null, desactualizado: bool}>
     */
    public function estado(Colaborador $colaborador): array
    {
        $limites = $this->limites($colaborador);
        $ingreso = $this->fechaIngreso($colaborador);
        $generados = app(RequisitoDocumentoGeneradoService::class);
        $bloques = [];

        foreach (array_keys(self::COLUMNAS) as $clave) {
            $elegida = $this->elegida($colaborador, $clave);
            $fecha = $elegida ?? $this->hoy();
            $documento = $generados->generadoVigente($colaborador, $clave);
            $generadoCon = $documento !== null ? DatosSolicitudEmpleoService::fechaDelPayload($clave, array_map('strval', (array) ($documento->payload ?? []))) : null;

            $bloques[] = [
                'clave' => $clave,
                'nombre' => CatalogoSolicitudEmpleo::DOCUMENTOS[$clave],
                'fecha' => $fecha->toDateString(),
                'elegida' => $elegida !== null,
                'minimo' => $limites['minimo']->toDateString(),
                'maximo' => $limites['maximo']->toDateString(),
                'fecha_ingreso' => $ingreso?->toDateString(),
                'aviso' => $this->aviso($clave, $fecha, $ingreso),
                'generado_con' => $generadoCon,
                // Ya generado con otra fecha: la nueva sale al regenerarlo.
                'desactualizado' => $elegida !== null && $generadoCon !== null && $generadoCon !== $fecha->toDateString(),
            ];
        }

        return $bloques;
    }

    /**
     * Guarda las fechas recibidas (solo las claves que llegan; null = volver
     * a «la fecha del día en que se genere»). Valida todas antes de escribir.
     *
     * @param  array<string, string|null>  $fechas  clave del documento => Y-m-d|null
     *
     * @throws ValidationException
     */
    public function guardar(Colaborador $colaborador, array $fechas, User $actor): void
    {
        $limites = $this->limites($colaborador);
        $cambios = [];
        $errores = [];

        foreach ($fechas as $clave => $valor) {
            $columna = self::COLUMNAS[$clave] ?? null;
            $nombre = CatalogoSolicitudEmpleo::DOCUMENTOS[$clave] ?? $clave;

            if ($columna === null) {
                $errores["fechas_emision.{$clave}"] = 'Solo la Solicitud de Empleo y el Estudio Socioeconómico tienen fecha de emisión.';

                continue;
            }

            if ($valor === null || $valor === '') {
                $cambios[$columna] = null;

                continue;
            }

            $fecha = $this->leer($valor);

            if ($fecha === null) {
                $errores["fechas_emision.{$clave}"] = sprintf('Elige una fecha de emisión válida para %s.', $nombre);
            } elseif ($fecha->lessThan($limites['minimo'])) {
                $errores["fechas_emision.{$clave}"] = sprintf('La fecha de emisión de %s no puede ser anterior al %s (máximo un año atrás).', $nombre, $this->larga($limites['minimo']));
            } elseif ($fecha->greaterThan($limites['maximo'])) {
                $errores["fechas_emision.{$clave}"] = sprintf('La fecha de emisión de %s no puede ser posterior al %s.', $nombre, $this->larga($limites['maximo']));
            } else {
                $cambios[$columna] = $fecha->toDateString();
            }
        }

        if ($errores !== []) {
            throw ValidationException::withMessages($errores);
        }

        if ($cambios === []) {
            return;
        }

        DB::transaction(function () use ($colaborador, $cambios, $actor): void {
            $perfil = ColaboradorPerfilIngreso::query()->firstOrNew(['colaborador_id' => $colaborador->id]);
            $perfil->colaborador_id = $colaborador->id;
            $perfil->fill($cambios);
            $perfil->save();

            $this->auditoria->registrar('solicitud_empleo_fecha_emision', $colaborador, $actor, ['fechas' => $cambios]);
        });
    }

    public function fechaIngreso(Colaborador $colaborador): ?CarbonImmutable
    {
        return $colaborador->fecha_ingreso !== null ? CarbonImmutable::parse($colaborador->fecha_ingreso->format('Y-m-d'), FechaMexico::ZONA) : null;
    }

    private function aviso(string $clave, CarbonImmutable $fecha, ?CarbonImmutable $ingreso): ?string
    {
        if ($ingreso === null || ! $fecha->greaterThan($ingreso)) {
            return null;
        }

        $articulo = (CatalogoSolicitudEmpleo::ARTICULOS[$clave] ?? 'la') === 'el' ? 'El' : 'La';

        return sprintf('%s %s quedaría fechad%s el %s, después de la fecha de ingreso (%s). Normalmente se llena antes de contratar.', $articulo, CatalogoSolicitudEmpleo::DOCUMENTOS[$clave], $articulo === 'El' ? 'o' : 'a', $this->larga($fecha), $this->larga($ingreso));
    }

    private function leer(string $valor): ?CarbonImmutable
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $valor, $m) !== 1 || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return null;
        }

        return CarbonImmutable::parse($valor, FechaMexico::ZONA)->startOfDay();
    }

    private function hoy(): CarbonImmutable
    {
        return CarbonImmutable::parse(FechaMexico::hoy(), FechaMexico::ZONA);
    }

    private function larga(CarbonImmutable $fecha): string
    {
        return sprintf('%d de %s de %d', $fecha->day, DatosSolicitudEmpleoService::MESES[$fecha->month - 1], $fecha->year);
    }
}
