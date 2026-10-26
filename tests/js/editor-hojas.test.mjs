/**
 * Editor de hojas del PDF histórico (resources/js/lib/editorHojas.ts):
 * separar una parte que el OCR juntó (INE de 2 hojas donde solo una es la
 * INE), mover hojas entre documentos y dejar hojas fuera.
 *
 *   npm run test:js
 */
import assert from 'node:assert/strict';
import { test } from 'node:test';
import {
    asignarTipo,
    crearEstado,
    moverA,
    planDe,
} from '../../resources/js/lib/editorHojas.ts';

const INE = 1;
const CURP = 2;
const SOLICITUD = 3;

function estadoInicial() {
    return crearEstado([
        { document_type_id: SOLICITUD, paginas: [1] },
        { document_type_id: INE, paginas: [2, 3] },
        { document_type_id: null, paginas: [4] },
        { document_type_id: CURP, paginas: [5, 6] },
    ]);
}

test('separar: la hoja 3 del documento INE (2-3) pasa a otro tipo y la INE queda solo con la 2', () => {
    const r = asignarTipo(estadoInicial(), [3], SOLICITUD, true);

    assert.ok(r.ok);
    assert.deepEqual(planDe(r.documentos), [
        { document_type_id: SOLICITUD, paginas: [1] },
        { document_type_id: INE, paginas: [2] },
        { document_type_id: SOLICITUD, paginas: [3] },
        { document_type_id: null, paginas: [4] },
        { document_type_id: CURP, paginas: [5, 6] },
    ]);
});

test('mover una hoja a otro documento la deja en el orden del PDF', () => {
    const docs = estadoInicial();
    const curp = docs.find((d) => d.tipoId === CURP);
    const r = moverA(docs, [3], curp.id);

    assert.ok(r.ok);
    assert.deepEqual(
        planDe(r.documentos).find((d) => d.document_type_id === CURP).paginas,
        [3, 5, 6],
    );
    assert.deepEqual(
        planDe(r.documentos).find((d) => d.document_type_id === INE).paginas,
        [2],
    );
});

test('dejar una hoja «sin clasificar» la une al tramo suelto vecino', () => {
    const r = asignarTipo(estadoInicial(), [3], null);

    assert.ok(r.ok);
    assert.deepEqual(planDe(r.documentos), [
        { document_type_id: SOLICITUD, paginas: [1] },
        { document_type_id: INE, paginas: [2] },
        { document_type_id: null, paginas: [3, 4] },
        { document_type_id: CURP, paginas: [5, 6] },
    ]);
});
