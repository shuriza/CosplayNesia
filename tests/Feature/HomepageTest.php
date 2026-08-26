<?php

namespace Tests\Feature;

use Tests\TestCase;

class HomepageTest extends TestCase
{
    public function test_homepage_renders_laravel_blade_application(): void
    {
        $this->withoutVite();

        $this->get('/')->assertOk()->assertSee('CosplayNesia')->assertSee('csrf-token');
    }
}
