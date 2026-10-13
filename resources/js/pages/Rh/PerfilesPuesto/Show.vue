<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import {
    ClipboardList,
    Pencil,
    Sparkles,
    UserSearch,
    Briefcase,
} from '@lucide/vue';
import { ref } from 'vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { formatearFecha } from '@/lib/fechas';
import { SECCIONES_CANDIDATO, SECCIONES_PUESTO } from '@/lib/perfilPuesto';
import type { SeccionPerfil } from '@/lib/perfilPuesto';
import { dashboard } from '@/routes';
import { index, update } from '@/routes/rh/perfiles-puesto';
import type { ListaPerfilPuesto, PerfilPuestoDetalle } from '@/types';

const props = defineProps<{
    puesto: PerfilPuestoDetalle['puesto'];
    perfil: PerfilPuestoDetalle['perfil'];
    es_borrador: boolean;
    avance: number;
    actualizado_por: string | null;
    actualizado_en: string | null;
    puedeEditar: boolean;
}>();

defineOptions({
    layout: (pageProps: PerfilPuestoDetalle) => ({
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Perfiles de puesto', href: index.url() },
            { title: pageProps.puesto.nombre, href: '' },
        ],
    }),
});

const LISTAS: ListaPerfilPuesto[] = [
    'funciones',
    'responsabilidades',
    'indicadores',
    'conocimientos',
    'habilidades',
    'competencias',
    'valores',
    'requisitos',
];

// Un puesto sin perfil abre directo en edición (con el borrador sugerido).
const editando = ref(props.puedeEditar && props.es_borrador);

function formularioInicial() {
    const datos: Record<string, string> = {
        objetivo: props.perfil.objetivo ?? '',
        escolaridad: props.perfil.escolaridad ?? '',
        experiencia: props.perfil.experiencia ?? '',
    };

    for (const lista of LISTAS) {
        datos[lista] = props.perfil[lista].join('\n');
    }

    return datos;
}

const form = useForm(formularioInicial());

function guardar() {
    form.transform((datos) => {
        const salida: Record<string, string | string[]> = {
            objetivo: datos.objetivo,
            escolaridad: datos.escolaridad,
            experiencia: datos.experiencia,
        };

        for (const lista of LISTAS) {
            salida[lista] = String(datos[lista] ?? '')
                .split('\n')
                .map((r) => r.trim())
                .filter(Boolean);
        }

        return salida;
    }).put(update.url(props.puesto.id), {
        preserveScroll: true,
        onSuccess: () => {
            editando.value = false;
        },
    });
}

function cancelar() {
    form.defaults(formularioInicial());
    form.reset();
    form.clearErrors();
    editando.value = false;
}

function valorTexto(seccion: SeccionPerfil): string | null {
    return seccion.tipo === 'lista' ? null : props.perfil[seccion.clave];
}

function valorLista(seccion: SeccionPerfil): string[] {
    return seccion.tipo === 'lista' ? props.perfil[seccion.clave] : [];
}

function error(clave: string): string | undefined {
    const errores = form.errors as Record<string, string | undefined>;

    return (
        errores[clave] ??
        Object.entries(errores).find(([k]) => k.startsWith(`${clave}.`))?.[1]
    );
}

const GRUPOS = [
    {
        clave: 'candidato',
        titulo: 'Perfil del candidato',
        descripcion: 'Lo que debe tener quien se postula a este puesto.',
        icono: UserSearch,
        secciones: SECCIONES_CANDIDATO,
    },
    {
        clave: 'puesto',
        titulo: 'Descripción del puesto',
        descripcion: 'El deber ser: para qué existe y qué hace en la empresa.',
        icono: Briefcase,
        secciones: SECCIONES_PUESTO,
    },
];
</script>

