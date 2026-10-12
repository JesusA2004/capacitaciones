/**
 * Integridad de la Guía maestra (resources/js/lib/guia): referencias entre
 * artículos, buscador local, ayuda contextual, video y documentos del repo
 * sincronizados. Las rutas reales y los anclajes de los recorridos los
 * prueba tests/Feature/Ayuda/GuiaMaestraTest.php.
 *
 *   npm run test:js
 */
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { existsSync } from 'node:fs';
import { join } from 'node:path';
import { test } from 'node:test';
import { ARTICULOS_ADMINISTRACION } from '../../resources/js/lib/guia/articulos/administracion.ts';
import { ARTICULOS_CONTRATACION } from '../../resources/js/lib/guia/articulos/contratacion.ts';
import { ARTICULOS_DESARROLLO } from '../../resources/js/lib/guia/articulos/desarrollo.ts';
import { ARTICULOS_RECLUTAMIENTO } from '../../resources/js/lib/guia/articulos/reclutamiento.ts';
import { ARTICULOS_SALIDA } from '../../resources/js/lib/guia/articulos/salida.ts';
import { ARTICULOS_VIDA_LABORAL } from '../../resources/js/lib/guia/articulos/vida-laboral.ts';
import { buscarArticulos } from '../../resources/js/lib/guia/buscador.ts';
import {
    AYUDA_POR_PANTALLA,
    articuloDePantalla,
} from '../../resources/js/lib/guia/contextual.ts';
import { FLUJO } from '../../resources/js/lib/guia/flujo.ts';
import {
    GLOSARIO,
    PREGUNTAS_FRECUENTES,
} from '../../resources/js/lib/guia/glosario.ts';
import { PROCESOS } from '../../resources/js/lib/guia/procesos.ts';
import { ROLES_GUIA } from '../../resources/js/lib/guia/roles.ts';
import {
    escenasVideo,
    MINI_VIDEOS,
    duracionEscenaCorto,
} from '../../resources/js/lib/guia/video.ts';

const ARTICULOS = [
    ...ARTICULOS_RECLUTAMIENTO,
    ...ARTICULOS_CONTRATACION,
    ...ARTICULOS_DESARROLLO,
    ...ARTICULOS_VIDA_LABORAL,
    ...ARTICULOS_SALIDA,
    ...ARTICULOS_ADMINISTRACION,
];
const IDS = new Set(ARTICULOS.map((a) => a.id));
const RAIZ = join(import.meta.dirname, '..', '..');

test('14 procesos y artículos con id único', () => {
    assert.equal(PROCESOS.length, 14);
    assert.equal(IDS.size, ARTICULOS.length, 'hay ids de artículo duplicados');
});

test('cada artículo tiene la estructura completa de la guía', () => {
    for (const a of ARTICULOS) {
        for (const campo of [
            'queEs',
            'quien',
            'antes',
            'pasos',
            'despues',
            'errores',
            'relacionados',
        ]) {
            assert.ok(a[campo] !== undefined, `${a.id}: falta «${campo}»`);
        }

        assert.ok(a.pasos.length > 0, `${a.id}: sin pasos`);
    }
});

test('ninguna referencia interna apunta a un artículo inexistente', () => {
    const rotas = [];

    for (const a of ARTICULOS) {
        a.relacionados
            .filter((id) => !IDS.has(id))
            .forEach((id) => rotas.push(`${a.id} → ${id}`));
        const proceso = PROCESOS.find((p) => p.id === a.proceso);

        if (!proceso?.articulos.includes(a.id)) {
            rotas.push(`${a.id} no está listado en su proceso ${a.proceso}`);
        }
    }

    PROCESOS.forEach((p) =>
        p.articulos
            .filter((id) => !IDS.has(id))
            .forEach((id) => rotas.push(`proceso ${p.id} → ${id}`)),
    );
    FLUJO.filter((b) => !IDS.has(b.articulo)).forEach((b) =>
        rotas.push(`flujo ${b.id} → ${b.articulo}`),
    );
    ROLES_GUIA.forEach((r) =>
        r.checklist
            .filter((k) => !IDS.has(k.articulo))
            .forEach((k) => rotas.push(`rol ${r.id} → ${k.articulo}`)),
    );
    [...GLOSARIO, ...PREGUNTAS_FRECUENTES]
        .filter((g) => g.articulo && !IDS.has(g.articulo))
        .forEach((g) => rotas.push(`glosario/faq → ${g.articulo}`));
    AYUDA_POR_PANTALLA.filter((p) => !IDS.has(p.articulo)).forEach((p) =>
        rotas.push(`contextual ${p.patron} → ${p.articulo}`),
    );
    MINI_VIDEOS.filter((m) => !IDS.has(m.articulo)).forEach((m) =>
        rotas.push(`mini ${m.id} → ${m.articulo}`),
    );

    assert.deepEqual(rotas, []);
});

