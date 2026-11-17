<?php

namespace App\Services\RecepcionDocumental;

use App\Models\Empresa;
use App\Models\RecepcionDocumentalCatalogo;
use App\Models\Sucursal;
use App\Models\User;
use App\Services\Auditoria\AuditoriaService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Configuración del catálogo de «lo que recibo» (permiso
 * recepcion_documental.configurar). Cada cambio:
 *
 *  1. se guarda en una transacción con la bitácora (antes / después);
 *  2. recalcula el corte del MES EN CURSO (si está cerrado no se toca: un
 *     mes cerrado nunca cambia). Un recalculo fallido no deshace el cambio
 *     del catálogo: se registra y la materialización diaria lo reintenta.
 *
 * Vista previa: calcula, SIN guardar, cuánto se esperaría en un mes con una
 * configuración en borrador.
 */
class ConfiguracionCatalogoRecepcionService
{
    public function __construct(
        private readonly CatalogoReglasRecepcion $catalogo,
        private readonly RecepcionDocumentalService $recepcion,
        private readonly AuditoriaService $auditoria,
    ) {}

    /**
     * @param  array{nombre?: string|null, descripcion?: string|null, documentos?: list<string>|null, activa?: bool|null, sucursal_ids?: list<int>|null, empresa_ids?: list<int>|null}  $datos
     * @return array{entrada: RecepcionDocumentalCatalogo, recalculo: array{periodo: string, recalculado: bool, cerrado: bool, nuevos: int, no_aplica: int, reactivados: int}|null}
     */
    public function actualizar(RecepcionDocumentalCatalogo $entrada, array $datos, User $actor): array
    {
        $cambios = $this->normalizar($entrada, $datos);

        $actualizada = DB::transaction(function () use ($entrada, $cambios, $actor): RecepcionDocumentalCatalogo {
            $actual = RecepcionDocumentalCatalogo::query()->lockForUpdate()->findOrFail($entrada->id);
            $antes = $this->instantanea($actual);
            $actual->fill([...$cambios, 'actualizado_por' => $actor->id]);

            if (! $actual->isDirty(array_keys($cambios))) {
                return $actual;
            }

            $actual->save();
            $this->auditoria->registrar('recepcion_documental_catalogo_actualizado', $actual, $actor, [
                'regla' => $actual->regla->value,
                'antes' => $antes,
                'despues' => $this->instantanea($actual),
            ]);

            return $actual;
        });

        return ['entrada' => $actualizada, 'recalculo' => $actualizada->wasChanged() ? $this->recalcularMesEnCurso($actor) : null];
    }

    /**
     * Nuevo orden de las entradas (ids en el orden deseado).
     *
     * @param  list<int>  $ids
     */
    public function reordenar(array $ids, User $actor): void
    {
        $entradas = RecepcionDocumentalCatalogo::query()->get(['id', 'orden', 'regla'])->keyBy('id');
        $ids = array_values(array_unique(array_filter($ids, fn (int $id): bool => $entradas->has($id))));

        if (count($ids) !== $entradas->count()) {
            throw ValidationException::withMessages(['ids' => 'Manda todas las entradas del catálogo en el nuevo orden.']);
        }

        DB::transaction(function () use ($ids, $entradas, $actor): void {
            $antes = $entradas->sortBy('orden')->map(fn (RecepcionDocumentalCatalogo $e): string => $e->regla->value)->values()->all();

            foreach ($ids as $posicion => $id) {
                RecepcionDocumentalCatalogo::query()->whereKey($id)->update(['orden' => $posicion + 1, 'actualizado_por' => $actor->id]);
            }

            $primera = RecepcionDocumentalCatalogo::query()->findOrFail($ids[0]);
            $this->auditoria->registrar('recepcion_documental_catalogo_reordenado', $primera, $actor, [
                'antes' => $antes,
                'despues' => array_map(fn (int $id): string => $entradas[$id]->regla->value, $ids),
            ]);
        });
    }

