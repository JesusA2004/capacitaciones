/**
 * Efecto breve de confeti para celebrar hitos puntuales (vacante cubierta, candidato
 * contratado, documento aprobado, cumpleaños del día). Sin dependencias nuevas: dibuja
 * en un <canvas> temporal superpuesto a toda la pantalla y se autodestruye al terminar.
 * Respeta `prefers-reduced-motion` (en ese caso no hace nada).
 */

interface Particula {
    x: number;
    y: number;
    vx: number;
    vy: number;
    rotacion: number;
    vRotacion: number;
    color: string;
    tamano: number;
    forma: 'rect' | 'circulo';
}

const COLORES = ['#F5B400', '#E85D75', '#4ADE80', '#60A5FA', '#C084FC', '#FB923C'];

function prefiereMovimientoReducido(): boolean {
    return typeof window !== 'undefined' && window.matchMedia?.('(prefers-reduced-motion: reduce)').matches === true;
}

export function useCelebracion() {
    function celebrar(origen?: { x: number; y: number }) {
        if (typeof document === 'undefined' || prefiereMovimientoReducido()) {
            return;
        }

        const canvas = document.createElement('canvas');
        canvas.style.position = 'fixed';
        canvas.style.inset = '0';
        canvas.style.width = '100vw';
        canvas.style.height = '100vh';
        canvas.style.pointerEvents = 'none';
        canvas.style.zIndex = '9999';
        canvas.width = window.innerWidth;
        canvas.height = window.innerHeight;
        document.body.appendChild(canvas);

        const ctx = canvas.getContext('2d');

        if (!ctx) {
            canvas.remove();

            return;
        }

        const puntoOrigen = origen ?? { x: canvas.width / 2, y: canvas.height / 3 };
        const cantidad = 60;
        const particulas: Particula[] = Array.from({ length: cantidad }, () => {
            const angulo = Math.random() * Math.PI * 2;
            const velocidad = 3 + Math.random() * 6;

            return {
                x: puntoOrigen.x,
                y: puntoOrigen.y,
                vx: Math.cos(angulo) * velocidad,
                vy: Math.sin(angulo) * velocidad - 4,
                rotacion: Math.random() * 360,
                vRotacion: (Math.random() - 0.5) * 16,
                color: COLORES[Math.floor(Math.random() * COLORES.length)],
                tamano: 5 + Math.random() * 5,
                forma: Math.random() > 0.5 ? 'rect' : 'circulo',
            };
        });

        const gravedad = 0.18;
        const duracionMs = 1400;
        const inicio = performance.now();
        let idFrame = 0;

        function dibujar(ahora: number) {
            const transcurrido = ahora - inicio;

            if (!ctx) {
return;
}

            ctx.clearRect(0, 0, canvas.width, canvas.height);

            const opacidad = Math.max(0, 1 - transcurrido / duracionMs);

            for (const p of particulas) {
                p.x += p.vx;
                p.y += p.vy;
                p.vy += gravedad;
                p.rotacion += p.vRotacion;

                ctx.save();
                ctx.globalAlpha = opacidad;
                ctx.translate(p.x, p.y);
                ctx.rotate((p.rotacion * Math.PI) / 180);
                ctx.fillStyle = p.color;

                if (p.forma === 'rect') {
                    ctx.fillRect(-p.tamano / 2, -p.tamano / 2, p.tamano, p.tamano * 0.6);
                } else {
                    ctx.beginPath();
                    ctx.arc(0, 0, p.tamano / 2, 0, Math.PI * 2);
                    ctx.fill();
                }

                ctx.restore();
            }

            if (transcurrido < duracionMs) {
                idFrame = requestAnimationFrame(dibujar);
            } else {
                cancelAnimationFrame(idFrame);
                canvas.remove();
            }
        }

        idFrame = requestAnimationFrame(dibujar);
    }

    function celebrarDesdeEvento(evento: MouseEvent | PointerEvent) {
        celebrar({ x: evento.clientX, y: evento.clientY });
    }

    return { celebrar, celebrarDesdeEvento };
}