test('la guía cubre los 6 roles pedidos', () => {
    assert.deepEqual(
        ROLES_GUIA.map((r) => r.id).sort(),
        [
            'colaborador',
            'direccion_comercial',
            'gerente',
            'regional',
            'rh',
            'sistemas',
        ].sort(),
    );
});

test('el buscador responde las preguntas típicas con el artículo correcto (sin IA)', () => {
    const esperado = {
        '¿Cómo genero un permiso?': 'permisos',
        '¿Cómo contrato un candidato?': 'iniciar-contratacion',
        '¿Cómo emito nómina?': 'nomina-lotes',
        '¿Cómo hago una baja?': 'baja',
        finiquito: 'finiquito',
    };

    for (const [consulta, id] of Object.entries(esperado)) {
        const ids = buscarArticulos(consulta, ARTICULOS, 3).map(
            (r) => r.articulo.id,
        );
        assert.ok(
            ids.includes(id),
            `«${consulta}» → ${ids.join(', ')} (esperaba ${id})`,
        );
    }

    assert.deepEqual(buscarArticulos('', ARTICULOS, 3), []);
});

test('la ayuda contextual reconoce las pantallas clave', () => {
    assert.equal(
        articuloDePantalla('/rh/candidatos/15'),
        'etapas-del-candidato',
    );
    assert.equal(articuloDePantalla('/rh/campanas'), 'campanas');
    assert.ok(articuloDePantalla('/rh/nomina/lotes'));
    assert.equal(articuloDePantalla('/una/ruta/que/no/existe'), null);
});

test('el video completo dura entre 12 y 18 minutos y sus escenas son continuas', () => {
    const escenas = escenasVideo();
    const total = escenas.at(-1).fin;

    assert.ok(total >= 12 * 60 && total <= 18 * 60, `dura ${total} s`);
    escenas
        .slice(1)
        .forEach((e, i) =>
            assert.equal(
                e.inicio,
                escenas[i].fin,
                `hueco antes de la escena ${e.numero}`,
            ),
        );
});

test('los 6 videos cortos duran de 1 a 3 minutos y cada escena tiene captura real', () => {
    assert.equal(MINI_VIDEOS.length, 6);

    for (const mini of MINI_VIDEOS) {
        const total = mini.escenas.reduce(
            (s, e) => s + duracionEscenaCorto(e.narracion),
            0,
        );
        assert.ok(total >= 60 && total <= 180, `${mini.id}: ${total} s`);
    }

    const capturas = new Set(escenasVideo().map((e) => e.captura));
    MINI_VIDEOS.flatMap((m) => m.escenas).forEach((e) =>
        assert.ok(capturas.has(e.captura), `captura desconocida ${e.captura}`),
    );
});

test('las capturas del storyboard existen en docs/guias/video/capturas', () => {
    const faltan = escenasVideo()
        .map((e) => e.captura)
        .filter(
            (c) =>
                !existsSync(
                    join(RAIZ, 'docs', 'guias', 'video', 'capturas', c),
                ),
        );

    assert.deepEqual(faltan, []);
});

test('docs/guias está sincronizado con la fuente (npm run guia:docs)', () => {
    execFileSync(
        'node',
        [join(RAIZ, 'scripts', 'guia', 'generar-docs.mjs'), '--comprobar'],
        { stdio: 'pipe' },
    );
});
