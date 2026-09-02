<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Briefcase, GitBranch, Pencil, Users } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import JerarquiaPuestoDialog from '@/components/Administracion/JerarquiaPuestoDialog.vue';
import OrganigramaNodo from '@/components/Administracion/OrganigramaNodo.vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Sheet,
    SheetContent,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { dashboard } from '@/routes';
import { index } from '@/routes/administracion/jerarquia-puestos';
import { index as indexCandidatos } from '@/routes/rh/candidatos';
import { index as indexVacantes } from '@/routes/rh/vacantes';
import type { PuestoJerarquiaItem } from '@/types';

const props = defineProps<{
    puestos: PuestoJerarquiaItem[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Jerarquía de puestos', href: index.url() },
        ],
    },
});

const TIPO_ETIQUETA: Record<string, string> = {
    comercial: 'Comercial',
    administrativo: 'Administrativo',
    operativo: 'Operativo',
    otro: 'Otro',
};

const grupos = computed(() => {
    const tipos = new Map<string, PuestoJerarquiaItem[]>();

    for (const puesto of props.puestos) {
        const clave = puesto.tipo_puesto ?? 'sin_clasificar';

        if (!tipos.has(clave)) {
            tipos.set(clave, []);
        }

        tipos.get(clave)!.push(puesto);
    }

    return Array.from(tipos.entries()).map(([tipo, lista]) => ({
        tipo,
        etiqueta: TIPO_ETIQUETA[tipo] ?? 'Sin clasificar',
        raices: lista.filter(
            (p) =>
                !p.puesto_superior_id ||
                !lista.some((otro) => otro.id === p.puesto_superior_id),
        ),
        lista,
    }));
});

function obtenerHijos(lista: PuestoJerarquiaItem[]) {
    return (id: number) => lista.filter((p) => p.puesto_superior_id === id);
}

const puestoSeleccionado = ref<PuestoJerarquiaItem | null>(null);
const panelAbierto = ref(false);
const dialogoAbierto = ref(false);

function seleccionar(puesto: PuestoJerarquiaItem) {
    puestoSeleccionado.value = puesto;
    panelAbierto.value = true;
}

function abrirEdicion() {
    dialogoAbierto.value = true;
}

watch(
    () => props.puestos,
    (lista) => {
        if (!puestoSeleccionado.value) {
            return;
        }

        puestoSeleccionado.value =
            lista.find((p) => p.id === puestoSeleccionado.value?.id) ?? null;
    },
);
</script>

