<?php

namespace App\Services\DocumentosMaestros;

use App\Models\Puesto;
use App\Models\User;
use App\Services\Auditoria\AuditoriaService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * COBERTURA DOCUMENTAL POR PUESTO: para cada puesto activo, qué documento
 * oficial le tocaría en cada momento (capacitación, indeterminado,
 * confidencialidad, no competencia…) y si ya existe un master activo y
 * validado para él. Sirve para enterarse ANTES de que alguien espere su
 * contrato, no cuando RH pulsa "Generar".
 *
 * Todo puesto activo debe tener una DECISIÓN explícita:
 *  - grupo documental (gestor, gerente…), o
 *  - "no requiere documentos laborales" con motivo.
 * Un puesto sin ninguna de las dos es ambiguo y se reporta.
 *
 * La resolución es la misma del motor (ResolvedorMaestroService::buscarPara):
 * puesto+empresa > puesto > grupo+empresa > grupo > general. La base de
 * datos es la fuente de verdad: config documentos_maestros.grupos_por_puesto
 * solo siembra el valor inicial de puestos sin decisión.
 */
class CoberturaDocumentalService
{
    public function __construct(
        private readonly ResolvedorMaestroService $resolvedor,
        private readonly CatalogoMaestrosService $catalogo,
        private readonly AuditoriaService $auditoria,
    ) {}

    /**
     * @return array{columnas: list<array{clave: string, etiqueta: string, requerida: bool}>, puestos: list<array<string, mixed>>, resumen: array{total: int, completos: int, incompletos: int, sin_decision: int, excluidos: int}}
     */
    public function reporte(): array
    {
        $columnas = $this->columnas();
        $requeridas = array_values(array_map('strval', (array) config('documentos_maestros.cobertura.requeridas', [])));
        $puestos = Puesto::query()->where('activo', true)->orderBy('nivel_jerarquico')->orderBy('nombre')->get();
        $filas = [];
        $resumen = ['total' => 0, 'completos' => 0, 'incompletos' => 0, 'sin_decision' => 0, 'excluidos' => 0];

        foreach ($puestos as $puesto) {
            $fila = $this->fila($puesto, $columnas, $requeridas);
            $filas[] = $fila;
            $resumen['total']++;
            $resumen[match ($fila['estado']) {
                'completo' => 'completos',
                'excluido' => 'excluidos',
                'sin_decision' => 'sin_decision',
                default => 'incompletos',
            }]++;
        }

        return [
            'columnas' => array_map(fn (string $clave, array $c): array => ['clave' => $clave, 'etiqueta' => (string) $c['etiqueta'], 'requerida' => in_array($clave, $requeridas, true)], array_keys($columnas), array_values($columnas)),
            'puestos' => $filas,
            'resumen' => $resumen,
        ];
    }

    /**
     * Puestos activos sin cobertura completa o sin decisión (KPI).
     */
    public function puestosConProblema(): int
    {
        $resumen = $this->reporte()['resumen'];

        return $resumen['incompletos'] + $resumen['sin_decision'];
    }

    /**
     * RH decide: o el puesto tiene grupo documental, o no requiere
     * documentos laborales (con motivo). Nunca ambos, nunca ninguno.
     *
     * @param  array{grupo_documental?: string|null, no_requiere_documentos_laborales?: bool, motivo_sin_documentos?: string|null}  $datos
     */
    public function decidir(Puesto $puesto, array $datos, User $actor): Puesto
    {
        $grupo = isset($datos['grupo_documental']) && $datos['grupo_documental'] !== '' ? (string) $datos['grupo_documental'] : null;
        $excluido = (bool) ($datos['no_requiere_documentos_laborales'] ?? false);
        $motivo = trim((string) ($datos['motivo_sin_documentos'] ?? ''));

        if ($grupo !== null && ! array_key_exists($grupo, (array) config('documentos_maestros.grupos', []))) {
            throw ValidationException::withMessages(['grupo_documental' => 'Selecciona un grupo documental válido.']);
        }

        if ($excluido && $grupo !== null) {
            throw ValidationException::withMessages(['grupo_documental' => 'Un puesto con grupo documental sí requiere documentos laborales.']);
        }

        if ($excluido && mb_strlen($motivo) < 10) {
            throw ValidationException::withMessages(['motivo_sin_documentos' => 'Explica por qué el puesto no requiere documentos laborales (mínimo 10 caracteres).']);
        }

        if (! $excluido && $grupo === null) {
            throw ValidationException::withMessages(['grupo_documental' => 'Elige el grupo documental del puesto o márcalo como "no requiere documentos laborales" con su motivo.']);
        }

        $antes = $puesto->only(['grupo_documental', 'no_requiere_documentos_laborales', 'motivo_sin_documentos']);

        DB::transaction(function () use ($puesto, $grupo, $excluido, $motivo): void {
            $puesto->update([
                'grupo_documental' => $grupo,
                'no_requiere_documentos_laborales' => $excluido,
                'motivo_sin_documentos' => $excluido ? $motivo : null,
            ]);
        });

        $despues = $puesto->only(['grupo_documental', 'no_requiere_documentos_laborales', 'motivo_sin_documentos']);

        if ($antes !== $despues) {
            $this->auditoria->registrar('cobertura_documental_puesto', $puesto, $actor, ['antes' => $antes, 'despues' => $despues]);
        }

        return $puesto->refresh();
    }

