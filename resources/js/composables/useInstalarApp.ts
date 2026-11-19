import { computed, ref } from 'vue';

/**
 * «Instalar PEOPLE» como app (PWA) desde el navegador. Complementa —nunca
 * reemplaza— la app nativa de Android: sirve sobre todo en iPhone/iPad y
 * computadoras, donde el APK no existe. Ver docs/PWA.md.
 *
 * - Android/Chrome/Edge: el navegador dispara `beforeinstallprompt` una
 *   sola vez; se guarda aquí y el botón «Instalar» lo usa.
 * - iPhone/iPad: no existe ese evento; se muestran las instrucciones
 *   «Compartir › Agregar a inicio».
 * - Ya instalada (modo standalone): no se muestra nada.
 */

type EventoInstalacion = Event & {
    prompt: () => Promise<void>;
    userChoice: Promise<{ outcome: 'accepted' | 'dismissed' }>;
};

const CLAVE_OCULTO = 'people:instalar-app-oculto-hasta';
const DIAS_OCULTO = 30;
const DIA = 86_400_000;

const eventoPendiente = ref<EventoInstalacion | null>(null);
const instalada = ref(false);
const oculto = ref(false);
let escuchando = false;

function enModoApp(): boolean {
    try {
        return (
            window.matchMedia('(display-mode: standalone)').matches ||
            window.matchMedia('(display-mode: fullscreen)').matches ||
            (navigator as Navigator & { standalone?: boolean }).standalone ===
                true
        );
    } catch {
        return false;
    }
}

function esIos(): boolean {
    const ua = navigator.userAgent;

    // iPadOS se presenta como Mac: se distingue por la pantalla táctil.
    return (
        /iPhone|iPad|iPod/i.test(ua) ||
        (/Macintosh/i.test(ua) && navigator.maxTouchPoints > 1)
    );
}

function leerOculto(): boolean {
    try {
        return Number(localStorage.getItem(CLAVE_OCULTO) ?? 0) > Date.now();
    } catch {
        return false;
    }
}

/** Se llama una vez desde app.ts, antes de montar Vue. */
export function escucharInstalacion(): void {
    if (escuchando || typeof window === 'undefined') {
        return;
    }

    escuchando = true;
    instalada.value = enModoApp();
    oculto.value = leerOculto();

    window.addEventListener('beforeinstallprompt', (evento) => {
        // Sin la mini barra automática de Chrome: se ofrece con el banner.
        evento.preventDefault();
        eventoPendiente.value = evento as EventoInstalacion;
    });

    window.addEventListener('appinstalled', () => {
        instalada.value = true;
        eventoPendiente.value = null;
    });
}

export function useInstalarApp() {
    const ios = typeof window !== 'undefined' && esIos();

    /** «android»: botón que abre el diálogo del navegador; «ios»: instrucciones. */
    const modo = computed<'android' | 'ios' | null>(() => {
        if (instalada.value) {
            return null;
        }

        if (eventoPendiente.value) {
            return 'android';
        }

        return ios ? 'ios' : null;
    });

    const visible = computed(() => modo.value !== null && !oculto.value);

    async function instalar(): Promise<boolean> {
        const evento = eventoPendiente.value;

        if (!evento) {
            return false;
        }

        try {
            await evento.prompt();
            const { outcome } = await evento.userChoice;

            // El evento solo sirve una vez: Chrome lo vuelve a mandar después.
            eventoPendiente.value = null;

            return outcome === 'accepted';
        } catch {
            return false;
        }
    }

    function ahoraNo(): void {
        oculto.value = true;

        try {
            localStorage.setItem(
                CLAVE_OCULTO,
                String(Date.now() + DIAS_OCULTO * DIA),
            );
        } catch {
            // Sin almacenamiento (modo privado): solo en esta pestaña.
        }
    }

    return { modo, visible, instalar, ahoraNo };
}
