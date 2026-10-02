<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { RotateCcw, SlidersHorizontal } from '@lucide/vue';
import { reactive } from 'vue';
import Casilla from '@/components/Common/Casilla.vue';
import SelectSimple from '@/components/Common/SelectSimple.vue';
import ConfiguracionTabs from '@/components/configuracion/ConfiguracionTabs.vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { dashboard } from '@/routes';
import { parametrosRh, restaurar } from '@/routes/administracion/configuracion';
import { update as actualizarPuesto } from '@/routes/administracion/configuracion/parametros-rh/puestos';
import { update as actualizarTipoDocumento } from '@/routes/administracion/configuracion/parametros-rh/tipos-documento';
import { configuracion as configuracionOnboarding } from '@/routes/rh/onboarding';
import type { ParametroConfiguracion, SeccionConfiguracion } from '@/types';

type PuestoParametros = {
    id: number;
    nombre: string;
    meses_periodo_prueba: number | null;
    grupo_indicador: string | null;
};

const props = defineProps<{
    parametros: ParametroConfiguracion[];
    puestos: PuestoParametros[];
    tiposDocumento: {
        id: number;
        nombre: string;
        vigencia_meses: number | null;
    }[];
    grupos: { value: string; etiqueta: string }[];
    secciones: SeccionConfiguracion[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Configuración', href: '' },
            { title: 'Parámetros de RH', href: '' },
        ],
    },
});

const form = useForm<{ valores: Record<string, string | number | string[]> }>({
    valores: Object.fromEntries(
        props.parametros.map((p) => [
            p.clave,
            Array.isArray(p.valor)
                ? [...p.valor]
                : (p.valor as string | number),
        ]),
    ),
});

function guardar() {
    form.put(parametrosRh.url(), { preserveScroll: true });
}

function restaurarParametro(p: ParametroConfiguracion) {
    router.post(restaurar.url(), { clave: p.clave }, { preserveScroll: true });
}

// Edición en línea: undefined = vacío (usa la duración por defecto / no vence).
const puestos = reactive(
    props.puestos.map((p) => ({
        ...p,
        meses_periodo_prueba: p.meses_periodo_prueba ?? undefined,
    })),
);
const vigencias = reactive(
    props.tiposDocumento.map((t) => ({
        ...t,
        vigencia_meses: t.vigencia_meses ?? undefined,
    })),
);

function guardarPuesto(
    p: Omit<PuestoParametros, 'meses_periodo_prueba'> & {
        meses_periodo_prueba: number | undefined;
    },
) {
    router.put(
        actualizarPuesto.url(p.id),
        {
            meses_periodo_prueba: p.meses_periodo_prueba || null,
            grupo_indicador: p.grupo_indicador || null,
        },
        { preserveScroll: true },
    );
}

function guardarVigencia(t: {
    id: number;
    vigencia_meses: number | undefined;
}) {
    router.put(
        actualizarTipoDocumento.url(t.id),
        { vigencia_meses: t.vigencia_meses || null },
        { preserveScroll: true },
    );
}

const error = (clave: string) =>
    (form.errors as Record<string, string>)[`valores.${clave}`];
</script>