<template>
    <Head :title="`Perfil · ${puesto.nombre}`" />

    <div class="pagina-ancha flex flex-col gap-5">
        <CrudPageHeader
            detalle
            :titulo="puesto.nombre"
            :descripcion="`${puesto.departamento ?? 'Sin departamento'} · ${puesto.ocupantes} persona(s) en el puesto`"
            :icono="ClipboardList"
        >
            <Button
                v-if="puedeEditar && !editando"
                variant="secondary"
                @click="editando = true"
            >
                <Pencil class="size-4" /> Editar perfil
            </Button>
        </CrudPageHeader>

        <section
            class="grid gap-3 rounded-2xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] p-4 text-sm sm:grid-cols-3"
        >
            <div>
                <p class="text-xs text-muted-foreground">Reporta a</p>
                <p class="font-medium">{{ puesto.reporta_a ?? '—' }}</p>
            </div>
            <div>
                <p class="text-xs text-muted-foreground">Supervisa a</p>
                <p class="font-medium">
                    {{
                        puesto.supervisa.length
                            ? puesto.supervisa.join(', ')
                            : '—'
                    }}
                </p>
            </div>
            <div>
                <p class="text-xs text-muted-foreground">
                    Última actualización
                </p>
                <p class="font-medium">
                    <template v-if="actualizado_en">
                        {{ formatearFecha(actualizado_en) }}
                        <span
                            v-if="actualizado_por"
                            class="text-muted-foreground"
                        >
                            · {{ actualizado_por }}</span
                        >
                    </template>
                    <template v-else>Sin perfil todavía</template>
                </p>
            </div>
            <p class="text-xs text-muted-foreground sm:col-span-3">
                «Reporta a» y «Supervisa a» salen del organigrama.
            </p>
        </section>

        <p
            v-if="es_borrador && puedeEditar"
            class="flex items-start gap-2 rounded-xl border border-[var(--mrl-dorado)]/50 bg-[var(--mrl-crema)] p-3 text-sm"
        >
            <Sparkles
                class="mt-0.5 size-4 shrink-0 text-[var(--mrl-dorado-oscuro)]"
            />
            Este puesto todavía no tiene perfil. Te dejamos un borrador con lo
            que ya conocemos del puesto (y las funciones del manual de
            inducción, si aplica): revísalo, complétalo y guárdalo.
        </p>
        <p
            v-else-if="es_borrador"
            class="rounded-xl bg-[var(--mrl-fondo)] p-3 text-sm text-muted-foreground"
        >
            RH todavía no ha definido el perfil de este puesto.
        </p>

        <form class="grid gap-5 lg:grid-cols-2" @submit.prevent="guardar">
            <section
                v-for="grupo in GRUPOS"
                :key="grupo.clave"
                class="flex flex-col gap-4 rounded-2xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] p-5"
            >
                <header class="flex items-start gap-3">
                    <span
                        class="flex size-9 shrink-0 items-center justify-center rounded-xl bg-[var(--mrl-petroleo)] text-[var(--mrl-dorado)]"
                    >
                        <component :is="grupo.icono" class="size-4" />
                    </span>
                    <div>
                        <h2 class="font-semibold">{{ grupo.titulo }}</h2>
                        <p class="text-xs text-muted-foreground">
                            {{ grupo.descripcion }}
                        </p>
                    </div>
                </header>

                <div
                    v-for="seccion in grupo.secciones"
                    :key="seccion.clave"
                    class="flex flex-col gap-1.5"
                >
                    <template v-if="editando">
                        <Label :for="`campo-${seccion.clave}`">{{
                            seccion.titulo
                        }}</Label>
                        <Input
                            v-if="seccion.tipo === 'linea'"
                            :id="`campo-${seccion.clave}`"
                            v-model="form[seccion.clave]"
                            :placeholder="seccion.ayuda"
                        />
                        <Textarea
                            v-else
                            :id="`campo-${seccion.clave}`"
                            v-model="form[seccion.clave]"
                            :rows="seccion.tipo === 'lista' ? 4 : 2"
                            :placeholder="seccion.ayuda"
                        />
                        <p
                            v-if="seccion.tipo === 'lista'"
                            class="text-[11px] text-muted-foreground"
                        >
                            Uno por renglón.
                        </p>
                        <InputError :message="error(seccion.clave)" />
                    </template>

                    <template v-else>
                        <p
                            class="text-xs font-semibold tracking-wide text-[var(--mrl-dorado-oscuro)] uppercase"
                        >
                            {{ seccion.titulo }}
                        </p>
                        <ul
                            v-if="
                                seccion.tipo === 'lista' &&
                                valorLista(seccion).length
                            "
                            class="flex flex-col gap-1 text-sm"
                        >
                            <li
                                v-for="item in valorLista(seccion)"
                                :key="item"
                                class="flex gap-2"
                            >
                                <span class="text-[var(--mrl-dorado-oscuro)]"
                                    >•</span
                                >{{ item }}
                            </li>
                        </ul>
                        <p
                            v-else-if="
                                seccion.tipo !== 'lista' && valorTexto(seccion)
                            "
                            class="text-sm whitespace-pre-line"
                        >
                            {{ valorTexto(seccion) }}
                        </p>
                        <p v-else class="text-sm text-muted-foreground italic">
                            Sin definir
                        </p>
                    </template>
                </div>
            </section>

            <div v-if="editando" class="flex justify-end gap-2 lg:col-span-2">
                <Button type="button" variant="secondary" @click="cancelar">
                    Cancelar
                </Button>
                <Button type="submit" :disabled="form.processing">
                    <Spinner v-if="form.processing" />
                    Guardar perfil
                </Button>
            </div>
        </form>
    </div>
</template>
