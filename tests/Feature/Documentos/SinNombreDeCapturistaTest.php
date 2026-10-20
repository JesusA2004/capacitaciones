<?php

use App\Enums\EstadoSolicitudInterna;
use App\Enums\EstadoUsuario;
use App\Enums\FamiliaAdministrativa;
use App\Enums\MotorPdf;
use App\Enums\TipoResponsiva;
use App\Enums\TipoSolicitudInterna;
use App\Models\ActaAdministrativa;
use App\Models\Colaborador;
use App\Models\Responsiva;
use App\Models\SolicitudInterna;
use App\Models\Sucursal;
use App\Models\User;
use App\Services\Actas\ActaPdfService;
use App\Services\Actas\ActaService;
use App\Services\DocumentosAdministrativos\DatosDocumentoAdministrativo;
use App\Services\DocumentosAdministrativos\DocumentoAdministrativoService;
use App\Services\DocumentosAdministrativos\PlantillasAdministrativasService;
use App\Services\DocumentosLaborales\MotorDocumentalService;
use App\Services\DocumentosMaestros\DatosDocumentoService;
use App\Services\Formatos\GeneradorFormatoService;
use App\Services\Formatos\Variables\ResolvedorVariablesFormato;
use App\Services\Responsivas\ResponsivaPdfService;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

/*
| Regla del usuario (documentos legales, 2026-10-20): NINGÚN documento
| generado imprime el nombre de la cuenta que lo capturó o generó (p. ej.
| «Sistemas Mrlana»). Por la empresa firma el representante legal con el
| nombre en blanco (pluma). Las personas reales del proceso (trabajador,
| testigos, jefe capturado) sí se conservan.
*/

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    Storage::fake('nas');
    Notification::fake();

    // Cuenta técnica sin colaborador ligado, como la de producción.
    $this->cuenta = User::factory()->create(['name' => 'Sistemas', 'apellidos' => 'Mrlana', 'colaborador_id' => null]);
    $this->cuenta->assignRole('rh_admin');

    $this->centro = Sucursal::factory()->create(['nombre' => 'Toluca Centro']);
    $this->trabajador = Colaborador::factory()->create(['name' => 'Adriana', 'apellidos' => 'Cortés Lima', 'sucursal_principal_id' => $this->centro->id, 'estatus' => EstadoUsuario::Activo]);
    $this->testigo = Colaborador::factory()->create(['name' => 'María', 'apellidos' => 'López Ruiz', 'sucursal_principal_id' => $this->centro->id, 'estatus' => EstadoUsuario::Activo]);
});

/** Texto plano en minúsculas y sin espacios: atrapa «Sistemas Mrlana», «SISTEMAS MR LANA»… */
function sncPlano(string $html): string
{
    return (string) preg_replace('/\s+/u', '', mb_strtolower(html_entity_decode(strip_tags($html)), 'UTF-8'));
}

function sncSinCuenta(string $html): void
{
    expect(sncPlano($html))->not->toContain('sistemasmr')
        ->and(sncPlano($html))->not->toContain('mrlanasistemas');
}

test('acta circunstanciada: firma el representante legal en blanco, nunca la cuenta que la levanta', function () {
    $this->actingAs($this->cuenta)->post(route('rh.actas.store', $this->trabajador), [
        'tipo' => 'administrativa',
        'fecha' => now()->toDateString(),
        'lugar' => 'Toluca, Estado de México',
        'hechos' => 'El arqueo de caja arrojó un faltante.',
        'circunstanciada' => [
            'incidencia' => ['tipo' => 'irregularidad_bienes', 'fecha_desde' => now()->subDay()->toDateString()],
            'responsable' => ['manifestacion' => 'Se constató el faltante.'],
            'jefe' => ['participa' => false],
            'testigos_hechos' => [['colaborador_id' => $this->testigo->id, 'relacion' => 'presencio_directamente', 'manifestacion' => 'Lo vi.']],
            'testigos_asistencia' => [],
            'trabajador' => ['manifesto' => false],
        ],
    ])->assertSessionHasNoErrors()->assertRedirect();

    $acta = ActaAdministrativa::query()->latest('id')->firstOrFail();
    // Peor caso: lo que guardaba la versión anterior (entrega e integración con la cuenta).
    $acta->update(['circunstanciada' => [
        ...$acta->circunstanciada,
        'entrega' => ['estado' => 'entregada', 'motivo' => null, 'por' => 'Sistemas Mrlana', 'en' => now()->toIso8601String()],
        'control' => ['fecha_integracion' => now()->toDateString(), 'integrado_por' => 'Sistemas Mrlana'],
    ]]);

    $html = app(ActaPdfService::class)->html($acta->refresh(), MotorPdf::DomPdf, true);

    sncSinCuenta($html);
    expect($html)->toContain('Representante legal')
        ->and($html)->toContain('PRODUCTOS Y SERVICIOS MR LANA, S.A.P.I. DE C.V.')
        ->and($html)->not->toContain('Responsable del levantamiento')
        ->and($html)->toContain('Adriana Cortés Lima')
        ->and($html)->toContain('María López Ruiz');
});

