<?php

namespace App\Services\Expedientes\MigracionInicial;

use App\Models\Colaborador;
use App\Models\ExpedienteHistorico;
use App\Models\Puesto;
use Illuminate\Support\Collection;

/**
 * Arma el PLAN de la migración inicial sin modificar nada (dry run).
 *
 * Identidad (la «Clave» nunca es llave; la 130 está repetida en la fuente):
 *   1. CURP válida y única en el Excel;
 *   2. RFC con homoclave, único en el Excel;
 *   3. correo, único en el Excel;
 *   4. nombre normalizado + sucursal + fecha de nacimiento.
 * Si dos llaves apuntan a colaboradores distintos, o una llave a varios, o
 * un colaborador a varias filas → CONFLICTO (no se relaciona solo).
 *
 * Carpetas del NAS: match EXACTO (mismos tokens) o ALTO (≥ umbral, misma
 * sucursal y sin competencia) se aplican solos; REVISIÓN MANUAL y SIN MATCH
 * nunca. Las carpetas que nadie del Excel reclama se proponen como:
 *   - vincular: coincide con un colaborador que ya existe en BD;
 *   - historico: nombre claro (≥ 3 palabras) → colaborador de baja con solo
 *     el nombre y la sucursal (nada inventado);
 *   - pendiente: dudoso → inventario «Pendientes de vincular».
 */
class PlanificadorMigracion
{
    public function __construct(private readonly InventarioNasHistorico $inventario) {}

    /**
     * @param  array{filas: list<array<string, mixed>>, columnas: array<string, string>, sin_reconocer: list<string>, hoja: string}  $excel
     * @return array<string, mixed>
     */
    public function planificar(array $excel): array
    {
        $colaboradores = Colaborador::withTrashed()->with('user:id,colaborador_id,email')->get();
        $puestos = Puesto::query()->get(['id', 'nombre', 'departamento_id']);
        $excluidas = array_values(array_map(fn ($n) => Normalizador::clave((string) $n), (array) config('expedientes.migracion_inicial.personas_excluidas', [])));

        $filas = array_map(fn (array $f) => $this->planFila($f, $excluidas, $puestos), $excel['filas']);
        $filas = $this->identificar($filas, $colaboradores);

        $nas = $this->inventario->inventario();
        [$filas, $huerfanas] = $this->emparejarCarpetas($filas, $nas['carpetas'], $colaboradores);

        $plan = [
            'excel' => ['hoja' => $excel['hoja'], 'columnas' => $excel['columnas'], 'sin_reconocer' => $excel['sin_reconocer'], 'total_filas' => count($excel['filas'])],
            'origen' => ['modo' => $this->inventario->enSitio() ? 'sitio' : 'legacy', 'disco' => $this->inventario->nombreDisco(), 'ruta' => $this->inventario->raiz(), 'existe' => $nas['origen_existe']],
            'filas' => $filas,
            'carpetas_sin_persona' => $huerfanas,
            'sucursales_no_autorizadas_nas' => $nas['sucursales_no_autorizadas'],
            'carpetas_total' => count($nas['carpetas']),
        ];
        $plan['totales'] = $this->totales($plan);

        return $plan;
    }

