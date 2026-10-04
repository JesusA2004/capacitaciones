<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { Save } from '@lucide/vue';
import { ref } from 'vue';
import CelebracionConfiguracionLayout from '@/components/Celebraciones/CelebracionConfiguracionLayout.vue';
import PosicionVerticalTexto from '@/components/Celebraciones/PosicionVerticalTexto.vue';
import InputError from '@/components/InputError.vue';
import PeopleFileDropzone from '@/components/people/PeopleFileDropzone.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { dashboard } from '@/routes';
import { index as indexAniversarios } from '@/routes/rh/aniversarios';
import { guardar, vistaPrevia } from '@/routes/rh/aniversarios/configuracion';

// Posiciones por defecto (misma fracción aproximada que calcula
// TarjetaAniversarioService cuando RH no ha movido cada marcador) — solo
// para que el marcador arranque en un lugar razonable antes del primer
// ajuste.
const POSICION_TITULO_DEFECTO = 0.15;
const POSICION_NOMBRE_DEFECTO = 0.42;
const POSICION_FRASE_DEFECTO = 0.55;

/**
 * Configuración de la tarjeta de aniversario (mensaje con {anios},
 * {nombre}, {sucursal}; fondo propio; activo; envío automático). Mismo
 * diseño que la configuración de Cumpleaños.
 */
const props = defineProps<{
    configuracion: {
        activo: boolean;
        mensaje: string | null;
        auto_enviar_colaborador: boolean;
        tiene_fondo: boolean;
        mostrar_logo: boolean;
        texto_titulo_y: number | null;
        texto_nombre_y: number | null;
        texto_frase_y: number | null;
    };
    mensajePredeterminado: string;
    ejemplo: {
        nombre: string;
        anios: number;
        puesto: string;
        sucursal: string;
    };
}>();

const datosEjemplo = [
    { etiqueta: 'Años', valor: props.ejemplo.anios },
    { etiqueta: 'Nombre', valor: props.ejemplo.nombre },
    { etiqueta: 'Puesto', valor: props.ejemplo.puesto },
    { etiqueta: 'Sucursal', valor: props.ejemplo.sucursal },
    { etiqueta: 'Mensaje', valor: 'El mensaje institucional guardado' },
];

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Aniversarios', href: indexAniversarios() },
            { title: 'Configuración', href: '' },
        ],
    },
});

const form = useForm<{
    activo: boolean;
    mensaje: string;
    auto_enviar_colaborador: boolean;
    fondo: File | null;
    quitar_fondo: boolean;
    mostrar_logo: boolean;
    texto_titulo_y: number;
    texto_nombre_y: number;
    texto_frase_y: number;
}>({
    activo: props.configuracion.activo,
    mensaje: props.configuracion.mensaje ?? props.mensajePredeterminado,
    auto_enviar_colaborador: props.configuracion.auto_enviar_colaborador,
    fondo: null,
    quitar_fondo: false,
    mostrar_logo: props.configuracion.mostrar_logo,
    texto_titulo_y:
        props.configuracion.texto_titulo_y ?? POSICION_TITULO_DEFECTO,
    texto_nombre_y:
        props.configuracion.texto_nombre_y ?? POSICION_NOMBRE_DEFECTO,
    texto_frase_y: props.configuracion.texto_frase_y ?? POSICION_FRASE_DEFECTO,
});

const version = ref(Date.now());

function enviar() {
    form.post(guardar.url(), {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            form.fondo = null;
            form.quitar_fondo = false;
            version.value = Date.now();
        },
    });
}
</script>

