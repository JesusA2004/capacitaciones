<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import {
    Award,
    CalendarCheck,
    GraduationCap,
    Hash,
    SearchX,
    ShieldCheck,
} from '@lucide/vue';
import AppLogoIcon from '@/components/AppLogoIcon.vue';

/**
 * Verificación pública de una constancia (sin sesión: el QR/folio del PDF
 * apunta aquí). Se pinta SIN AppLayout (ver app.ts): un visitante anónimo
 * no tiene menú ni usuario.
 */
defineProps<{
    folio: string;
    valido: boolean;
    certificado: { nombre: string; curso: string; emitido_en: string } | null;
}>();
</script>

<template>
    <Head title="Verificar constancia" />

    <main
        class="relative flex min-h-svh flex-col items-center justify-center overflow-hidden bg-background px-4 py-10"
    >
        <!-- Fondo decorativo suave, con la paleta del sitio. -->
        <span
            class="pointer-events-none absolute -top-32 -left-24 size-96 rounded-full bg-salvia/70 blur-3xl"
            aria-hidden="true"
        />
        <span
            class="pointer-events-none absolute -right-24 -bottom-32 size-96 rounded-full bg-crema/80 blur-3xl"
            aria-hidden="true"
        />

        <div class="relative flex w-full max-w-lg flex-col gap-6">
            <header class="flex entrada-suave items-center gap-3">
                <AppLogoIcon class-name="size-10" />
                <div>
                    <p
                        class="text-xs font-semibold tracking-[0.2em] text-bronce uppercase"
                    >
                        MR. LANA PEOPLE
                    </p>
                    <p class="text-sm text-muted-foreground">
                        Verificación de constancias de capacitación
                    </p>
                </div>
            </header>

            <section
                v-if="valido && certificado"
                class="entrada-suave overflow-hidden rounded-3xl border border-border/60 bg-card shadow-lg"
            >
                <div
                    class="relative flex flex-col items-center gap-3 bg-linear-to-br from-success-soft via-menta to-salvia px-6 pt-8 pb-6 text-center"
                >
                    <span
                        class="flex size-20 aparece-zoom items-center justify-center rounded-full bg-card text-primary shadow-md ring-8 ring-card/50"
                    >
                        <ShieldCheck class="size-10" aria-hidden="true" />
                    </span>
                    <div>
                        <p
                            class="text-xs font-semibold tracking-wide text-primary uppercase"
                        >
                            Constancia válida
                        </p>
                        <h1
                            class="mt-1 text-2xl font-bold text-balance text-foreground"
                        >
                            {{ certificado.nombre }}
                        </h1>
                    </div>
                </div>

                <dl class="grid grid-cols-1 gap-px bg-border/60 sm:grid-cols-2">
                    <div class="flex gap-3 bg-card p-5 sm:col-span-2">
                        <span
                            class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-salvia text-primary"
                        >
                            <GraduationCap class="size-5" aria-hidden="true" />
                        </span>
                        <div class="min-w-0">
                            <dt class="text-xs text-muted-foreground">
                                Curso acreditado
                            </dt>
                            <dd class="font-semibold text-pretty">
                                {{ certificado.curso }}
                            </dd>
                        </div>
                    </div>
                    <div class="flex gap-3 bg-card p-5">
                        <span
                            class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-oro/20 text-bronce"
                        >
                            <CalendarCheck class="size-5" aria-hidden="true" />
                        </span>
                        <div>
                            <dt class="text-xs text-muted-foreground">
                                Fecha de emisión
                            </dt>
                            <dd class="font-semibold tabular-nums">
                                {{ certificado.emitido_en }}
                            </dd>
                        </div>
                    </div>
                    <div class="flex gap-3 bg-card p-5">
                        <span
                            class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-crema text-bronce"
                        >
                            <Hash class="size-5" aria-hidden="true" />
                        </span>
                        <div class="min-w-0">
                            <dt class="text-xs text-muted-foreground">Folio</dt>
                            <dd class="font-mono font-semibold break-all">
                                {{ folio }}
                            </dd>
                        </div>
                    </div>
                </dl>

                <p
                    class="flex items-start gap-2 border-t border-border/60 bg-crema/40 px-5 py-4 text-xs text-pretty text-muted-foreground"
                >
                    <Award
                        class="mt-0.5 size-4 shrink-0 text-bronce"
                        aria-hidden="true"
                    />
                    Esta constancia fue emitida por MR. LANA PEOPLE al concluir
                    el curso. Si los datos no coinciden con el documento que
                    tienes en mano, el documento no es auténtico.
                </p>
            </section>

            <section
                v-else
                class="flex entrada-suave flex-col items-center gap-3 rounded-3xl border border-border/60 bg-card px-6 py-10 text-center shadow-lg"
            >
                <span
                    class="flex size-20 aparece-zoom items-center justify-center rounded-full bg-danger-soft text-destructive"
                >
                    <SearchX class="size-10" aria-hidden="true" />
                </span>
                <h1 class="text-xl font-bold">
                    No encontramos esta constancia
                </h1>
                <p class="max-w-sm text-sm text-pretty text-muted-foreground">
                    No existe ninguna constancia con el folio
                    <span class="font-mono font-semibold text-foreground">{{
                        folio
                    }}</span
                    >. Revisa que esté escrito igual que en el documento.
                </p>
            </section>
        </div>
    </main>
</template>
