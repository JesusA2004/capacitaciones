<?php

namespace App\Services\Expedientes;

use App\Enums\EstadoAltaColaborador;
use App\Enums\EstadoCartaCompromiso;
use App\Enums\EstadoUsuario;
use App\Models\CartaCompromiso;
use App\Models\Colaborador;
use App\Models\User;
use App\Services\AlcanceOrganizacionalService;
use App\Services\CicloLaboral\OrganizacionJerarquiaService;
use App\Services\Colaboradores\BuscadorColaboradoresService;
use App\Services\Incorporacion\RegistroAppService;
use App\Support\Paginacion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Módulo «Cartas compromiso» (grupo 2 · Contratación y expediente): la
 * lista con sus indicadores y la generación MANUAL de la carta por RH, el
 * gerente (de sucursal — «gerente» y «gerente_sucursal» son el mismo
 * puesto para este módulo: los dos roles reciben el mismo catálogo y el
 * mismo alcance de sucursal) o la gerente regional, para que la firme quien
 * entra.
 *
 * La carta en sí (snapshot de documentos, folio, plazo, PDF al expediente,
 * avisos) la genera SIEMPRE CartaCompromisoService::generar(): mismo
 * camino que la carta automática del registro en la app. Aquí solo viven
 * las reglas de quién y a quién:
 *  - alcance del módulo `cartas_compromiso` (Roles y permisos): gerente = su
 *    sucursal, regional = su región de la matriz comercial, RH = todos;
 *  - nunca la propia, nunca a una cuenta super_admin; la carta de un Gerente
 *    de Sucursal la genera su Gerente Regional (o RH), como su contrato;
 *  - solo personal de NUEVO INGRESO (en contratación u onboarding): el
 *    personal que ya laboraba no tiene semana de gracia (regla del dueño);
 *  - le pueden faltar a lo más max_documentos (2): con más, la app ya está
 *    limitada a subirlos y la carta se genera sola al bajar al máximo;
 *  - una sola carta abierta: con una vigente o vencida no se genera otra
 *    (generar otra le reiniciaría el plazo).
 */
class GestionCartasCompromisoService
{
    public const MODULO = 'cartas_compromiso';

    public const PERMISO_VER = 'cartas_compromiso.ver';

    public const PERMISO_GENERAR = 'cartas_compromiso.generar';

    public const PERMISO_SUBIR_FIRMADA = 'cartas_compromiso.subir_firmada';

    public const PERMISO_DESCARGAR = 'cartas_compromiso.descargar';

    /** Pestañas de la lista. */
    public const PESTANAS = ['pendientes_firma', 'vigentes', 'por_vencer', 'vencidas', 'cumplidas', 'firmadas', 'todas', 'sustituidas'];

    public function __construct(
        private readonly CartaCompromisoService $cartas,
        private readonly DocumentosPendientesService $pendientes,
        private readonly AlcanceOrganizacionalService $alcance,
        private readonly OrganizacionJerarquiaService $jerarquia,
        private readonly RegistroAppService $registro,
        private readonly BuscadorColaboradoresService $buscador,
    ) {}

    public function diasPorVencer(): int
    {
        return max(0, (int) config('expedientes.carta_compromiso.dias_por_vencer', 2));
    }

