<?php

use App\Models\CoberturaPuesto;
use App\Models\Colaborador;
use App\Models\Puesto;
use App\Models\Sucursal;
use App\Services\Organigrama\OrganigramaPersonasService;
use Illuminate\Support\Facades\Artisan;

/*
| Subgerentes = APOYO (regla de la dueña del negocio, 2026-11-09;
| config organigrama.puestos_apoyo): el Subgerente de Sucursal y el Subgerente
| Regional nunca son jefe directo mientras su superior tenga titular o
| cobertura; si ese superior está vacante, lo suplen.
|
|  Gestor / Volante: Gerente de Sucursal → Subgerente → Gerente Regional → Subgerente Regional
|  Subgerente:       Gerente de Sucursal → Gerente Regional → Subgerente Regional
|  Gerente Sucursal: Gerente Regional → Subgerente Regional
*/

beforeEach(function () {
    config(['organigrama.puestos_raiz_sucursal' => ['Gerente de Sucursal']]);
    Sucursal::query()->update(['activo' => false]);

    $dc = Puesto::factory()->create(['nombre' => 'Dirección Comercial', 'nivel_jerarquico' => 2, 'activo' => true]);
    $gr = Puesto::factory()->create(['nombre' => 'Gerente Regional', 'nivel_jerarquico' => 3, 'puesto_superior_id' => $dc->id, 'activo' => true]);
    $sr = Puesto::factory()->create(['nombre' => 'Subgerente Regional', 'nivel_jerarquico' => 4, 'puesto_superior_id' => $gr->id, 'activo' => true]);
    $this->puestoGs = Puesto::factory()->create(['nombre' => 'Gerente de Sucursal', 'nivel_jerarquico' => 5, 'puesto_superior_id' => $gr->id, 'activo' => true]);
    $sub = Puesto::factory()->create(['nombre' => 'Subgerente', 'nivel_jerarquico' => 6, 'puesto_superior_id' => $this->puestoGs->id, 'activo' => true]);
    $gestor = Puesto::factory()->create(['nombre' => 'Gestor', 'nivel_jerarquico' => 7, 'puesto_superior_id' => $sub->id, 'activo' => true]);
    $volante = Puesto::factory()->create(['nombre' => 'Gestor Volante', 'nivel_jerarquico' => 7, 'puesto_superior_id' => $sub->id, 'activo' => true]);

    $this->corporativo = Sucursal::factory()->create(['nombre' => 'Corporativo', 'activo' => true, 'es_corporativo' => true]);
    $this->sucursal = Sucursal::factory()->create(['nombre' => 'Cuernavaca', 'activo' => true, 'es_corporativo' => false]);

    $persona = fn (string $nombre, Puesto $puesto, Sucursal $sucursal): Colaborador => Colaborador::factory()->create([
        'name' => $nombre, 'apellidos' => null, 'estatus' => 'activo', 'puesto_id' => $puesto->id, 'sucursal_principal_id' => $sucursal->id, 'jefe_id' => null,
    ]);

    $this->dc = $persona('Directora', $dc, $this->corporativo);
    $this->gr = $persona('Gerente Regional', $gr, $this->corporativo);
    $this->sr = $persona('Subgerente Regional', $sr, $this->corporativo);
    $this->gs = $persona('Gerente Sucursal', $this->puestoGs, $this->sucursal);
    $this->sub = $persona('Subgerente Sucursal', $sub, $this->sucursal);
    $this->gestor = $persona('Gestor Uno', $gestor, $this->sucursal);
    $this->volante = $persona('Volante Uno', $volante, $this->sucursal);

    $this->jefes = fn (): array => app(OrganigramaPersonasService::class)->jefesDerivados();
});

test('con Gerente de Sucursal, gestores y volantes le reportan a él; el subgerente es apoyo', function () {
    $jefes = ($this->jefes)();

    expect($jefes[$this->gestor->id])->toBe($this->gs->id)
        ->and($jefes[$this->volante->id])->toBe($this->gs->id)
        ->and($jefes[$this->sub->id])->toBe($this->gs->id)
        ->and($jefes[$this->gs->id])->toBe($this->gr->id)
        ->and($jefes[$this->sr->id])->toBe($this->gr->id);
});

test('quien cubre temporalmente la gerencia también manda sobre el subgerente', function () {
    $this->gs->update(['estatus' => 'inactivo']);
    CoberturaPuesto::factory()->create(['colaborador_id' => $this->gr->id, 'puesto_id' => $this->puestoGs->id, 'sucursal_id' => $this->sucursal->id, 'activa' => true]);

    $jefes = ($this->jefes)();

    expect($jefes[$this->gestor->id])->toBe($this->gr->id)
        ->and($jefes[$this->volante->id])->toBe($this->gr->id);
});

test('gerencia vacante: el subgerente suple al gerente', function () {
    $this->gs->update(['estatus' => 'inactivo']);
    $jefes = ($this->jefes)();

    expect($jefes[$this->gestor->id])->toBe($this->sub->id)
        ->and($jefes[$this->volante->id])->toBe($this->sub->id)
        ->and($jefes[$this->sub->id])->toBe($this->gr->id);
});

test('gerencia y subgerencia vacantes: el Gerente Regional', function () {
    $this->gs->update(['estatus' => 'inactivo']);
    $this->sub->update(['estatus' => 'inactivo']);
    $jefes = ($this->jefes)();

    expect($jefes[$this->gestor->id])->toBe($this->gr->id)
        ->and($jefes[$this->volante->id])->toBe($this->gr->id);
});

test('Gerencia Regional vacante: el Subgerente Regional la suple', function () {
    $this->gs->update(['estatus' => 'inactivo']);
    $this->sub->update(['estatus' => 'inactivo']);
    $this->gr->update(['estatus' => 'inactivo']);
    $jefes = ($this->jefes)();

    expect($jefes[$this->gestor->id])->toBe($this->sr->id)
        ->and($jefes[$this->volante->id])->toBe($this->sr->id);

    // Con gerente de sucursal: él reporta al Subgerente Regional.
    $this->gs->update(['estatus' => 'activo']);
    $jefes = ($this->jefes)();

    expect($jefes[$this->gs->id])->toBe($this->sr->id)
        ->and($jefes[$this->gestor->id])->toBe($this->gs->id);
});

test('nunca Dirección Comercial: sin regionales, el personal de sucursal queda sin jefe asignable', function () {
    foreach ([$this->gs, $this->sub, $this->gr, $this->sr] as $persona) {
        $persona->update(['estatus' => 'inactivo']);
    }

    $servicio = app(OrganigramaPersonasService::class);
    $jefes = $servicio->jefesDerivados();

    expect($jefes[$this->gestor->id])->toBeNull()
        ->and($servicio->sinJefeAsignable())->toContain($this->gestor->id);
});

test('explicar-jefe dice que el subgerente se omite por ser apoyo', function () {
    Artisan::call('organigrama:explicar-jefe', ['colaborador' => $this->gestor->id]);
    $salida = Artisan::output();

    expect($salida)->toContain('Subgerente: apoyo, se omite porque hay Gerente de Sucursal')
        ->and($salida)->toContain('Gerente Sucursal');
});
