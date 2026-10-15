<?php

use App\Enums\CanalReclutamiento;
use App\Enums\CriterioFechaReporteCampanas;
use App\Enums\EstadoCampanaReclutamiento;
use App\Enums\EstadoCandidato;
use App\Exports\ReporteCampanasExport;
use App\Models\CampanaReclutamiento;
use App\Models\Candidato;
use App\Models\Sucursal;
use App\Models\User;
use App\Models\Vacante;
use App\Services\Pdf\BrowsershotRenderer;
use App\Services\Pdf\OpcionesPdf;
use App\Services\Reclutamiento\FiltrosReporteCampanas;
use App\Services\Reclutamiento\ReporteCampanasService;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Carbon;
use Maatwebsite\Excel\Excel as TipoExcel;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\IOFactory;

/*
 * Reporte de campañas con filtros: rango de fechas (vigencia de la campaña
 * o registro del candidato), campañas puntuales o general, canal, estado,
 * ubicación, vacante, responsable y fase del candidato. Los mismos filtros
 * arman la pantalla, el PDF y el Excel, y respetan el alcance del usuario.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-10-15 10:00:00');
    $this->seed(RolesYPermisosSeeder::class);
    $this->rh = clUsuario('rh_admin');
    $this->estructura = clEstructura();
    $this->vacante = Vacante::factory()->create([
        'empresa_id' => $this->estructura['empresa']->id,
        'sucursal_id' => $this->estructura['sucursal']->id,
        'puesto_id' => $this->estructura['puesto']->id,
    ]);
    $this->servicio = app(ReporteCampanasService::class);

    $this->campana = function (array $atributos = []): CampanaReclutamiento {
        return CampanaReclutamiento::query()->create([
            'vacante_id' => $this->vacante->id,
            'empresa_id' => $this->estructura['empresa']->id,
            'sucursal_id' => $this->estructura['sucursal']->id,
            'puesto_id' => $this->estructura['puesto']->id,
            'canal' => 'meta',
            'mes' => 10,
            'anio' => 2026,
            'monto' => 1000,
            ...$atributos,
        ]);
    };

    $this->candidato = function (CampanaReclutamiento $campana, string $registrado, array $atributos = []): Candidato {
        $candidato = Candidato::factory()->create([
            'campana_reclutamiento_id' => $campana->id,
            'vacante_id' => $this->vacante->id,
            'sucursal_id' => $this->estructura['sucursal']->id,
            'etapa_maxima' => 1,
            ...$atributos,
        ]);
        $candidato->forceFill(['created_at' => Carbon::parse($registrado)])->save();

        return $candidato;
    };

    $this->filtros = fn (array $datos = []): FiltrosReporteCampanas => FiltrosReporteCampanas::desdeArreglo($datos);
    $this->ids = fn (array $reporte): array => array_map(fn (array $c) => $c['id'], $reporte['campanas']);
});

afterEach(function () {
    Carbon::setTestNow();
});

test('por vigencia entran las campañas que se cruzan con el rango, las abiertas y las históricas por su mes', function () {
    $dentro = ($this->campana)(['nombre' => 'Dentro', 'fecha_inicio' => '2026-09-20', 'fecha_fin' => '2026-10-05']);
    $abierta = ($this->campana)(['nombre' => 'Abierta', 'fecha_inicio' => '2026-08-01', 'fecha_fin' => null]);
    $historica = ($this->campana)(['nombre' => 'Histórica', 'fecha_inicio' => null, 'mes' => 10, 'anio' => 2026]);
    ($this->campana)(['nombre' => 'Antes', 'fecha_inicio' => '2026-08-01', 'fecha_fin' => '2026-09-30']);
    ($this->campana)(['nombre' => 'Después', 'fecha_inicio' => '2026-11-01', 'fecha_fin' => null, 'mes' => 11]);
    ($this->campana)(['nombre' => 'Histórica de otro mes', 'fecha_inicio' => null, 'mes' => 9, 'anio' => 2026]);

    $reporte = $this->servicio->reporte(($this->filtros)(['desde' => '2026-10-01', 'hasta' => '2026-10-31']), $this->rh, true);

    expect(($this->ids)($reporte))->toEqualCanonicalizing([$dentro->id, $abierta->id, $historica->id])
        ->and($reporte['periodo'])->toBe('Del 01/10/2026 al 31/10/2026');
});

test('por fecha de registro solo cuentan los candidatos registrados en el rango y se recuentan los contratados', function () {
    $campana = ($this->campana)(['fecha_inicio' => '2026-09-01', 'monto' => 3000]);
    ($this->candidato)($campana, '2026-09-10', ['estado' => EstadoCandidato::Contratado->value]);
    ($this->candidato)($campana, '2026-10-02', ['estado' => EstadoCandidato::Contratado->value]);
    ($this->candidato)($campana, '2026-10-03');
    $sinCandidatosEnRango = ($this->campana)(['fecha_inicio' => '2026-10-01']);
    ($this->candidato)($sinCandidatosEnRango, '2026-09-15');

    $reporte = $this->servicio->reporte(($this->filtros)([
        'desde' => '2026-10-01', 'hasta' => '2026-10-31', 'criterio_fecha' => CriterioFechaReporteCampanas::Registro->value,
    ]), $this->rh, true);

    expect(($this->ids)($reporte))->toBe([$campana->id]);
    $fila = $reporte['campanas'][0];
    expect($fila['llegaron'])->toBe(2)
        ->and($fila['contratados'])->toBe(1)
        ->and($fila['costo_por_contratado'])->toEqual(3000)
        ->and($fila['costo_por_candidato'])->toEqual(1500)
        ->and($reporte['totales']['contratados'])->toBe(1)
        ->and($reporte['totales']['gasto'])->toEqual(3000);

    // Por vigencia la misma campaña cuenta a todos sus candidatos.
    $vigencia = $this->servicio->reporte(($this->filtros)(['desde' => '2026-10-01', 'hasta' => '2026-10-31']), $this->rh, true);
    $filaVigencia = collect($vigencia['campanas'])->firstWhere('id', $campana->id);
    expect($filaVigencia['llegaron'])->toBe(3)->and($filaVigencia['contratados'])->toBe(2);
});

test('filtra por campañas elegidas, canal, estado, responsable, vacante, sucursal y fase del candidato', function () {
    $responsable = User::factory()->create(['name' => 'Laura', 'apellidos' => 'Reclutadora']);
    $meta = ($this->campana)(['nombre' => 'Meta vigente', 'fecha_inicio' => '2026-10-01', 'responsable_id' => $responsable->id]);
    $indeed = ($this->campana)(['nombre' => 'Indeed concluida', 'canal' => 'indeed', 'fecha_inicio' => '2026-10-01', 'fecha_fin' => '2026-10-10']);
    $programada = ($this->campana)(['nombre' => 'Programada', 'fecha_inicio' => '2026-10-25']);
    $otraSucursal = Sucursal::factory()->create(['empresa_id' => $this->estructura['empresa']->id]);
    $lejana = ($this->campana)(['nombre' => 'Otra sucursal', 'fecha_inicio' => '2026-10-01', 'sucursal_id' => $otraSucursal->id, 'vacante_id' => null]);
    $base = ['desde' => '2026-10-01', 'hasta' => '2026-10-31'];

    $reporte = fn (array $extra) => ($this->ids)($this->servicio->reporte(($this->filtros)([...$base, ...$extra]), $this->rh, true));

    expect($reporte([]))->toHaveCount(4)
        ->and($reporte(['campanas' => [(string) $meta->id, (string) $indeed->id]]))->toEqualCanonicalizing([$meta->id, $indeed->id])
        ->and($reporte(['canales' => [CanalReclutamiento::Indeed->value]]))->toBe([$indeed->id])
        ->and($reporte(['estados' => [EstadoCampanaReclutamiento::Concluida->value]]))->toBe([$indeed->id])
        ->and($reporte(['estados' => [EstadoCampanaReclutamiento::Programada->value]]))->toBe([$programada->id])
        ->and($reporte(['estados' => [EstadoCampanaReclutamiento::Vigente->value]]))->toEqualCanonicalizing([$meta->id, $lejana->id])
        ->and($reporte(['responsable_id' => $responsable->id]))->toBe([$meta->id])
        ->and($reporte(['sucursal_id' => $otraSucursal->id]))->toBe([$lejana->id])
        ->and($reporte(['vacante_id' => $this->vacante->id]))->toHaveCount(3);

    ($this->candidato)($meta, '2026-10-02', ['estado' => EstadoCandidato::Contratado->value]);
    ($this->candidato)($meta, '2026-10-03', ['estado' => EstadoCandidato::Desistio->value]);
    ($this->candidato)($meta, '2026-10-04', ['estado' => EstadoCandidato::EntrevistaPendiente->value]);

    $porFase = $this->servicio->reporte(($this->filtros)([...$base, 'campanas' => [$meta->id], 'fases' => ['contratado', 'desistido']]), $this->rh, true);
    expect($porFase['campanas'][0]['llegaron'])->toBe(2)
        ->and($porFase['campanas'][0]['contratados'])->toBe(1)
        ->and($porFase['campanas'][0]['no_continuaron'])->toBe(1);
});

test('los filtros aplicados se describen en texto para el encabezado', function () {
    $campana = ($this->campana)(['nombre' => 'Gestores octubre', 'fecha_inicio' => '2026-10-01']);

    $general = $this->servicio->filtrosAplicados(($this->filtros)(['desde' => '2026-10-01', 'hasta' => '2026-10-31']));
    expect($general)->toBe([
        ['etiqueta' => 'Periodo', 'valor' => 'Del 01/10/2026 al 31/10/2026'],
        ['etiqueta' => 'Fecha que se filtra', 'valor' => 'Campañas vigentes en el rango'],
        ['etiqueta' => 'Campañas', 'valor' => 'Todas (reporte general)'],
    ]);

    $puntual = collect($this->servicio->filtrosAplicados(($this->filtros)([
        'desde' => '2026-10-01', 'hasta' => '2026-10-31', 'campanas' => [$campana->id],
        'canales' => ['meta'], 'fases' => ['contratado'], 'sucursal_id' => $this->estructura['sucursal']->id,
    ])))->pluck('valor', 'etiqueta');

    expect($puntual['Campañas'])->toBe('Gestores octubre')
        ->and($puntual['Canal / medio'])->toBe('Meta Ads')
        ->and($puntual['Fase del candidato'])->toBe('Contratados')
        ->and($puntual['Sucursal'])->toBe('Cuernavaca');
});

test('sin fechas toma el mes en curso y el enlace anterior por mes/año se vuelve ese mes completo', function () {
    $actual = ($this->filtros)([]);
    $legado = ($this->filtros)(['mes' => 2, 'anio' => 2026]);
    $soloInicio = ($this->filtros)(['desde' => '2026-10-03']);

    expect($actual->desde->toDateString())->toBe('2026-10-01')
        ->and($actual->hasta->toDateString())->toBe('2026-10-31')
        ->and($legado->desde->toDateString())->toBe('2026-02-01')
        ->and($legado->hasta->toDateString())->toBe('2026-02-28')
        ->and($soloInicio->hasta->toDateString())->toBe('2026-10-15');
});

test('la pantalla del reporte refleja los filtros de la URL y las fechas se validan', function () {
    $campana = ($this->campana)(['nombre' => 'Gestores octubre', 'fecha_inicio' => '2026-10-01']);
    ($this->candidato)($campana, '2026-10-02');

    $this->actingAs($this->rh)
        ->get(route('rh.campanas.reporte', ['desde' => '2026-10-01', 'hasta' => '2026-10-31', 'campanas' => [$campana->id], 'canales' => ['meta']]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Rh/Campanas/Reporte')
            ->where('filtros.desde', '2026-10-01')
            ->where('filtros.hasta', '2026-10-31')
            ->where('filtros.campanas', [(string) $campana->id])
            ->where('filtros.canales', ['meta'])
            ->where('reporte.campanas.0.nombre', 'Gestores octubre')
            ->where('reporte.campanas.0.llegaron', 1)
            ->where('reporte.filtros_aplicados.2.valor', 'Gestores octubre')
            ->has('opciones.campanas', 1)
            ->has('opciones.fases'));

    $this->actingAs($this->rh)
        ->get(route('rh.campanas.reporte', ['desde' => '2026-10-31', 'hasta' => '2026-10-01']))
        ->assertRedirect(route('rh.campanas.reporte'))
        ->assertSessionHasErrors(['hasta' => 'La fecha de fin no puede ser anterior a la fecha de inicio.']);

    $this->actingAs($this->rh)
        ->get(route('rh.campanas.reporte', ['canales' => ['tiktok'], 'fases' => ['inventada']]))
        ->assertSessionHasErrors(['canales.0', 'fases.0']);

    $this->actingAs(clUsuario('colaborador'))->get(route('rh.campanas.reporte'))->assertForbidden();
    $this->actingAs(clUsuario('colaborador'))->get(route('rh.campanas.reporte-excel'))->assertForbidden();
});

test('el PDF y el Excel salen con los mismos filtros y los muestran en el encabezado', function () {
    $html = new ArrayObject;
    $this->app->instance(BrowsershotRenderer::class, new class($html) extends BrowsershotRenderer
    {
        /** @param ArrayObject<int, string> $html */
        public function __construct(private ArrayObject $html) {}

        public function renderizar(string $html, OpcionesPdf $opciones): string
        {
            $this->html->append($html);

            return '%PDF-1.4 prueba';
        }
    });

    $octubre = ($this->campana)(['nombre' => 'Gestores octubre', 'fecha_inicio' => '2026-10-01']);
    ($this->campana)(['nombre' => 'Campaña de agosto', 'fecha_inicio' => '2026-08-01', 'fecha_fin' => '2026-08-31']);
    ($this->candidato)($octubre, '2026-10-02');
    $query = ['desde' => '2026-10-01', 'hasta' => '2026-10-31', 'canales' => ['meta']];

    $this->actingAs($this->rh)->get(route('rh.campanas.reporte-pdf', $query))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf')
        ->assertHeader('Content-Disposition', 'inline; filename="campanas-2026-10-01-2026-10-31.pdf"');

    expect($html[0])->toContain('Filtros aplicados')
        ->toContain('Del 01/10/2026 al 31/10/2026')
        ->toContain('Meta Ads')
        ->toContain('Gestores octubre')
        ->not->toContain('Campaña de agosto');

    // Excel real: encabezado con filtros y la tabla de campañas debajo.
    $filtros = ($this->filtros)($query);
    $export = $this->servicio->excel($this->servicio->reporte($filtros, $this->rh, true), true);
    $ruta = tempnam(sys_get_temp_dir(), 'campanas').'.xlsx';
    file_put_contents($ruta, Excel::raw($export, TipoExcel::XLSX));
    $hoja = IOFactory::load($ruta)->getSheetByName('Campañas');
    @unlink($ruta);

    expect($hoja?->getCell('A4')->getValue())->toBe('Periodo')
        ->and($hoja?->getCell('B4')->getValue())->toBe('Del 01/10/2026 al 31/10/2026')
        ->and($hoja?->getCell('A7')->getValue())->toBe('Canal / medio')
        ->and($hoja?->getCell('A9')->getValue())->toBe('Campaña')
        ->and($hoja?->getCell('A10')->getValue())->toBe('Gestores octubre')
        ->and($hoja?->getCell('A11')->getValue())->toBe('TOTAL');

    Excel::fake();
    $this->actingAs($this->rh)->get(route('rh.campanas.reporte-excel', $query))->assertOk();
    Excel::assertDownloaded('campanas-2026-10-01-2026-10-31.xlsx', fn (ReporteCampanasExport $e) => true);
});

