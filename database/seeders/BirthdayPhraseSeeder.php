<?php

namespace Database\Seeders;

use App\Models\BirthdayPhrase;
use Illuminate\Database\Seeder;

/**
 * Catalogo inicial de frases de cumpleanos usadas por
 * App\Services\Cumpleanos\CumpleanosService al generar una felicitacion.
 * Idempotente: usa firstOrCreate por texto para que correr db:seed varias
 * veces no duplique frases.
 */
class BirthdayPhraseSeeder extends Seeder
{
    /**
     * @var array<int, string>
     */
    private const FRASES = [
        'Que este nuevo año de vida te traiga salud, alegría y nuevos logros. Gracias por ser parte de MR. LANA.',
        'Hoy celebramos tu vida y la energía que aportas al equipo. ¡Feliz cumpleaños!',
        'Deseamos que este día esté lleno de momentos especiales. Gracias por tu compromiso y dedicación.',
        'Que tus metas sigan creciendo y que este año venga lleno de buenas noticias.',
        'Hoy celebramos tu esfuerzo, tu actitud y todo lo que aportas al equipo.',
        'Feliz cumpleaños: que la felicidad te acompañe hoy y en cada proyecto que emprendas.',
        'Gracias por tu entrega diaria. Que este nuevo año esté lleno de éxitos y buena energía.',
        'Un año más de vida es un año más de experiencia, sabiduría y motivos para celebrar. ¡Feliz cumpleaños!',
        'Que la pases increíble hoy. Eres una pieza clave de este equipo y nos alegra celebrarte.',
        'Hoy es tu día: disfrútalo al máximo. Gracias por todo lo que haces por MR. LANA.',
        'Que este cumpleaños marque el inicio de un año lleno de crecimiento personal y profesional.',
        'Celebramos contigo un año más de vida y de formar parte de esta gran familia.',
        'Que tengas un día tan especial como tú. ¡Feliz cumpleaños de parte de todo el equipo!',
        'Gracias por tu buena actitud y tu esfuerzo constante. Hoy te deseamos un cumpleaños increíble.',
        'Que este nuevo ciclo te traiga salud, prosperidad y muchas razones para sonreír.',
        'Hoy celebramos quién eres y todo lo que aportas cada día. ¡Feliz cumpleaños!',
        'Que disfrutes este día rodeado de las personas que más quieres. Gracias por ser parte de MR. LANA.',
        'Un año más cumplido es un año más de historias, logros y aprendizajes. ¡Felicidades!',
        'Esperamos que este día esté lleno de sorpresas agradables y mucha felicidad.',
        'Gracias por tu compromiso con el equipo. Que este nuevo año de vida sea el mejor hasta ahora.',
        'Que la magia de tu cumpleaños te acompañe todo el año. ¡Felicidades!',
        'Hoy brindamos por ti: por tu talento, tu esfuerzo y tu buena energía. ¡Feliz cumpleaños!',
        'Que cada meta que te propongas este año se cumpla. Gracias por ser parte de nuestro equipo.',
        'Deseamos que tengas un día lleno de cariño, risas y buenos momentos. ¡Felicidades!',
        'Gracias por inspirar con tu ejemplo. Que este cumpleaños sea el comienzo de un año extraordinario.',
        'Que la vida te siga regalando motivos para sonreír. ¡Feliz cumpleaños de parte de MR. LANA!',
        'Hoy es un buen día para recordarte lo valioso que eres para este equipo. ¡Felicidades!',
        'Que este nuevo año te traiga equilibrio, bienestar y muchos logros por celebrar.',
        'Gracias por tu dedicación día a día. Disfruta mucho tu cumpleaños, te lo mereces.',
        'Que cumplas muchos años más, siempre con la misma energía y entusiasmo que contagias al equipo.',
    ];

    public function run(): void
    {
        foreach (self::FRASES as $indice => $texto) {
            BirthdayPhrase::query()->firstOrCreate(
                ['texto' => $texto],
                ['categoria' => 'general', 'activo' => true, 'orden' => $indice + 1],
            );
        }
    }
}