    /**
     * null si el usuario puede operar cartas de esa persona (generar, subir
     * la firmada…); si no, el motivo en español. No revisa el permiso de la
     * acción (eso lo hace CartaCompromisoPolicy).
     */
    public function motivoFueraDeAlcance(User $usuario, Colaborador $colaborador): ?string
    {
        if ($usuario->colaborador_id !== null && $usuario->colaborador_id === $colaborador->id) {
            return 'Tu propia carta compromiso la gestiona tu gerente o Recursos Humanos.';
        }

        if (! $this->alcance->alcanzaColaboradorEnModulo($usuario, $colaborador, self::MODULO)) {
            return 'Esta persona está fuera de tu alcance.';
        }

        // Como su contrato: la carta de un Gerente de Sucursal no la maneja
        // otro gerente de sucursal, sino su Gerente Regional (o RH, que
        // tiene alcance de todos).
        if (! $this->alcance->tieneAlcanceGlobal($usuario) && $this->jerarquia->esPuestoDeGerenciaSucursal($colaborador->puesto_id)) {
            $regionales = $this->jerarquia->regionalesDe($colaborador->sucursal_principal_id, $colaborador);

            if (! $regionales->contains(fn (User $regional): bool => $regional->id === $usuario->id)) {
                return 'La carta de un Gerente de Sucursal la genera su Gerente Regional (o Recursos Humanos).';
            }
        }

        return null;
    }

    public function alcanza(User $usuario, Colaborador $colaborador): bool
    {
        return $this->motivoFueraDeAlcance($usuario, $colaborador) === null;
    }

    /**
     * ¿Persona de nuevo ingreso (contratación u onboarding)? El personal que
     * ya laboraba nunca recibe carta.
     */
    public function esNuevoIngreso(Colaborador $colaborador): bool
    {
        if ($colaborador->trashed()) {
            return false;
        }

        if ($colaborador->estatus === EstadoUsuario::EnIncorporacion) {
            return true;
        }

        return $colaborador->estatus === EstadoUsuario::Activo
            && in_array($colaborador->estado_alta, self::estadosAltaNuevoIngreso(), true);
    }

    /**
     * @return list<EstadoAltaColaborador>
     */
    public static function estadosAltaNuevoIngreso(): array
    {
        return array_values(array_filter(
            EstadoAltaColaborador::cases(),
            fn (EstadoAltaColaborador $e): bool => $e->enContratacion() || in_array($e, [EstadoAltaColaborador::EnOnboarding, EstadoAltaColaborador::PendienteActivacion], true),
        ));
    }

    /**
     * Lo que la pantalla «Generar carta» necesita de la persona elegida:
     * documentos que le faltan, regla del máximo, plazo y si se puede
     * generar (con el motivo si no).
     *
     * @return array<string, mixed>
     */
    public function preparar(Colaborador $colaborador): array
    {
        $colaborador->loadMissing(['sucursalPrincipal:id,nombre', 'puesto:id,nombre']);
        $pendientes = $this->pendientes->pendientes($colaborador);
        $abierta = $this->cartaAbierta($colaborador);

        return [
            'colaborador' => $this->resumenColaborador($colaborador),
            'pendientes' => array_map(fn (array $p): array => [
                'document_type_id' => $p['document_type_id'],
                'nombre' => $p['nombre'],
                'descripcion' => $p['descripcion'],
                'estado_etiqueta' => $p['estado_etiqueta'],
            ], $pendientes),
            'max_documentos' => $this->cartas->maxDocumentos(),
            'dias_plazo' => $this->cartas->diasPlazo(),
            'fecha_limite' => now()->startOfDay()->addDays($this->cartas->diasPlazo())->toDateString(),
            'motivo_no_elegible' => $this->motivoNoElegible($colaborador, count($pendientes), $abierta),
            // «Ver carta» también cuando aún no se puede generar solo por
            // tener más faltantes que el máximo (solo consulta).
            'puede_ver_carta' => $this->motivoSinVistaPrevia($colaborador, count($pendientes), $abierta) === null,
            'carta_abierta' => $abierta !== null ? [
                'id' => $abierta->id,
                'folio' => $abierta->folio,
                'estado_etiqueta' => $this->cartas->estadoEfectivo($abierta)->etiqueta(),
                'url' => route('rh.cartas-compromiso.show', $abierta, false),
                'pdf_url' => route('rh.cartas-compromiso.pdf', $abierta, false),
            ] : null,
        ];
    }

