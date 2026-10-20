<?php

namespace App\Services\CierreLaboral;

use App\Enums\TipoBaja;
use App\Models\CausalRotacion;
use App\Models\User;
use App\Services\Auditoria\AuditoriaService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Causales de rotación: el desglose («motivo») de los tres tipos de baja
 * (renuncia, rescisión, fin de contrato). RH las administra desde Bajas y
 * finiquitos → «Causales de rotación»; la baja (CierreLaboralService) y la
 * solicitud de baja (SolicitudesService) las validan aquí.
 *
 * Nunca se borra una causal que ya usó alguna baja: se desactiva (deja de
 * ofrecerse y el historial la sigue mostrando).
 */
class CausalRotacionService
{
    public const PERMISO_ADMINISTRAR = 'finiquitos.causales_rotacion';

    public function __construct(private readonly AuditoriaService $auditoria) {}

    /**
     * Causal elegida para una baja de este tipo. Debe existir, estar activa
     * y pertenecer al tipo. Es obligatoria cuando el tipo tiene causales
     * activas en el catálogo (siempre, una vez sembrado).
     */
    public function resolver(TipoBaja $tipo, mixed $causalId): ?CausalRotacion
    {
        if ($causalId === null || $causalId === '') {
            if (CausalRotacion::query()->activas()->where('tipo', $tipo->value)->exists()) {
                throw ValidationException::withMessages(['causal_rotacion_id' => sprintf('Elige el motivo (causal de rotación) de la %s.', mb_strtolower($tipo->etiqueta()))]);
            }

            return null;
        }

        $causal = is_numeric($causalId) ? CausalRotacion::query()->where('id', (int) $causalId)->first() : null;

        if ($causal === null || ! $causal->activo) {
            throw ValidationException::withMessages(['causal_rotacion_id' => 'Ese motivo no existe o ya no está activo.']);
        }

        if ($causal->tipo !== $tipo) {
            throw ValidationException::withMessages(['causal_rotacion_id' => sprintf('«%s» es un motivo de %s, no de %s.', $causal->nombre, mb_strtolower($causal->tipo->etiqueta()), mb_strtolower($tipo->etiqueta()))]);
        }

        return $causal;
    }

    /**
     * Causal del sistema por su clave estable (p. ej. la de no renovación
     * desde la evaluación del periodo de prueba). null si RH la quitó del
     * catálogo o no está activa.
     */
    public function porClave(string $clave, TipoBaja $tipo): ?CausalRotacion
    {
        return CausalRotacion::query()->activas()->where('clave', $clave)->where('tipo', $tipo->value)->first();
    }

    /**
     * Tipos que puede elegir el usuario al iniciar una baja: RH todos; un
     * jefe/gerente los de ciclo_laboral.cierre.causas_solicitables (valores
     * anteriores guardados se traducen a su tipo vigente).
     *
     * @return list<TipoBaja>
     */
    public function tiposPermitidos(User $usuario): array
    {
        if ($usuario->can(CierreLaboralService::PERMISO_GESTIONAR)) {
            return TipoBaja::cases();
        }

        $permitidos = [];

        foreach ((array) config('ciclo_laboral.cierre.causas_solicitables', []) as $valor) {
            $tipo = is_string($valor) ? TipoBaja::desdeValor($valor) : null;

            if ($tipo !== null && ! in_array($tipo, $permitidos, true)) {
                $permitidos[] = $tipo;
            }
        }

        return array_values(array_filter(TipoBaja::cases(), fn (TipoBaja $t): bool => in_array($t, $permitidos, true)));
    }

    /**
     * Opciones del diálogo «Iniciar baja»: los tipos permitidos con sus
     * causales activas en orden.
     *
     * @return list<array{value: string, etiqueta: string, descripcion: string, causales: list<array{id: int, nombre: string, descripcion: string|null}>}>
     */
    public function opcionesIniciarBaja(User $usuario): array
    {
        $causales = CausalRotacion::query()->activas()->ordenadas()->get()->groupBy(fn (CausalRotacion $c): string => $c->tipo->value);

        return array_map(fn (TipoBaja $t): array => [
            'value' => $t->value,
            'etiqueta' => $t->etiqueta(),
            'descripcion' => $t->descripcion(),
            'causales' => array_values(($causales->get($t->value) ?? collect())->map(fn (CausalRotacion $c): array => [
                'id' => $c->id,
                'nombre' => $c->nombre,
                'descripcion' => $c->descripcion,
            ])->all()),
        ], $this->tiposPermitidos($usuario));
    }

