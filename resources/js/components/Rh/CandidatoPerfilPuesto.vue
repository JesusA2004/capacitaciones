<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    BriefcaseBusiness,
    ChevronDown,
    GraduationCap,
    UserSearch,
} from '@lucide/vue';
import { ref } from 'vue';
import ListaPerfilLectura from '@/components/puestos/ListaPerfilLectura.vue';
import { PERFIL_CANDIDATO } from '@/lib/perfilPuesto';
import { show } from '@/routes/rh/perfiles-puesto';
import type { PerfilPuestoCandidato } from '@/types';

/**
 * Lo que buscamos para el puesto (perfil del candidato que define RH), a la
 * mano de quien entrevista y evalúa.
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
            class="flex w-full items-center gap-3 text-left"
            :aria-expanded="abierto"
            @click="abierto = !abierto"
        >
            <span
                class="flex size-8 items-center justify-center rounded-xl bg-[var(--mrl-petroleo)] text-[var(--mrl-dorado)]"
            >
                <UserSearch class="size-4" />
            </span>
            <span class="min-w-0">
                <span
                    class="block text-[11px] tracking-[0.14em] text-muted-foreground uppercase"
                    >Perfil buscado</span
                >
                <span class="block font-semibold">{{ perfil.puesto }}</span>
            </span>
            <ChevronDown
                class="ml-auto size-4 text-muted-foreground transition-transform"
                :class="abierto ? 'rotate-180' : ''"
            />
        </button>

        <div v-if="abierto" class="mt-4 flex flex-col gap-4">
            <p v-if="!perfil.perfil" class="text-sm text-muted-foreground">
                Este puesto aún no tiene perfil.
            </p>
            <template v-else>
                <div
                    v-if="
                        perfil.perfil.escolaridad || perfil.perfil.experiencia
                    "
                    class="flex flex-wrap gap-2 text-sm"
                >
                    <span
                        v-if="perfil.perfil.escolaridad"
                        class="inline-flex items-center gap-1.5 rounded-xl bg-[var(--mrl-crema)] px-3 py-1.5"
                    >
                        <GraduationCap
                            class="size-4 text-[var(--mrl-dorado-oscuro)]"
                        />
                        {{ perfil.perfil.escolaridad }}
                    </span>
                    <span
                        v-if="perfil.perfil.experiencia"
                        class="inline-flex items-center gap-1.5 rounded-xl bg-[var(--mrl-crema)] px-3 py-1.5"
                    >
                        <BriefcaseBusiness
                            class="size-4 text-[var(--mrl-dorado-oscuro)]"
                        />
                        {{ perfil.perfil.experiencia }}
                    </span>
                </div>
                <ListaPerfilLectura
                    v-for="s in PERFIL_CANDIDATO"
                    :key="s.clave"
                    :titulo="s.titulo"
                    :estilo="s.estilo"
                    :items="perfil.perfil[s.clave]"
                />
            </template>
            <Link
                :href="show.url(perfil.puesto_id)"
                class="self-start text-xs font-medium text-[var(--mrl-petroleo)] hover:underline"
            >
                Ver descriptivo completo
            </Link>
        </div>
    </section>
</template>
