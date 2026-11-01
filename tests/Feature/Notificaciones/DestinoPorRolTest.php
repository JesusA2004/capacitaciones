<?php

use App\Enums\CategoriaNotificacion;
use App\Enums\EstadoCierreLaboral;
use App\Enums\EstadoIntervencionCandidato;
use App\Enums\EstatusBaja;
use App\Enums\GrupoPuestoIndicador;
use App\Enums\OrigenAgendaEvento;
use App\Enums\RutaIntervencionCandidato;
use App\Enums\TipoAgendaEvento;
use App\Enums\TipoBaja;
use App\Models\ActaAdministrativa;
use App\Models\AgendaEvento;
use App\Models\Baja;
use App\Models\Candidato;
use App\Models\CausalRotacion;
use App\Models\CierreLaboral;
use App\Models\Colaborador;
use App\Models\ContratoLaboral;
use App\Models\EmployeeDocument;
use App\Models\EvaluacionPeriodoPrueba;
use App\Models\IntervencionCandidato;
use App\Models\Puesto;
use App\Models\SolicitudExcepcionPeriodoPrueba;
use App\Models\SolicitudInterna;
use App\Models\Sucursal;
use App\Models\User;
use App\Services\Bajas\BajaConsultaService;
use App\Services\Notificaciones\Aviso;
use App\Services\Notificaciones\NotificacionEventosService;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

/*
| Todo aviso entregado lleva a un destino que su destinatario puede abrir: por
| cada clave operativa y cada rol marcado, en web y en la API que usa la app.
*/

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    Storage::fake('nas');
    $this->sucursal = Sucursal::factory()->create();
    $this->persona = Colaborador::factory()->create(['sucursal_principal_id' => $this->sucursal->id]);
    clUsuarioDe($this->persona);
});

/**
 * Recurso real de cada familia de avisos.
 */
function dpRecurso(string $clave, Colaborador $persona, Sucursal $sucursal, string $rol = 'rh_admin'): mixed
{
    // Dirección Comercial revisa Subgerentes y Gerentes; el resto, Gestores.
    $puesto = $rol === 'director_comercial'
        ? Puesto::query()->firstOrCreate(['nombre' => 'Gerente de Sucursal'], Puesto::factory()->raw(['nombre' => 'Gerente de Sucursal']))
        : Puesto::factory()->create(['grupo_indicador' => GrupoPuestoIndicador::Gestores]);
    $candidato = fn () => Candidato::factory()->create(['sucursal_id' => $sucursal->id, 'puesto_objetivo_id' => $puesto->id, 'vacante_id' => null]);
    $creador = User::factory()->create();
    $baja = fn () => Baja::query()->create([
        'folio' => 'BJ-'.fake()->unique()->numerify('######'), 'colaborador_id' => $persona->id, 'nombre_colaborador' => $persona->nombreCompleto(),
        'sucursal_id' => $sucursal->id, 'estatus' => EstatusBaja::EnRevisionRh, 'creado_por' => $creador->id,
    ]);
    $cierre = fn () => CierreLaboral::query()->create([
        'colaborador_id' => $persona->id, 'tipo_baja' => TipoBaja::Renuncia, 'motivo' => 'Prueba',
        'fecha_efectiva' => now()->toDateString(), 'estado' => EstadoCierreLaboral::Iniciado,
    ]);

    return match (true) {
        str_starts_with($clave, 'solicitud_'), str_starts_with($clave, 'correccion_') => SolicitudInterna::factory()->create(['user_id' => $persona->user->id, 'colaborador_id' => $persona->id]),
        str_starts_with($clave, 'candidato_') => $candidato(),
        $clave === 'intervencion_solicitada' => IntervencionCandidato::query()->create([
            'candidato_id' => $candidato()->id, 'ruta' => RutaIntervencionCandidato::Regional, 'gerente_solicitante_id' => $creador->id,
            'motivo_solicitud' => 'Revisar', 'solicitada_en' => now(), 'estado' => EstadoIntervencionCandidato::Pendiente,
        ]),
        str_starts_with($clave, 'agenda_') => AgendaEvento::query()->create([
            'candidato_id' => $candidato()->id, 'sucursal_id' => $sucursal->id, 'tipo' => TipoAgendaEvento::Entrevista,
            'fecha_hora' => now()->addDay(), 'origen' => OrigenAgendaEvento::Reclutamiento, 'agendado_por' => $creador->id,
        ]),
        $clave === 'documento_subido' => EmployeeDocument::factory()->create(['colaborador_id' => $persona->id]),
        in_array($clave, ['contrato_por_vencer', 'contrato_pendiente'], true) => ContratoLaboral::factory()->create(['colaborador_id' => $persona->id]),
        $clave === 'excepcion_periodo_prueba_solicitada' => SolicitudExcepcionPeriodoPrueba::factory()->create(),
        $clave === 'evaluacion_capturada' => EvaluacionPeriodoPrueba::factory()->create(['colaborador_id' => $persona->id]),
        in_array($clave, ['baja_alerta_contraloria', 'baja_alerta_juridico', 'baja_rotacion_temprana', 'baja_alerta_clima_laboral'], true) => $baja(),
        in_array($clave, ['cierre_baja_solicitada', 'cierre_negativa_firma', 'finiquito_pendiente', 'finiquito_por_autorizar', 'cierre_por_concluir'], true) => $cierre(),
        $clave === 'acta_registrada' => ActaAdministrativa::factory()->create(['colaborador_id' => $persona->id]),
        default => $persona,
    };
}

