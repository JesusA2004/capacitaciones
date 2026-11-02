<?php

namespace App\Services\Rotacion;

use App\Enums\ClasificacionSalida;
use App\Enums\EstatusBaja;
use App\Models\Baja;
use App\Models\BajaHistorica;
use App\Models\CausalAnalitica;
use App\Models\CausalRotacion;
use App\Models\RotacionCorreccion;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Correcciones auditadas del módulo de Rotación. Todas exigen comentario,
 * corren en una transacción y dejan antes/después en rotacion_correcciones.
 *
 *  - Nunca se sobrescribe el motivo original.
 *  - En una baja del SISTEMA solo cambia su categoría de análisis
 *    (bajas.causal_analitica_id): la causal administrativa, la forma de salida,
 *    los documentos firmados y el cierre laboral no se tocan. Para cambiar esos
 *    datos se usa la rectificación del módulo de Bajas.
 *  - La fecha de ingreso de una baja del sistema sale del expediente: se
 *    corrige ahí. Aquí solo se completa la de una baja histórica.
 *  - Cambiar el mapeo de una causal del catálogo o la clasificación de una
 *    categoría avisa cuántas bajas se reclasifican y queda en la bitácora.
 */
class CorreccionRotacionService
{
    public const PERMISO = 'bajas.gestionar';

    public function corregirCausal(User $actor, string $fuente, int $id, int $causalAnaliticaId, string $comentario, FiltrosRotacion $alcance): void
    {
        $this->autorizar($actor);
        $this->exigirComentario($comentario);
        $nueva = CausalAnalitica::query()->whereKey($causalAnaliticaId)->where('activo', true)->first()
            ?? throw ValidationException::withMessages(['causal_analitica_id' => 'Elige una causal homologada activa.']);

        DB::transaction(function () use ($actor, $fuente, $id, $nueva, $comentario, $alcance): void {
            $sujeto = $this->sujeto($fuente, $id, $alcance);
            $antes = $sujeto->causal_analitica_id !== null ? CausalAnalitica::query()->whereKey($sujeto->causal_analitica_id)->value('nombre') : $this->causalHeredada($sujeto);

            if ((int) $sujeto->causal_analitica_id === $nueva->id) {
                throw ValidationException::withMessages(['causal_analitica_id' => 'La baja ya tiene esa causal homologada.']);
            }

            $sujeto->forceFill(['causal_analitica_id' => $nueva->id])->save();
            $this->bitacora($actor, $sujeto instanceof Baja ? 'baja' : 'baja_historica', $sujeto->id, 'causal_homologada', $antes, $nueva->nombre, $comentario);
        });
    }

    public function completarFechaIngreso(User $actor, int $historicaId, string $fecha, string $comentario, FiltrosRotacion $alcance): void
    {
        $this->autorizar($actor);
        $this->exigirComentario($comentario);
        $nueva = CarbonImmutable::createFromFormat('!Y-m-d', $fecha) ?: throw ValidationException::withMessages(['fecha_ingreso' => 'Fecha inválida.']);

        DB::transaction(function () use ($actor, $historicaId, $nueva, $comentario, $alcance): void {
            $historica = $this->sujeto('historico', $historicaId, $alcance);

            if (! $historica instanceof BajaHistorica) {
                throw ValidationException::withMessages(['fecha_ingreso' => 'Solo se completa la fecha de ingreso de una baja histórica.']);
            }

            if ($nueva->gt($historica->fecha_baja)) {
                throw ValidationException::withMessages(['fecha_ingreso' => 'La fecha de ingreso no puede ser posterior al último día laborado.']);
            }

            $antes = $historica->fecha_ingreso?->toDateString();
            $historica->forceFill(['fecha_ingreso' => $nueva->toDateString()])->save();
            $this->bitacora($actor, 'baja_historica', $historica->id, 'fecha_ingreso', $antes, $nueva->toDateString(), $comentario);
        });
    }

    /**
     * Cambia a qué categoría de análisis va una causal del catálogo. Devuelve
     * cuántas bajas del sistema se reclasifican (sin contar las corregidas a
     * mano, que conservan su corrección).
     */
    public function mapearCausal(User $actor, CausalRotacion $causal, ?int $causalAnaliticaId, string $comentario): int
    {
        $this->autorizar($actor);
        $this->exigirComentario($comentario);
        $nueva = $causalAnaliticaId !== null ? CausalAnalitica::query()->whereKey($causalAnaliticaId)->where('activo', true)->first() : null;

        if ($causalAnaliticaId !== null && $nueva === null) {
            throw ValidationException::withMessages(['causal_analitica_id' => 'Elige una causal homologada activa.']);
        }

        return DB::transaction(function () use ($actor, $causal, $nueva, $comentario): int {
            $antes = $causal->causal_analitica_id !== null ? CausalAnalitica::query()->whereKey($causal->causal_analitica_id)->value('nombre') : null;
            $causal->forceFill(['causal_analitica_id' => $nueva?->id])->save();
            $this->bitacora($actor, 'causal_rotacion', $causal->id, 'causal_homologada', $antes, $nueva?->nombre, $comentario);

            return $this->afectadasPorMapeo($causal);
        });
    }

