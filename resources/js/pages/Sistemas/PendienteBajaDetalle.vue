<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    ArrowLeft,
    CalendarX2,
    Check,
    CircleCheckBig,
    ClipboardCopy,
    Copy,
    CopyCheck,
    FolderOpen,
    Hourglass,
    Send,
    Undo2,
    UserRoundCheck,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import ColaboradorAvatar from '@/components/Common/ColaboradorAvatar.vue';
import { Button } from '@/components/ui/button';
import CambioSuave from '@/components/ui/CambioSuave.vue';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { dashboard } from '@/routes';
import {
    atender,
    index,
    reabrir,
    reenviar,
} from '@/routes/sistemas/pendientes-baja';
import type { DatoAltaSistemas, PendienteBajaSistemas } from '@/types';

/**
 * Detalle de una baja en sistemas (a donde lleva «Ver detalle de la baja»
 * del correo): datos con su botón Copiar y marcar como atendido.
 * PendientesBajaSistemasService::detalle.
 */
const props = defineProps<{ pendiente: PendienteBajaSistemas }>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Pendientes de baja en sistemas', href: index.url() },
            { title: 'Detalle de la baja', href: '#' },
        ],
    },
});

const p = computed(() => props.pendiente);
const copiado = ref<string | null>(null);
const procesando = ref(false);
const notas = ref('');

async function copiarTexto(clave: string, texto: string): Promise<void> {
    try {
        await navigator.clipboard.writeText(texto);
        copiado.value = clave;
        window.setTimeout(() => {
            if (copiado.value === clave) {
                copiado.value = null;
            }
        }, 1600);
    } catch {
        copiado.value = null;
    }
}

function copiarDato(dato: DatoAltaSistemas): void {
    if (!dato.falta) {
        void copiarTexto(dato.etiqueta, dato.valor);
    }
}

function enviar(url: string, datos: Record<string, string> = {}): void {
    procesando.value = true;
    router.post(url, datos, {
        preserveScroll: true,
        onFinish: () => (procesando.value = false),
    });
}
</script>

