<?php

namespace App\Services\CierreLaboral;

use App\Enums\DocumentoSalida;
use App\Enums\FormaSalida;
use App\Enums\TipoBaja;
use App\Models\CausalRotacion;
use App\Models\User;
use App\Services\Auditoria\AuditoriaService;
use App\Services\Bajas\CatalogoBajasService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Causales de baja (segundo nivel del catálogo, docs/BAJAS_Y_CAUSALES.md):
 * 17 claves RV-01…RE-05 de la especificación de RH. RH las administra desde
 * Bajas y finiquitos → «Causales» (también accesible desde Configuración →
 * «Catálogo de bajas»: es la misma pantalla). El cierre laboral, la
 * solicitud de baja y el módulo de bajas las validan aquí.
 *
 * RN-15: nunca se borra una causal; se desactiva (deja de ofrecerse y el
 * historial la sigue mostrando). Una causal nueva que agregue RH recibe la
 * siguiente clave de su forma (p. ej. RV-07).
 */
class CausalRotacionService
{
    public const PERMISO_ADMINISTRAR = 'finiquitos.causales_rotacion';

    public function __construct(
        private readonly AuditoriaService $auditoria,
        private readonly CatalogoBajasService $catalogo,
    ) {}

    /**
     * Causal elegida para una baja de este tipo: debe existir, estar activa y
     * pertenecer a la forma. Con $obligatoria (pantallas nuevas) no puede
     * faltar (RN-01); el atajo del ciclo laboral y la API móvil la aceptan
     * vacía y la baja queda «causal pendiente» para RH.
     */
    public function resolver(TipoBaja $tipo, mixed $causalId, bool $obligatoria = true): ?CausalRotacion
    {
        if ($causalId === null || $causalId === '') {
            if ($obligatoria && CausalRotacion::query()->activas()->where('tipo', $tipo->value)->exists()) {
                throw ValidationException::withMessages(['causal_rotacion_id' => sprintf('Elige la causal de la %s.', mb_strtolower($tipo->etiqueta()))]);
            }

            return null;
        }

        $causal = is_numeric($causalId) ? CausalRotacion::query()->where('id', (int) $causalId)->first() : null;

        if ($causal === null || ! $causal->activo || $causal->legado) {
            throw ValidationException::withMessages(['causal_rotacion_id' => 'Esa causal no existe o ya no está activa.']);
        }

        if ($causal->tipo !== $tipo) {
            throw ValidationException::withMessages(['causal_rotacion_id' => sprintf('«%s» es una causal de %s, no de %s.', $causal->nombre, mb_strtolower($causal->tipo->etiqueta()), mb_strtolower($tipo->etiqueta()))]);
        }

        return $causal;
    }

    /**
     * Causal del sistema por su clave estable (p. ej. NR-02 desde la
     * evaluación del periodo de prueba). null si RH la desactivó.
     */
    public function porClave(string $clave, TipoBaja $tipo): ?CausalRotacion
    {
        return CausalRotacion::query()->activas()->where('clave', $clave)->where('tipo', $tipo->value)->first();
    }

