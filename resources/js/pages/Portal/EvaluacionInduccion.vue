<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { ArrowRight, Eye } from '@lucide/vue';
import FormularioInduccion from '@/components/formularios-evaluacion/FormularioInduccion.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { dashboard } from '@/routes';
import { presentacion as presentacionInduccion } from '@/routes/induccion';
import { miProceso } from '@/routes/portal';
import { presentar } from '@/routes/portal/induccion';
import type { InduccionContenido, InduccionRespuestas } from '@/types';

/**
 * Evaluación FO-RH-EI tal cual la contesta el colaborador, con el diseño y
 * las preguntas de la versión del formulario de SU evaluación (módulo
 * «Formularios de evaluación»). Con `vistaPrevia` (RH, «Ver evaluación como
 * colaborador») se muestra idéntica pero sin evaluación detrás: no se puede
 * enviar y no crea nada.
 */
const props = defineProps<{
    contenido: InduccionContenido;
    /** null en la vista previa de RH. */
    evaluacionId: number | null;
    vistaPrevia?: boolean;
}>();

// `layout` como función: defineOptions() no puede leer `props`.
defineOptions({
    layout: (pageProps: { vistaPrevia?: boolean }) => ({
        breadcrumbs: pageProps.vistaPrevia
            ? [
                  { title: 'Inicio', href: dashboard() },
                  {
                      title: 'Presentar inducción',
                      href: presentacionInduccion(),
                  },
                  { title: 'Vista previa de la evaluación', href: '' },
              ]
            : [
                  { title: 'Inicio', href: dashboard() },
                  { title: 'Mis pendientes', href: miProceso() },
                  { title: 'Evaluación de inducción', href: '' },
              ],
    }),
});

const form = useForm<{
    respuestas: InduccionRespuestas;
    opinion: Record<string, number | string | null>;
}>({
    respuestas: { a: {}, b: {}, c: {}, d: {} },
    opinion: {
        ...Object.fromEntries(
            props.contenido.seccion_e.aspectos.map((a) => [a.clave, null]),
        ),
        tema_reforzar: '',
    },
});

function enviar() {
    if (props.vistaPrevia || props.evaluacionId === null) {
        return;
    }

    form.post(presentar.url(props.evaluacionId), { preserveScroll: true });
}
</script>

<template>
    <Head title="Evaluación de inducción" />

    <div class="pagina-ancha pb-16">
        <div
            v-if="vistaPrevia"
            role="status"
            class="sticky top-2 z-10 mb-4 flex items-start gap-3 rounded-2xl border bg-warning-soft px-4 py-3 text-sm shadow-sm"
        >
            <Eye class="mt-0.5 size-4 shrink-0" />
            <p>
                <strong>Vista previa — así lo verá el colaborador.</strong>
                Puedes marcar respuestas para probarla, pero no se envía ni se
                guarda nada.
            </p>
        </div>

        <form @submit.prevent="enviar">
            <FormularioInduccion
                v-model:respuestas="form.respuestas"
                v-model:opinion="form.opinion"
                :contenido="contenido"
            >
                <template #acciones>
                    <InputError :message="form.errors.respuestas as string" />
                    <Button
                        type="submit"
                        size="lg"
                        class="w-fit"
                        :disabled="form.processing || vistaPrevia"
                        :title="
                            vistaPrevia
                                ? 'Vista previa: el envío está deshabilitado'
                                : undefined
                        "
                    >
                        Enviar evaluación <ArrowRight class="size-4" />
                    </Button>
                </template>
            </FormularioInduccion>
        </form>
    </div>
</template>
