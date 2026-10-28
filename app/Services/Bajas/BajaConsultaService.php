<?php

namespace App\Services\Bajas;

use App\Enums\EstatusBaja;
use App\Enums\FormaSalida;
use App\Models\Baja;
use App\Models\Colaborador;
use App\Models\User;
use App\Services\AlcanceOrganizacionalService;
use App\Services\Colaboradores\BuscadorColaboradoresService;
use App\Services\Colaboradores\FotoColaboradorService;
use App\Support\Paginacion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Quién ve qué bajas (permisos de Spatie + AlcanceOrganizacionalService),
 * la bandeja con filtros y el buscador de colaboradores del formulario.
 *
 *  - bajas.ver: las de su alcance del módulo «bajas» (gerente: su sucursal;
 *    regional: su región; RH: todas). Solo gerentes, RH y super admin lo
 *    tienen. Un borrador del flujo anterior solo lo ve quien lo capturó (y
 *    RH).
 *  - bajas.revisar_juridico: las que requieren Jurídico y las rescisiones.
 *  - bajas.consulta_contraloria: solo las de causales con aviso a
 *    Contraloría (RE-02 y RE-03), solo lectura.
 */
class BajaConsultaService
{
    public const MODULO = 'bajas';

    public function __construct(
        private readonly AlcanceOrganizacionalService $alcance,
        private readonly BajaDocumentosService $documentos,
        private readonly FotoColaboradorService $fotos,
    ) {}

    public function puedeEntrar(User $usuario): bool
    {
        return $usuario->can('bajas.ver') || $usuario->can('bajas.revisar_juridico') || $usuario->can('bajas.consulta_contraloria');
    }

