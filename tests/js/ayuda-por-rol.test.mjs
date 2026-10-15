/**
 * Ayuda estrictamente por rol + modo (cierre 2026-10-15): un colaborador no
 * ve el flujo interno de reclutamiento, autorizaciones, nómina RH ni bajas;
 * Sistemas no ve las decisiones de RH; super_admin ve todo. Y nada de lo
 * que ya no existe (recibos para el colaborador, preautorizaciones).
 *
 *   npm run test:js
 */
import assert from 'node:assert/strict';
import { test } from 'node:test';
import {
    articuloVisible,
    calcularAlcance,
    procesoVisible,
    rolVisible,
    veFlujoCompleto,
} from '../../resources/js/lib/guia/alcance.ts';
import { ARTICULOS_ADMINISTRACION } from '../../resources/js/lib/guia/articulos/administracion.ts';
import { ARTICULOS_CONTRATACION } from '../../resources/js/lib/guia/articulos/contratacion.ts';
import { ARTICULOS_DESARROLLO } from '../../resources/js/lib/guia/articulos/desarrollo.ts';
import { ARTICULOS_RECLUTAMIENTO } from '../../resources/js/lib/guia/articulos/reclutamiento.ts';
import { ARTICULOS_SALIDA } from '../../resources/js/lib/guia/articulos/salida.ts';
import { ARTICULOS_VIDA_LABORAL } from '../../resources/js/lib/guia/articulos/vida-laboral.ts';
import { FLUJO } from '../../resources/js/lib/guia/flujo.ts';
import { GLOSARIO, PREGUNTAS_FRECUENTES } from '../../resources/js/lib/guia/glosario.ts';
import { PROCESOS } from '../../resources/js/lib/guia/procesos.ts';
import { ROLES_GUIA } from '../../resources/js/lib/guia/roles.ts';

const ARTICULOS = [
    ...ARTICULOS_RECLUTAMIENTO,
    ...ARTICULOS_CONTRATACION,
    ...ARTICULOS_DESARROLLO,
    ...ARTICULOS_VIDA_LABORAL,
    ...ARTICULOS_SALIDA,
    ...ARTICULOS_ADMINISTRACION,
];
const porId = new Map(ARTICULOS.map((a) => [a.id, a]));
const alcance = (roles, modoColaborador = false) =>
    calcularAlcance(roles, modoColaborador, ROLES_GUIA);
const procesos = (a) => PROCESOS.filter((p) => procesoVisible(p, a)).map((p) => p.id);

test('colaborador: solo Mi espacio, sin reclutamiento, contratación, nómina, bajas ni administración', () => {
    const a = alcance(['colaborador'], true);
    const ids = procesos(a);

    for (const prohibido of ['reclutamiento', 'contratacion', 'nomina', 'baja', 'administracion', 'contratos', 'evaluacion', 'reingresos']) {
        assert.ok(!ids.includes(prohibido), `el colaborador no debe ver «${prohibido}»`);
    }

    assert.ok(ids.includes('solicitudes'));
    assert.equal(veFlujoCompleto(a), false);
    assert.equal(rolVisible('rh', a), false);
    assert.equal(articuloVisible(porId.get('autorizacion-rh'), a), false);
    assert.equal(articuloVisible(porId.get('nomina-lotes'), a), false);
    assert.equal(articuloVisible(porId.get('baja'), a), false);
});

test('una cuenta de RH en «Mi espacio» ve la guía del colaborador, no la de RH', () => {
    assert.deepEqual(alcance(['rh_admin', 'colaborador'], true).roles, ['colaborador']);
});

test('gerente: su operación, sin nómina, contratación RH ni administración', () => {
    const a = alcance(['gerente_sucursal']);
    const ids = procesos(a);

    assert.ok(ids.includes('reclutamiento'));
    assert.ok(ids.includes('baja'));
    assert.ok(!ids.includes('nomina'));
    assert.ok(!ids.includes('contratacion'));
    assert.ok(!ids.includes('administracion'));
    assert.equal(veFlujoCompleto(a), false);
});

test('sistemas: cuentas, accesos y app; nunca las decisiones de RH', () => {
    const a = alcance(['sistemas']);
    const ids = procesos(a);

    assert.ok(ids.includes('administracion'));
    assert.ok(!ids.includes('reclutamiento'));
    assert.ok(!ids.includes('nomina'));
    assert.ok(!ids.includes('solicitudes'));
    assert.equal(articuloVisible(porId.get('autorizacion-rh'), a), false);
});

test('RH ve el flujo completo y super_admin la ayuda global', () => {
    assert.equal(veFlujoCompleto(alcance(['rh_admin'])), true);
    const sa = alcance(['super_admin']);
    assert.equal(sa.global, true);
    assert.equal(procesos(sa).length, PROCESOS.length);
});

test('cada punto del checklist de un rol abre un artículo que ese rol puede ver', () => {
    for (const rol of ROLES_GUIA) {
        const a = { global: false, roles: [rol.id] };

        for (const item of rol.checklist) {
            const art = porId.get(item.articulo);
            assert.ok(art, `${rol.id}: el artículo «${item.articulo}» no existe`);
            assert.ok(articuloVisible(art, a), `${rol.id}: «${item.texto}» apunta a «${item.articulo}», que no es de su rol`);
        }
    }
});

test('la ayuda no menciona flujos que ya no existen', () => {
    const texto = JSON.stringify([ROLES_GUIA, PROCESOS, FLUJO, GLOSARIO, PREGUNTAS_FRECUENTES, ARTICULOS]);

    for (const obsoleto of ['Ver mis recibos', 'mis-recibos', 'Preautorizar', 'preautoriza bajas', 'Solicitar baja»', 'Preselección del gerente']) {
        assert.ok(!texto.includes(obsoleto), `la guía todavía dice «${obsoleto}»`);
    }
});