    /**
     * Vista previa (no guarda nada) de exactamente la carta que se
     * generaría con estos documentos: mismas validaciones que generar()
     * salvo una, para que se pueda VER antes de asignar: con más faltantes
     * que el máximo todavía no se genera, pero sí se ve cómo quedaría.
     *
     * @param  list<int>  $documentTypeIds
     *
     * @throws ValidationException
     */
    public function vistaPrevia(Colaborador $colaborador, array $documentTypeIds): string
    {
        $motivo = $this->motivoSinVistaPrevia($colaborador, count($this->pendientes->idsPendientes($colaborador)), $this->cartaAbierta($colaborador));

        if ($motivo !== null) {
            throw ValidationException::withMessages(['colaborador' => $motivo]);
        }

        return $this->cartas->vistaPrevia($colaborador, $documentTypeIds);
    }

    /**
     * Genera la carta (CartaCompromisoService::generar: PDF al expediente,
     * aviso a RH y a quien entra para que la firme).
     *
     * @param  list<int>  $documentTypeIds
     *
     * @throws ValidationException
     */
    public function generar(Colaborador $colaborador, array $documentTypeIds, User $actor): CartaCompromiso
    {
        $this->exigirElegible($colaborador);

        $carta = $this->cartas->generar($colaborador, $documentTypeIds, $actor);

        // Si esperaba la carta automática (cerró su registro con más
        // faltantes que el máximo), esta la resuelve: no se genera otra.
        $usuario = $colaborador->user;

        if ($usuario !== null && $this->registro->cartaDiferida($usuario)) {
            $this->registro->resolverCartaDiferida($usuario, $carta);
        }

        return $carta;
    }

    /**
     * Buscador del formulario (sin mínimo de letras: lista completa al
     * enfocar, filtra al escribir; cada palabra en nombre, número, puesto,
     * departamento o sucursal): personas de nuevo ingreso del alcance.
     *
     * @return list<array{id: int, nombre: string, numero_empleado: string|null, puesto: string|null, sucursal: string|null}>
     */
    public function buscarColaboradores(User $usuario, string $termino, int $limite = 50): array
    {
        $query = Colaborador::query()
            ->with(['sucursalPrincipal:id,nombre', 'puesto:id,nombre'])
            ->where(fn (Builder $q) => $q
                ->where('estatus', EstadoUsuario::EnIncorporacion->value)
                ->orWhere(fn (Builder $a) => $a
                    ->where('estatus', EstadoUsuario::Activo->value)
                    ->whereIn('estado_alta', array_map(fn (EstadoAltaColaborador $e): string => $e->value, self::estadosAltaNuevoIngreso()))))
            ->whereDoesntHave('user.roles', fn (Builder $r) => $r->where('name', 'super_admin'))
            ->when($usuario->colaborador_id !== null, fn (Builder $q) => $q->where('id', '!=', $usuario->colaborador_id));

        $this->alcance->limitarPorModulo($query, $usuario, self::MODULO, 'id');
        $this->buscador->aplicar($query, $termino);

        return array_values($query->orderBy('name')->orderBy('apellidos')->limit($limite)->get()
            ->filter(fn (Colaborador $c): bool => $this->alcanza($usuario, $c))
            ->map(fn (Colaborador $c): array => $this->resumenColaborador($c))
            ->values()
            ->all());
    }

    /**
     * Cartas del alcance del usuario (nunca la propia).
     *
     * @return Builder<CartaCompromiso>
     */
    public function consulta(User $usuario): Builder
    {
        $query = CartaCompromiso::query()
            ->when($usuario->colaborador_id !== null, fn (Builder $q) => $q->where('colaborador_id', '!=', $usuario->colaborador_id));

        return $this->alcance->limitarPorModulo($query, $usuario, self::MODULO, 'colaborador_id', true);
    }

