import { ref } from 'vue';

/**
 * Avisos del navegador al estilo WhatsApp Web: cuando llega una
 * notificación nueva con PEOPLE abierto, suena un tono corto, el título de
 * la pestaña muestra «(3) …», el ícono de la pestaña lleva un punto rojo y
 * —si la pestaña no está al frente— Chrome muestra la notificación del
 * sistema. Todo es local del navegador; el servidor no cambia.
 */

const CLAVE_SONIDO = 'people:avisos-sonido';
const PREFIJO_TITULO = /^\(\d+\+?\)\s/;

function leerPreferenciaSonido(): boolean {
    try {
        return localStorage.getItem(CLAVE_SONIDO) !== 'no';
    } catch {
        return true;
    }
}

/** Preferencia «Sonido de avisos» (por navegador). */
export const sonidoAvisos = ref(
    typeof window === 'undefined' ? true : leerPreferenciaSonido(),
);

export function cambiarSonidoAvisos(activo: boolean): void {
    sonidoAvisos.value = activo;

    try {
        localStorage.setItem(CLAVE_SONIDO, activo ? 'si' : 'no');
    } catch {
        // Sin almacenamiento (modo privado): dura lo que la pestaña.
    }
}

let audio: AudioContext | null = null;
let ultimoSonido = 0;

/**
 * Los navegadores solo dejan reproducir audio después de una interacción:
 * el contexto se crea/reanuda con el primer clic o tecla de la sesión.
 */
function prepararAudio(): void {
    if (typeof window === 'undefined' || !('AudioContext' in window)) {
        return;
    }

    audio ??= new AudioContext();

    if (audio.state === 'suspended') {
        void audio.resume().catch(() => undefined);
    }
}

if (typeof window !== 'undefined') {
    window.addEventListener('pointerdown', prepararAudio, { passive: true });
    window.addEventListener('keydown', prepararAudio, { passive: true });
}

/** Tono corto de dos notas (sintetizado: no hay archivo que descargar). */
export function sonarAviso(): void {
    if (!sonidoAvisos.value || !audio || audio.state !== 'running') {
        return;
    }

    // Varios avisos juntos = un solo tono.
    const ahora = Date.now();

    if (ahora - ultimoSonido < 2500) {
        return;
    }

    ultimoSonido = ahora;

    const inicio = audio.currentTime;

    for (const [indice, frecuencia] of [880, 1318.5].entries()) {
        const oscilador = audio.createOscillator();
        const volumen = audio.createGain();
        const t = inicio + indice * 0.12;

        oscilador.type = 'sine';
        oscilador.frequency.value = frecuencia;
        volumen.gain.setValueAtTime(0.0001, t);
        volumen.gain.exponentialRampToValueAtTime(0.12, t + 0.02);
        volumen.gain.exponentialRampToValueAtTime(0.0001, t + 0.35);
        oscilador.connect(volumen).connect(audio.destination);
        oscilador.start(t);
        oscilador.stop(t + 0.4);
    }
}

let pendientesTitulo = 0;

/**
 * «(3) Candidatos - PEOPLE». Inertia reescribe el título en cada
 * navegación: app.ts lo vuelve a pasar por aquí.
 */
export function tituloConPendientes(titulo: string): string {
    const limpio = titulo.replace(PREFIJO_TITULO, '');

    if (pendientesTitulo <= 0) {
        return limpio;
    }

    return `(${pendientesTitulo > 99 ? '99+' : pendientesTitulo}) ${limpio}`;
}

let iconosOriginales: Map<HTMLLinkElement, string> | null = null;
let imagenBase: Promise<HTMLImageElement | null> | null = null;

function cargarImagenBase(): Promise<HTMLImageElement | null> {
    imagenBase ??= new Promise((resolver) => {
        const imagen = new Image();
        imagen.onload = () => resolver(imagen);
        imagen.onerror = () => resolver(null);
        imagen.src = '/favicon.svg';
    });

    return imagenBase;
}

