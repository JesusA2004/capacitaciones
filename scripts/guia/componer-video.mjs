#!/usr/bin/env node
/**
 * Compone el video tutorial y los videos cortos a partir de las capturas
 * REALES (scripts/guia/grabar-capturas.mjs) y del guion único
 * (resources/js/lib/guia/video.ts).
 *
 *   node scripts/guia/componer-video.mjs            # video completo + 6 cortos
 *   node scripts/guia/componer-video.mjs --solo-completo
 *   GUIA_VOZ=/ruta/locucion.wav node scripts/guia/componer-video.mjs
 *
 * Resultado (1920×1080, 25 fps, H.264 + AAC, subtítulos en español):
 *   docs/guias/video/MR_LANA_PEOPLE_GUIA_COMPLETA.mp4  (+ miniatura.png)
 *   docs/guias/video/cortos/<id>.mp4
 *
 * Voz: si existe GUIA_VOZ (la locución humana grabada sobre el guion, ver
 * video/PRODUCCION.md) se usa esa pista completa. Si no, se genera una voz
 * SINTÉTICA de referencia con espeak-ng + MBROLA (voz mexicana mx2, o la
 * latinoamericana de espeak si MBROLA no está), escena por escena y ajustada
 * al tiempo exacto del guion — así subtítulos, capítulos e imagen cuadran.
 *
 * Requiere ffmpeg (libx264, aac, drawtext) y, para la voz de referencia,
 * espeak-ng (+ mbrola y mbrola-mx2 opcionales).
 */