<template>
    <Head title="Parámetros de RH" />

    <div class="pagina-ancha flex flex-col gap-5">
        <CrudPageHeader
            titulo="Parámetros de RH"
            descripcion="Valores del ciclo laboral que RH ajusta"
            :icono="SlidersHorizontal"
        />
        <ConfiguracionTabs :secciones="secciones" actual="parametros-rh" />

        <p class="text-sm text-[var(--mrl-texto-suave)]">
            Lo esencial no se configura: RH siempre da la autorización final y
            quien preautoriza no puede autorizar. Los módulos de inducción y los
            tipos de activo se administran en
            <Link
                :href="configuracionOnboarding().url"
                class="text-[var(--mrl-primary)] hover:underline"
                >Onboarding</Link
            >.
        </p>

        <form class="flex flex-col gap-3" @submit.prevent="guardar">
            <div
                v-for="p in parametros"
                :key="p.clave"
                class="rounded-xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] p-4"
            >
                <div class="flex flex-wrap items-start gap-3">
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-medium">
                            {{ p.etiqueta }}
                            <span
                                v-if="p.personalizado"
                                class="ml-1 rounded-full bg-[var(--mrl-gold)]/15 px-2 py-0.5 text-xs text-[var(--mrl-gold-dark)]"
                                >personalizado</span
                            >
                        </p>
                        <p class="text-xs text-[var(--mrl-texto-suave)]">
                            {{ p.descripcion }}
                        </p>
                    </div>

                    <Input
                        v-if="p.tipo === 'entero' || p.tipo === 'decimal'"
                        v-model.number="form.valores[p.clave] as number"
                        type="number"
                        :step="p.tipo === 'decimal' ? '0.5' : '1'"
                        class="w-28"
                        :aria-label="p.etiqueta"
                    />
                    <Button
                        v-if="p.personalizado"
                        type="button"
                        size="sm"
                        variant="ghost"
                        :title="`Restaurar ${p.defecto}`"
                        @click="restaurarParametro(p)"
                    >
                        <RotateCcw class="size-4" />
                    </Button>
                </div>

                <fieldset
                    v-if="p.tipo === 'lista' && p.opciones"
                    class="mt-3 grid gap-1 text-sm sm:grid-cols-2"
                >
                    <label
                        v-for="o in p.opciones"
                        :key="o.value"
                        class="flex items-center gap-2"
                    >
                        <Casilla
                            v-model="form.valores[p.clave] as string[]"
                            :value="o.value"
                        />
                        {{ o.etiqueta }}
                    </label>
                </fieldset>
                <InputError :message="error(p.clave)" />
            </div>

            <Button
                type="submit"
                class="w-fit"
                :disabled="form.processing || !form.isDirty"
                >Guardar parámetros</Button
            >
        </form>

        <section
            class="flex flex-col gap-2"
            aria-label="Duración del contrato por puesto"
        >
            <h2 class="text-sm font-semibold">
                Duración del contrato de capacitación por puesto
            </h2>
            <p class="text-xs text-[var(--mrl-texto-suave)]">
                Vacío = se usa la duración por defecto. El aviso de renovación
                sale los días configurados antes del fin.
            </p>
            <div
                class="overflow-x-auto rounded-xl border border-[var(--mrl-borde)]"
            >
                <table class="w-full text-sm">
                    <thead
                        class="bg-[var(--mrl-fondo)] text-left text-xs text-[var(--mrl-texto-suave)]"
                    >
                        <tr>
                            <th class="px-3 py-2">Puesto</th>
                            <th class="px-3 py-2">Meses</th>
                            <th class="px-3 py-2">Grupo para indicadores</th>
                            <th class="px-3 py-2" />
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="p in puestos"
                            :key="p.id"
                            class="border-t border-[var(--mrl-borde)]"
                        >
                            <td class="px-3 py-2">{{ p.nombre }}</td>
                            <td class="px-3 py-2">
                                <Input
                                    v-model.number="p.meses_periodo_prueba"
                                    type="number"
                                    min="1"
                                    max="12"
                                    class="h-8 w-20"
                                    :aria-label="`Meses de ${p.nombre}`"
                                />
                            </td>
                            <td class="px-3 py-2">
                                <SelectSimple
                                    v-model="p.grupo_indicador"
                                    :opciones="
                                        grupos.map((g) => ({
                                            value: g.value,
                                            label: g.etiqueta,
                                        }))
                                    "
                                    opcion-vacia="Sin grupo"
                                    size="sm"
                                    class="w-44"
                                    :aria-label="`Grupo de ${p.nombre}`"
                                />
                            </td>
                            <td class="px-3 py-2 text-right">
                                <Button
                                    size="sm"
                                    variant="outline"
                                    @click="guardarPuesto(p)"
                                    >Guardar</Button
                                >
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="flex flex-col gap-2" aria-label="Vigencia documental">
            <h2 class="text-sm font-semibold">
                Vigencia de documentos del expediente
            </h2>
            <p class="text-xs text-[var(--mrl-texto-suave)]">
                Meses tras los cuales un documento se considera vencido y se
                vuelve a pedir (p. ej. en un reingreso). Vacío = no vence.
            </p>
            <ul class="grid gap-2 sm:grid-cols-2">
                <li
                    v-for="t in vigencias"
                    :key="t.id"
                    class="flex items-center gap-2 rounded-xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] p-2 text-sm"
                >
                    <span class="min-w-0 flex-1 truncate">{{ t.nombre }}</span>
                    <Input
                        v-model.number="t.vigencia_meses"
                        type="number"
                        min="1"
                        max="120"
                        class="h-8 w-20"
                        :aria-label="`Vigencia de ${t.nombre}`"
                    />
                    <Button
                        size="sm"
                        variant="ghost"
                        @click="guardarVigencia(t)"
                        >Guardar</Button
                    >
                </li>
            </ul>
        </section>
    </div>
</template>