test('acta con el formato anterior y variables de la plantilla Word: sin la cuenta que la levantó', function () {
    $acta = ActaAdministrativa::query()->create([
        'colaborador_id' => $this->trabajador->id, 'responsable_id' => $this->cuenta->id, 'creado_por' => $this->cuenta->id, 'tipo' => 'hechos', 'folio' => 'ACT-SNC-1',
        'fecha' => now()->toDateString(), 'hora' => '10:00', 'motivo' => 'Prueba', 'hechos' => 'Hechos de prueba.',
        'testigos' => [['nombre' => 'Ana Ruiz', 'puesto' => 'Cajera'], ['nombre' => 'Luis Mora', 'puesto' => 'Gestor']],
        'declaraciones' => [['persona' => 'Colaborador', 'declaracion' => 'Sin comentarios.']],
    ]);

    sncSinCuenta(app(ActaPdfService::class)->html($acta, MotorPdf::DomPdf));
    expect(app(ActaService::class)->variablesDocumento($acta)['responsable_acta'])->toBe('');

    // Masters Word (acta por negativa, acta general): el representante de RH
    // nunca se propone con la cuenta que genera.
    $motor = app(MotorDocumentalService::class);
    $valores = app(DatosDocumentoService::class)->resolver($motor->contextoDesde($this->trabajador, null, $this->cuenta));
    expect($valores['rh_nombre'])->toBe('')
        ->and($valores['rh_cargo'])->toBe('');
});

test('permiso, comprobante y formatos oficiales: sin la cuenta que autorizó o generó', function () {
    $permiso = new SolicitudInterna([
        'tipo' => TipoSolicitudInterna::Permiso, 'estado' => EstadoSolicitudInterna::Aprobada, 'folio' => 'SOL-SNC-1',
        'fecha_inicio' => now()->toDateString(), 'fecha_fin' => now()->toDateString(), 'dias_solicitados' => 1, 'motivo' => 'Trámite personal',
        'permiso_tipo' => 'dia_completo', 'permiso_goce' => 'con_goce', 'revisado_por' => $this->cuenta->id, 'revisado_en' => now(),
    ]);
    $permiso->setRelation('revisadoPor', $this->cuenta);
    $vacaciones = new SolicitudInterna([
        'tipo' => TipoSolicitudInterna::Vacaciones, 'estado' => EstadoSolicitudInterna::Aprobada, 'folio' => 'VAC-SNC-1',
        'fecha_inicio' => now()->toDateString(), 'fecha_fin' => now()->toDateString(), 'dias_solicitados' => 1, 'motivo' => 'Descanso',
        'revisado_por' => $this->cuenta->id, 'revisado_en' => now(),
    ]);
    $vacaciones->setRelation('revisadoPor', $this->cuenta);
    $vacaciones->setRelation('diasVacaciones', collect());

    $datos = app(DatosDocumentoAdministrativo::class);
    $plantillas = app(PlantillasAdministrativasService::class);
    $admin = app(DocumentoAdministrativoService::class);

    foreach ([
        [FamiliaAdministrativa::Permiso, $datos->permiso($permiso, $this->trabajador)],
        [FamiliaAdministrativa::ComprobanteSolicitud, $datos->comprobante($vacaciones, $this->trabajador)],
    ] as [$familia, $valores]) {
        // También en los textos editables ({{ autorizo }}): solo el área.
        expect(sncPlano((string) ($valores['autorizo'] ?? '')))->not->toContain('sistemasmr');
        sncSinCuenta($admin->html($familia, $valores, $plantillas->vigente($familia)['diseno'], MotorPdf::DomPdf));
    }

    $variables = app(ResolvedorVariablesFormato::class)->resolver(app(GeneradorFormatoService::class)->contextoDesde($this->trabajador, $permiso, $this->cuenta));
    expect($variables['otros.generado_por'])->toBeNull()
        ->and($variables['solicitud.autorizo'])->toBeNull();
});

test('responsiva: por la empresa firma el representante legal, nunca quien entrega', function () {
    $responsiva = new Responsiva([
        'tipo' => TipoResponsiva::EquipoElectronico, 'folio' => 'RESP-SNC-1', 'lugar' => 'Toluca', 'fecha_entrega' => now()->toDateString(), 'datos' => [],
        'snapshot' => [
            'receptor' => ['nombre' => $this->trabajador->nombreCompleto()],
            'otorgante' => ['nombre' => 'Sistemas Mrlana', 'puesto' => 'Sistemas'],
            'empresa' => ['nombre' => 'Mr. Lana'],
        ],
    ]);
    $pdf = app(ResponsivaPdfService::class);
    $html = $pdf->html($pdf->datos($responsiva), MotorPdf::DomPdf, true);

    sncSinCuenta($html);
    expect($html)->toContain('REPRESENTANTE LEGAL')
        ->and(mb_strtoupper($html, 'UTF-8'))->toContain('ADRIANA CORTÉS LIMA');
});