    /**
     * Catálogo completo para la administración (activas e inactivas), con
     * cuántas bajas usan cada causal.
     *
     * @return list<array{id: int, tipo: string, tipo_etiqueta: string, clave: string|null, nombre: string, descripcion: string|null, excluye_indemnizacion: bool, activo: bool, orden: int, usos: int}>
     */
    public function listado(): array
    {
        return array_values(CausalRotacion::query()
            ->withCount(['cierres', 'solicitudes'])
            ->ordenadas()
            ->get()
            ->map(fn (CausalRotacion $c): array => [
                'id' => $c->id,
                'tipo' => $c->tipo->value,
                'tipo_etiqueta' => $c->tipo->etiqueta(),
                'clave' => $c->clave,
                'nombre' => $c->nombre,
                'descripcion' => $c->descripcion,
                'excluye_indemnizacion' => $c->excluye_indemnizacion,
                'activo' => $c->activo,
                'orden' => $c->orden,
                // La solicitud y su cierre son la misma baja: se cuenta la mayor.
                'usos' => max((int) $c->getAttribute('cierres_count'), (int) $c->getAttribute('solicitudes_count')),
            ])
            ->all());
    }

    /**
     * @param  array{tipo: string, nombre: string, descripcion?: string|null, excluye_indemnizacion?: bool|null, activo?: bool|null}  $datos
     */
    public function crear(array $datos, User $actor): CausalRotacion
    {
        $tipo = TipoBaja::from($datos['tipo']);
        $nombre = $this->nombreLimpio($datos['nombre']);
        $this->exigirNombreLibre($tipo, $nombre, null);

        $causal = CausalRotacion::query()->create([
            'tipo' => $tipo,
            'nombre' => $nombre,
            'descripcion' => $this->textoOpcional($datos['descripcion'] ?? null),
            'excluye_indemnizacion' => (bool) ($datos['excluye_indemnizacion'] ?? false),
            'activo' => (bool) ($datos['activo'] ?? true),
            'orden' => ((int) CausalRotacion::query()->where('tipo', $tipo->value)->max('orden')) + 10,
        ]);

        $this->auditoria->registrar('causal_rotacion_creada', $causal, $actor, ['tipo' => $tipo->value, 'nombre' => $nombre]);

        return $causal;
    }

    /**
     * Edita nombre, descripción y la exclusión de indemnización. El tipo no
     * cambia si la causal ya se usó (reclasificaría bajas históricas).
     *
     * @param  array{tipo: string, nombre: string, descripcion?: string|null, excluye_indemnizacion?: bool|null, activo?: bool|null}  $datos
     */
    public function actualizar(CausalRotacion $causal, array $datos, User $actor): CausalRotacion
    {
        $tipo = TipoBaja::from($datos['tipo']);

        if ($tipo !== $causal->tipo && $causal->enUso()) {
            throw ValidationException::withMessages(['tipo' => 'Este motivo ya se usó en bajas: no se puede cambiar de tipo. Desactívalo y crea uno nuevo.']);
        }

        $nombre = $this->nombreLimpio($datos['nombre']);
        $this->exigirNombreLibre($tipo, $nombre, $causal->id);
        $antes = $causal->only(['tipo', 'nombre', 'descripcion', 'excluye_indemnizacion', 'activo']);

        $causal->update([
            'tipo' => $tipo,
            'nombre' => $nombre,
            'descripcion' => $this->textoOpcional($datos['descripcion'] ?? null),
            'excluye_indemnizacion' => (bool) ($datos['excluye_indemnizacion'] ?? false),
            'activo' => (bool) ($datos['activo'] ?? $causal->activo),
        ]);

        $this->auditoria->registrar('causal_rotacion_actualizada', $causal, $actor, ['antes' => $antes, 'despues' => $causal->only(['tipo', 'nombre', 'descripcion', 'excluye_indemnizacion', 'activo'])]);

        return $causal->refresh();
    }

