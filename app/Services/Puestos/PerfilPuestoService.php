<?php

namespace App\Services\Puestos;

use App\Models\Candidato;
use App\Models\Colaborador;
use App\Models\PerfilPuesto;
use App\Models\Puesto;
use App\Models\User;
use App\Services\Auditoria\AuditoriaService;
use Illuminate\Support\Str;

/**
 * Perfiles de puesto (editados por RH) con dos propósitos:
 *
 * 1. PERFIL DEL CANDIDATO: qué debe tener quien se postula (escolaridad,
 *    experiencia, conocimientos, habilidades, competencias, valores,
 *    requisitos). Se muestra en la ficha del candidato para quien entrevista.
 * 2. DESCRIPCIÓN DEL PUESTO: el «deber ser» (objetivo, funciones,
 *    responsabilidades, indicadores). A quién reporta y a quién supervisa
 *    sale del organigrama, nunca se captura dos veces.
 *
 * Un puesto sin perfil ofrece un BORRADOR (funciones del PDF de Inducción y
 * lo ya capturado en el puesto) que RH revisa; no se guarda solo.
 */
class PerfilPuestoService
{
    /** Secciones que cuentan para el avance del perfil. */
    private const SECCIONES = ['objetivo', 'funciones', 'responsabilidades', 'indicadores', 'escolaridad', 'experiencia', 'conocimientos', 'habilidades', 'competencias', 'valores', 'requisitos'];

    public function __construct(private readonly AuditoriaService $auditoria) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function listado(): array
    {
        $puestos = Puesto::query()
            ->where('activo', true)
            ->with(['perfil', 'departamento:id,nombre', 'puestoSuperior:id,nombre'])
            ->withCount(['colaboradores as ocupantes' => fn ($q) => $q->where('estatus', 'activo')])
            ->orderByRaw('COALESCE(nivel_jerarquico, 99)')
            ->orderBy('nombre')
            ->get();

        return array_values($puestos->map(fn (Puesto $p): array => [
            'id' => $p->id,
            'nombre' => $p->nombre,
            'departamento' => $p->departamento->nombre ?? null,
            'reporta_a' => $p->puestoSuperior->nombre ?? null,
            'ocupantes' => (int) $p->getAttribute('ocupantes'),
            'avance' => $this->avance($p->perfil),
            'actualizado_en' => $p->perfil?->updated_at?->toIso8601String(),
        ])->all());
    }