import { execFileSync } from 'node:child_process';
import { existsSync, mkdirSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const RAIZ = join(dirname(fileURLToPath(import.meta.url)), '..', '..');
const VIDEO = join(RAIZ, 'docs', 'guias', 'video');
const CAPTURAS = join(VIDEO, 'capturas');
const TMP = join(process.env.GUIA_TMP ?? tmpdir(), 'mrlana-guia-video');
const FPS = 25;
const FUENTE = [
    '/usr/share/fonts/opentype/inter/Inter-SemiBold.otf',
    '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
].find(existsSync);

const video = await import(
    pathToFileURL(join(RAIZ, 'resources', 'js', 'lib', 'guia', 'video.ts')).href
);
const {
    duracionEscenaCorto,
    escenasVideo,
    MINI_VIDEOS,
    PALABRAS_POR_SEGUNDO,
    TITULO_VIDEO,
} = video;

rmSync(TMP, { recursive: true, force: true });
mkdirSync(TMP, { recursive: true });
mkdirSync(join(VIDEO, 'cortos'), { recursive: true });

const ff = (args) =>
    execFileSync('ffmpeg', ['-v', 'error', '-y', ...args], {
        stdio: ['ignore', 'ignore', 'inherit'],
    });
const duracionDe = (archivo) =>
    Number(
        execFileSync(
            'ffprobe',
            [
                '-v',
                'error',
                '-show_entries',
                'format=duration',
                '-of',
                'csv=p=0',
                archivo,
            ],
            { encoding: 'utf8' },
        ).trim(),
    );
const palabras = (t) => t.split(/\s+/).filter(Boolean).length;
const hay = (bin) => {
    try {
        execFileSync('which', [bin], { stdio: 'ignore' });

        return true;
    } catch {
        return false;
    }
};

/* ───────────── voz de referencia ───────────── */

/** Cómo se lee en voz alta lo que en pantalla va en siglas o inglés. */
const PRONUNCIACION = [
    [/MR\.?\s*LANA\s*PEOPLE/gi, 'Míster Lana Pípol'],
    [/MR\.?\s*LANA/gi, 'Míster Lana'],
    [/\bPEOPLE\b/g, 'Pípol'],
    [/\bRH\b/g, 'erre hache'],
    [/\bQR\b/g, 'cu erre'],
    [/\bPDF\b/g, 'pe de efe'],
    [/\bCURP\b/g, 'curp'],
    [/\bRFC\b/g, 'erre efe ce'],
    [/\bNSS\b/g, 'ene ese ese'],
    [/\bCV\b/g, 'currículum'],
    [/\bapp\b/gi, 'ap'],
    [/\bonboarding\b/gi, 'onbórding'],
    [/\bweb\b/gi, 'güeb'],
    [/«|»/g, ''],
];

const MBROLA_MX2 = [
    '/usr/share/mbrola/mx2/mx2',
    '/usr/share/mbrola/voices/mx2',
].find(existsSync);
const usarVozSintetica = !process.env.GUIA_VOZ;

if (usarVozSintetica && !hay('espeak-ng')) {
    console.error(
        'componer-video: falta espeak-ng (o define GUIA_VOZ con la locución grabada).',
    );
    process.exit(1);
}

/** WAV mono 22.05 kHz con la narración, estirado/comprimido a `objetivo` segundos. */
function locucion(texto, objetivo, salida) {
    const leible = PRONUNCIACION.reduce(
        (t, [re, rep]) => t.replace(re, rep),
        texto,
    );
    const crudo = `${salida}.crudo.wav`;

    if (MBROLA_MX2 && hay('mbrola')) {
        // espeak-ng → fonemas → MBROLA. Los fonemas acentuados (e/, o/…)
        // que la voz mx2 no conoce se normalizan antes de sintetizar.
        const pho = execFileSync(
            'espeak-ng',
            ['-v', 'mb-mx2', '-s', '140', '-q', '--pho', leible],
            { encoding: 'utf8' },
        );
        const limpio = pho
            .replace(/^([a-zA-Z_]+)\//gm, '$1')
            .replace(/^l\^/gm, 'l');
        writeFileSync(`${salida}.pho`, limpio);
        execFileSync('mbrola', ['-e', MBROLA_MX2, `${salida}.pho`, crudo], {
            stdio: 'ignore',
        });
    } else {
        execFileSync('espeak-ng', [
            '-v',
            'es-419',
            '-s',
            '150',
            '-w',
            crudo,
            leible,
        ]);
    }

    const real = duracionDe(crudo);
    // atempo admite 0.5–2; el guion y la voz difieren poco (± 25 %).
    const tempo = Math.min(2, Math.max(0.5, real / objetivo));
    ff([
        '-i',
        crudo,
        '-af',
        `atempo=${tempo.toFixed(4)},aresample=44100,apad`,
        '-t',
        objetivo.toFixed(3),
        '-ac',
        '1',
        salida,
    ]);
}

/* ───────────── imagen ───────────── */

function textoArchivo(nombre, texto) {
    const ruta = join(TMP, `${nombre}.txt`);
    writeFileSync(ruta, texto);

    return ruta;
}

/**
 * Un segmento de video por escena: la captura real, un acercamiento muy
 * lento (sensación de movimiento sin marear) y la etiqueta del capítulo.
 */
function segmento({ captura, duracion, etiqueta, salida, clave }) {
    const imagen = join(CAPTURAS, captura);

    if (!existsSync(imagen)) {
        throw new Error(
            `Falta la captura ${captura}: corre primero scripts/guia/grabar-capturas.mjs`,
        );
    }

    const cuadros = Math.round(duracion * FPS);
    // Imagen fija por defecto (archivo ligero para el repositorio y el
    // servidor); GUIA_ZOOM=1 agrega un acercamiento lento (pesa ~4×).
    const filtros = [
        process.env.GUIA_ZOOM
            ? `scale=1920:1080,zoompan=z='min(1+0.00012*on,1.06)':x='iw/2-(iw/zoom/2)':y='ih/2-(ih/zoom/2)':d=${cuadros}:s=1920x1080:fps=${FPS}`
            : `scale=1920:1080,fps=${FPS}`,
    ];

    if (etiqueta && FUENTE) {
        const archivo = textoArchivo(`etiqueta-${clave}`, etiqueta);
        filtros.push(
            `drawtext=fontfile=${FUENTE}:textfile=${archivo}:fontsize=30:fontcolor=0x1f4b4f:box=1:boxcolor=0xfbf8f1@0.92:boxborderw=18:x=60:y=h-110`,
        );
    }

    filtros.push(
        `fade=t=in:st=0:d=0.35,fade=t=out:st=${Math.max(0, duracion - 0.35).toFixed(2)}:d=0.35`,
        'format=yuv420p',
    );

    ff([
        '-loop',
        '1',
        '-i',
        imagen,
        '-vf',
        filtros.join(','),
        '-frames:v',
        String(cuadros),
        '-r',
        String(FPS),
        '-c:v',
        'libx264',
        '-preset',
        'veryfast',
        '-tune',
        'stillimage',
        '-crf',
        '28',
        '-g',
        String(FPS * 10),
        salida,
    ]);
}

/** Une segmentos + audio + subtítulos (pista seleccionable mov_text). */
function ensamblar({ escenas, salida, subtitulos, voz }) {
    const lista = join(TMP, `${Date.now()}-lista.txt`);
    writeFileSync(lista, escenas.map((e) => `file '${e.video}'`).join('\n'));
    const imagen = join(TMP, `${Date.now()}-imagen.mp4`);
    ff(['-f', 'concat', '-safe', '0', '-i', lista, '-c', 'copy', imagen]);

    let audio = voz;

    if (!audio) {
        const listaAudio = join(TMP, `${Date.now()}-audio.txt`);
        writeFileSync(
            listaAudio,
            escenas.map((e) => `file '${e.audio}'`).join('\n'),
        );
        audio = join(TMP, `${Date.now()}-voz.wav`);
        ff([
            '-f',
            'concat',
            '-safe',
            '0',
            '-i',
            listaAudio,
            '-c',
            'copy',
            audio,
        ]);
    }

    const args = ['-i', imagen, '-i', audio];

    if (subtitulos) {
        args.push('-i', subtitulos);
    }

    args.push('-map', '0:v', '-map', '1:a');

    if (subtitulos) {
        args.push(
            '-map',
            '2:s',
            '-c:s',
            'mov_text',
            '-metadata:s:s:0',
            'language=spa',
        );
    }

    args.push(
        '-c:v',
        'copy',
        '-c:a',
        'aac',
        '-b:a',
        '96k',
        '-ac',
        '1',
        '-af',
        'loudnorm=I=-16:TP=-1.5:LRA=11',
        '-shortest',
        '-movflags',
        '+faststart',
        '-metadata',
        `title=${TITULO_VIDEO}`,
        '-metadata',
        'language=spa',
        salida,
    );
    ff(args);
}

/** Escena con 1 s de aire, la voz y el resto en silencio hasta su fin. */
function audioEscena(texto, duracion, clave) {
    const voz = join(TMP, `voz-${clave}.wav`);
    const hablar = palabras(texto) / PALABRAS_POR_SEGUNDO;
    locucion(texto, hablar, voz);
    const salida = join(TMP, `audio-${clave}.wav`);
    ff([
        '-i',
        voz,
        '-af',
        `adelay=1000,apad`,
        '-t',
        duracion.toFixed(3),
        '-ar',
        '44100',
        '-ac',
        '1',
        salida,
    ]);

    return salida;
}

/* ───────────── video completo ───────────── */

const escenas = escenasVideo();
const partes = [];

console.log(`Componiendo «${TITULO_VIDEO}» (${escenas.length} escenas)…`);

for (const e of escenas) {
    const clave = String(e.numero).padStart(2, '0');
    const duracion = e.fin - e.inicio;
    const parte = { video: join(TMP, `escena-${clave}.mp4`) };
    const etiqueta =
        e.captura.startsWith('00-') || e.captura.startsWith('35-')
            ? null
            : `${e.capitulo}  ·  ${e.textoPantalla}`;
    segmento({
        captura: e.captura,
        duracion,
        etiqueta,
        salida: parte.video,
        clave,
    });

    if (usarVozSintetica) {
        parte.audio = audioEscena(e.narracion, duracion, clave);
    }

    partes.push(parte);
    process.stdout.write(`  escena ${clave}/${escenas.length}\r`);
}

const completo = join(VIDEO, 'MR_LANA_PEOPLE_GUIA_COMPLETA.mp4');
ensamblar({
    escenas: partes,
    salida: completo,
    subtitulos: join(VIDEO, 'subtitulos.srt'),
    voz: process.env.GUIA_VOZ,
});
console.log(
    `\n✓ ${completo.replace(`${RAIZ}/`, '')} (${Math.round(duracionDe(completo))} s)`,
);

// Miniatura: la portada real con el sello de duración.
const minutos = `${Math.floor(escenas.at(-1).fin / 60)}:${String(Math.round(escenas.at(-1).fin % 60)).padStart(2, '0')}`;
ff([
    '-i',
    join(CAPTURAS, '00-portada.png'),
    '-vf',
    FUENTE
        ? `drawtext=fontfile=${FUENTE}:text='${minutos} min · Guía completa':fontsize=40:fontcolor=white:box=1:boxcolor=0x1f4b4f@0.95:boxborderw=22:x=w-tw-90:y=h-th-90`
        : 'null',
    join(VIDEO, 'miniatura.png'),
]);

/* ───────────── videos cortos ───────────── */

if (!process.argv.includes('--solo-completo')) {
    for (const mini of MINI_VIDEOS) {
        const piezas = [];
        const cues = [];
        let t = 0;

        mini.escenas.forEach((escena, i) => {
            const clave = `${mini.id}-${i}`;
            const duracion = duracionEscenaCorto(escena.narracion);
            const pieza = { video: join(TMP, `mini-${clave}.mp4`) };
            segmento({
                captura: escena.captura,
                duracion,
                etiqueta: `${mini.titulo}  ·  ${escena.pantalla}`,
                salida: pieza.video,
                clave,
            });
            pieza.audio = usarVozSintetica
                ? audioEscena(escena.narracion, duracion, clave)
                : null;
            cues.push({
                inicio: t + 1,
                fin: t + duracion - 0.2,
                texto: escena.narracion,
            });
            t += duracion;
            piezas.push(pieza);
        });

        const srt = join(TMP, `mini-${mini.id}.srt`);
        const tiempo = (s) =>
            new Date(s * 1000).toISOString().slice(11, 23).replace('.', ',');
        writeFileSync(
            srt,
            cues
                .map(
                    (c, i) =>
                        `${i + 1}\n${tiempo(c.inicio)} --> ${tiempo(c.fin)}\n${c.texto}\n`,
                )
                .join('\n'),
        );

        if (!usarVozSintetica) {
            console.warn(
                `  ⚠ ${mini.id}: con GUIA_VOZ solo se compone el video completo; graba la voz de cada corto aparte.`,
            );
            continue;
        }

        const salida = join(VIDEO, 'cortos', `${mini.id}.mp4`);
        ensamblar({ escenas: piezas, salida, subtitulos: srt });
        console.log(
            `✓ docs/guias/video/cortos/${mini.id}.mp4 (${Math.round(duracionDe(salida))} s)`,
        );
    }
}

rmSync(TMP, { recursive: true, force: true });
