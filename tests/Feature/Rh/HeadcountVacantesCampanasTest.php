<?php

use App\Enums\EstadoCandidato;
use App\Models\CampanaReclutamiento;
use App\Models\Candidato;
use App\Models\Colaborador;
use App\Models\HeadcountTarget;
use App\Models\Puesto;
use App\Models\Sucursal;
use App\Models\Vacante;
use App\Services\Configuracion\ConfiguracionSistemaService;
use App\Services\DocumentosMaestros\ContextoDocumento;
use App\Services\DocumentosMaestros\DatosDocumentoService;
use App\Services\MovimientosLaborales\MovimientoLaboralService;
use Database\Seeders\RolesYPermisosSeeder;

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
});

test('campañas: costo por colaborador de cada campaña y totales del periodo', function () {
    $usuario = clUsuario('rh_admin');
    $mes = (int) now()->month;
    $anio = (int) now()->year;

    $meta = CampanaReclutamiento::query()->create(['canal' => 'meta', 'mes' => $mes, 'anio' => $anio, 'monto' => 3000]);
    $indeed = CampanaReclutamiento::query()->create(['canal' => 'indeed', 'mes' => $mes, 'anio' => $anio, 'monto' => 1000]);

    Candidato::factory()->count(2)->create(['campana_reclutamiento_id' => $meta->id, 'estado' => EstadoCandidato::Contratado->value, 'contratado_en' => now()]);
    Candidato::factory()->create(['campana_reclutamiento_id' => $meta->id]);

    $props = $this->actingAs($usuario)->get(route('rh.campanas.index'))->assertOk()->viewData('page')['props'];
    $filas = collect($props['campanas']['data'])->keyBy('id');

    expect($props)->not->toHaveKey('kpis')
        ->and($filas[$meta->id]['resultado'])->toMatchArray(['candidatos' => 3, 'contratados' => 2, 'costo_por_colaborador' => 1500.0, 'costo_por_candidato' => 1000.0, 'conversion' => 66.7])
        // Sin contratados todavía: no se inventa un costo.
        ->and($filas[$indeed->id]['resultado']['costo_por_colaborador'])->toBeNull()
        ->and($props['totales'])->toBe(['gasto' => 4000.0, 'contratados' => 2, 'costo_por_colaborador' => 2000.0, 'campanas' => 2]);
});

test('headcount: solo Gerencia de RH edita la plantilla y cada cambio queda en el histórico', function () {
    $sucursal = Sucursal::factory()->create();
    $puesto = Puesto::factory()->create(['nombre' => 'Gestor']);
    $ruta = route('administracion.sucursales.plantilla.update', ['sucursal' => $sucursal->id, 'puesto' => $puesto->id]);

    foreach (['rh_auxiliar', 'gerente_sucursal', 'direccion'] as $rol) {
        $this->actingAs(clUsuario($rol))->put($ruta, ['plantilla_autorizada' => 4, 'motivo' => 'Prueba'])->assertForbidden();
    }

    $gerenciaRh = clUsuario('rh_admin');
    $this->actingAs($gerenciaRh)->put($ruta, ['plantilla_autorizada' => 4, 'motivo' => 'Apertura de ruta'])->assertRedirect();
    $this->actingAs($gerenciaRh)->put($ruta, ['plantilla_autorizada' => 2, 'motivo' => 'Cierre de ruta'])->assertRedirect();

    $historial = $this->actingAs($gerenciaRh)->get(route('administracion.sucursales.show', $sucursal))->viewData('page')['props']['historialPlantilla'];

    expect($historial)->toHaveCount(2)
        ->and($historial[0]['valor_anterior'])->toBe(4)
        ->and($historial[0]['valor_nuevo'])->toBe(2)
        ->and($historial[0]['motivo'])->toBe('Cierre de ruta')
        ->and($historial[0]['usuario'])->toBe($gerenciaRh->name)
        // La vacante automática sigue a la plantilla: 2 autorizadas, 0 ocupadas.
        ->and(Vacante::query()->where('sucursal_id', $sucursal->id)->where('puesto_id', $puesto->id)->where('estado', 'abierta')->value('plazas_disponibles'))->toBe(2);
});