test('los candidatos del reporte respetan el alcance del usuario', function () {
    $otraSucursal = Sucursal::factory()->create(['empresa_id' => $this->estructura['empresa']->id]);
    $campana = ($this->campana)(['fecha_inicio' => '2026-10-01']);
    ($this->candidato)($campana, '2026-10-02', ['nombre' => 'Propia', 'estado' => EstadoCandidato::Contratado->value]);
    // Espontáneo (sin vacante) de otra sucursal: la sucursal no se re-deriva de la vacante.
    ($this->candidato)($campana, '2026-10-03', ['nombre' => 'Ajena', 'vacante_id' => null, 'sucursal_id' => $otraSucursal->id, 'estado' => EstadoCandidato::Contratado->value]);

    $gerente = clUsuario('gerente_sucursal', ['sucursal_principal_id' => $this->estructura['sucursal']->id]);
    $gerente->givePermissionTo('reclutamiento.campanas.ver');

    $filtros = ($this->filtros)(['desde' => '2026-10-01', 'hasta' => '2026-10-31']);
    $deRh = $this->servicio->reporte($filtros, $this->rh, true);
    $deGerente = $this->servicio->reporte($filtros, $gerente, true);

    expect($deRh['campanas'][0]['llegaron'])->toBe(2)
        ->and($deGerente['campanas'][0]['llegaron'])->toBe(1)
        ->and($deGerente['campanas'][0]['contratados'])->toBe(1)
        ->and(array_column($deGerente['campanas'][0]['candidatos'], 'nombre'))->each->toContain('Propia');

    $this->actingAs($gerente)->get(route('rh.campanas.reporte', ['desde' => '2026-10-01', 'hasta' => '2026-10-31']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('reporte.campanas.0.llegaron', 1));
});