    /** Bajas del sistema que siguen el mapeo de la causal (sin corrección propia). */
    public function afectadasPorMapeo(CausalRotacion $causal): int
    {
        return Baja::query()->where('causal_rotacion_id', $causal->id)->whereNull('causal_analitica_id')->where('es_rotacion', true)->where('estatus', '!=', EstatusBaja::Cancelada->value)->count();
    }

    /**
     * Crea o edita una categoría de análisis. Cambiar su clasificación mueve
     * todas sus bajas de voluntarias a involuntarias (o al revés): por eso
     * exige comentario y queda en la bitácora.
     *
     * @param  array{nombre: string, clasificacion: string, es_estructural?: bool, descripcion?: string|null, activo?: bool}  $datos
     */
    public function guardarCategoria(User $actor, ?CausalAnalitica $categoria, array $datos, string $comentario): CausalAnalitica
    {
        $this->autorizar($actor);
        $this->exigirComentario($comentario);
        $clasificacion = ClasificacionSalida::tryFrom($datos['clasificacion']) ?? throw ValidationException::withMessages(['clasificacion' => 'Elige una clasificación.']);

        return DB::transaction(function () use ($actor, $categoria, $datos, $comentario, $clasificacion): CausalAnalitica {
            if ($categoria === null) {
                $categoria = CausalAnalitica::query()->create([
                    'clave' => $this->claveNueva($datos['nombre']),
                    'nombre' => $datos['nombre'],
                    'clasificacion' => $clasificacion,
                    'es_estructural' => (bool) ($datos['es_estructural'] ?? false),
                    'descripcion' => $datos['descripcion'] ?? null,
                    'orden' => (int) CausalAnalitica::query()->max('orden') + 1,
                    'activo' => true,
                ]);
                $this->bitacora($actor, 'causal_analitica', $categoria->id, 'alta', null, sprintf('%s (%s)', $categoria->nombre, $clasificacion->etiqueta()), $comentario);

                return $categoria;
            }

            if ($categoria->clave === CausalAnalitica::SIN_CAUSAL && $clasificacion !== ClasificacionSalida::SinClasificar) {
                throw ValidationException::withMessages(['clasificacion' => '«Sin causal registrada» siempre es sin clasificar.']);
            }

            $cambios = [];

            foreach (['nombre' => $datos['nombre'], 'clasificacion' => $clasificacion, 'es_estructural' => (bool) ($datos['es_estructural'] ?? $categoria->es_estructural), 'activo' => (bool) ($datos['activo'] ?? $categoria->activo), 'descripcion' => $datos['descripcion'] ?? $categoria->descripcion] as $campo => $valor) {
                $actual = $categoria->{$campo};
                $texto = fn (mixed $v): ?string => $v instanceof ClasificacionSalida ? $v->etiqueta() : (is_bool($v) ? ($v ? 'Sí' : 'No') : ($v === null ? null : (string) $v));

                if ($texto($actual) !== $texto($valor)) {
                    $cambios[$campo] = [$texto($actual), $texto($valor)];
                }
            }

            $categoria->update([
                'nombre' => $datos['nombre'],
                'clasificacion' => $clasificacion,
                'es_estructural' => (bool) ($datos['es_estructural'] ?? $categoria->es_estructural),
                'activo' => (bool) ($datos['activo'] ?? $categoria->activo),
                'descripcion' => $datos['descripcion'] ?? $categoria->descripcion,
            ]);

            foreach ($cambios as $campo => [$antes, $despues]) {
                $this->bitacora($actor, 'causal_analitica', $categoria->id, $campo, $antes, $despues, $comentario);
            }

            return $categoria;
        });
    }

