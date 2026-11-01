<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Storage;
use Laravel\Fortify\Features;

abstract class TestCase extends BaseTestCase
{
    /**
     * Ninguna prueba toca el NAS real: `.env.example` apunta NAS_ROOT a
     * /mnt/people-storage, que no existe ni se puede crear en CI ni en una
     * laptop, y crear el disco ahí tronaría con «Unable to create a directory».
     * Cada prueba parte de un disco temporal vacío, propio de su proceso.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('nas');
        Storage::fake('nas_legacy');
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}
