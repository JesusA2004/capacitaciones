<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { Palette, RotateCcw } from '@lucide/vue';
import { computed } from 'vue';
import AyudaBoton from '@/components/Common/AyudaBoton.vue';
import ConfiguracionTabs from '@/components/configuracion/ConfiguracionTabs.vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { dashboard } from '@/routes';
import { apariencia, restaurar } from '@/routes/administracion/configuracion';
import type { ParametroConfiguracion, SeccionConfiguracion } from '@/types';

const props = defineProps<{
    colores: ParametroConfiguracion[];
    secciones: SeccionConfiguracion[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Configuración', href: '' },
            { title: 'Apariencia', href: '' },
        ],
    },
});

const HEX = /^#[0-9A-Fa-f]{6}$/;

const form = useForm<{ valores: Record<string, string> }>({
    valores: Object.fromEntries(
        props.colores.map((c) => [c.clave, String(c.valor).toUpperCase()]),
    ),
});

const valido = (valor: string) => HEX.test(valor);

// Dos bloques: colores institucionales y un color por cada gráfica.
const institucionales = computed(() =>
    props.colores.filter((c) => c.seccion !== 'graficas'),
);
const graficas = computed(() =>
    props.colores.filter((c) => c.seccion === 'graficas'),
);
const bloques = computed(() => [
    {
        clave: 'institucionales',
        titulo: 'Colores institucionales',
        ayuda: 'Los colores de la marca: encabezados, botones, estados (aprobado, en proceso, rechazado) y fondos. Se aplican en la web (modo claro) y en la app.',
        colores: institucionales.value,
    },
    {
        clave: 'graficas',
        titulo: 'Colores de las gráficas',
        ayuda: 'Cada gráfica del tablero de RH tiene su propio color. Cámbialo aquí y se verá así en la web.',
        colores: graficas.value,
    },
]);
const hayCambios = computed(() =>
    props.colores.some(
        (c) => form.valores[c.clave] !== String(c.valor).toUpperCase(),
    ),
);
const todosValidos = computed(() => Object.values(form.valores).every(valido));

// Vista previa local: aplica los colores del formulario solo dentro del
// recuadro de muestra (no a toda la app hasta guardar).
const estiloPreview = computed(() =>
    Object.fromEntries(
        props.colores
            .filter((c) => c.css && valido(form.valores[c.clave]))
            .map((c) => [c.css as string, form.valores[c.clave]]),
    ),
);

function normalizar(clave: string) {
    const v = form.valores[clave].trim();
    form.valores[clave] = (v.startsWith('#') ? v : `#${v}`).toUpperCase();
}

function guardar() {
    form.put(apariencia.url(), { preserveScroll: true });
}

function restaurarColor(c: ParametroConfiguracion) {
    router.post(
        restaurar.url(),
        { clave: c.clave },
        {
            preserveScroll: true,
            onSuccess: () =>
                (form.valores[c.clave] = String(c.defecto).toUpperCase()),
        },
    );
}
</script>