    public function cambiarActivo(CausalRotacion $causal, bool $activo, User $actor): CausalRotacion
    {
        $causal->update(['activo' => $activo]);
        $this->auditoria->registrar($activo ? 'causal_rotacion_activada' : 'causal_rotacion_desactivada', $causal, $actor, ['nombre' => $causal->nombre]);

        return $causal->refresh();
    }

    /**
     * Sube o baja la causal un lugar dentro de su tipo (intercambia el orden
     * con la vecina). Renumera el tipo de 10 en 10 para evitar empates.
     */
    public function mover(CausalRotacion $causal, string $direccion, User $actor): void
    {
        DB::transaction(function () use ($causal, $direccion): void {
            $hermanas = CausalRotacion::query()->where('tipo', $causal->tipo->value)->ordenadas()->lockForUpdate()->get()->values();
            $indice = $hermanas->search(fn (CausalRotacion $c): bool => $c->id === $causal->id);

            if (! is_int($indice)) {
                return;
            }

            $destino = $direccion === 'arriba' ? $indice - 1 : $indice + 1;

            if ($destino < 0 || $destino >= $hermanas->count()) {
                return;
            }

            $ids = $hermanas->pluck('id')->all();
            [$ids[$indice], $ids[$destino]] = [$ids[$destino], $ids[$indice]];

            foreach ($ids as $posicion => $id) {
                CausalRotacion::query()->where('id', $id)->update(['orden' => ($posicion + 1) * 10]);
            }
        });

        $this->auditoria->registrar('causal_rotacion_reordenada', $causal, $actor, ['nombre' => $causal->nombre, 'direccion' => $direccion]);
    }

    /**
     * Solo una causal que NINGUNA baja ha usado se elimina; si ya se usó,
     * se desactiva (el historial la conserva).
     */
    public function eliminar(CausalRotacion $causal, User $actor): void
    {
        if ($causal->enUso()) {
            throw ValidationException::withMessages(['causal' => 'Este motivo ya se usó en bajas: no se puede eliminar. Desactívalo para que ya no se ofrezca.']);
        }

        if ($causal->clave !== null) {
            throw ValidationException::withMessages(['causal' => 'Este motivo lo usa el sistema (p. ej. la no renovación del periodo de prueba): desactívalo en lugar de eliminarlo.']);
        }

        $this->auditoria->registrar('causal_rotacion_eliminada', $causal, $actor, ['tipo' => $causal->tipo->value, 'nombre' => $causal->nombre]);
        $causal->delete();
    }

    /**
     * Catálogo inicial (config/causales_rotacion.php): agrega las que falten
     * por clave y nunca modifica una que RH ya editó o desactivó.
     */
    public function sembrarIniciales(): int
    {
        $creadas = 0;
        $orden = 0;

        foreach ((array) config('causales_rotacion.iniciales', []) as $tipo => $causales) {
            foreach ((array) $causales as $clave => $datos) {
                $orden += 10;
                $causal = CausalRotacion::query()->firstOrCreate(['clave' => (string) $clave], [
                    'tipo' => TipoBaja::from((string) $tipo),
                    'nombre' => (string) $datos['nombre'],
                    'excluye_indemnizacion' => (bool) ($datos['excluye_indemnizacion'] ?? false),
                    'activo' => true,
                    'orden' => $orden,
                ]);

                $creadas += $causal->wasRecentlyCreated ? 1 : 0;
            }
        }

        return $creadas;
    }

    private function exigirNombreLibre(TipoBaja $tipo, string $nombre, ?int $ignorar): void
    {
        $existe = CausalRotacion::query()
            ->where('tipo', $tipo->value)
            ->where('nombre', $nombre)
            ->when($ignorar !== null, fn ($q) => $q->where('id', '!=', $ignorar))
            ->exists();

        if ($existe) {
            throw ValidationException::withMessages(['nombre' => sprintf('Ya existe el motivo «%s» en %s.', $nombre, mb_strtolower($tipo->etiqueta()))]);
        }
    }

    private function nombreLimpio(string $nombre): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $nombre));
    }

    private function textoOpcional(mixed $texto): ?string
    {
        $texto = is_string($texto) ? trim($texto) : '';

        return $texto === '' ? null : $texto;
    }
}
