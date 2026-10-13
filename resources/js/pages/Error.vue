<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ArrowLeft, ClipboardCopy, House } from '@lucide/vue';
import { computed, ref } from 'vue';
import AppLogo from '@/components/AppLogo.vue';
import { Button } from '@/components/ui/button';

/**
 * Página de error corporativa (bootstrap/app.php, solo con APP_DEBUG=false):
 * nunca muestra trazas, rutas del servidor, consultas ni cookies. Se pinta
 * sin AppLayout (ver resources/js/app.ts) porque un 404 o un 500 puede
 * ocurrir sin sesión ni props compartidas.
 *
 * `diagnostico` solo llega para super_admin/sistemas (bootstrap/app.php):
 * ruta, permiso o regla que negó el acceso y roles — nunca trazas, clases
 * PHP ni datos de otras personas. Un colaborador normal no lo recibe.
 */
type DiagnosticoAcceso = {
    ruta: string;
    nombre_ruta: string | null;
    metodo: string;
    permiso_requerido: string | null;
    regla_negada: string | null;
    motivo: string | null;
    roles: string[];
    tiene_colaborador: boolean;
};

const props = defineProps<{
    status: number;
    mensaje?: string | null;
    diagnostico?: DiagnosticoAcceso | null;
}>();

const TEXTOS: Record<number, { titulo: string; descripcion: string }> = {
    403: {
        titulo: 'No tienes acceso a esta sección',
        descripcion:
            'Tu rol no tiene permiso para abrir esta pantalla. Si crees que es un error, pide a Recursos Humanos o a Sistemas que revisen tus permisos.',
    },
    404: {
        titulo: 'No encontramos esta página',
        descripcion:
            'La dirección no existe o el registro ya no está disponible. Revisa el enlace o regresa al inicio.',
    },
    409: {
        titulo: 'Todavía no está disponible',
        descripcion:
            'Este documento o paso aún no se libera. Revisa el estado del trámite e inténtalo más tarde.',
    },
    419: {
        titulo: 'Tu sesión expiró',
        descripcion:
            'Por seguridad, la página caducó. Recarga e intenta de nuevo.',
    },
    422: {
        titulo: 'No se pudo procesar la solicitud',
        descripcion:
            'Algunos datos no son válidos. Regresa, revisa la información e intenta de nuevo.',
    },
    429: {
        titulo: 'Demasiados intentos',
        descripcion: 'Espera un momento antes de volver a intentarlo.',
    },
    500: {
        titulo: 'Algo salió mal',
        descripcion:
            'Ocurrió un error inesperado y ya quedó registrado para que Sistemas lo revise. Intenta de nuevo en unos minutos.',
    },
    503: {
        titulo: 'Estamos en mantenimiento',
        descripcion:
            'MR. LANA PEOPLE está temporalmente fuera de servicio por mantenimiento. Vuelve a intentarlo en unos minutos.',
    },
};

const texto = computed(() => TEXTOS[props.status] ?? TEXTOS[500]);

const copiado = ref(false);

const textoDiagnostico = computed(() => {
    const d = props.diagnostico;

    if (!d) {
        return '';
    }

    return [
        `Error ${props.status}`,
        `Ruta: ${d.metodo} ${d.ruta}${d.nombre_ruta ? ` (${d.nombre_ruta})` : ''}`,
        `Permiso requerido: ${d.permiso_requerido ?? '—'}`,
        `Regla que negó: ${d.regla_negada ?? '—'}`,
        `Motivo: ${d.motivo ?? '—'}`,
        `Rol: ${d.roles.join(', ') || '—'}`,
        `Colaborador vinculado: ${d.tiene_colaborador ? 'sí' : 'no'}`,
    ].join('\n');
});

async function copiarDiagnostico() {
    try {
        await navigator.clipboard.writeText(textoDiagnostico.value);
        copiado.value = true;
        window.setTimeout(() => (copiado.value = false), 2000);
    } catch {
        copiado.value = false;
    }
}

function volver() {
    if (window.history.length > 1) {
        window.history.back();

        return;
    }

    window.location.assign('/');
}
</script>

<template>
    <Head :title="texto.titulo" />

    <main
        class="flex min-h-svh items-center justify-center bg-background px-4 py-10 text-foreground"
    >
        <div
            class="flex w-full max-w-md flex-col gap-6 rounded-2xl border bg-card p-6 shadow-sm sm:p-8"
        >
            <div class="flex items-center">
                <AppLogo />
            </div>

            <div class="space-y-2">
                <p
                    class="text-sm font-semibold text-[var(--brand-primary)] tabular-nums"
                >
                    Error {{ status }}
                </p>
                <h1 class="text-xl font-semibold tracking-tight text-balance">
                    {{ texto.titulo }}
                </h1>
                <p class="text-sm text-pretty text-muted-foreground">
                    {{ mensaje || texto.descripcion }}
                </p>
            </div>

            <div class="flex flex-col gap-2 sm:flex-row">
                <Button variant="outline" class="gap-2" @click="volver">
                    <ArrowLeft class="size-4" />
                    Regresar
                </Button>
                <Button as-child class="gap-2">
                    <Link href="/">
                        <House class="size-4" />
                        Ir al inicio
                    </Link>
                </Button>
            </div>

            <details
                v-if="diagnostico"
                class="rounded-xl border bg-muted/40 p-3 text-xs text-muted-foreground"
            >
                <summary class="cursor-pointer font-medium text-foreground">
                    Diagnóstico de acceso
                </summary>
                <dl class="mt-3 grid grid-cols-[auto_1fr] gap-x-3 gap-y-1">
                    <dt>Ruta</dt>
                    <dd class="break-all">
                        {{ diagnostico.metodo }} {{ diagnostico.ruta }}
                        <span v-if="diagnostico.nombre_ruta"
                            >({{ diagnostico.nombre_ruta }})</span
                        >
                    </dd>
                    <dt>Permiso requerido</dt>
                    <dd class="break-all">
                        {{ diagnostico.permiso_requerido ?? '—' }}
                    </dd>
                    <dt>Regla que negó</dt>
                    <dd class="break-all">
                        {{ diagnostico.regla_negada ?? '—' }}
                    </dd>
                    <dt>Motivo</dt>
                    <dd>{{ diagnostico.motivo ?? '—' }}</dd>
                    <dt>Rol</dt>
                    <dd>{{ diagnostico.roles.join(', ') || '—' }}</dd>
                    <dt>Colaborador</dt>
                    <dd>
                        {{
                            diagnostico.tiene_colaborador
                                ? 'Vinculado'
                                : 'Sin colaborador vinculado'
                        }}
                    </dd>
                </dl>
                <Button
                    variant="outline"
                    size="sm"
                    class="mt-3 gap-2"
                    @click="copiarDiagnostico"
                >
                    <ClipboardCopy class="size-4" />
                    {{ copiado ? 'Copiado' : 'Copiar diagnóstico' }}
                </Button>
            </details>
        </div>
    </main>
</template>
