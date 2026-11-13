<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { ArrowRight, Eye, History } from '@lucide/vue';
import { ref, watch } from 'vue';
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

/*
 * Borrador en este navegador: cada respuesta se guarda al momento, así una
 * sesión vencida, una recarga o un corte de red nunca borran lo contestado.
 * Se elimina al enviar la evaluación. La vista previa de RH no guarda nada.
 */
const claveBorrador =
    props.vistaPrevia || props.evaluacionId === null
        ? null
        : `people:evaluacion-induccion:${props.evaluacionId}`;
const borradorRecuperado = ref(false);

function esObjeto(valor: unknown): valor is Record<string, unknown> {
    return typeof valor === 'object' && valor !== null && !Array.isArray(valor);
}

function recuperarBorrador(): void {
    if (claveBorrador === null) {
        return;
    }

    try {
        const guardado: unknown = JSON.parse(
            window.localStorage.getItem(claveBorrador) ?? 'null',
        );

        if (!esObjeto(guardado)) {
            return;
        }

        if (esObjeto(guardado.respuestas)) {
            form.respuestas = {
                ...form.respuestas,
                ...(guardado.respuestas as InduccionRespuestas),
            };
        }

        if (esObjeto(guardado.opinion)) {
            form.opinion = {
                ...form.opinion,
                ...(guardado.opinion as Record<string, number | string | null>),
            };
        }

        borradorRecuperado.value = true;
    } catch {
        // Borrador ilegible o almacenamiento bloqueado: se empieza en blanco.
    }
}

function borrarBorrador(): void {
    if (claveBorrador === null) {
        return;
    }

    try {
        window.localStorage.removeItem(claveBorrador);
    } catch {
        // Sin almacenamiento local no hay nada que borrar.
    }
}

recuperarBorrador();

watch(
    () => [form.respuestas, form.opinion],
    () => {
        if (claveBorrador === null) {
            return;
        }

        try {
            window.localStorage.setItem(
                claveBorrador,
                JSON.stringify({
                    respuestas: form.respuestas,
                    opinion: form.opinion,
                }),
            );
        } catch {
            // Modo privado / almacenamiento lleno: la evaluación sigue en pantalla.
        }
    },
    { deep: true },
);

function enviar() {
    if (props.vistaPrevia || props.evaluacionId === null) {
        return;
    }

    form.post(presentar.url(props.evaluacionId), {
        preserveScroll: true,
        onSuccess: (pagina) => {
            // Solo si de verdad salió de la evaluación (un 419 regresa aquí).
            if (pagina.component !== 'Portal/EvaluacionInduccion') {
                borrarBorrador();
            }
        },
    });
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
            <Eye class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
            <p>
                <strong>Vista previa — así lo verá el colaborador.</strong>
                Puedes marcar respuestas para probarla, pero no se envía ni se
                guarda nada.
            </p>
        </div>

        <div
            v-if="borradorRecuperado"
            role="status"
            class="mb-4 flex items-start gap-3 rounded-2xl border bg-success-soft px-4 py-3 text-sm shadow-sm"
        >
            <History class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
            <p>
                <strong>Recuperamos tus respuestas.</strong>
                Lo que ya habías contestado en este equipo sigue aquí: revisa y
                envía tu evaluación cuando termines.
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
                        Enviar evaluación
                        <ArrowRight
                            class="size-4 icono-avanza"
                            aria-hidden="true"
                        />
                    </Button>
                </template>
            </FormularioInduccion>
        </form>
    </div>
</template>
