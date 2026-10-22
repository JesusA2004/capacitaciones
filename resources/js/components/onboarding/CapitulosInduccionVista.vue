<script setup lang="ts">
import { Network, Sparkles } from '@lucide/vue';
import type { CapituloInduccion, ItemCapituloInduccion } from '@/types';

/**
 * Dibuja los capítulos estructurados de la Inducción Mr. Lana (el JSON de
 * `onboarding_modulos.contenido`: parrafo, datos, destacado, tarjetas, lista,
 * organigrama_dinamico, cierre) como texto legible — nunca como JSON crudo.
 * Lo usa la vista previa de «Configuración de onboarding». El colaborador
 * hoy ve la inducción en el PDF oficial; este es su resumen por capítulos.
 */
defineProps<{ capitulos: CapituloInduccion[] }>();

function textoDe(item: ItemCapituloInduccion): string {
    return typeof item === 'string'
        ? item
        : [item.titulo, item.texto].filter(Boolean).join(': ');
}
</script>

<template>
    <div class="flex flex-col gap-6">
        <p v-if="capitulos.length === 0" class="text-sm text-muted-foreground">
            Este módulo no tiene capítulos cargados.
        </p>
        <section
            v-for="(c, i) in capitulos"
            :key="c.clave ?? i"
            class="flex flex-col gap-3 rounded-2xl border border-border/60 bg-card p-4"
        >
            <header class="flex items-start gap-3">
                <span
                    class="flex size-8 shrink-0 items-center justify-center rounded-full bg-salvia text-sm font-bold text-primary"
                >
                    {{ c.numero ?? i + 1 }}
                </span>
                <div>
                    <h3 class="font-semibold">{{ c.titulo }}</h3>
                    <p v-if="c.subtitulo" class="text-xs text-muted-foreground">
                        {{ c.subtitulo }}
                    </p>
                </div>
            </header>

            <template v-for="(b, j) in c.bloques ?? []" :key="j">
                <div v-if="b.tipo === 'parrafo'" class="text-sm">
                    <p v-if="b.titulo" class="font-medium">{{ b.titulo }}</p>
                    <p class="text-pretty text-muted-foreground">
                        {{ b.texto }}
                    </p>
                </div>

                <dl
                    v-else-if="b.tipo === 'datos'"
                    class="grid gap-2 sm:grid-cols-3"
                >
                    <div
                        v-for="(d, k) in b.items ?? []"
                        :key="k"
                        class="rounded-xl bg-muted/40 px-3 py-2"
                    >
                        <dt class="text-xs text-muted-foreground">
                            {{ typeof d === 'string' ? '' : d.etiqueta }}
                        </dt>
                        <dd class="text-sm font-medium">
                            {{ typeof d === 'string' ? d : d.valor }}
                        </dd>
                    </div>
                </dl>

                <p
                    v-else-if="b.tipo === 'destacado' || b.tipo === 'cierre'"
                    class="flex items-start gap-2 rounded-xl bg-crema/70 px-3 py-2 text-sm"
                >
                    <Sparkles class="mt-0.5 size-4 shrink-0 text-bronce" />
                    <span
                        ><strong v-if="b.titulo">{{ b.titulo }} </strong
                        >{{ b.texto }}</span
                    >
                </p>

                <div
                    v-else-if="b.tipo === 'tarjetas'"
                    class="flex flex-col gap-2"
                >
                    <p v-if="b.titulo" class="text-sm font-medium">
                        {{ b.titulo }}
                    </p>
                    <div class="grid gap-2 sm:grid-cols-2">
                        <div
                            v-for="(t, k) in b.items ?? []"
                            :key="k"
                            class="rounded-xl border border-border/60 p-3 text-sm"
                        >
                            <template v-if="typeof t === 'string'">{{
                                t
                            }}</template>
                            <template v-else>
                                <p class="font-medium">{{ t.titulo }}</p>
                                <p class="text-muted-foreground">
                                    {{ t.texto }}
                                </p>
                                <p
                                    v-if="t.nota"
                                    class="mt-1 text-xs text-bronce"
                                >
                                    {{ t.nota }}
                                </p>
                            </template>
                        </div>
                    </div>
                </div>

                <div v-else-if="b.tipo === 'lista'" class="text-sm">
                    <p v-if="b.titulo" class="font-medium">{{ b.titulo }}</p>
                    <ul class="mt-1 list-disc pl-5 text-muted-foreground">
                        <li v-for="(t, k) in b.items ?? []" :key="k">
                            {{ textoDe(t) }}
                        </li>
                    </ul>
                </div>

                <p
                    v-else-if="b.tipo === 'organigrama_dinamico'"
                    class="flex items-start gap-2 rounded-xl border border-dashed border-border/60 px-3 py-2 text-sm"
                >
                    <Network class="mt-0.5 size-4 shrink-0 text-primary" />
                    <span>
                        <strong>{{ b.titulo ?? 'Organigrama' }}</strong> — se
                        arma en vivo con los puestos y personas reales del
                        sistema.
                        <span
                            v-if="b.nota"
                            class="block text-xs text-muted-foreground"
                            >{{ b.nota }}</span
                        >
                    </span>
                </p>

                <p v-else-if="b.texto" class="text-sm text-muted-foreground">
                    {{ b.texto }}
                </p>
            </template>
        </section>
    </div>
</template>