test('cambio de sucursal: se abre la vacante del lugar que deja y se cierra la del lugar al que llega', function () {
    $puesto = Puesto::factory()->create(['nombre' => 'Gestor']);
    $origen = Sucursal::factory()->create();
    $destino = Sucursal::factory()->create();
    HeadcountTarget::factory()->create(['sucursal_id' => $origen->id, 'puesto_id' => $puesto->id, 'plantilla_autorizada' => 1]);
    HeadcountTarget::factory()->create(['sucursal_id' => $destino->id, 'puesto_id' => $puesto->id, 'plantilla_autorizada' => 1]);
    $colaborador = Colaborador::factory()->create(['sucursal_principal_id' => $origen->id, 'puesto_id' => $puesto->id, 'estatus' => 'activo']);
    Vacante::factory()->create(['sucursal_id' => $destino->id, 'puesto_id' => $puesto->id, 'estado' => 'abierta', 'generada_automaticamente' => true, 'plazas_requeridas' => 1, 'plazas_disponibles' => 1]);

    $movimientos = app(MovimientoLaboralService::class);
    $antes = $movimientos->snapshot($colaborador);
    $colaborador->update(['sucursal_principal_id' => $destino->id]);
    $movimientos->registrarCambioPuesto($colaborador->refresh(), $antes, clUsuario('rh_admin'), 'Traslado');

    $abiertas = fn (Sucursal $s) => Vacante::query()->where('sucursal_id', $s->id)->where('puesto_id', $puesto->id)->whereNotIn('estado', ['cubierta', 'cancelada'])->count();

    expect($abiertas($origen))->toBe(1)
        ->and($abiertas($destino))->toBe(0);
});

test('domicilio del patrón: Configuración decide si es el fiscal o el de la sucursal', function () {
    $estructura = clEstructura();
    $estructura['empresa']->update(['domicilio_fiscal' => 'Subida al Club 114, Cuernavaca, Morelos']);
    $estructura['sucursal']->update(['direccion' => 'Av. Morelos 120', 'colonia' => 'Centro', 'codigo_postal' => '62000', 'municipio' => 'Cuernavaca', 'estado' => 'Morelos']);
    $colaborador = Colaborador::factory()->create(['sucursal_principal_id' => $estructura['sucursal']->id]);
    $rh = clUsuario('super_admin');
    $domicilio = fn (): string => (string) app(DatosDocumentoService::class)->resolver(new ContextoDocumento($colaborador->refresh()))['empresa_domicilio'];

    expect($domicilio())->toBe('Subida al Club 114, Cuernavaca, Morelos');

    $configuracion = app(ConfiguracionSistemaService::class);
    $configuracion->guardar('rh', ['rh.domicilio_patron_documentos' => 'sucursal'], $rh);
    $configuracion->aplicarAConfig();

    expect($domicilio())->toBe('Av. Morelos 120, colonia Centro, C.P. 62000, Cuernavaca, Morelos');

    // Sucursal sin domicilio capturado: se usa el fiscal.
    $estructura['sucursal']->update(['direccion' => null]);
    expect($domicilio())->toBe('Subida al Club 114, Cuernavaca, Morelos');
});

test('sucursal: se captura su domicilio completo', function () {
    $sucursal = clEstructura()['sucursal'];

    $this->actingAs(clUsuario('rh_admin'))->put(route('administracion.sucursales.update', $sucursal), [
        'empresa_id' => $sucursal->empresa_id,
        'nombre' => $sucursal->nombre,
        'clave' => $sucursal->clave,
        'direccion' => 'Av. Morelos 120',
        'colonia' => 'Centro',
        'municipio' => 'Cuernavaca',
        'codigo_postal' => '62000',
        'estado' => 'Morelos',
        'activo' => true,
    ])->assertSessionHasNoErrors();

    expect($sucursal->refresh()->only(['colonia', 'municipio', 'codigo_postal']))->toBe(['colonia' => 'Centro', 'municipio' => 'Cuernavaca', 'codigo_postal' => '62000']);
});