    /**
     * Liga una histórica con la baja del sistema que es el MISMO evento: la
     * histórica deja de contar (nunca se cuenta dos veces) y se conserva.
     */
    public function vincular(User $actor, int $historicaId, int $bajaId, string $comentario, FiltrosRotacion $alcance): void
    {
        $this->autorizar($actor);
        $this->exigirComentario($comentario);

        DB::transaction(function () use ($actor, $historicaId, $bajaId, $comentario, $alcance): void {
            $historica = $this->sujeto('historico', $historicaId, $alcance);
            $baja = $this->sujeto('sistema', $bajaId, $alcance);

            if (! $historica instanceof BajaHistorica || ! $baja instanceof Baja) {
                throw ValidationException::withMessages(['baja_id' => 'No se encontró el par a ligar.']);
            }

            $historica->forceFill(['baja_id' => $baja->id, 'estado' => BajaHistorica::DUPLICADA])->save();
            $this->bitacora($actor, 'baja_historica', $historica->id, 'estado', 'Válida', sprintf('Duplicada de %s', $baja->folio), $comentario);
        });
    }

    /** Excluye (o restablece) una histórica sin borrarla. */
    public function cambiarEstadoHistorica(User $actor, int $historicaId, string $estado, string $comentario, FiltrosRotacion $alcance): void
    {
        $this->autorizar($actor);
        $this->exigirComentario($comentario);

        if (! in_array($estado, [BajaHistorica::VALIDA, BajaHistorica::DESCARTADA], true)) {
            throw ValidationException::withMessages(['estado' => 'Estado no válido.']);
        }

        DB::transaction(function () use ($actor, $historicaId, $estado, $comentario, $alcance): void {
            $historica = BajaHistorica::query()->whereKey($historicaId)->enAlcanceRotacion($alcance->sucursales, $alcance->externas)->lockForUpdate()->first()
                ?? throw ValidationException::withMessages(['id' => 'La baja histórica no existe o está fuera de tu alcance.']);
            $antes = $historica->estado;
            $historica->forceFill(['estado' => $estado, 'baja_id' => $estado === BajaHistorica::VALIDA ? null : $historica->baja_id])->save();
            $this->bitacora($actor, 'baja_historica', $historica->id, 'estado', $antes, $estado, $comentario);
        });
    }

    private function sujeto(string $fuente, int $id, FiltrosRotacion $alcance): Baja|BajaHistorica
    {
        $consulta = $fuente === 'sistema'
            ? Baja::query()->whereIn('sucursal_id', $alcance->sucursales->all())
            : BajaHistorica::query()->where('estado', BajaHistorica::VALIDA)->enAlcanceRotacion($alcance->sucursales, $alcance->externas);

        return $consulta->whereKey($id)->lockForUpdate()->first()
            ?? throw ValidationException::withMessages(['id' => 'La baja no existe o está fuera de tu alcance.']);
    }

    private function causalHeredada(Baja|BajaHistorica $sujeto): ?string
    {
        if ($sujeto instanceof Baja && $sujeto->causal_rotacion_id !== null) {
            $analitica = CausalRotacion::query()->whereKey($sujeto->causal_rotacion_id)->value('causal_analitica_id');

            return $analitica !== null ? CausalAnalitica::query()->whereKey($analitica)->value('nombre') : 'Sin homologar';
        }

        return 'Sin causal registrada';
    }

    private function bitacora(User $actor, string $tipo, int $id, string $campo, ?string $antes, ?string $despues, string $comentario): void
    {
        RotacionCorreccion::query()->create([
            'sujeto_tipo' => $tipo,
            'sujeto_id' => $id,
            'campo' => $campo,
            'antes' => $antes !== null ? mb_substr($antes, 0, 500) : null,
            'despues' => $despues !== null ? mb_substr($despues, 0, 500) : null,
            'comentario' => mb_substr(trim($comentario), 0, 1000),
            'usuario_id' => $actor->id,
        ]);
    }

    private function claveNueva(string $nombre): string
    {
        $base = mb_substr(str_replace(' ', '_', BajaHistorica::normalizar($nombre)), 0, 50) ?: 'causal';
        $clave = $base;
        $n = 2;

        while (CausalAnalitica::query()->where('clave', $clave)->exists()) {
            $clave = sprintf('%s_%d', $base, $n++);
        }

        return $clave;
    }

    private function autorizar(User $actor): void
    {
        if (! $actor->can(self::PERMISO)) {
            throw new AuthorizationException('No tienes permiso para corregir información de rotación.');
        }
    }

    private function exigirComentario(string $comentario): void
    {
        if (mb_strlen(trim($comentario)) < 5) {
            throw ValidationException::withMessages(['comentario' => 'Escribe por qué se corrige (al menos 5 caracteres): queda en la bitácora.']);
        }
    }
}