    /**
     * Siembra inicial: los puestos activos SIN decisión toman el grupo de
     * config documentos_maestros.grupos_por_puesto (por nombre exacto). Un
     * puesto que RH ya configuró (grupo o exclusión) nunca se toca.
     */
    public function aplicarGruposIniciales(): int
    {
        $aplicados = 0;

        foreach ((array) config('documentos_maestros.grupos_por_puesto', []) as $nombre => $grupo) {
            $aplicados += Puesto::query()
                ->where('nombre', (string) $nombre)
                ->whereNull('grupo_documental')
                ->where('no_requiere_documentos_laborales', false)
                ->update(['grupo_documental' => (string) $grupo]);
        }

        return $aplicados;
    }

    /**
     * @param  array<string, array<string, mixed>>  $columnas
     * @param  list<string>  $requeridas
     * @return array<string, mixed>
     */
    private function fila(Puesto $puesto, array $columnas, array $requeridas): array
    {
        $grupo = $puesto->grupo_documental;
        $base = [
            'id' => $puesto->id,
            'nombre' => $puesto->nombre,
            'grupo' => $grupo,
            'grupo_etiqueta' => $this->catalogo->etiquetaGrupo($grupo),
            'no_requiere' => $puesto->no_requiere_documentos_laborales,
            'motivo_sin_documentos' => $puesto->motivo_sin_documentos,
        ];

        if ($puesto->no_requiere_documentos_laborales) {
            return [...$base, 'estado' => 'excluido', 'estado_etiqueta' => 'No requiere documentos', 'faltantes' => [], 'celdas' => array_map(fn (): array => ['estado' => 'no_aplica', 'master' => null, 'nivel' => null], $columnas)];
        }

        $celdas = [];
        $faltantes = [];

        foreach ($columnas as $clave => $columna) {
            $documento = (string) $columna['clave'];

            if (! $this->solicitada($columna, $grupo)) {
                $celdas[$clave] = ['estado' => 'no_aplica', 'master' => null, 'nivel' => null];

                continue;
            }

            $master = $this->resolvedor->buscarPara($documento, $puesto->id, $grupo, null);
            $nivel = $master !== null ? $this->resolvedor->nivelPara($documento, $puesto->id, $grupo, null) : null;
            $estado = match (true) {
                $master === null => 'falta',
                ! $master->disenoValidado() => 'sin_validar',
                $nivel === 'general' => 'general',
                default => 'ok',
            };

            $celdas[$clave] = [
                'estado' => $estado,
                'nivel' => $nivel,
                'master' => $master !== null ? ['id' => $master->id, 'familia' => $master->familia, 'nombre' => $master->nombre, 'version' => $master->version] : null,
            ];

            if (in_array($clave, $requeridas, true) && in_array($estado, ['falta', 'sin_validar'], true)) {
                $faltantes[] = sprintf('%s %s', $columna['etiqueta'], $estado === 'falta' ? '(sin formato)' : '(diseño sin validar)');
            }
        }

        $estado = match (true) {
            $grupo === null => 'sin_decision',
            $faltantes !== [] => 'incompleto',
            default => 'completo',
        };

        return [
            ...$base,
            'estado' => $estado,
            'estado_etiqueta' => match ($estado) {
                'sin_decision' => 'Sin grupo documental',
                'incompleto' => 'Incompleto',
                default => 'Completo',
            },
            'faltantes' => $faltantes,
            'celdas' => $celdas,
        ];
    }

    /**
     * ¿El flujo pide este documento para el grupo? (paquetes del config).
     *
     * @param  array<string, mixed>  $columna
     */
    private function solicitada(array $columna, ?string $grupo): bool
    {
        $modalidades = (array) ($columna['modalidades'] ?? []);

        // Responsivas: se piden en la entrega de activos, para cualquier puesto.
        if ($modalidades === []) {
            return true;
        }

        foreach ($modalidades as $ruta) {
            $paquetes = (array) config('documentos_maestros.paquetes.'.$ruta, []);
            $lista = $grupo !== null && isset($paquetes[$grupo]) ? $paquetes[$grupo] : ($paquetes['*'] ?? []);

            if (in_array($columna['clave'], (array) $lista, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function columnas(): array
    {
        $columnas = config('documentos_maestros.cobertura.columnas', []);

        return is_array($columnas) ? array_filter($columnas, 'is_array') : [];
    }
}