    /**
     * @param  array<string, mixed>  $filtros  pestana, busqueda, per_page
     * @return LengthAwarePaginator<int, CartaCompromiso>
     */
    public function listado(User $usuario, array $filtros): LengthAwarePaginator
    {
        $pestana = in_array($filtros['pestana'] ?? null, self::PESTANAS, true) ? (string) $filtros['pestana'] : 'todas';
        $query = $this->consulta($usuario)
            ->with(['colaborador' => fn ($q) => $q->withTrashed()->with(['sucursalPrincipal:id,nombre', 'puesto:id,nombre'])]);

        $this->aplicarPestana($query, $pestana);

        // Folio o persona (criterio único de BuscadorColaboradoresService).
        $termino = trim((string) ($filtros['busqueda'] ?? ''));

        if ($termino !== '') {
            $query->where(fn (Builder $b) => $this->buscador->aplicarPorColumna(
                $b->where('folio', 'like', sprintf('%%%s%%', $termino)),
                $termino,
                'colaborador_id',
                'or',
            ));
        }

        // Primero las pendientes de firma, luego por fecha límite.
        return $query->orderByRaw('firmada_en is not null')
            ->orderBy('fecha_limite')
            ->orderByDesc('id')
            ->paginate(Paginacion::porPagina(20, $filtros['per_page'] ?? null));
    }

    /**
     * Indicadores de la cabecera (sobre todo el alcance, no la página).
     *
     * @return array{vigentes: int, por_vencer: int, vencidas: int, cumplidas: int, firmadas: int, pendientes_firma: int}
     */
    public function indicadores(User $usuario): array
    {
        $contar = function (string $pestana) use ($usuario): int {
            $query = $this->consulta($usuario);
            $this->aplicarPestana($query, $pestana);

            return $query->count();
        };

        return [
            'vigentes' => $contar('vigentes'),
            'por_vencer' => $contar('por_vencer'),
            'vencidas' => $contar('vencidas'),
            'cumplidas' => $contar('cumplidas'),
            'firmadas' => $contar('firmadas'),
            'pendientes_firma' => $contar('pendientes_firma'),
        ];
    }

    /**
     * Fila de la lista.
     *
     * @return array<string, mixed>
     */
    public function presentar(CartaCompromiso $carta): array
    {
        $estado = $this->cartas->estadoEfectivo($carta);
        $colaborador = $carta->colaborador;

        return [
            'id' => $carta->id,
            'folio' => $carta->folio,
            'estado' => $estado->value,
            'estado_etiqueta' => $estado->etiqueta(),
            'por_vencer' => $this->porVencer($carta),
            'fecha_limite' => $carta->fecha_limite->toDateString(),
            'dias_restantes' => $carta->diasRestantes(),
            'generada_en' => $carta->generada_en->toIso8601String(),
            'firmada' => $carta->firmada(),
            'firmada_en' => $carta->firmada_en?->toIso8601String(),
            'documentos' => array_map(fn (array $d): string => (string) $d['nombre'], $carta->documentos),
            'colaborador' => $colaborador !== null ? $this->resumenColaborador($colaborador) : null,
            // «Ver» desde la lista (visor centrado); lo autoriza la Policy (descargar).
            'pdf_url' => route('rh.cartas-compromiso.pdf', $carta, false),
        ];
    }

