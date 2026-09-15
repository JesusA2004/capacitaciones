<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import { Check, Palette, Sparkles, UserCircle2 } from '@lucide/vue';
import { computed, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { useInitials } from '@/composables/useInitials';
import {
    AVATAR_COLORES,
    TEMAS_COLOR,
    usePersonalizacion,
} from '@/composables/usePersonalizacion';
import { edit } from '@/routes/personalizacion';
import type { Auth } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Personalización', href: edit() }],
    },
});

const { preferencias, guardar } = usePersonalizacion();
const page = usePage<{ auth: Auth }>();
const { getInitials } = useInitials();

const nombreUsuario = computed(() => page.props.auth.user?.name ?? '');

const animacionesLocal = ref(preferencias.value.animaciones);

function elegirTema(id: (typeof TEMAS_COLOR)[number]['id']) {
    guardar({ tema_color: id });
}

function elegirAvatarColor(hex: string) {
    guardar({ avatar_color: hex });
}

function alternarAnimaciones(valor: boolean) {
    animacionesLocal.value = valor;
    guardar({ animaciones: valor });
}
</script>

<template>
    <Head title="Personalización" />

    <div class="space-y-10">
        <Heading
            variant="small"
            title="Personalización"
            description="Haz tuya la interfaz: color de acento, color de tu avatar y animaciones."
        />

        <section class="space-y-3">
            <div class="flex items-center gap-2">
                <Palette class="size-4 text-muted-foreground" />
                <h3 class="text-sm font-semibold">Color de acento</h3>
            </div>
            <p class="text-sm text-muted-foreground">
                Cambia el color principal de botones, enlaces y gráficas en
                toda la aplicación.
            </p>
            <div class="flex flex-wrap gap-3">
                <button
                    v-for="tema in TEMAS_COLOR"
                    :key="tema.id"
                    type="button"
                    class="group flex flex-col items-center gap-1.5"
                    @click="elegirTema(tema.id)"
                >
                    <span
                        class="flex size-11 items-center justify-center rounded-full ring-2 ring-offset-2 ring-offset-background transition-shadow group-hover:shadow-md"
                        :class="
                            preferencias.tema_color === tema.id
                                ? 'ring-foreground'
                                : 'ring-transparent'
                        "
                        :style="{
                            background: `linear-gradient(135deg, ${tema.primario}, ${tema.secundario})`,
                        }"
                    >
                        <Check
                            v-if="preferencias.tema_color === tema.id"
                            class="size-5 text-white drop-shadow"
                        />
                    </span>
                    <span class="text-xs text-muted-foreground">{{
                        tema.nombre
                    }}</span>
                </button>
            </div>
        </section>

        <section class="space-y-3">
            <div class="flex items-center gap-2">
                <UserCircle2 class="size-4 text-muted-foreground" />
                <h3 class="text-sm font-semibold">Color de tu avatar</h3>
            </div>
            <p class="text-sm text-muted-foreground">
                Se usa como fondo de tus iniciales donde no tengas foto de
                perfil.
            </p>
            <div class="flex items-center gap-4">
                <span
                    class="flex size-14 items-center justify-center rounded-full text-lg font-semibold text-white shadow-sm"
                    :style="{ backgroundColor: preferencias.avatar_color }"
                >
                    {{ getInitials(nombreUsuario) }}
                </span>
                <div class="flex flex-wrap gap-2">
                    <button
                        v-for="color in AVATAR_COLORES"
                        :key="color.id"
                        type="button"
                        class="size-8 rounded-full ring-2 ring-offset-2 ring-offset-background transition-shadow hover:shadow-md"
                        :class="
                            preferencias.avatar_color === color.hex
                                ? 'ring-foreground'
                                : 'ring-transparent'
                        "
                        :style="{ backgroundColor: color.hex }"
                        @click="elegirAvatarColor(color.hex)"
                    />
                </div>
            </div>
        </section>

        <section class="space-y-3">
            <div class="flex items-center gap-2">
                <Sparkles class="size-4 text-muted-foreground" />
                <h3 class="text-sm font-semibold">Animaciones</h3>
            </div>
            <label
                class="flex max-w-md cursor-pointer items-start gap-3 rounded-xl border border-border/60 p-3"
            >
                <Checkbox
                    :model-value="animacionesLocal"
                    @update:model-value="
                        (v) => alternarAnimaciones(v === true)
                    "
                />
                <span class="flex flex-col gap-0.5">
                    <Label class="cursor-pointer"
                        >Activar animaciones de la interfaz</Label
                    >
                    <span class="text-xs text-muted-foreground">
                        Desactívalas si prefieres transiciones instantáneas
                        (recomendado en equipos más lentos).
                    </span>
                </span>
            </label>
        </section>
    </div>
</template>
