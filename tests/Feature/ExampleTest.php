<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /** A home manda o visitante direto para o modelador de ER. */
    public function test_the_application_returns_a_successful_response(): void
    {
        $this->get('/')->assertRedirect('/schema');
    }
}