    /**
     * Formas que puede elegir el usuario al iniciar una baja: RH todas; un
     * jefe/gerente las de ciclo_laboral.cierre.causas_solicitables (valores
     * anteriores guardados se traducen a su tipo vigente).
     *
     * @return list<TipoBaja>
     */
    public function tiposPermitidos(User $usuario): array
    {
        if ($usuario->can(CierreLaboralService::PERMISO_GESTIONAR) || $usuario->can('bajas.gestionar')) {
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
     * @return list<FormaSalida>
     */
    public function formasPermitidas(User $usuario): array
    {
        $tipos = $this->tiposPermitidos($usuario);

        return array_values(array_filter(FormaSalida::cases(), fn (FormaSalida $f): bool => in_array($f->tipoBaja(), $tipos, true)));
    }

    /**
     * Opciones del diálogo «Iniciar baja» (atajo del ciclo laboral): los
     * tipos permitidos (en orden de forma: RV, NR, RE) con sus causales
     * activas, su ayuda y sus reglas.
     *
     * @return list<array{value: string, forma: string, etiqueta: string, resumen: string, descripcion: string, token: string, causales: list<array<string, mixed>>}>
     */
    public function opcionesIniciarBaja(User $usuario): array
    {
        return array_map(fn (array $forma): array => [
            'value' => $forma['tipo_baja'],
            'forma' => $forma['value'],
            'etiqueta' => $forma['etiqueta'],
            'resumen' => $forma['resumen'],
            'descripcion' => $forma['descripcion'],
            'token' => $forma['token'],
            'causales' => $forma['causales'],
        ], $this->catalogo->opciones($this->formasPermitidas($usuario)));
    }

    /**
     * Catálogo completo para la administración (activas e inactivas; sin las
     * causales de legado), con cuántas bajas usan cada causal.
     *
     * @return list<array<string, mixed>>
     */
    public function listado(): array
    {
        return array_values(CausalRotacion::query()
            ->delCatalogo()
            ->withCount(['cierres', 'solicitudes', 'bajas'])
            ->get()
            ->sortBy(fn (CausalRotacion $c): string => sprintf('%02d-%06d-%s', $c->forma()->orden(), $c->orden, $c->nombre))
            ->map(fn (CausalRotacion $c): array => [
                'id' => $c->id,
                'tipo' => $c->tipo->value,
                'tipo_etiqueta' => $c->tipo->etiqueta(),
                'forma' => $c->forma()->value,
                'forma_etiqueta' => $c->forma()->etiqueta(),
                'clave' => $c->clave,
                'nombre' => $c->nombre,
                'descripcion' => $c->descripcion,
                'requiere_juridico' => $c->requiere_juridico,
                'comentario_obligatorio' => $c->comentario_obligatorio,
                'alerta' => $c->alerta,
                'fundamento_legal' => $c->fundamento_legal,
                'genera' => DocumentoSalida::deForma($c->forma())->etiqueta(),
                'excluye_indemnizacion' => $c->excluye_indemnizacion,
                'implica_indemnizacion' => $c->implica_indemnizacion,
                'integrar_expediente_probatorio' => $c->requiere_juridico,
                'activo' => $c->activo,
                'orden' => $c->orden,
                // La solicitud, su cierre y su baja son la misma baja: se cuenta la mayor.
                'usos' => max((int) $c->getAttribute('cierres_count'), (int) $c->getAttribute('solicitudes_count'), (int) $c->getAttribute('bajas_count')),
            ])
            ->all());
    }

    /**
     * @param  array<string, mixed>  $datos  tipo, nombre, descripcion?, requiere_juridico?, comentario_obligatorio?, alerta?, excluye_indemnizacion?, activo?
     */
    public function crear(array $datos, User $actor): CausalRotacion
    {
        $tipo = TipoBaja::from((string) $datos['tipo']);
        $forma = $tipo->forma();
        $nombre = $this->nombreLimpio((string) $datos['nombre']);
        $this->exigirNombreLibre($tipo, $nombre, null);

        $causal = DB::transaction(fn (): CausalRotacion => CausalRotacion::query()->create([
            'tipo' => $tipo,
            'forma_salida' => $forma,
            'clave' => $this->siguienteClave($forma),
            'nombre' => $nombre,
            'descripcion' => $this->textoOpcional($datos['descripcion'] ?? null),
            'requiere_juridico' => (bool) ($datos['requiere_juridico'] ?? false),
            'comentario_obligatorio' => (bool) ($datos['comentario_obligatorio'] ?? $forma === FormaSalida::Rescision),
            'alerta' => $this->textoOpcional($datos['alerta'] ?? null),
            'fundamento_legal' => $this->textoOpcional($datos['fundamento_legal'] ?? null),
            'excluye_indemnizacion' => (bool) ($datos['excluye_indemnizacion'] ?? false),
            'implica_indemnizacion' => (bool) ($datos['implica_indemnizacion'] ?? false),
            'activo' => (bool) ($datos['activo'] ?? true),
            'legado' => false,
            'orden' => ((int) CausalRotacion::query()->where('tipo', $tipo->value)->max('orden')) + 10,
        ]));

        $this->auditoria->registrar('causal_rotacion_creada', $causal, $actor, ['clave' => $causal->clave, 'nombre' => $nombre]);

        return $causal;
    }

    /**
     * Edita nombre, ayuda y reglas. La forma no cambia si la causal ya se usó
     * (reclasificaría bajas históricas). La clave nunca cambia.
     *
     * @param  array<string, mixed>  $datos
     */
    public function actualizar(CausalRotacion $causal, array $datos, User $actor): CausalRotacion
    {
        $tipo = TipoBaja::from((string) $datos['tipo']);

        if ($tipo !== $causal->tipo && $causal->enUso()) {
            throw ValidationException::withMessages(['tipo' => 'Esta causal ya se usó en bajas: no se puede cambiar de forma de salida. Desactívala y crea una nueva.']);
        }

        $nombre = $this->nombreLimpio((string) $datos['nombre']);
        $this->exigirNombreLibre($tipo, $nombre, $causal->id);
        $campos = ['tipo', 'nombre', 'descripcion', 'requiere_juridico', 'comentario_obligatorio', 'alerta', 'fundamento_legal', 'excluye_indemnizacion', 'implica_indemnizacion', 'activo'];
        $antes = $causal->only($campos);

        $causal->update([
            'tipo' => $tipo,
            'forma_salida' => $tipo->forma(),
            'clave' => $tipo === $causal->tipo ? $causal->clave : $this->siguienteClave($tipo->forma()),
            'nombre' => $nombre,
            'descripcion' => $this->textoOpcional($datos['descripcion'] ?? null),
            'requiere_juridico' => (bool) ($datos['requiere_juridico'] ?? $causal->requiere_juridico),
            'comentario_obligatorio' => (bool) ($datos['comentario_obligatorio'] ?? $causal->comentario_obligatorio),
            'alerta' => $this->textoOpcional($datos['alerta'] ?? null),
            'fundamento_legal' => $this->textoOpcional($datos['fundamento_legal'] ?? null),
            'excluye_indemnizacion' => (bool) ($datos['excluye_indemnizacion'] ?? false),
            'implica_indemnizacion' => (bool) ($datos['implica_indemnizacion'] ?? false),
            'activo' => (bool) ($datos['activo'] ?? $causal->activo),
        ]);

        $this->auditoria->registrar('causal_rotacion_actualizada', $causal, $actor, ['antes' => $antes, 'despues' => $causal->only($campos)]);

        return $causal->refresh();
    }

    public function cambiarActivo(CausalRotacion $causal, bool $activo, User $actor): CausalRotacion
    {
        $causal->update(['activo' => $activo]);
        $this->auditoria->registrar($activo ? 'causal_rotacion_activada' : 'causal_rotacion_desactivada', $causal, $actor, ['clave' => $causal->clave, 'nombre' => $causal->nombre]);

        return $causal->refresh();
    }

    /**
     * Sube o baja la causal un lugar dentro de su forma (intercambia el orden
     * con la vecina). Renumera la forma de 10 en 10 para evitar empates.
     */
    public function mover(CausalRotacion $causal, string $direccion, User $actor): void
    {
        DB::transaction(function () use ($causal, $direccion): void {
            $hermanas = CausalRotacion::query()->delCatalogo()->where('tipo', $causal->tipo->value)->ordenadas()->lockForUpdate()->get()->values();
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
     * Catálogo inicial (config/bajas.php): agrega lo que falte por clave y
     * nunca modifica lo que RH ya editó o desactivó.
     */
    public function sembrarIniciales(): int
    {
        return $this->catalogo->sembrar()['causales'];
    }

    /**
     * Siguiente clave libre de la forma (RV-07, NR-07…).
     */
    private function siguienteClave(FormaSalida $forma): string
    {
        $mayor = CausalRotacion::query()->where('clave', 'like', $forma->value.'-%')->pluck('clave')
            ->map(fn (?string $c): int => (int) substr((string) $c, 3))
            ->max();

        return sprintf('%s-%02d', $forma->value, ((int) $mayor) + 1);
    }

    private function exigirNombreLibre(TipoBaja $tipo, string $nombre, ?int $ignorar): void
    {
        $existe = CausalRotacion::query()
            ->delCatalogo()
            ->where('tipo', $tipo->value)
            ->where('nombre', $nombre)
            ->when($ignorar !== null, fn ($q) => $q->where('id', '!=', $ignorar))
            ->exists();

        if ($existe) {
            throw ValidationException::withMessages(['nombre' => sprintf('Ya existe la causal «%s» en %s.', $nombre, mb_strtolower($tipo->etiqueta()))]);
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
