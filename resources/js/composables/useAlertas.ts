import Swal from 'sweetalert2';
import { avisarExito } from '@/lib/flashToast';

const base = Swal.mixin({
    confirmButtonColor: '#64d64b',
    cancelButtonColor: '#6b7280',
    reverseButtons: true,
    focusCancel: true,
    buttonsStyling: true,
});

type ConfirmarOpciones = {
    titulo?: string;
    texto?: string;
    confirmarTexto?: string;
    cancelarTexto?: string;
};

async function confirmar(
    opciones: ConfirmarOpciones & { icono: 'warning' | 'question' },
): Promise<boolean> {
    const resultado = await base.fire({
        icon: opciones.icono,
        title: opciones.titulo,
        text: opciones.texto,
        showCancelButton: true,
        confirmButtonText: opciones.confirmarTexto ?? 'Confirmar',
        cancelButtonText: opciones.cancelarTexto ?? 'Cancelar',
    });

    return resultado.isConfirmed;
}

/**
 * Composable centralizado de alertas (SweetAlert2). Es la unica forma en que
 * la aplicacion debe mostrar confirmaciones y avisos modales; no inicializar
 * SweetAlert2 directamente en componentes o paginas.
 */
export function useAlertas() {
    function confirmarEliminacion(entidad = 'este registro'): Promise<boolean> {
        return confirmar({
            icono: 'warning',
            titulo: '¿Eliminar registro?',
            texto: `Esta acción eliminará ${entidad} y no se puede deshacer.`,
            confirmarTexto: 'Sí, eliminar',
        });
    }

    function confirmarPublicacion(
        entidad = 'este contenido',
    ): Promise<boolean> {
        return confirmar({
            icono: 'question',
            titulo: '¿Publicar ahora?',
            texto: `Al publicar, ${entidad} quedará visible según sus reglas de asignación.`,
            confirmarTexto: 'Sí, publicar',
        });
    }

    function confirmarAsignacionMasiva(
        cantidadUsuarios: number,
    ): Promise<boolean> {
        return confirmar({
            icono: 'question',
            titulo: '¿Confirmar asignación masiva?',
            texto: `Esta asignación afectará a ${cantidadUsuarios} colaborador(es).`,
            confirmarTexto: 'Sí, asignar',
        });
    }

    function confirmarCambioAsistencia(): Promise<boolean> {
        return confirmar({
            icono: 'warning',
            titulo: '¿Corregir asistencia?',
            texto: 'Este cambio quedará registrado en la auditoría con tu usuario, la fecha y el motivo.',
            confirmarTexto: 'Sí, corregir',
        });
    }

    function confirmarCierreIntento(): Promise<boolean> {
        return confirmar({
            icono: 'warning',
            titulo: '¿Finalizar intento?',
            texto: 'Una vez enviado no podrás modificar tus respuestas.',
            confirmarTexto: 'Sí, finalizar',
        });
    }

    function confirmarRevocacion(
        entidad = 'esta invitación',
    ): Promise<boolean> {
        return confirmar({
            icono: 'warning',
            titulo: '¿Revocar invitación?',
            texto: `${entidad} dejará de aceptar registro de inmediato. No se puede deshacer.`,
            confirmarTexto: 'Sí, revocar',
        });
    }

    function confirmarRegeneracion(
        entidad = 'esta invitación',
    ): Promise<boolean> {
        return confirmar({
            icono: 'question',
            titulo: '¿Regenerar QR?',
            texto: `Se creará un código nuevo y ${entidad} anterior quedará revocada.`,
            confirmarTexto: 'Sí, regenerar',
        });
    }

    function confirmarDesvinculacion(
        entidad = 'este puesto',
    ): Promise<boolean> {
        return confirmar({
            icono: 'warning',
            titulo: '¿Quitar relación?',
            texto: `${entidad} dejará de reportar a su puesto superior actual. Puedes volver a asignarlo cuando quieras.`,
            confirmarTexto: 'Sí, quitar',
        });
    }

    /**
     * @returns el motivo capturado, o null si RH cerró el diálogo sin confirmar.
     */
    async function pedirMotivoCancelacionVacante(): Promise<string | null> {
        const resultado = await base.fire({
            icon: 'warning',
            title: '¿Cancelar vacante?',
            text: 'Indica por qué esta plaza ya no se va a cubrir.',
            input: 'textarea',
            inputLabel: 'Motivo de cancelación',
            inputPlaceholder:
                'Ej. La ruta se dio de baja y ya no requiere cobertura.',
            showCancelButton: true,
            confirmButtonText: 'Sí, cancelar vacante',
            cancelButtonText: 'Volver',
            inputValidator: (valor: string) =>
                valor.trim() === '' ? 'El motivo es obligatorio.' : undefined,
        });

        return resultado.isConfirmed ? String(resultado.value) : null;
    }

    function avisarSesionExpirada(): Promise<void> {
        return base
            .fire({
                icon: 'info',
                title: 'Tu sesión ha expirado',
                text: 'Por seguridad debes iniciar sesión de nuevo para continuar.',
                confirmButtonText: 'Ir a iniciar sesión',
                allowOutsideClick: false,
            })
            .then(() => undefined);
    }

    /**
     * Éxito = toast no bloqueante (no un modal que hay que cerrar). Si el
     * backend ya avisó lo mismo con su flash toast hace un instante, no se
     * repite (ver avisarExito en lib/flashToast.ts).
     */
    function mostrarExito(mensaje: string): Promise<void> {
        avisarExito(mensaje);

        return Promise.resolve();
    }

    function mostrarError(
        mensaje: string,
        titulo = 'Ocurrió un error',
    ): Promise<void> {
        return base
            .fire({ icon: 'error', title: titulo, text: mensaje })
            .then(() => undefined);
    }

    function mostrarAdvertencia(
        mensaje: string,
        titulo = 'Advertencia',
    ): Promise<void> {
        return base
            .fire({ icon: 'warning', title: titulo, text: mensaje })
            .then(() => undefined);
    }

    function confirmarFinCobertura(
        persona: string,
        puesto: string,
    ): Promise<boolean> {
        return confirmar({
            icono: 'question',
            titulo: '¿Terminar cobertura?',
            texto: `${persona} deja de cubrir «${puesto}». Conserva su propio puesto; el lugar cubierto vuelve a mostrarse sin ocupar.`,
            confirmarTexto: 'Sí, terminar',
        });
    }

    /**
     * Confirmación genérica para acciones con consecuencias (publicar una
     * versión, archivar, avisar a todos…). El texto explica qué pasará.
     */
    function confirmarAccion(
        titulo: string,
        texto: string,
        confirmarTexto = 'Confirmar',
    ): Promise<boolean> {
        return confirmar({ icono: 'question', titulo, texto, confirmarTexto });
    }

    return {
        confirmarAccion,
        confirmarFinCobertura,
        confirmarEliminacion,
        confirmarPublicacion,
        confirmarAsignacionMasiva,
        confirmarCambioAsistencia,
        confirmarCierreIntento,
        confirmarRevocacion,
        confirmarRegeneracion,
        confirmarDesvinculacion,
        pedirMotivoCancelacionVacante,
        avisarSesionExpirada,
        mostrarExito,
        mostrarError,
        mostrarAdvertencia,
    };
}