<template>
    <Head title="Configuración de aniversarios" />

    <CelebracionConfiguracionLayout
        titulo="Tarjeta de aniversario"
        :volver-url="indexAniversarios.url()"
        :vista-previa-url="vistaPrevia.url()"
        :version="version"
        :fondo-propio="configuracion.tiene_fondo"
        :datos-ejemplo="datosEjemplo"
    >
        <template #overlay>
            <PosicionVerticalTexto
                v-model="form.texto_titulo_y"
                variante="titulo"
            />
            <PosicionVerticalTexto
                v-model="form.texto_nombre_y"
                variante="nombre"
            />
            <PosicionVerticalTexto
                v-model="form.texto_frase_y"
                variante="frase"
            />
        </template>

        <form class="flex flex-col gap-6" @submit.prevent="enviar">
            <section class="flex flex-col gap-3" aria-label="Envío">
                <label class="flex items-start gap-2 text-sm">
                    <Checkbox
                        class="mt-0.5"
                        :model-value="form.activo"
                        @update:model-value="(v) => (form.activo = !!v)"
                    />
                    Aniversarios activos (preparar las tarjetas cada día)
                </label>
                <label class="flex items-start gap-2 text-sm">
                    <Checkbox
                        class="mt-0.5"
                        :model-value="form.auto_enviar_colaborador"
                        @update:model-value="
                            (v) => (form.auto_enviar_colaborador = !!v)
                        "
                    />
                    Enviar automáticamente la felicitación al colaborador (nunca
                    avisa a todos sin que RH lo decida)
                </label>
            </section>

            <section class="grid gap-1.5 border-t pt-6">
                <Label for="mensaje">Mensaje institucional</Label>
                <Textarea id="mensaje" v-model="form.mensaje" rows="5" />
                <p class="text-xs text-muted-foreground">
                    Usa <code>{anios}</code> (se escribe «6 años»),
                    <code>{nombre}</code> y <code>{sucursal}</code>.
                    <button
                        type="button"
                        class="ml-1 text-primary underline"
                        @click="form.mensaje = mensajePredeterminado"
                    >
                        Restaurar predeterminado
                    </button>
                </p>
                <InputError :message="form.errors.mensaje" />
            </section>

            <section
                class="flex flex-col gap-3 border-t pt-6"
                aria-label="Apariencia"
            >
                <p class="text-sm text-muted-foreground">
                    Arrastra cada marcador de color sobre la vista previa:
                    «Título» mueve el número de años + «ANIVERSARIO», «Nombre»
                    mueve el nombre del colaborador y «Frase» mueve el mensaje —
                    cada uno por separado.
                </p>
                <label class="flex items-start gap-2 text-sm">
                    <Checkbox
                        class="mt-0.5"
                        :model-value="form.mostrar_logo"
                        @update:model-value="(v) => (form.mostrar_logo = !!v)"
                    />
                    Mostrar el logo de MR. LANA en la tarjeta
                </label>
                <InputError :message="form.errors.texto_titulo_y" />
                <InputError :message="form.errors.texto_nombre_y" />
                <InputError :message="form.errors.texto_frase_y" />
            </section>

            <section class="grid gap-1.5 border-t pt-6">
                <Label for="fondo"
                    >Fondo propio
                    <span class="font-normal text-muted-foreground"
                        >(opcional, vertical 1080×1350)</span
                    ></Label
                >
                <PeopleFileDropzone
                    :model-value="form.fondo ? [form.fondo] : []"
                    accept=".png,.jpg,.jpeg,.webp"
                    :max-size-mb="10"
                    label="Arrastra la imagen de fondo aquí"
                    @update:model-value="(f) => (form.fondo = f[0] ?? null)"
                />
                <InputError :message="form.errors.fondo" />
                <label
                    v-if="configuracion.tiene_fondo"
                    class="flex items-center gap-2 text-sm"
                >
                    <Checkbox
                        :model-value="form.quitar_fondo"
                        @update:model-value="(v) => (form.quitar_fondo = !!v)"
                    />
                    Quitar el fondo propio y usar el diseño de globos
                </label>
            </section>

            <div class="border-t pt-4">
                <Button type="submit" :disabled="form.processing">
                    <Spinner v-if="form.processing" />
                    <Save v-else class="size-4" />
                    Guardar
                </Button>
            </div>
        </form>
    </CelebracionConfiguracionLayout>
</template>