    /**
     * Cuánto se esperaría en el periodo con una configuración en BORRADOR
     * (no guarda nada). Sin borrador, usa la configuración guardada.
     *
     * @param  array{documentos?: list<string>|null, sucursal_ids?: list<int>|null, empresa_ids?: list<int>|null, activa?: bool|null}  $borrador
     * @return array{periodo: string, periodo_etiqueta: string, activa: bool, eventos: int, documentos: int, por_documento: list<array{clave: string, etiqueta: string, cantidad: int}>}
     */
    public function vistaPrevia(RecepcionDocumentalCatalogo $entrada, PeriodoRecepcion $periodo, array $borrador = []): array
    {
        $configurada = $this->catalogo->deEntrada($entrada);
        $posibles = $configurada->regla->documentosPosibles();
        $documentos = array_key_exists('documentos', $borrador) && is_array($borrador['documentos'])
            ? array_values(array_intersect(array_map('strval', $borrador['documentos']), array_map('strval', array_keys($posibles))))
            : $entrada->claves();
        $sucursales = array_key_exists('sucursal_ids', $borrador) ? array_map('intval', (array) $borrador['sucursal_ids']) : $entrada->sucursales();
        $empresas = array_key_exists('empresa_ids', $borrador) ? array_map('intval', (array) $borrador['empresa_ids']) : $entrada->empresas();
        $activa = array_key_exists('activa', $borrador) && $borrador['activa'] !== null ? (bool) $borrador['activa'] : $entrada->activa;

        $esperados = $activa && ! $periodo->esFuturo()
            ? ReglaConfigurada::filtrar($configurada->regla->esperados($periodo), $documentos, $sucursales, $empresas)
            : [];

        $porDocumento = [];
        $eventos = [];

        foreach ($esperados as $esperado) {
            $clave = $esperado->documento();
            $porDocumento[$clave] ??= ['clave' => $clave, 'etiqueta' => $posibles[$clave] ?? $esperado->tipoEtiqueta, 'cantidad' => 0];
            $porDocumento[$clave]['cantidad'] += max(1, $esperado->cantidad);
            $eventos[sprintf('%s|%d', $esperado->origen->getMorphClass(), (int) $esperado->origen->getKey())] = true;
        }

        return [
            'periodo' => $periodo->clave(),
            'periodo_etiqueta' => ucfirst($periodo->etiqueta()),
            'activa' => $activa,
            'eventos' => count($eventos),
            'documentos' => array_sum(array_column($porDocumento, 'cantidad')),
            'por_documento' => array_values($porDocumento),
        ];
    }

    /**
     * Vista previa de TODAS las entradas guardadas en el periodo.
     *
     * @return array<int, array{periodo: string, periodo_etiqueta: string, activa: bool, eventos: int, documentos: int, por_documento: list<array{clave: string, etiqueta: string, cantidad: int}>}>
     */
    public function vistaPreviaGeneral(PeriodoRecepcion $periodo): array
    {
        $resultado = [];

        foreach ($this->catalogo->entradas() as $configurada) {
            $resultado[$configurada->entrada->id] = $this->vistaPrevia($configurada->entrada, $periodo);
        }

        return $resultado;
    }

    /**
     * Recalcula el corte del mes en curso tras un cambio del catálogo. Un
     * corte cerrado no se toca (auditable: el mes cerrado conserva su foto).
     *
     * @return array{periodo: string, recalculado: bool, cerrado: bool, nuevos: int, no_aplica: int, reactivados: int}
     */
    public function recalcularMesEnCurso(?User $actor): array
    {
        $periodo = PeriodoRecepcion::actual();
        $base = ['periodo' => ucfirst($periodo->etiqueta()), 'recalculado' => false, 'cerrado' => false, 'nuevos' => 0, 'no_aplica' => 0, 'reactivados' => 0];

        try {
            ['corte' => $corte, 'conteo' => $conteo] = $this->recepcion->generar($periodo, $actor, true);
        } catch (Throwable $e) {
            Log::warning('Recepción documental: no se pudo recalcular el mes en curso tras cambiar el catálogo.', ['error' => $e->getMessage()]);

            return $base;
        }

        if ($conteo === null) {
            return [...$base, 'cerrado' => $corte->estaCerrado()];
        }

        return [...$base, 'recalculado' => true, 'nuevos' => $conteo['nuevos'], 'no_aplica' => $conteo['no_aplica'], 'reactivados' => $conteo['reactivados']];
    }