    /**
     * Detalle con línea de tiempo y lo que el usuario puede hacer.
     *
     * @return array<string, mixed>
     */
    public function detalle(CartaCompromiso $carta, User $usuario): array
    {
        $carta->loadMissing(['generadaPor', 'firmaRegistradaPor', 'documentoFirmado', 'colaborador' => fn ($q) => $q->withTrashed()]);
        $colaborador = $carta->colaborador;
        $pendientes = $colaborador !== null ? $this->pendientes->idsPendientes($colaborador) : [];
        $generadaPorLaPersona = $carta->generada_por !== null && $carta->generada_por === $colaborador?->user?->id;

        $eventos = [[
            'clave' => 'generada',
            'titulo' => 'Carta generada',
            'detalle' => $generadaPorLaPersona
                ? 'La propia persona, al cerrar su registro en la app.'
                : sprintf('Por %s.', $carta->generadaPor?->nombreCompleto() ?? 'el sistema'),
            'fecha' => $carta->generada_en->toIso8601String(),
        ]];

        if ($carta->firmada_en !== null) {
            $eventos[] = [
                'clave' => 'firmada',
                'titulo' => 'Carta firmada',
                'detalle' => sprintf('Escaneo firmado subido por %s.', $carta->firmaRegistradaPor?->nombreCompleto() ?? 'Recursos Humanos'),
                'fecha' => $carta->firmada_en->toIso8601String(),
            ];
        }

        foreach ([
            ['cumplida', 'Documentos entregados', 'La persona ya subió todos los documentos comprometidos.', $carta->cumplida_en],
            ['vencida', 'Plazo vencido', 'Venció con documentos por entregar: la app quedó limitada a subirlos.', $carta->vencida_en],
            ['sustituida', 'Sustituida', 'Se generó una carta nueva para esta persona.', $carta->sustituida_en],
        ] as [$clave, $titulo, $detalle, $fecha]) {
            if ($fecha !== null) {
                $eventos[] = ['clave' => $clave, 'titulo' => $titulo, 'detalle' => $detalle, 'fecha' => $fecha->toIso8601String()];
            }
        }

        usort($eventos, fn (array $a, array $b): int => strcmp($a['fecha'], $b['fecha']));

        return [
            ...$this->presentar($carta),
            'cumplida_en' => $carta->cumplida_en?->toIso8601String(),
            'vencida_en' => $carta->vencida_en?->toIso8601String(),
            'dias_plazo' => $this->cartas->diasPlazo(),
            'generada_por' => $generadaPorLaPersona ? 'La propia persona (registro en la app)' : $carta->generadaPor?->nombreCompleto(),
            'firma_registrada_por' => $carta->firmaRegistradaPor?->nombreCompleto(),
            'documentos_detalle' => array_map(fn (array $d): array => [
                'document_type_id' => (int) $d['document_type_id'],
                'nombre' => (string) $d['nombre'],
                'entregado' => ! in_array((int) $d['document_type_id'], $pendientes, true),
            ], $carta->documentos),
            'eventos' => $eventos,
            'archivo_firmado' => $carta->documentoFirmado !== null ? [
                'nombre' => $carta->documentoFirmado->original_name,
                'mime' => $carta->documentoFirmado->mime,
                'url' => route('rh.cartas-compromiso.firmada', $carta, false),
            ] : null,
            'pdf_url' => route('rh.cartas-compromiso.pdf', $carta, false),
            'puede' => [
                'descargar' => $usuario->can('descargar', $carta),
                'subir_firmada' => $usuario->can('subirFirmada', $carta),
            ],
        ];
    }

    /**
     * ¿Vigente y a dias_por_vencer() días o menos del límite?
     */
    public function porVencer(CartaCompromiso $carta): bool
    {
        return $this->cartas->estadoEfectivo($carta) === EstadoCartaCompromiso::Vigente
            && $carta->diasRestantes() <= $this->diasPorVencer();
    }