async function pintarFavicon(conPunto: boolean): Promise<void> {
    const enlaces = Array.from(
        document.querySelectorAll<HTMLLinkElement>(
            'link[rel="icon"], link[rel="shortcut icon"]',
        ),
    );

    if (enlaces.length === 0) {
        return;
    }

    iconosOriginales ??= new Map(enlaces.map((e) => [e, e.href]));

    if (!conPunto) {
        iconosOriginales.forEach((href, enlace) => {
            enlace.href = href;
        });

        return;
    }

    const imagen = await cargarImagenBase();
    const lienzo = document.createElement('canvas');
    lienzo.width = 64;
    lienzo.height = 64;
    const contexto = lienzo.getContext('2d');

    if (!contexto) {
        return;
    }

    if (imagen) {
        contexto.drawImage(imagen, 0, 0, 64, 64);
    }

    const estilos = getComputedStyle(document.documentElement);
    contexto.beginPath();
    contexto.arc(48, 16, 15, 0, Math.PI * 2);
    contexto.fillStyle =
        estilos.getPropertyValue('--semaforo-sube').trim() || 'red';
    contexto.fill();
    contexto.lineWidth = 4;
    contexto.strokeStyle =
        estilos.getPropertyValue('--pastel-superficie').trim() || 'white';
    contexto.stroke();

    const datos = lienzo.toDataURL('image/png');
    enlaces.forEach((enlace) => {
        enlace.href = datos;
    });
}

/** Contador en el título y punto rojo en el ícono de la pestaña. */
export function marcarPendientesEnPestana(total: number): void {
    if (typeof document === 'undefined') {
        return;
    }

    const antes = pendientesTitulo;
    pendientesTitulo = total;
    document.title = tituloConPendientes(document.title);

    if (antes > 0 !== total > 0) {
        void pintarFavicon(total > 0);
    }
}

/** ¿El usuario está viendo esta pestaña ahora mismo? */
export function pestanaAlFrente(): boolean {
    return document.visibilityState === 'visible' && document.hasFocus();
}

/**
 * Notificación del sistema (Windows/macOS/Android) desde la pestaña abierta.
 * El `tag` evita duplicados si hay varias pestañas abiertas. Clic → enfoca
 * PEOPLE y ejecuta `alHacerClic` (abre el aviso).
 */
export function notificacionDeSistema(
    id: string,
    titulo: string,
    cuerpo: string,
    alHacerClic: () => void,
    url = '/notificaciones/centro',
): void {
    if (!('Notification' in window) || Notification.permission !== 'granted') {
        return;
    }

    try {
        const aviso = new Notification(titulo, {
            body: cuerpo,
            tag: `mrlana-notificacion-${id}`,
            icon: '/apple-touch-icon.png',
            badge: '/favicon.svg',
        });

        aviso.onclick = () => {
            window.focus();
            alHacerClic();
            aviso.close();
        };
    } catch {
        // Android Chrome solo permite notificaciones vía Service Worker:
        // public/sw.js la muestra y, al tocarla, abre `url`.
        void navigator.serviceWorker
            ?.getRegistration()
            .then((registro) =>
                registro?.showNotification(titulo, {
                    body: cuerpo,
                    tag: `mrlana-notificacion-${id}`,
                    icon: '/apple-touch-icon.png',
                    data: { url },
                }),
            )
            .catch(() => undefined);
    }
}

/**
 * Con varias pestañas abiertas, solo UNA suena/avisa por cada notificación.
 */
export function reclamarAviso(id: string): boolean {
    const clave = `people:aviso:${id}`;

    try {
        if (localStorage.getItem(clave)) {
            return false;
        }

        localStorage.setItem(clave, String(Date.now()));
        // Limpieza: se olvidan los avisos de hace más de un día.
        const limite = Date.now() - 86_400_000;

        for (let i = localStorage.length - 1; i >= 0; i--) {
            const k = localStorage.key(i);

            if (
                k?.startsWith('people:aviso:') &&
                Number(localStorage.getItem(k)) < limite
            ) {
                localStorage.removeItem(k);
            }
        }
    } catch {
        // Sin almacenamiento: cada pestaña avisa por su cuenta.
    }

    return true;
}
