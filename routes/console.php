<?php

use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function (): void {
    $this->comment('CosplayNesia siap membantu komunitas cosplay Indonesia.');
})->purpose('Display an inspiring message');