    /**
     * Valida y normaliza lo que se puede cambiar de una entrada.
     *
     * @param  array<string, mixed>  $datos
     * @return array<string, mixed>
     */
    private function normalizar(RecepcionDocumentalCatalogo $entrada, array $datos): array
    {
        $cambios = [];

        if (array_key_exists('nombre', $datos)) {
            $nombre = trim((string) $datos['nombre']);

            if ($nombre === '') {
                throw ValidationException::withMessages(['nombre' => 'Escribe el nombre de lo que se recibe.']);
            }

            $cambios['nombre'] = mb_substr($nombre, 0, 160);
        }

        if (array_key_exists('descripcion', $datos)) {
            $descripcion = trim((string) $datos['descripcion']);
            $cambios['descripcion'] = $descripcion !== '' ? mb_substr($descripcion, 0, 500) : null;
        }

        if (array_key_exists('documentos', $datos) && is_array($datos['documentos'])) {
            $posibles = array_map('strval', array_keys($this->catalogo->deEntrada($entrada)->regla->documentosPosibles()));
            $elegidos = array_values(array_unique(array_map('strval', $datos['documentos'])));
            $ajenos = array_diff($elegidos, $posibles, $entrada->claves());

            if ($ajenos !== []) {
                throw ValidationException::withMessages(['documentos' => sprintf('Esta regla no genera: %s.', implode(', ', $ajenos))]);
            }

            $cambios['documentos'] = $elegidos;
        }

        if (array_key_exists('activa', $datos) && $datos['activa'] !== null) {
            $cambios['activa'] = (bool) $datos['activa'];
        }

        $documentosFinales = $cambios['documentos'] ?? $entrada->claves();

        if (($cambios['activa'] ?? $entrada->activa) && $documentosFinales === []) {
            throw ValidationException::withMessages(['documentos' => 'Elige al menos un documento para dejar la entrada encendida.']);
        }

        if (array_key_exists('sucursal_ids', $datos)) {
            $ids = array_values(array_unique(array_map('intval', (array) $datos['sucursal_ids'])));

            if (count($ids) !== Sucursal::query()->whereIn('id', $ids)->count()) {
                throw ValidationException::withMessages(['sucursal_ids' => 'Alguna sucursal elegida no existe.']);
            }

            sort($ids);
            $cambios['sucursal_ids'] = $ids !== [] ? $ids : null;
        }

        if (array_key_exists('empresa_ids', $datos)) {
            $ids = array_values(array_unique(array_map('intval', (array) $datos['empresa_ids'])));

            if (count($ids) !== Empresa::query()->whereIn('id', $ids)->count()) {
                throw ValidationException::withMessages(['empresa_ids' => 'Alguna empresa elegida no existe.']);
            }

            sort($ids);
            $cambios['empresa_ids'] = $ids !== [] ? $ids : null;
        }

        return $cambios;
    }

    /**
     * @return array<string, mixed>
     */
    private function instantanea(RecepcionDocumentalCatalogo $e): array
    {
        return [
            'nombre' => $e->nombre,
            'descripcion' => $e->descripcion,
            'documentos' => $e->claves(),
            'activa' => $e->activa,
            'sucursal_ids' => $e->sucursales(),
            'empresa_ids' => $e->empresas(),
        ];
    }
}