    /**
     * @return Builder<Baja>
     */
    public function consulta(User $usuario): Builder
    {
        $query = Baja::query();
        $porAlcance = $usuario->can('bajas.ver');
        $juridico = $usuario->can('bajas.revisar_juridico');
        $contraloria = $usuario->can('bajas.consulta_contraloria');

        if (! $porAlcance && ! $juridico && ! $contraloria) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $q) use ($usuario, $porAlcance, $juridico, $contraloria): void {
            if ($porAlcance) {
                $q->orWhere(function (Builder $s) use ($usuario): void {
                    // Con alcance «Todos» limitarPorModulo no agrega nada y un
                    // grupo vacío se descarta del OR: sin esta condición, quien
                    // además tiene Jurídico/Contraloría (super_admin) solo vería
                    // esas bajas.
                    $s->whereNotNull('bajas.id');
                    $this->alcance->limitarPorModulo($s, $usuario, self::MODULO, 'colaborador_id', true);

                    if (! $usuario->can('bajas.gestionar')) {
                        $s->where(fn (Builder $b) => $b->where('estatus', '!=', EstatusBaja::Borrador->value)->orWhere('creado_por', $usuario->id));
                    }
                });
            }

            if ($juridico) {
                $q->orWhere(fn (Builder $s) => $s->where('estatus', '!=', EstatusBaja::Borrador->value)
                    ->where(fn (Builder $b) => $b->where('forma_salida', FormaSalida::Rescision->value)->orWhereHas('causal', fn (Builder $c) => $c->where('requiere_juridico', true))));
            }

            if ($contraloria) {
                $q->orWhere(fn (Builder $s) => $s->where('estatus', '!=', EstatusBaja::Borrador->value)
                    ->whereHas('causal', fn (Builder $c) => $c->whereIn('clave', $this->clavesContraloria())));
            }
        });
    }

    public function puedeVer(User $usuario, Baja $baja): bool
    {
        return $this->consulta($usuario)->whereKey($baja->id)->exists();
    }

    /**
     * Causales cuya baja avisa a Contraloría (config/bajas.php alertas).
     *
     * @return list<string>
     */
    public function clavesContraloria(): array
    {
        $claves = [];

        foreach ((array) config('bajas.alertas', []) as $clave => $destinos) {
            if (in_array('contraloria', (array) $destinos, true)) {
                $claves[] = (string) $clave;
            }
        }

        return $claves;
    }

    /**
     * Bandeja: folio, colaborador, sucursal, forma, causal, días en espera y
     * semáforo de documentos; filtros por estatus, sucursal, forma y texto.
     *
     * @param  array<string, mixed>  $filtros
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function bandeja(User $usuario, array $filtros): LengthAwarePaginator
    {
        $estatus = is_string($filtros['estatus'] ?? null) ? $filtros['estatus'] : 'abiertas';
        $busqueda = trim((string) ($filtros['busqueda'] ?? ''));

        return $this->consulta($usuario)
            ->with(['causal', 'sucursal:id,nombre', 'puesto:id,nombre', 'cierre', 'documentos'])
            ->when($estatus === 'abiertas', fn (Builder $q) => $q->whereIn('estatus', EstatusBaja::valoresAbiertos()))
            ->when($estatus !== 'abiertas' && $estatus !== 'todas' && EstatusBaja::tryFrom($estatus) !== null, fn (Builder $q) => $q->where('estatus', $estatus))
            ->when(is_numeric($filtros['sucursal_id'] ?? null), fn (Builder $q) => $q->where('sucursal_id', (int) $filtros['sucursal_id']))
            ->when(FormaSalida::tryFrom((string) ($filtros['forma'] ?? '')) !== null, fn (Builder $q) => $q->where('forma_salida', (string) $filtros['forma']))
            ->when($busqueda !== '', fn (Builder $q) => $q->where(fn (Builder $b) => app(BuscadorColaboradoresService::class)->aplicarPorColumna($b
                ->where('nombre_colaborador', 'like', '%'.$busqueda.'%')
                ->orWhere('numero_empleado', 'like', '%'.$busqueda.'%')
                ->orWhere('folio', 'like', '%'.$busqueda.'%'), $busqueda, 'colaborador_id', 'or')))
            ->orderByRaw('case when enviado_en is null then 1 else 0 end')
            ->orderBy('enviado_en')
            ->orderByDesc('id')
            ->paginate(Paginacion::porPagina(20, $filtros['per_page'] ?? null))
            ->withQueryString()
            ->through(fn (Baja $b): array => $this->fila($b));
    }

    /**
     * Números grandes de la bandeja (dentro del alcance de quien mira).
     *
     * @return array{en_tramite: int, juridico: int, cerradas: int, no_procede: int}
     */
    public function resumen(User $usuario): array
    {
        $conteos = $this->consulta($usuario)->selectRaw('estatus, count(*) as total')->groupBy('estatus')->pluck('total', 'estatus');

        return [
            'en_tramite' => (int) ($conteos[EstatusBaja::EnRevisionRh->value] ?? 0),
            'juridico' => (int) ($conteos[EstatusBaja::EnRevisionJuridico->value] ?? 0),
            'cerradas' => (int) ($conteos[EstatusBaja::Cerrada->value] ?? 0),
            'no_procede' => (int) ($conteos[EstatusBaja::Cancelada->value] ?? 0),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function fila(Baja $baja): array
    {
        $faltantes = $this->documentos->faltantes($baja);

        return [
            'id' => $baja->id,
            'folio' => $baja->folio,
            'colaborador' => $baja->nombre_colaborador,
            'numero_empleado' => $baja->numero_empleado,
            'sucursal' => $baja->sucursal?->nombre,
            'puesto' => $baja->puesto?->nombre,
            'forma' => $baja->forma_salida?->value,
            'forma_etiqueta' => $baja->forma_salida?->etiqueta(),
            'forma_token' => $baja->forma_salida?->token(),
            'causal' => $baja->causal?->etiquetaConClave(),
            'causal_migrada' => $baja->causal_migrada_de !== null,
            'estatus' => $baja->estatus->value,
            'estatus_etiqueta' => $baja->estatus->etiqueta(),
            'fecha_baja' => $baja->fecha_baja?->toDateString(),
            'enviado_en' => $baja->enviado_en?->toDateTimeString(),
            // Días desde que se registró (abierta): cuánto lleva en trámite.
            'dias_en_espera' => $baja->estatus->esAbierta() && $baja->enviado_en !== null ? (int) $baja->enviado_en->copy()->startOfDay()->diffInDays(now()->startOfDay()) : null,
            'documentos_total' => count(array_filter($this->documentos->espacios($baja), fn (array $e): bool => $e['obligatorio'])),
            'documentos_completos' => $faltantes === [],
            'documentos_faltantes' => count($faltantes),
            'requiere_juridico' => $baja->requiereJuridico(),
            'origen' => $baja->origen->value,
        ];
    }

    /**
     * Buscador del formulario (sin mínimo de letras: lista completa al
     * enfocar, filtra al escribir): colaboradores ACTIVOS del alcance del
     * gerente, con sucursal, puesto e ingreso para llenar el bloque 1.
     *
     * @return list<array<string, mixed>>
     */
    public function buscarColaboradores(User $usuario, string $termino, int $limite = 50): array
    {
        $termino = trim($termino);
        $query = Colaborador::query()
            ->with(['sucursalPrincipal:id,nombre', 'puesto:id,nombre'])
            ->whereIn('estatus', ['activo', 'en_incorporacion'])
            ->when($usuario->colaborador_id !== null, fn (Builder $q) => $q->where('id', '!=', $usuario->colaborador_id));

        if (! $usuario->can('bajas.gestionar')) {
            $this->alcance->limitarPorModulo($query, $usuario, self::MODULO, 'id');
        }

        // Buscador único: nombre, número, puesto, sucursal o departamento.
        $resultado = $query->buscar($termino)
            ->orderBy('name')
            ->orderBy('apellidos')
            ->limit($limite)
            ->get();

        return array_values($resultado->map(fn (Colaborador $c): array => $this->filaColaborador($c, $usuario))->all());
    }

    /**
     * Persona del bloque «¿Quién se va?» (buscador y preselección desde el
     * ciclo laboral). Requiere sucursalPrincipal y puesto cargados.
     *
     * @return array{id: int, nombre: string, numero_empleado: string|null, puesto: string|null, sucursal: string|null, fecha_ingreso: string|null, foto_url: string|null}
     */
    public function filaColaborador(Colaborador $colaborador, User $usuario): array
    {
        return [
            'id' => $colaborador->id,
            'nombre' => $colaborador->nombreCompleto(),
            'numero_empleado' => $colaborador->numero_empleado,
            'puesto' => $colaborador->puesto?->nombre,
            'sucursal' => $colaborador->sucursalPrincipal?->nombre,
            'fecha_ingreso' => $colaborador->fecha_ingreso?->toDateString(),
            // Foto oficial del expediente (URL protegida), solo dentro del
            // alcance de quien registra; si no, iniciales.
            'foto_url' => $this->fotos->urlPara($colaborador, $usuario),
        ];
    }
}
