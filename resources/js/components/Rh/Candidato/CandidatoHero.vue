<script setup lang="ts">
import {
    Brain,
    BriefcaseBusiness,
    Building2,
    CalendarClock,
    FileText,
    Home,
    MapPin,
    Megaphone,
    Pencil,
    Sparkles,
    UserCog,
    Video,
} from '@lucide/vue';
import { computed } from 'vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { formatearFecha } from '@/lib/fechas';
import type { CandidatoFicha, EstadoCiclo, EvidenciaCandidato } from '@/types';

/**
 * Encabezado de la ficha: foto (o iniciales), nombre, a qué puesto y
 * sucursal aspira, en qué etapa va, quién lo tiene en sus manos y los
 * documentos clave a un clic.
 */
const props = defineProps<{
    candidato: CandidatoFicha;
    ciclo: EstadoCiclo;
}>();

const emit = defineEmits<{
    editar: [];
    verCv: [];
    ver: [evidencia: EvidenciaCandidato];
}>();

const iniciales = computed(() => {
    const partes = props.candidato.nombre_completo.split(/\s+/).filter(Boolean);

    return (
        (partes[0]?.[0] ?? '') + (partes[1]?.[0] ?? partes[0]?.[1] ?? '')
    ).toUpperCase();
});

const accesos = computed(() => props.candidato.accesos_rapidos);

const responsable = computed(
    () =>
        props.ciclo.responsable_actual.nombre ??
        props.ciclo.responsable_actual.rol,
);
</script>

