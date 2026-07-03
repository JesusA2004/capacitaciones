<?php

namespace App\Jobs;

use App\Models\Asignacion;
use App\Services\Asignaciones\AsignacionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class MaterializarAsignacionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(public readonly Asignacion $asignacion) {}

    public function handle(AsignacionService $asignacionService): void
    {
        $asignacionService->materializar($this->asignacion);
    }
}