    /**
     * @return array<string, mixed>
     */
    public function detalle(Puesto $puesto): array
    {
        $puesto->loadMissing(['perfil.editor:id,name,apellidos,colaborador_id', 'departamento:id,nombre', 'puestoSuperior:id,nombre', 'subordinadosDirectos:id,nombre,puesto_superior_id,activo']);
        $perfil = $puesto->perfil;

        return [
            'puesto' => [
                'id' => $puesto->id,
                'nombre' => $puesto->nombre,
                'departamento' => $puesto->departamento->nombre ?? null,
                'reporta_a' => $puesto->puestoSuperior->nombre ?? null,
                'supervisa' => array_values($puesto->subordinadosDirectos->where('activo', true)->pluck('nombre')->all()),
                'ocupantes' => Colaborador::query()->where('puesto_id', $puesto->id)->where('estatus', 'activo')->count(),
            ],
            'perfil' => $perfil !== null ? $this->datos($perfil) : $this->borrador($puesto),
            'es_borrador' => $perfil === null,
            'avance' => $this->avance($perfil),
            'actualizado_por' => $perfil?->editor?->nombreCompleto(),
            'actualizado_en' => $perfil?->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $datos  Validado por GuardarPerfilPuestoRequest.
     */
    public function guardar(Puesto $puesto, array $datos, User $actor): PerfilPuesto
    {
        $valores = [
            'objetivo' => $this->texto($datos['objetivo'] ?? null),
            'escolaridad' => $this->texto($datos['escolaridad'] ?? null),
            'experiencia' => $this->texto($datos['experiencia'] ?? null),
            'actualizado_por' => $actor->id,
        ];

        foreach (PerfilPuesto::LISTAS as $lista) {
            $valores[$lista] = $this->lista($datos[$lista] ?? []);
        }

        $perfil = PerfilPuesto::query()->updateOrCreate(['puesto_id' => $puesto->id], $valores);

        $this->auditoria->registrar('perfil_puesto_actualizado', $perfil, $actor, ['puesto_id' => $puesto->id, 'puesto' => $puesto->nombre]);

        return $perfil;
    }

    /**
     * Resumen para la ficha del candidato (solo si el puesto ya tiene
     * perfil guardado; un borrador no orienta una entrevista).
     *
     * @return array<string, mixed>|null
     */
    public function paraCandidato(Candidato $candidato): ?array
    {
        $puesto = $candidato->puesto_objetivo_id !== null
            ? Puesto::query()->with('perfil')->where('id', $candidato->puesto_objetivo_id)->first()
            : null;

        if ($puesto === null || $puesto->perfil === null) {
            return $puesto !== null ? ['puesto_id' => $puesto->id, 'puesto' => $puesto->nombre, 'perfil' => null] : null;
        }

        return ['puesto_id' => $puesto->id, 'puesto' => $puesto->nombre, 'perfil' => $this->datos($puesto->perfil)];
    }

    /**
     * @return array<string, mixed>
     */
    private function datos(PerfilPuesto $perfil): array
    {
        $datos = [
            'objetivo' => $perfil->objetivo,
            'escolaridad' => $perfil->escolaridad,
            'experiencia' => $perfil->experiencia,
        ];

        foreach (PerfilPuesto::LISTAS as $lista) {
            $datos[$lista] = $this->lista($perfil->getAttribute($lista) ?? []);
        }

        return $datos;
    }

    /**
     * @return array<string, mixed>
     */
    private function borrador(Puesto $puesto): array
    {
        $datos = array_fill_keys(PerfilPuesto::LISTAS, []);
        $datos['objetivo'] = $this->texto($puesto->descripcion);
        $datos['escolaridad'] = null;
        $datos['experiencia'] = null;
        $datos['responsabilidades'] = $this->renglones($puesto->responsabilidades);
        $datos['requisitos'] = $this->renglones($puesto->requisitos);
        $datos['funciones'] = $this->funcionesDeInduccion($puesto->nombre);

        return $datos;
    }

    /**
     * Funciones por puesto tal cual el PDF de Inducción (hoja 19), si el
     * puesto es uno de los de sucursal.
     *
     * @return list<string>
     */
    private function funcionesDeInduccion(string $nombrePuesto): array
    {
        $nombre = Str::ascii(trim($nombrePuesto));

        foreach ((array) config('organigrama.laminas.sucursal', []) as $bloque) {
            if (! is_array($bloque) || ! isset($bloque['puestos'], $bloque['funciones'])) {
                continue;
            }

            foreach ((array) $bloque['puestos'] as $patron) {
                $regex = '/^'.str_replace('%', '.*', preg_quote(Str::ascii((string) $patron), '/')).'$/i';

                if (preg_match($regex, $nombre) === 1) {
                    return $this->lista((array) $bloque['funciones']);
                }
            }
        }

        return [];
    }

    private function avance(?PerfilPuesto $perfil): int
    {
        if ($perfil === null) {
            return 0;
        }

        $llenas = 0;

        foreach (self::SECCIONES as $seccion) {
            $valor = $perfil->getAttribute($seccion);
            $llenas += (is_array($valor) ? $valor !== [] : filled($valor)) ? 1 : 0;
        }

        return (int) round($llenas * 100 / count(self::SECCIONES));
    }

    /**
     * @return list<string>
     */
    private function lista(mixed $valor): array
    {
        $items = [];

        foreach (is_array($valor) ? $valor : [] as $item) {
            $texto = is_string($item) ? trim(preg_replace('/^[\s•\-–·*]+/u', '', $item) ?? '') : '';

            if ($texto !== '' && ! in_array($texto, $items, true)) {
                $items[] = mb_substr($texto, 0, 300);
            }
        }

        return $items;
    }

    /**
     * @return list<string>
     */
    private function renglones(?string $texto): array
    {
        return $this->lista(preg_split('/\r\n|\r|\n/', (string) $texto) ?: []);
    }

    private function texto(mixed $valor): ?string
    {
        $texto = is_string($valor) ? trim($valor) : '';

        return $texto === '' ? null : $texto;
    }
}
