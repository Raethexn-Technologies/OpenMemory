<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    public function test_root_redirects_to_chat(): void
    {
        $response = $this->get('/');

        $response->assertRedirect('/chat');
    }

    public function test_chat_page_loads(): void
    {
        $response = $this->get('/chat');

        $response->assertStatus(200);
        $response->assertSee('Chat\\/Index', false);
    }

    public function test_memory_page_loads(): void
    {
        $response = $this->get('/memory');

        $response->assertStatus(200);
        $response->assertSee('Memory\\/Index', false);
    }
}
