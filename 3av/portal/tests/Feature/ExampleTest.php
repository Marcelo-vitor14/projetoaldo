<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_a_successful_response(): void
    {
        // A página inicial leva direto ao login do portal
        $response = $this->get(route('home'));

        $response->assertRedirect('/login');
    }
}