    /**
     * @param  list<string>  $excluidas
     * @param  Collection<int, Puesto>  $puestos
     * @param  array<string, mixed>  $f
     * @return array<string, mixed>
     */
    private function planFila(array $f, array $excluidas, Collection $puestos): array
    {
        $advertencias = [];
        $motivos = [];
        $operacion = null;

        if (in_array(Normalizador::clave((string) $f['nombre_completo']), $excluidas, true)) {
            $operacion = 'omitir';
            $motivos[] = 'Está en CONTACTOS_SIN_MATCH: se deja fuera hasta que RH lo revise.';
        }

        $sucursal = null;

        if ($operacion === null) {
            if ($this->inventario->esSucursalExcluida($f['sucursal'])) {
                $operacion = 'omitir';
                $motivos[] = sprintf('Sucursal excluida de esta migración (%s).', $f['sucursal']);
            } else {
                $sucursal = $this->inventario->resolverSucursal($f['sucursal']);

                if ($sucursal === null) {
                    $operacion = 'conflicto';
                    $motivos[] = $f['sucursal'] === null ? 'Sin sucursal en el Excel.' : sprintf('Sucursal desconocida «%s»: no se crea sola; agrégala o un alias.', $f['sucursal']);
                }
            }
        }

        if ($f['nombre_completo'] === null || count($f['tokens']) < 2) {
            $operacion = 'conflicto';
            $motivos[] = 'Nombre incompleto.';
        }

        if ($f['curp'] !== null && ! Normalizador::curpValida($f['curp'])) {
            $advertencias[] = sprintf('CURP con formato inválido (%s): se guarda tal cual pero no se usa para identificar.', $f['curp']);
        }

        $sexo = $f['sexo'];

        if ($sexo === null && Normalizador::curpValida($f['curp'])) {
            $sexo = substr((string) $f['curp'], 10, 1) === 'H' ? 'masculino' : (substr((string) $f['curp'], 10, 1) === 'M' ? 'femenino' : null);
        }

        // Puesto: match exacto (normalizado) o alias EXPLÍCITO; nunca aproximado.
        $puesto = null;

        if ($f['puesto'] !== null) {
            $alias = collect((array) config('expedientes.migracion_inicial.alias_puestos', []))
                ->mapWithKeys(fn ($destino, $origen) => [Normalizador::clave((string) $origen) => Normalizador::clave((string) $destino)]);
            $buscado = $alias->get(Normalizador::clave($f['puesto']), Normalizador::clave($f['puesto']));
            $puesto = $puestos->first(fn (Puesto $p) => Normalizador::clave($p->nombre) === $buscado);

            if ($puesto === null && $operacion !== 'omitir') {
                $operacion = 'conflicto';
                $motivos[] = sprintf('PUESTO NO ENCONTRADO: «%s» no está en el catálogo de puestos (agrega el puesto o un alias explícito).', $f['puesto']);
            }
        } else {
            $advertencias[] = 'Sin puesto en el Excel: se importa sin puesto.';
        }

        if ($f['estatus'] === null) {
            $advertencias[] = $f['estatus_original'] !== null
                ? sprintf('Estatus «%s» no reconocido: se importa como activo (el valor original se conserva).', $f['estatus_original'])
                : 'Sin estatus laboral: se importa como activo.';
        }

        return [
            'fila' => $f['fila'],
            'clave' => $f['clave'],
            'nombre_completo' => $f['nombre_completo'],
            'tokens' => $f['tokens'],
            'sucursal_excel' => $f['sucursal'],
            'sucursal_id' => $sucursal?->id,
            'sucursal_nombre' => $sucursal?->nombre,
            'empresa_nombre' => $sucursal?->empresa?->nombre,
            'puesto_excel' => $f['puesto'],
            'puesto_nombre' => $puesto?->nombre,
            'estatus_origen' => $f['estatus_original'],
            'curp' => $f['curp'],
            'rfc' => $f['rfc'],
            'correo' => $f['correo'],
            'operacion' => $operacion,
            'colaborador_id' => null,
            'motivos' => $motivos,
            'advertencias' => $advertencias,
            'cambios' => [],
            'datos' => [
                'name' => $f['nombre'],
                'apellidos' => $f['apellidos'],
                'genero' => $sexo,
                'curp' => $f['curp'],
                'rfc' => $f['rfc'],
                'nss' => $f['nss'],
                'correo_personal' => $f['correo'],
                'telefono' => $f['telefono_personal'] ?? $f['telefono'],
                'telefono_corporativo' => $f['telefono_personal'] !== null ? $f['telefono'] : null,
                'fecha_nacimiento' => $f['fecha_nacimiento'],
                'fecha_ingreso' => $f['fecha_alta'],
                'estatus' => $f['estatus'] === 'baja' ? 'inactivo' : 'activo',
                'sucursal_principal_id' => $sucursal?->id,
                'puesto_id' => $puesto?->id,
                'departamento_id' => $puesto?->departamento_id,
                'contacto_emergencia_nombre' => $f['contacto_nombre'],
                'contacto_emergencia_parentesco' => $f['parentesco'],
                'contacto_emergencia_telefono' => $f['contacto_telefono'],
                'contacto_emergencia_direccion' => $f['contacto_direccion'],
                'clave_legacy' => $f['clave'],
                'estatus_origen' => $f['estatus_original'],
            ],
            'medicos' => array_filter(['condicion_medica' => $f['condicion_medica'], 'alergias' => $f['alergias']], fn ($v) => $v !== null),
            'nas' => ['tipo' => 'sin_match', 'score' => 0, 'carpeta' => null, 'candidatos' => []],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $filas
     * @param  Collection<int, Colaborador>  $colaboradores
     * @return list<array<string, mixed>>
     */
    private function identificar(array $filas, Collection $colaboradores): array
    {
        // Duplicados DENTRO del Excel: una llave repetida no identifica.
        $conteo = fn (string $campo, callable $valida) => collect($filas)->filter(fn ($f) => $valida($f[$campo]))->countBy(fn ($f) => (string) $f[$campo]);
        $curps = $conteo('curp', fn ($v) => Normalizador::curpValida($v));
        $rfcs = $conteo('rfc', fn ($v) => Normalizador::rfcConfiable($v));
        $correos = $conteo('correo', fn ($v) => $v !== null);
        $claves = collect($filas)->filter(fn ($f) => $f['clave'] !== null)->countBy(fn ($f) => (string) $f['clave']);

        $porCurp = $colaboradores->filter(fn ($c) => $c->curp !== null)->groupBy(fn (Colaborador $c): string => (string) Normalizador::curp($c->curp));
        $porRfc = $colaboradores->filter(fn ($c) => $c->rfc !== null)->groupBy(fn (Colaborador $c): string => (string) Normalizador::rfc($c->rfc));
        $porCorreo = $colaboradores->groupBy(fn ($c) => Normalizador::correo($c->correo_personal) ?? Normalizador::correo($c->user?->email) ?? '');
        $porNombre = $colaboradores->groupBy(fn ($c) => implode('|', [Normalizador::clave($c->nombreCompleto()), $c->sucursal_principal_id, $c->fecha_nacimiento?->toDateString()]));

        foreach ($filas as $i => $f) {
            if ((int) ($claves[(string) $f['clave']] ?? 0) > 1) {
                $filas[$i]['advertencias'][] = sprintf('La Clave %s está repetida en el Excel: solo se guarda como referencia (clave_legacy).', $f['clave']);
            }

            if (in_array($f['operacion'], ['omitir', 'conflicto'], true)) {
                continue;
            }

            if (Normalizador::curpValida($f['curp']) && ($curps[$f['curp']] ?? 0) > 1) {
                $filas[$i]['operacion'] = 'conflicto';
                $filas[$i]['motivos'][] = sprintf('CURP %s repetida en el Excel (%d filas).', $f['curp'], $curps[$f['curp']]);

                continue;
            }

            $encontrados = [];

            if (Normalizador::curpValida($f['curp'])) {
                $encontrados['CURP'] = $porCurp->get($f['curp'], collect())->pluck('id')->all();
            }

            if (Normalizador::rfcConfiable($f['rfc']) && ($rfcs[$f['rfc']] ?? 0) === 1) {
                $encontrados['RFC'] = $porRfc->get($f['rfc'], collect())->pluck('id')->all();
            }

            if ($f['correo'] !== null && ($correos[$f['correo']] ?? 0) === 1) {
                $encontrados['correo'] = $porCorreo->get($f['correo'], collect())->pluck('id')->all();
            }

            if ($f['datos']['fecha_nacimiento'] !== null && $f['sucursal_id'] !== null) {
                $encontrados['nombre+sucursal+nacimiento'] = $porNombre->get(implode('|', [Normalizador::clave((string) $f['nombre_completo']), $f['sucursal_id'], $f['datos']['fecha_nacimiento']]), collect())->pluck('id')->all();
            }

            $ids = [];

            foreach ($encontrados as $llave => $lista) {
                if (count($lista) > 1) {
                    $filas[$i]['operacion'] = 'conflicto';
                    $filas[$i]['motivos'][] = sprintf('La %s coincide con %d colaboradores distintos.', $llave, count($lista));

                    continue 2;
                }

                $ids = [...$ids, ...$lista];
            }

            $ids = array_values(array_unique($ids));

            if (count($ids) > 1) {
                $filas[$i]['operacion'] = 'conflicto';
                $filas[$i]['motivos'][] = 'Sus datos (CURP/RFC/correo/nombre) apuntan a colaboradores distintos.';

                continue;
            }

            if ($ids === []) {
                $filas[$i]['operacion'] = 'crear';

                continue;
            }

            /** @var Colaborador $existente */
            $existente = $colaboradores->firstWhere('id', $ids[0]);
            $filas[$i]['colaborador_id'] = $existente->id;
            $filas[$i]['cambios'] = $this->cambios($existente, $f['datos']);
            $filas[$i]['operacion'] = $filas[$i]['cambios'] === [] ? 'sin_cambios' : 'actualizar';
        }

        // Un mismo colaborador reclamado por dos filas: ambas a conflicto.
        $reclamados = collect($filas)->filter(fn ($f) => $f['colaborador_id'] !== null)->countBy('colaborador_id');

        foreach ($filas as $i => $f) {
            if ($f['colaborador_id'] !== null && $reclamados[$f['colaborador_id']] > 1) {
                $filas[$i]['operacion'] = 'conflicto';
                $filas[$i]['motivos'][] = 'Otra fila del Excel se identifica con el mismo colaborador.';
            }
        }

        return $filas;
    }

    /**
     * Solo campos con valor en el Excel que difieren de la BD (un vacío del
     * Excel nunca borra un dato existente).
     *
     * @param  array<string, mixed>  $datos
     * @return array<string, array{antes: mixed, despues: mixed}>
     */
    private function cambios(Colaborador $c, array $datos): array
    {
        $cambios = [];

        foreach ($datos as $campo => $nuevo) {
            if ($nuevo === null || $nuevo === '') {
                continue;
            }

            $actual = $c->getAttribute($campo);
            $actual = $actual instanceof \BackedEnum ? $actual->value : ($actual instanceof \DateTimeInterface ? $actual->format('Y-m-d') : $actual);

            if ((string) $actual !== (string) $nuevo) {
                $cambios[$campo] = ['antes' => $actual, 'despues' => $nuevo];
            }
        }

        return $cambios;
    }

    /**
     * @param  list<array<string, mixed>>  $filas
     * @param  list<array<string, mixed>>  $carpetas
     * @param  Collection<int, Colaborador>  $colaboradores
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>}
     */
    private function emparejarCarpetas(array $filas, array $carpetas, Collection $colaboradores): array
    {
        $alto = (float) config('expedientes.migracion_inicial.umbral_alto', 0.85);
        $revision = (float) config('expedientes.migracion_inicial.umbral_revision', 0.55);
        $candidatos = [];

        foreach ($filas as $i => $f) {
            if ($f['operacion'] === 'omitir') {
                continue;
            }

            foreach ($carpetas as $j => $c) {
                $score = max(Normalizador::similitud($f['tokens'], $c['tokens']), ...array_map(fn ($t) => Normalizador::similitud($f['tokens'], $t), $c['tokens_pdf'] ?: [[]]));

                if ($score < $revision) {
                    continue;
                }

                // Otra sucursal: nunca automático.
                if ($f['sucursal_id'] === null || $c['sucursal_id'] !== $f['sucursal_id']) {
                    $score = min($score, $alto - 0.01);
                }

                $candidatos[$i][$j] = $score;
            }
        }

        // Pares automáticos: score ≥ alto y sin competencia en ninguno de los dos lados.
        $porCarpeta = [];

        foreach ($candidatos as $i => $lista) {
            foreach ($lista as $j => $score) {
                if ($score >= $alto) {
                    $porCarpeta[$j][] = $i;
                }
            }
        }

        $asignadas = [];

        foreach ($candidatos as $i => $lista) {
            arsort($lista);
            $fuertes = array_filter($lista, fn ($s) => $s >= $alto);
            $filas[$i]['nas']['candidatos'] = array_map(fn ($j, $s) => [
                'ruta' => $carpetas[$j]['ruta'],
                'carpeta' => $carpetas[$j]['carpeta'],
                'sucursal' => $carpetas[$j]['sucursal_carpeta'],
                'score' => $s,
                'pdfs' => count($carpetas[$j]['pdfs']),
            ], array_keys(array_slice($lista, 0, 5, true)), array_slice($lista, 0, 5, true));

            $competencia = count($fuertes) > 1;

            if (count($fuertes) === 1) {
                $unica = (int) array_key_first($fuertes);
                $competencia = count($porCarpeta[$unica] ?? []) > 1;

                if (! $competencia && $filas[$i]['operacion'] !== 'conflicto') {
                    $filas[$i]['nas'] = [...$filas[$i]['nas'], ...$this->resumenCarpeta($carpetas[$unica]), 'tipo' => $fuertes[$unica] >= 0.999 ? 'exacto' : 'alto', 'score' => $fuertes[$unica]];
                    $asignadas[$unica] = true;

                    continue;
                }
            }

            // Hay candidatas pero ninguna segura: revisión manual.
            $filas[$i]['nas']['tipo'] = 'revision';
            $filas[$i]['nas']['score'] = (float) reset($lista);
            $filas[$i]['advertencias'][] = $competencia
                ? 'Varias carpetas/personas compiten por el mismo expediente: elige manualmente.'
                : 'Carpeta parecida pero no segura: confírmala manualmente.';
        }

        // Carpetas que nadie del Excel reclama (ni como candidata).
        $reclamadas = [];

        foreach ($candidatos as $lista) {
            foreach (array_keys($lista) as $j) {
                $reclamadas[$j] = true;
            }
        }

        $existentes = $colaboradores->map(fn (Colaborador $c) => ['id' => $c->id, 'tokens' => Normalizador::tokensNombre($c->nombreCompleto()), 'sucursal_id' => $c->sucursal_principal_id, 'nombre' => $c->nombreCompleto()]);
        $vinculados = ExpedienteHistorico::query()->pluck('colaborador_id', 'source_path');
        $huerfanas = [];

        foreach ($carpetas as $j => $c) {
            if (isset($asignadas[$j]) || isset($reclamadas[$j])) {
                continue;
            }

            $rutaPrimerPdf = $c['pdfs'][0]['ruta'] ?? null;
            $yaVinculado = $rutaPrimerPdf !== null ? $vinculados->get($rutaPrimerPdf) : null;
            $parecidos = $existentes->map(fn ($e) => [...$e, 'score' => $e['sucursal_id'] === $c['sucursal_id'] ? Normalizador::similitud($c['tokens'], $e['tokens']) : 0.0])
                ->filter(fn ($e) => $e['score'] >= $alto)->values();

            $accion = match (true) {
                $c['pdfs'] === [] => 'sin_pdf',
                $c['sucursal_id'] === null => 'pendiente',
                $yaVinculado !== null => 'vincular',
                $parecidos->count() === 1 => 'vincular',
                $parecidos->count() > 1 => 'pendiente',
                count($c['tokens']) >= 3 => 'historico',
                default => 'pendiente',
            };

            $huerfanas[] = [
                ...$this->resumenCarpeta($c),
                'sucursal_id' => $c['sucursal_id'],
                'sucursal_nombre' => $c['sucursal_nombre'],
                'nombre_detectado' => $c['tokens'] !== [] ? mb_convert_case(mb_strtolower(implode(' ', $c['tokens'])), MB_CASE_TITLE, 'UTF-8') : null,
                'fecha_en_nombre' => $c['fecha_en_nombre'],
                'accion' => $accion,
                'colaborador_id' => $yaVinculado ?? ($parecidos->count() === 1 ? $parecidos[0]['id'] : null),
                'colaborador_nombre' => $parecidos->count() === 1 ? $parecidos[0]['nombre'] : null,
            ];
        }

        return [$filas, $huerfanas];
    }

    /**
     * @param  array<string, mixed>  $c
     * @return array<string, mixed>
     */
    private function resumenCarpeta(array $c): array
    {
        return [
            'carpeta' => $c['carpeta'],
            'ruta' => $c['ruta'],
            'sucursal_carpeta' => $c['sucursal_carpeta'],
            'pdfs' => $c['pdfs'],
        ];
    }

    /**
     * @param  array<string, mixed>  $plan
     * @return array<string, int>
     */
    public function totales(array $plan): array
    {
        $filas = array_values(array_filter((array) $plan['filas'], 'is_array'));
        $huerfanas = array_values(array_filter((array) $plan['carpetas_sin_persona'], 'is_array'));
        $cuenta = fn (array $lista, callable $condicion): int => count(array_filter($lista, $condicion));
        $op = fn (string $valor): int => $cuenta($filas, fn (array $f) => ($f['operacion'] ?? null) === $valor);
        $match = fn (string $valor): int => $cuenta($filas, fn (array $f) => ($f['nas']['tipo'] ?? null) === $valor);
        $accion = fn (string $valor): int => $cuenta($huerfanas, fn (array $h) => ($h['accion'] ?? null) === $valor);

        return [
            'filas' => count($filas),
            'crear' => $op('crear'),
            'actualizar' => $op('actualizar'),
            'sin_cambios' => $op('sin_cambios'),
            'conflictos' => $op('conflicto'),
            'omitidos' => $op('omitir'),
            'match_exacto' => $match('exacto'),
            'match_alto' => $match('alto'),
            'revision_manual' => $match('revision'),
            'sin_match' => $cuenta($filas, fn (array $f) => ($f['operacion'] ?? null) !== 'omitir' && ($f['nas']['tipo'] ?? null) === 'sin_match'),
            'carpetas_nas' => (int) ($plan['carpetas_total'] ?? 0),
            'historicos' => $accion('historico'),
            'vincular_existentes' => $accion('vincular'),
            'pendientes_vincular' => $accion('pendiente'),
            'pdfs' => array_sum(array_map(fn (array $f) => count((array) ($f['nas']['pdfs'] ?? [])), $filas)) + array_sum(array_map(fn (array $h) => count((array) ($h['pdfs'] ?? [])), $huerfanas)),
        ];
    }
}
