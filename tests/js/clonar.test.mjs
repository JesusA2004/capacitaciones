/**
 * Regresión del DataCloneError del editor de documentos maestros:
 * `structuredClone()` sobre un Proxy reactivo de Vue (props de Inertia,
 * refs) lanza "#<Object> could not be cloned". `clonarSerializable()` debe
 * copiar el DTO sin compartir referencias ni reactividad.
 */
import assert from 'node:assert/strict';
import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join } from 'node:path';
import { test } from 'node:test';
import { isProxy, reactive } from 'vue';
import { clonarSerializable } from '../../resources/js/lib/clonar.ts';

const editor = () =>
    reactive({
        master: { id: 23, nombre: 'Contrato', editable: true },
        campos: [{ field_key: 'c1', x: 10, y: 20, sugerencias: [] }],
    });

test('structuredClone falla con un proxy reactivo (la causa original)', () => {
    assert.throws(() => structuredClone(editor()), { name: 'DataCloneError' });
});

test('clonarSerializable copia un proxy reactivo como datos planos independientes', () => {
    const original = editor();
    const copia = clonarSerializable(original);

    assert.equal(isProxy(copia), false);
    assert.deepEqual(copia, { master: { id: 23, nombre: 'Contrato', editable: true }, campos: [{ field_key: 'c1', x: 10, y: 20, sugerencias: [] }] });

    copia.campos[0].x = 99;
    assert.equal(original.campos[0].x, 10);
});

test('ningún archivo del frontend usa structuredClone directamente', () => {
    const raiz = new URL('../../resources/js/', import.meta.url).pathname.replace(/^\/([A-Za-z]:)/, '$1');
    const usos = [];
    const recorrer = (dir) => {
        for (const nombre of readdirSync(dir)) {
            const ruta = join(dir, nombre);

            if (statSync(ruta).isDirectory()) {
                recorrer(ruta);
            } else if (/\.(ts|vue)$/.test(nombre) && !ruta.endsWith('clonar.ts') && readFileSync(ruta, 'utf8').includes('structuredClone(')) {
                usos.push(ruta);
            }
        }
    };
    recorrer(raiz);

    assert.deepEqual(usos, []);
});
