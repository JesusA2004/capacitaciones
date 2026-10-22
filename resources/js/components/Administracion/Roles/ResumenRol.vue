<script setup lang="ts">
import { CircleCheck, CircleSlash } from '@lucide/vue';
import { computed } from 'vue';
import { etiquetaModulo, etiquetaPermiso } from '@/lib/permisos';
import { moduloActivo, nivelDe } from '@/lib/rolesPermisos';
import type { CatalogoPermisos, NivelAlcance } from '@/types/roles';

/**
 * «Qué puede hacer este rol», en español llano y solo lectura: lo que la
 * matriz dice, módulo por módulo, sin nombres técnicos.
 */
const props = defineProps<{
    catalogo: CatalogoPermisos;
    permisos: Set<string>;
    nombreRol: string;
}>();

const FRASE_ALCANCE: Record<NivelAlcance | 'propios', string> = {
    propios: 'solo lo propio',
    equipo: 'lo de su equipo directo',
    sucursal: 'lo de su sucursal',
    region: 'lo de su región',
    todos: 'lo de toda la empresa',
};

const lista = new Intl.ListFormat('es-MX', {
    style: 'long',
    type: 'conjunction',
});

function minuscula(texto: string): string {
    return texto.charAt(0).toLowerCase() + texto.slice(1);
}

const etapas = computed(() =>
    props.catalogo.etapas
        .map((etapa) => ({
            ...etapa,
            frases: etapa.modulos
                .filter((m) => moduloActivo(m, props.permisos))
                .map((m) => {
                    const acciones = m.acciones
                        .filter((a) => props.permisos.has(a.permiso))
                        .map((a) => minuscula(a.etiqueta));
                    const conAlcance = Object.keys(m.alcances).length > 0;
                    const partes: string[] = [];

                    if (m.acceso) {
                        partes.push(
                            conAlcance
                                ? `entra y ve ${FRASE_ALCANCE[nivelDe(m, props.permisos)]}`
                                : 'entra al módulo',
                        );
                    }

                    partes.push(
                        acciones.length > 0
                            ? `puede: ${lista.format(acciones)}`
                            : 'solo consulta',
                    );

                    return { modulo: m.etiqueta, texto: partes.join('; ') };
                }),
        }))
        .filter((etapa) => etapa.frases.length > 0),
);

const sinAcceso = computed(() =>
    props.catalogo.etapas
        .flatMap((e) => e.modulos)
        .filter((m) => !moduloActivo(m, props.permisos))
        .map((m) => m.etiqueta),
);

const otros = computed(() =>
    Object.entries(props.catalogo.otros)
        .map(([grupo, permisos]) => ({
            grupo: etiquetaModulo(grupo),
            permisos: permisos
                .filter((p) => props.permisos.has(p))
                .map((p) => etiquetaPermiso(p)),
        }))
        .filter((g) => g.permisos.length > 0),
);
</script>

<template>
    <div class="flex flex-col gap-5">
        <p v-if="etapas.length === 0" class="text-sm text-muted-foreground">
            «{{ nombreRol }}» todavía no puede entrar a ningún módulo.
        </p>

        <section
            v-for="etapa in etapas"
            :key="etapa.clave"
            class="flex flex-col gap-2"
        >
            <h4 class="text-sm font-semibold">
                <span v-if="etapa.numero" class="text-muted-foreground"
                    >{{ etapa.numero }} ·
                </span>
                {{ etapa.etiqueta }}
            </h4>
            <ul class="flex flex-col gap-1.5">
                <li
                    v-for="frase in etapa.frases"
                    :key="frase.modulo"
                    class="flex gap-2 text-sm"
                >
                    <CircleCheck class="mt-0.5 size-4 shrink-0 text-success" />
                    <span
                        ><strong class="font-medium">{{ frase.modulo }}</strong
                        >: {{ frase.texto }}.</span
                    >
                </li>
            </ul>
        </section>

        <section v-if="otros.length > 0" class="flex flex-col gap-2">
            <h4 class="text-sm font-semibold">Otros permisos</h4>
            <ul class="flex flex-col gap-1.5">
                <li
                    v-for="grupo in otros"
                    :key="grupo.grupo"
                    class="flex gap-2 text-sm"
                >
                    <CircleCheck class="mt-0.5 size-4 shrink-0 text-success" />
                    <span
                        ><strong class="font-medium">{{ grupo.grupo }}</strong
                        >:
                        {{ lista.format(grupo.permisos.map(minuscula)) }}.</span
                    >
                </li>
            </ul>
        </section>

        <section
            v-if="sinAcceso.length > 0"
            class="flex flex-col gap-2 rounded-2xl bg-muted/40 p-4"
        >
            <h4 class="flex items-center gap-2 text-sm font-semibold">
                <CircleSlash class="size-4 text-muted-foreground" />
                No entra a {{ sinAcceso.length }} módulo(s)
            </h4>
            <p class="text-sm text-muted-foreground">
                {{ lista.format(sinAcceso) }}.
            </p>
        </section>
    </div>
</template>
