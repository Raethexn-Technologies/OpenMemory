<?php

namespace Tests\Unit;

use App\Models\RedactionPolicy;
use App\Services\RedactionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RedactionServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_payment_card_is_tokenized_even_if_policy_tries_to_allow_it(): void
    {
        $service = new RedactionService();

        $result = $service->redact(
            'My card is 4111 1111 1111 1111.',
            'user-1',
            ['payment_card' => ['action' => 'allow', 'sensitivity' => 'public']],
        );

        $this->assertStringNotContainsString('4111 1111 1111 1111', $result->text);
        $this->assertMatchesRegularExpression('/\[PAYMENT_CARD#[a-f0-9]{12}\]/', $result->text);
        $this->assertSame('sensitive', $result->minimumSensitivity);
        $this->assertSame(['payment_card'], $result->categories());
    }

    public function test_bank_routing_number_uses_aba_checksum_before_redaction(): void
    {
        $service = new RedactionService();

        $result = $service->redact('Routing number is 021000021.', 'user-1');

        $this->assertStringNotContainsString('021000021', $result->text);
        $this->assertMatchesRegularExpression('/\[BANK_ROUTING#[a-f0-9]{12}\]/', $result->text);
        $this->assertSame('sensitive', $result->minimumSensitivity);
    }

    public function test_policy_can_redact_email_for_regulated_contexts(): void
    {
        $service = new RedactionService();

        $result = $service->redact(
            'Email me at person@example.com.',
            'user-1',
            ['email' => ['action' => 'tokenize', 'sensitivity' => 'private']],
        );

        $this->assertStringNotContainsString('person@example.com', $result->text);
        $this->assertMatchesRegularExpression('/\[EMAIL#[a-f0-9]{12}\]/', $result->text);
        $this->assertSame('private', $result->minimumSensitivity);
    }

    public function test_compensation_can_be_stored_as_an_abstraction(): void
    {
        $service = new RedactionService();

        $result = $service->redact('My salary is $180,000 per year.', 'user-1');

        $this->assertStringNotContainsString('$180,000', $result->text);
        $this->assertStringContainsString('[COMPENSATION:100K_200K]', $result->text);
        $this->assertSame('sensitive', $result->minimumSensitivity);
    }

    public function test_user_policy_preset_is_loaded_from_storage(): void
    {
        RedactionPolicy::create([
            'user_id' => 'user-regulated',
            'preset' => 'regulated',
            'rules' => [],
        ]);

        $service = new RedactionService();

        $result = $service->redact('Email me at person@example.com.', 'user-regulated');

        $this->assertStringNotContainsString('person@example.com', $result->text);
        $this->assertMatchesRegularExpression('/\[EMAIL#[a-f0-9]{12}\]/', $result->text);
        $this->assertSame('regulated', $result->policy);
    }
}