<template>
    <section
        class="relative entrada-suave overflow-hidden rounded-3xl border border-[var(--mrl-borde)] bg-gradient-to-br from-crema/70 via-[var(--mrl-superficie)] to-salvia/60 p-4 shadow-sm sm:p-6"
        aria-label="Resumen del candidato"
    >
        <!-- Adorno suave de fondo (no informa nada). -->
        <span
            class="pointer-events-none absolute -top-16 -right-16 size-48 rounded-full bg-salvia/50 blur-2xl"
            aria-hidden="true"
        />

        <div class="relative flex flex-col gap-5 lg:flex-row lg:items-start">
            <div class="flex min-w-0 flex-1 flex-col gap-4 sm:flex-row">
                <!-- Foto oficial del expediente si ya hay colaborador
                     vinculado; si no (o si falla), iniciales. -->
                <Avatar
                    class="size-20 shrink-0 rounded-3xl shadow-md ring-4 ring-[var(--mrl-superficie)] transicion-ui hover:scale-105 sm:size-24"
                >
                    <AvatarImage
                        v-if="candidato.foto_url"
                        :src="candidato.foto_url"
                        :alt="`Fotografía de ${candidato.nombre_completo}`"
                        class="object-cover"
                    />
                    <AvatarFallback
                        class="rounded-3xl bg-[var(--mrl-petroleo)] text-2xl font-semibold text-[var(--mrl-dorado)]"
                        >{{ iniciales }}</AvatarFallback
                    >
                </Avatar>

                <div class="flex min-w-0 flex-1 flex-col gap-3">
                    <div class="min-w-0">
                        <p
                            class="text-[11px] font-medium tracking-[0.14em] text-muted-foreground uppercase"
                        >
                            Candidato
                        </p>
                        <h1
                            class="text-2xl leading-tight font-semibold text-balance break-words sm:text-3xl"
                        >
                            {{ candidato.nombre_completo }}
                        </h1>
                    </div>

                    <ul class="flex flex-wrap gap-2 text-sm">
                        <li
                            class="inline-flex max-w-full items-center gap-1.5 rounded-xl bg-[var(--mrl-superficie)]/80 px-3 py-1.5 shadow-xs"
                        >
                            <BriefcaseBusiness
                                class="size-4 shrink-0 text-bronce"
                                aria-hidden="true"
                            />
                            <span class="sr-only">Puesto:</span>
                            <span class="font-medium break-words">{{
                                candidato.puesto ?? 'Sin puesto objetivo'
                            }}</span>
                        </li>
                        <li
                            class="inline-flex max-w-full items-center gap-1.5 rounded-xl bg-[var(--mrl-superficie)]/80 px-3 py-1.5 shadow-xs"
                        >
                            <MapPin
                                class="size-4 shrink-0 text-bronce"
                                aria-hidden="true"
                            />
                            <span class="sr-only">Sucursal:</span>
                            <span class="font-medium break-words">{{
                                candidato.sucursal ?? 'Sin sucursal'
                            }}</span>
                        </li>
                        <li
                            v-if="candidato.empresa"
                            class="inline-flex max-w-full items-center gap-1.5 rounded-xl bg-[var(--mrl-superficie)]/80 px-3 py-1.5 shadow-xs"
                        >
                            <Building2
                                class="size-4 shrink-0 text-bronce"
                                aria-hidden="true"
                            />
                            <span class="sr-only">Empresa:</span>
                            <span class="break-words">{{
                                candidato.empresa
                            }}</span>
                        </li>
                        <li
                            v-if="candidato.fuente_etiqueta"
                            class="inline-flex max-w-full items-center gap-1.5 rounded-xl bg-[var(--mrl-superficie)]/80 px-3 py-1.5 shadow-xs"
                        >
                            <Megaphone
                                class="size-4 shrink-0 text-bronce"
                                aria-hidden="true"
                            />
                            <span class="sr-only">Fuente:</span>
                            <span class="break-words">{{
                                candidato.fuente_etiqueta
                            }}</span>
                        </li>
                    </ul>

                    <dl class="grid gap-2 sm:grid-cols-3">
                        <div
                            class="flex min-w-0 items-center gap-2.5 rounded-xl bg-salvia/70 px-3 py-2"
                        >
                            <Sparkles
                                class="size-4 shrink-0 text-primary"
                                aria-hidden="true"
                            />
                            <div class="min-w-0">
                                <dt class="text-[11px] text-muted-foreground">
                                    Etapa actual
                                </dt>
                                <dd
                                    class="text-sm font-semibold break-words text-[var(--mrl-petroleo)]"
                                >
                                    {{ candidato.estado_etiqueta }}
                                </dd>
                            </div>
                        </div>
                        <div
                            class="flex min-w-0 items-center gap-2.5 rounded-xl bg-crema/80 px-3 py-2"
                        >
                            <UserCog
                                class="size-4 shrink-0 text-bronce"
                                aria-hidden="true"
                            />
                            <div class="min-w-0">
                                <dt class="text-[11px] text-muted-foreground">
                                    Lo tiene ahora
                                </dt>
                                <dd class="text-sm font-semibold break-words">
                                    {{ responsable }}
                                    <span
                                        v-if="ciclo.responsable_actual.nombre"
                                        class="block text-xs font-normal text-muted-foreground"
                                        >{{
                                            ciclo.responsable_actual.rol
                                        }}</span
                                    >
                                </dd>
                            </div>
                        </div>
                        <div
                            class="flex min-w-0 items-center gap-2.5 rounded-xl bg-[var(--mrl-superficie)]/80 px-3 py-2"
                        >
                            <CalendarClock
                                class="size-4 shrink-0 text-bronce"
                                aria-hidden="true"
                            />
                            <div class="min-w-0">
                                <dt class="text-[11px] text-muted-foreground">
                                    Registrado
                                </dt>
                                <dd class="text-sm font-semibold">
                                    {{ formatearFecha(candidato.creado_en) }}
                                </dd>
                            </div>
                        </div>
                    </dl>
                </div>
            </div>

            <div
                class="flex flex-col gap-2 sm:flex-row sm:flex-wrap lg:w-64 lg:flex-col lg:flex-nowrap"
                aria-label="Acciones y documentos clave"
            >
                <Button
                    variant="secondary"
                    class="transicion-ui hover:-translate-y-0.5"
                    @click="emit('editar')"
                >
                    <Pencil class="size-4" />
                    Editar datos
                </Button>
                <Button
                    v-if="candidato.tiene_cv"
                    variant="outline"
                    class="bg-[var(--mrl-superficie)] transicion-ui hover:-translate-y-0.5"
                    @click="emit('verCv')"
                >
                    <FileText class="size-4" /> Ver CV
                </Button>
                <Button
                    v-if="accesos?.psicometrico"
                    variant="outline"
                    class="bg-[var(--mrl-superficie)] transicion-ui hover:-translate-y-0.5"
                    @click="emit('ver', accesos.psicometrico)"
                >
                    <Brain class="size-4" /> Ver psicométrico
                </Button>
                <Button
                    v-if="accesos?.socioeconomico"
                    variant="outline"
                    class="bg-[var(--mrl-superficie)] transicion-ui hover:-translate-y-0.5"
                    @click="emit('ver', accesos.socioeconomico)"
                >
                    <Home class="size-4" /> Ver socioeconómico
                </Button>
                <Button
                    v-if="accesos?.video"
                    variant="outline"
                    class="bg-[var(--mrl-superficie)] transicion-ui hover:-translate-y-0.5"
                    @click="emit('ver', accesos.video)"
                >
                    <Video class="size-4" /> Ver video
                </Button>
            </div>
        </div>
    </section>
</template>