/**
 * Endpoint de la API que la app abre para este recurso (src/utils/appLinks.ts).
 */
function dpEndpointApp(mixed $recurso): ?string
{
    return match (true) {
        $recurso instanceof Colaborador => "/api/v1/rh/colaboradores/{$recurso->id}",
        $recurso instanceof Candidato => "/api/v1/rh/candidatos/{$recurso->id}",
        $recurso instanceof CierreLaboral => "/api/v1/rh/cierres/{$recurso->id}",
        default => null,
    };
}

/**
 * @return list<array{0: string, 1: string}>
 */
function dpParejas(): array
{
    $parejas = [];

    foreach ((require __DIR__.'/../../../config/notificaciones_catalogo.php')['eventos'] as $clave => $evento) {
        if ($evento['audiencia'] !== 'operativa' || in_array('*', $evento['defecto'] ?? [], true) || $clave === 'rh_cumpleanos') {
            continue;
        }

        foreach (array_unique([...($evento['defecto'] ?? []), ...($evento['obligatorios'] ?? [])]) as $rol) {
            $parejas[sprintf('%s → %s', $clave, $rol)] = [$clave, $rol];
        }
    }

    return $parejas;
}

test('el aviso abre un destino que el rol puede ver, en web y en la app', function (string $clave, string $rol) {
    $usuario = clUsuario($rol, ['sucursal_principal_id' => $this->sucursal->id]);
    $recurso = dpRecurso($clave, $this->persona, $this->sucursal, $rol);
    $aviso = new Aviso($clave, $clave, 'Título', 'Texto', $recurso, 'ver_detalle');

    expect(app(NotificacionEventosService::class)->entregar($usuario, CategoriaNotificacion::Resultado, $aviso))->toBeTrue();

    $id = $usuario->notifications()->value('id');

    $web = $this->actingAs($usuario)->followingRedirects()->get("/notificaciones/{$id}/ir");
    expect($web->getStatusCode())->toBe(200, sprintf('Web: %s con %s respondió %d', $clave, $rol, $web->getStatusCode()));

    Sanctum::actingAs($usuario);
    $this->postJson("/api/v1/notificaciones/{$id}/abrir")->assertOk();

    // Lo que la app abriría con el payload: nunca un 403.
    $endpoint = dpEndpointApp($recurso);

    if ($endpoint !== null && $usuario->can('mobile.bootstrap.ver')) {
        expect($this->getJson($endpoint)->getStatusCode())->not->toBe(403, sprintf('App: %s con %s respondió 403 en %s', $clave, $rol, $endpoint));
    }
})->with(dpParejas());

test('préstamo disponible no lleva al colaborador a una pantalla de RH', function () {
    $usuario = $this->persona->user;
    $aviso = new Aviso('prestamo_disponible', 'prestamo_disponible', 'Ya puedes', 'Texto', $this->persona, 'solicitar_prestamo', 'media', [], 'prestamo_disponible', payloadSinRecurso: true);

    app(NotificacionEventosService::class)->avisarResultado($usuario, $aviso);

    $notificacion = $usuario->notifications()->first();
    expect($notificacion->data['related_type'] ?? null)->toBeNull()
        ->and($notificacion->data['resource_id'] ?? null)->toBeNull();

    $this->actingAs($usuario)->get("/notificaciones/{$notificacion->id}/ir")->assertRedirectContains('/solicitudes');
});

test('Contraloría abre la baja de su aviso, no el expediente', function () {
    $contraloria = clUsuario('contraloria', ['sucursal_principal_id' => $this->sucursal->id]);
    $baja = dpRecurso('baja_alerta_contraloria', $this->persona, $this->sucursal);
    // Contraloría sólo consulta las causales que le avisan (RE-02, RE-03).
    $clave = app(BajaConsultaService::class)->clavesContraloria()[0];
    $baja->update(['causal_rotacion_id' => CausalRotacion::query()->where('clave', $clave)->value('id')]);
    app(NotificacionEventosService::class)->entregar($contraloria, CategoriaNotificacion::Resultado, new Aviso('baja_alerta_contraloria', 'baja', 'Aviso', 'Texto', $baja, 'ver_baja'));

    $this->actingAs($contraloria)->get('/notificaciones/'.$contraloria->notifications()->value('id').'/ir')
        ->assertRedirectContains('/bajas/'.$baja->id);
});