<template>
    <Head :title="`Baja en sistemas · ${p.colaborador.nombre}`" />

    <div class="pagina-ancha flex flex-col gap-4">
        <section
            class="flex flex-wrap items-center gap-4 rounded-2xl border bg-card p-4 shadow-xs"
        >
            <ColaboradorAvatar
                :nombre="p.colaborador.nombre"
                :foto-url="p.colaborador.foto_url"
                tamano="xl"
            />
            <div class="min-w-0 flex-1">
                <span
                    class="inline-flex items-center rounded-full bg-crema px-2.5 py-0.5 text-xs font-semibold text-bronce"
                >
                    {{ p.evento_etiqueta }}
                </span>
                <h1 class="mt-1 text-xl font-semibold">
                    {{ p.colaborador.nombre }}
                </h1>
                <p class="text-sm text-muted-foreground">
                    {{ p.colaborador.puesto ?? 'Sin puesto' }} ·
                    {{ p.colaborador.sucursal ?? 'Sin sucursal' }}
                </p>
            </div>
            <div
                class="flex items-center gap-3 rounded-xl bg-salvia/60 px-4 py-3"
            >
                <CalendarX2 class="size-6 text-esmeralda" aria-hidden="true" />
                <div class="flex flex-col">
                    <span class="text-xs text-muted-foreground">
                        Baja efectiva
                    </span>
                    <span class="font-semibold">
                        {{ p.fecha_baja ?? 'Sin capturar' }}
                    </span>
                </div>
            </div>
        </section>

        <p
            v-if="p.cancelado"
            class="rounded-xl bg-warning-soft px-4 py-3 text-sm text-warning"
        >
            Esta baja se canceló: la persona sigue laborando. Este aviso ya no
            se envía.
        </p>

        <div class="flex flex-wrap items-center gap-2">
            <Button variant="outline" as-child>
                <Link :href="index.url()">
                    <ArrowLeft class="size-4" />
                    Volver a la bandeja
                </Link>
            </Button>
            <Button @click="copiarTexto('__todo', p.texto_copiar)">
                <CopyCheck v-if="copiado === '__todo'" class="size-4" />
                <ClipboardCopy v-else class="size-4" />
                {{ copiado === '__todo' ? '¡Todo copiado!' : 'Copiar todo' }}
            </Button>
            <Button
                v-if="p.colaborador.url_expediente"
                variant="ghost"
                as-child
            >
                <Link :href="p.colaborador.url_expediente">
                    <FolderOpen class="size-4" />
                    Expediente
                </Link>
            </Button>
            <Button
                v-if="p.puede_reenviar"
                variant="ghost"
                :disabled="procesando"
                @click="enviar(reenviar.url(p.id))"
            >
                <Send class="size-4" />
                {{
                    p.correo.estado === 'sin_enviar'
                        ? 'Enviar correo'
                        : 'Reenviar correo'
                }}
            </Button>
        </div>

        <div
            class="grid entrada-escalonada grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4"
        >
            <section
                v-for="seccion in p.secciones"
                :key="seccion.clave"
                class="flex flex-col gap-1 rounded-2xl border bg-card p-3 shadow-xs"
            >
                <h2
                    class="mb-1 px-1 text-xs font-semibold tracking-wide text-[var(--pastel-petroleo)] uppercase"
                >
                    {{ seccion.titulo }}
                </h2>
                <button
                    v-for="dato in seccion.datos"
                    :key="dato.etiqueta"
                    type="button"
                    class="group flex items-center gap-3 rounded-xl border border-transparent px-2 py-2 text-left transicion-ui hover:border-[var(--pastel-borde)] hover:bg-crema/40 disabled:cursor-default"
                    :disabled="dato.falta"
                    :aria-label="`Copiar ${dato.etiqueta}`"
                    @click="copiarDato(dato)"
                >
                    <span class="min-w-0 flex-1">
                        <span class="block text-xs text-muted-foreground">
                            {{ dato.etiqueta }}
                        </span>
                        <span
                            class="block text-sm break-words"
                            :class="
                                dato.falta
                                    ? 'text-warning italic'
                                    : 'font-medium'
                            "
                        >
                            {{ dato.valor }}
                        </span>
                    </span>
                    <span
                        v-if="!dato.falta"
                        class="flex shrink-0 items-center gap-1 rounded-lg px-2 py-1 text-xs text-muted-foreground group-hover:bg-card group-hover:text-foreground"
                    >
                        <CambioSuave>
                            <Check
                                v-if="copiado === dato.etiqueta"
                                key="ok"
                                class="size-3.5 text-success"
                            />
                            <Copy v-else key="copiar" class="size-3.5" />
                        </CambioSuave>
                        {{ copiado === dato.etiqueta ? 'Copiado' : 'Copiar' }}
                    </span>
                </button>
            </section>
        </div>

        <section class="rounded-2xl border bg-card p-4 shadow-xs">
            <CambioSuave>
                <div
                    v-if="p.atendido"
                    key="atendido"
                    class="flex flex-wrap items-center gap-3"
                >
                    <UserRoundCheck class="size-5 text-success" />
                    <p class="flex-1 text-sm">
                        Atendido por <strong>{{ p.atendido.por }}</strong> ·
                        {{ p.atendido.en }}
                        <span
                            v-if="p.atendido.notas"
                            class="block text-muted-foreground italic"
                            >«{{ p.atendido.notas }}»</span
                        >
                    </p>
                    <Button
                        v-if="p.puede_atender"
                        variant="ghost"
                        size="sm"
                        :disabled="procesando"
                        @click="enviar(reabrir.url(p.id))"
                    >
                        <Undo2 class="size-4" />
                        Deshacer
                    </Button>
                </div>
                <div
                    v-else-if="p.puede_atender && !p.cancelado"
                    key="marcar"
                    class="flex flex-col gap-2"
                >
                    <p class="flex items-center gap-2 text-sm font-medium">
                        <Hourglass class="size-4 text-info" />
                        {{
                            p.evento === 'baja_cancelada'
                                ? '¿Ya reactivaste sus usuarios en los demás sistemas?'
                                : '¿Ya diste de baja sus usuarios en los demás sistemas?'
                        }}
                    </p>
                    <Label for="notas-baja" class="sr-only">Notas</Label>
                    <Textarea
                        id="notas-baja"
                        v-model="notas"
                        maxlength="1000"
                        placeholder="Notas (opcional): qué cuentas se desactivaron."
                    />
                    <div>
                        <Button
                            :disabled="procesando"
                            @click="enviar(atender.url(p.id), { notas: notas })"
                        >
                            <Spinner v-if="procesando" />
                            <CircleCheckBig v-else class="size-4" />
                            {{ p.accion }}
                        </Button>
                    </div>
                </div>
                <p
                    v-else
                    key="pendiente"
                    class="flex items-center gap-2 text-sm text-muted-foreground"
                >
                    <Hourglass class="size-4" />
                    {{
                        p.cancelado
                            ? 'Sin acción pendiente: la baja se canceló.'
                            : 'Pendiente de que Sistemas lo marque como atendido.'
                    }}
                </p>
            </CambioSuave>
        </section>
    </div>
</template>