    /**
     * @param  Builder<CartaCompromiso>  $query
     */
    private function aplicarPestana(Builder $query, string $pestana): void
    {
        $hoy = Carbon::today()->toDateString();
        $vigente = EstadoCartaCompromiso::Vigente->value;

        match ($pestana) {
            'vigentes' => $query->where('estado', $vigente)->where('fecha_limite', '>=', $hoy),
            'por_vencer' => $query->where('estado', $vigente)->where('fecha_limite', '>=', $hoy)
                ->where('fecha_limite', '<=', Carbon::today()->addDays($this->diasPorVencer())->toDateString()),
            'vencidas' => $query->where(fn (Builder $q) => $q
                ->where('estado', EstadoCartaCompromiso::Vencida->value)
                ->orWhere(fn (Builder $v) => $v->where('estado', $vigente)->where('fecha_limite', '<', $hoy))),
            'cumplidas' => $query->where('estado', EstadoCartaCompromiso::Cumplida->value),
            'firmadas' => $query->whereNotNull('firmada_en')->where('estado', '!=', EstadoCartaCompromiso::Sustituida->value),
            'pendientes_firma' => $query->whereNull('firmada_en')->where('estado', '!=', EstadoCartaCompromiso::Sustituida->value),
            'sustituidas' => $query->where('estado', EstadoCartaCompromiso::Sustituida->value),
            default => $query->where('estado', '!=', EstadoCartaCompromiso::Sustituida->value),
        };
    }

    /** Vigente o vencida (no sustituida ni cumplida). */
    private function cartaAbierta(Colaborador $colaborador): ?CartaCompromiso
    {
        $carta = $this->cartas->ultima($colaborador);

        return $carta !== null && in_array($carta->estado, [EstadoCartaCompromiso::Vigente, EstadoCartaCompromiso::Vencida], true) ? $carta : null;
    }

    private function motivoNoElegible(Colaborador $colaborador, int $pendientes, ?CartaCompromiso $abierta): ?string
    {
        $maximo = $this->cartas->maxDocumentos();

        return match (true) {
            $colaborador->user?->hasRole('super_admin') === true => 'Las cuentas de super administrador no reciben carta compromiso.',
            ! $this->esNuevoIngreso($colaborador) => 'Solo el personal de nuevo ingreso (en contratación u onboarding) puede firmar una carta compromiso. El personal que ya laboraba debe subir sus documentos.',
            $abierta !== null => sprintf('Ya tiene la carta %s (%s). Una persona solo puede tener una carta abierta: al entregar sus documentos queda cumplida.', (string) $abierta->folio, mb_strtolower($this->cartas->estadoEfectivo($abierta)->etiqueta(), 'UTF-8')),
            $pendientes === 0 => 'No le falta ningún documento obligatorio: no necesita carta compromiso.',
            $pendientes > $maximo => sprintf(
                'Le faltan %d documentos y una carta cubre como máximo %d. Mientras tanto la app le pide subirlos; en cuanto le falten %d o menos, la carta se genera sola.',
                $pendientes,
                $maximo,
                $maximo,
            ),
            default => null,
        };
    }

    /**
     * Motivo por el que ni siquiera se puede VER la carta (super admin, no
     * es nuevo ingreso, ya tiene una abierta, no le falta nada). Tener más
     * faltantes que el máximo no impide verla: se evalúa como si tuviera
     * justo el máximo.
     */
    private function motivoSinVistaPrevia(Colaborador $colaborador, int $pendientes, ?CartaCompromiso $abierta): ?string
    {
        return $this->motivoNoElegible($colaborador, min($pendientes, $this->cartas->maxDocumentos()), $abierta);
    }

    /**
     * @throws ValidationException
     */
    private function exigirElegible(Colaborador $colaborador): void
    {
        $motivo = $this->motivoNoElegible($colaborador, count($this->pendientes->idsPendientes($colaborador)), $this->cartaAbierta($colaborador));

        if ($motivo !== null) {
            throw ValidationException::withMessages(['colaborador' => $motivo]);
        }
    }

    /**
     * @return array{id: int, nombre: string, numero_empleado: string|null, puesto: string|null, sucursal: string|null}
     */
    private function resumenColaborador(Colaborador $colaborador): array
    {
        return [
            'id' => $colaborador->id,
            'nombre' => $colaborador->nombreCompleto(),
            'numero_empleado' => $colaborador->numero_empleado,
            'puesto' => $colaborador->puesto?->nombre,
            'sucursal' => $colaborador->sucursalPrincipal?->nombre,
        ];
    }
}
