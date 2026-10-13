<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ChevronDown, UserSearch } from '@lucide/vue';
import { ref } from 'vue';
import { SECCIONES_CANDIDATO } from '@/lib/perfilPuesto';
import { show } from '@/routes/rh/perfiles-puesto';
import type { PerfilPuestoCandidato } from '@/types';

/**
 * Lo que debe tener quien se postula al puesto (perfil de puesto de RH),
 * a la mano de quien entrevista y evalúa al candidato.
 */
defineProps<{ perfil: NonNullable<PerfilPuestoCandidato> }>();

const abierto = ref(true);
</script>

<template>
    <section
        class="rounded-2xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] p-5"
        aria-label="Perfil del puesto"
    >
        <button
            type="button"
            class="flex w-full items-center gap-2 text-left"
            :aria-expanded="abierto"
            @click="abierto = !abierto"
        >
            <UserSearch class="size-4 text-[var(--mrl-petroleo)]" />
            <h2 class="text-sm font-semibold">
                Qué buscamos para {{ perfil.puesto }}
            </h2>
            <ChevronDown
                class="ml-auto size-4 text-muted-foreground transition-transform"
                :class="abierto ? 'rotate-180' : ''"
            />
        </button>

        <template v-if="abierto">
            <p v-if="!perfil.perfil" class="mt-3 text-sm text-muted-foreground">
                Este puesto todavía no tiene perfil definido por RH.
            </p>
            <dl v-else class="mt-3 grid gap-3 text-sm sm:grid-cols-2">
                <div
                    v-for="seccion in SECCIONES_CANDIDATO"
                    :key="seccion.clave"
                >
                    <dt
                        class="text-xs font-semibold tracking-wide text-[var(--mrl-dorado-oscuro)] uppercase"
                    >
                        {{ seccion.titulo }}
                    </dt>
                    <dd v-if="seccion.tipo === 'lista'">
                        <ul
                            v-if="perfil.perfil[seccion.clave].length"
                            class="mt-0.5 flex flex-col gap-0.5"
                        >
                            <li
                                v-for="item in perfil.perfil[seccion.clave]"
                                :key="item"
                                class="flex gap-1.5"
                            >
                                <span class="text-[var(--mrl-dorado-oscuro)]"
                                    >•</span
                                >{{ item }}
                            </li>
                        </ul>
                        <span v-else class="text-muted-foreground italic"
                            >—</span
                        >
                    </dd>
                    <dd v-else class="mt-0.5 whitespace-pre-line">
                        {{ perfil.perfil[seccion.clave] || '—' }}
                    </dd>
                </div>
            </dl>
            <Link
                :href="show.url(perfil.puesto_id)"
                class="mt-3 inline-block text-xs text-[var(--mrl-petroleo)] hover:underline"
            >
                Ver perfil completo del puesto
            </Link>
        </template>
    </section>
</template>
