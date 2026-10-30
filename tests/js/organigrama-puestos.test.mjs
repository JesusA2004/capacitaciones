/**
 * Vista «Por puestos» (resources/js/lib/organigramaPuestos.ts): un regional
 * bajo otro regional no debe cerrar un ciclo («Maximum call stack size
 * exceeded» al abrir el organigrama).
 */
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { hijosDePuestos } from '../../resources/js/lib/organigramaPuestos.ts';

const puesto = (id, superior, extra = {}) => ({
    id,
    puesto_superior_id: superior,
    ...extra,
});

/** Recorre todas las rutas desde las raíces; falla si alguna se repite. */
function assertSinCiclos(hijos, raices) {
    const bajar = (id, cadena) => {
        assert.ok(!cadena.includes(id), `ciclo: ${[...cadena, id].join(' > ')}`);

        for (const hijo of hijos.get(id) ?? []) {
            bajar(hijo.id, [...cadena, id]);
        }
    };

    raices.forEach((id) => bajar(id, []));
}

const ids = (lista) => (lista ?? []).map((p) => p.id);

test('la rama de sucursal se ve debajo de cada regional', () => {
    const hijos = hijosDePuestos([
        puesto(1, null),
        puesto(11, 1, { region: 'Región Q1' }),
        puesto(12, 1, { region: 'Región Q3' }),
        puesto(20, 11, { depende_de_region: true }),
        puesto(21, 20),
    ]);

    assert.deepEqual(ids(hijos.get(11)), [20]);
    assert.deepEqual(ids(hijos.get(12)), [20]);
    assert.deepEqual(ids(hijos.get(20)), [21]);
});

test('un regional bajo otro regional no se contiene a sí mismo', () => {
    const hijos = hijosDePuestos([
        puesto(1, null),
        puesto(11, 1, { region: 'Región Q1' }),
        puesto(12, 11, { region: 'Región Q3', depende_de_region: true }),
        puesto(20, 11, { depende_de_region: true }),
    ]);

    assertSinCiclos(hijos, [1]);
    assert.deepEqual(ids(hijos.get(11)), [12, 20]);
    assert.deepEqual(ids(hijos.get(12)), [20]);
});

test('un regional debajo de la rama de sucursal tampoco cierra ciclo', () => {
    const hijos = hijosDePuestos([
        puesto(1, null),
        puesto(11, 1, { region: 'Región Q1' }),
        puesto(20, 11, { depende_de_region: true }),
        puesto(12, 20, { region: 'Región Q3' }),
    ]);

    assertSinCiclos(hijos, [1]);
    assert.deepEqual(ids(hijos.get(20)), [12]);
});

test('un ciclo real en los datos no cuelga el recorrido', () => {
    const hijos = hijosDePuestos([puesto(1, 2), puesto(2, 1)]);

    assertSinCiclos(hijos, [1, 2]);
});