<template>
    <Head title="Apariencia" />

    <div class="pagina-ancha flex flex-col gap-5">
        <CrudPageHeader
            titulo="Apariencia"
            descripcion="Colores institucionales de la web y la app"
            :icono="Palette"
        />
        <ConfiguracionTabs :secciones="secciones" actual="apariencia" />

        <p class="text-sm text-[var(--mrl-texto-suave)]">
            Los colores institucionales se aplican a toda la plataforma web
            (modo claro) y la app los recibe sin actualizar la APK. Los colores
            de las gráficas aplican al tablero de RH en ambos modos.
        </p>

        <div class="grid gap-5 lg:grid-cols-[1fr_320px]">
            <form class="flex flex-col gap-2" @submit.prevent="guardar">
                <template v-for="b in bloques" :key="b.clave">
                    <h2
                        v-if="b.colores.length"
                        class="mt-2 flex items-center gap-1.5 text-sm font-semibold first:mt-0"
                    >
                        {{ b.titulo }}
                        <AyudaBoton :titulo="b.titulo" :texto="b.ayuda" />
                    </h2>
                    <div
                        v-for="c in b.colores"
                        :key="c.clave"
                        class="flex flex-wrap items-center gap-3 rounded-xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] p-3"
                    >
                        <input
                            v-model="form.valores[c.clave]"
                            type="color"
                            class="size-10 shrink-0 cursor-pointer rounded-md border border-[var(--mrl-borde)] bg-transparent"
                            :aria-label="`Elegir ${c.etiqueta}`"
                            @change="normalizar(c.clave)"
                        />
                        <div class="min-w-0 flex-1">
                            <p
                                class="flex items-center gap-1.5 text-sm font-medium"
                            >
                                {{ c.etiqueta }}
                                <AyudaBoton
                                    v-if="c.descripcion"
                                    :titulo="c.etiqueta"
                                    :texto="c.descripcion"
                                />
                                <span
                                    v-if="c.personalizado"
                                    class="ml-1 rounded-full bg-[var(--mrl-gold)]/15 px-2 py-0.5 text-xs text-[var(--mrl-gold-dark)]"
                                    >personalizado</span
                                >
                            </p>
                            <p class="text-xs text-[var(--mrl-texto-suave)]">
                                {{ c.descripcion }}
                            </p>
                            <InputError
                                :message="
                                    (form.errors as Record<string, string>)[
                                        `valores.${c.clave}`
                                    ]
                                "
                            />
                        </div>
                        <Input
                            v-model="form.valores[c.clave]"
                            class="w-28 font-mono uppercase"
                            maxlength="7"
                            :aria-invalid="!valido(form.valores[c.clave])"
                            :aria-label="`Código HEX de ${c.etiqueta}`"
                            @blur="normalizar(c.clave)"
                        />
                        <Button
                            v-if="c.personalizado"
                            type="button"
                            size="sm"
                            variant="ghost"
                            :title="`Restaurar ${c.defecto}`"
                            @click="restaurarColor(c)"
                        >
                            <RotateCcw class="size-4" /> {{ c.defecto }}
                        </Button>
                    </div>
                </template>

                <div
                    class="sticky bottom-0 flex items-center gap-3 bg-[var(--mrl-fondo)] py-3"
                >
                    <Button
                        type="submit"
                        :disabled="
                            form.processing || !hayCambios || !todosValidos
                        "
                        >Guardar colores</Button
                    >
                    <span
                        v-if="!todosValidos"
                        class="text-sm text-[var(--mrl-danger)]"
                        >Usa el formato #RRGGBB.</span
                    >
                </div>
            </form>

            <aside
                class="flex flex-col gap-3 rounded-2xl border border-[var(--mrl-borde)] p-4 lg:sticky lg:top-4 lg:self-start"
                :style="estiloPreview"
                aria-label="Vista previa"
            >
                <p
                    class="text-xs font-semibold tracking-wide text-[var(--mrl-texto-suave)] uppercase"
                >
                    Vista previa
                </p>
                <div
                    class="flex flex-col gap-3 rounded-xl bg-[var(--mrl-fondo)] p-3"
                >
                    <div
                        class="rounded-lg bg-[var(--mrl-petroleo)] px-3 py-2 text-sm font-semibold text-white"
                    >
                        Encabezado institucional
                    </div>
                    <div
                        class="rounded-lg border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] p-3 text-sm"
                    >
                        <p
                            class="font-semibold text-[var(--mrl-verde-profundo)]"
                        >
                            Tarjeta de ejemplo
                        </p>
                        <p class="text-[var(--mrl-texto-suave)]">
                            Texto secundario
                        </p>
                        <div class="mt-2 flex flex-wrap gap-1.5">
                            <span
                                class="rounded-full bg-[var(--mrl-verde)] px-2 py-0.5 text-xs text-white"
                                >Aprobado</span
                            >
                            <span
                                class="rounded-full bg-[var(--mrl-dorado)] px-2 py-0.5 text-xs text-white"
                                >En proceso</span
                            >
                            <span
                                class="rounded-full bg-[var(--mrl-rojo)] px-2 py-0.5 text-xs text-white"
                                >Rechazado</span
                            >
                            <span
                                class="rounded-full bg-[var(--mrl-cyan)] px-2 py-0.5 text-xs text-white"
                                >Dato</span
                            >
                            <span
                                class="rounded-full bg-[var(--mrl-navy)] px-2 py-0.5 text-xs text-white"
                                >Gráfica</span
                            >
                        </div>
                    </div>
                    <button
                        type="button"
                        class="rounded-lg bg-[var(--mrl-petroleo-2)] px-3 py-2 text-sm font-medium text-white"
                    >
                        Botón principal
                    </button>
                    <button
                        type="button"
                        class="rounded-lg border border-[var(--mrl-verde-secundario)] px-3 py-2 text-sm font-medium text-[var(--mrl-verde-secundario)]"
                    >
                        Botón secundario
                    </button>
                    <p class="text-xs text-[var(--mrl-dorado-oscuro)]">
                        Advertencia en dorado oscuro
                    </p>
                    <p class="text-xs text-[var(--mrl-gris-verdoso)]">
                        Elemento deshabilitado
                    </p>
                </div>
                <div
                    v-if="graficas.length"
                    class="flex flex-col gap-2 rounded-xl bg-[var(--mrl-fondo)] p-3"
                >
                    <p
                        class="text-xs font-medium text-[var(--mrl-texto-suave)]"
                    >
                        Gráficas
                    </p>
                    <div
                        v-for="(g, i) in graficas"
                        :key="g.clave"
                        class="grid grid-cols-[7rem_1fr] items-center gap-2 text-xs"
                    >
                        <span class="truncate">{{ g.etiqueta }}</span>
                        <span
                            class="h-2.5 overflow-hidden rounded-full bg-muted"
                        >
                            <span
                                class="block h-full rounded-full"
                                :style="{
                                    width: `${90 - i * 11}%`,
                                    background: valido(form.valores[g.clave])
                                        ? form.valores[g.clave]
                                        : String(g.defecto),
                                }"
                            />
                        </span>
                    </div>
                </div>
            </aside>
        </div>
    </div>
</template>