<template>
    <Head title="Jerarquía de puestos" />

    <div class="flex flex-col gap-6 p-4">
        <CrudPageHeader
            titulo="Jerarquía de puestos"
            descripcion="Organigrama, rutas de crecimiento y respaldos de cada puesto."
            :icono="GitBranch"
        />

        <div
            v-if="!puestos.length"
            class="rounded-2xl border border-dashed p-8 text-center text-sm text-muted-foreground"
        >
            Todavía no hay puestos configurados.
        </div>

        <div
            v-for="grupo in grupos"
            :key="grupo.tipo"
            class="flex flex-col gap-4"
        >
            <h2 class="flex items-center gap-2 text-lg font-semibold">
                <Briefcase class="size-4 text-muted-foreground" />
                {{ grupo.etiqueta }}
            </h2>

            <div
                class="flex flex-wrap items-start justify-center gap-10 overflow-x-auto rounded-2xl border border-border/60 bg-muted/20 p-6"
            >
                <OrganigramaNodo
                    v-for="raiz in grupo.raices"
                    :key="raiz.id"
                    :puesto="raiz"
                    :hijos="obtenerHijos(grupo.lista)(raiz.id)"
                    :obtener-hijos="obtenerHijos(grupo.lista)"
                    @seleccionar="seleccionar"
                />
            </div>
        </div>
    </div>

    <Sheet v-model:open="panelAbierto">
        <SheetContent v-if="puestoSeleccionado" class="overflow-y-auto">
            <SheetHeader>
                <SheetTitle>{{ puestoSeleccionado.nombre }}</SheetTitle>
            </SheetHeader>

            <div class="flex flex-col gap-4 px-4 pb-4">
                <p class="text-sm text-muted-foreground">
                    {{
                        puestoSeleccionado.descripcion ??
                        'Sin descripción registrada.'
                    }}
                </p>

                <div class="flex flex-wrap gap-2">
                    <Badge
                        v-if="puestoSeleccionado.tipo_puesto"
                        variant="outline"
                    >
                        {{ TIPO_ETIQUETA[puestoSeleccionado.tipo_puesto] }}
                    </Badge>
                    <Badge
                        v-if="puestoSeleccionado.nivel_jerarquico"
                        variant="outline"
                    >
                        Nivel {{ puestoSeleccionado.nivel_jerarquico }}
                    </Badge>
                    <Badge
                        v-if="puestoSeleccionado.requiere_ruta"
                        variant="outline"
                    >
                        Requiere ruta
                    </Badge>
                </div>

                <dl class="grid grid-cols-1 gap-3 text-sm">
                    <div>
                        <dt class="text-muted-foreground">Puesto superior</dt>
                        <dd>
                            {{
                                puestoSeleccionado.puesto_superior?.nombre ??
                                '—'
                            }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground">
                            Ruta de crecimiento
                        </dt>
                        <dd>
                            {{
                                puestoSeleccionado.puesto_crecimiento?.nombre ??
                                '—'
                            }}
                        </dd>
                    </div>
                    <div v-if="puestoSeleccionado.esquema_comisiones">
                        <dt class="text-muted-foreground">
                            Esquema de comisiones
                        </dt>
                        <dd>{{ puestoSeleccionado.esquema_comisiones }}</dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground">Respaldos</dt>
                        <dd v-if="puestoSeleccionado.respaldos.length">
                            {{
                                puestoSeleccionado.respaldos
                                    .map((r) => r.nombre)
                                    .join(', ')
                            }}
                        </dd>
                        <dd v-else>—</dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground">
                            Puestos que puede cubrir
                        </dt>
                        <dd
                            v-if="
                                puestoSeleccionado.puestos_que_puede_cubrir
                                    .length
                            "
                        >
                            {{
                                puestoSeleccionado.puestos_que_puede_cubrir
                                    .map((r) => r.nombre)
                                    .join(', ')
                            }}
                        </dd>
                        <dd v-else>—</dd>
                    </div>
                    <div v-if="puestoSeleccionado.responsabilidades">
                        <dt class="text-muted-foreground">Responsabilidades</dt>
                        <dd class="whitespace-pre-line">
                            {{ puestoSeleccionado.responsabilidades }}
                        </dd>
                    </div>
                    <div v-if="puestoSeleccionado.requisitos">
                        <dt class="text-muted-foreground">Requisitos</dt>
                        <dd class="whitespace-pre-line">
                            {{ puestoSeleccionado.requisitos }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground">
                            Colaboradores actuales
                        </dt>
                        <dd class="flex items-center gap-1.5">
                            <Users class="size-3.5" />
                            {{ puestoSeleccionado.usuarios_count }}
                        </dd>
                    </div>
                </dl>

                <Button class="w-fit" size="sm" @click="abrirEdicion">
                    <Pencil class="size-4" />
                    Editar jerarquía
                </Button>

                <div class="grid grid-cols-2 gap-2">
                    <Link
                        :href="
                            indexVacantes.url({
                                query: { puesto_id: puestoSeleccionado.id },
                            })
                        "
                        class="rounded-xl border border-border/60 bg-card p-3 text-sm hover:border-primary/40"
                    >
                        <span class="block text-lg font-semibold">{{
                            puestoSeleccionado.vacantes_abiertas_count
                        }}</span>
                        <span class="text-xs text-muted-foreground"
                            >Vacantes abiertas</span
                        >
                    </Link>
                    <Link
                        :href="
                            indexCandidatos.url({
                                query: {
                                    puesto_objetivo_id: puestoSeleccionado.id,
                                },
                            })
                        "
                        class="rounded-xl border border-border/60 bg-card p-3 text-sm hover:border-primary/40"
                    >
                        <span class="block text-lg font-semibold">{{
                            puestoSeleccionado.candidatos_count
                        }}</span>
                        <span class="text-xs text-muted-foreground"
                            >Candidatos</span
                        >
                    </Link>
                </div>
            </div>
        </SheetContent>
    </Sheet>

    <JerarquiaPuestoDialog
        v-if="dialogoAbierto && puestoSeleccionado"
        v-model:open="dialogoAbierto"
        :puesto="puestoSeleccionado"
        :todos-los-puestos="puestos"
        :key="puestoSeleccionado.id"
    />
</template>
