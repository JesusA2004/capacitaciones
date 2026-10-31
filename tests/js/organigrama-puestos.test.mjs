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

test('la rama de sucursal cuelga solo de Subgerente Regional', () => {
    const hijos = hijosDePuestos([
        puesto(1, null),
        puesto(11, 1),
        puesto(12, 11),
        puesto(20, 12),
        puesto(21, 20),
    ]);

    assert.deepEqual(ids(hijos.get(11)), [12]);
    assert.deepEqual(ids(hijos.get(12)), [20]);
    assert.deepEqual(ids(hijos.get(20)), [21]);
});

test('un ciclo real en los datos no cuelga el recorrido', () => {
    const hijos = hijosDePuestos([puesto(1, 2), puesto(2, 1)]);

    assertSinCiclos(hijos, [1, 2]);
});
