<?php

use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class LoginViewTest extends CIUnitTestCase
{
    /**
     * #439: pole e-mail ma type="email" (walidacja HTML5 + semantyka dla screen readerów)
     * i zostaje autocomplete="username"; hasło ma autocomplete="current-password".
     */
    public function testFormularzLogowaniaMaTypeEmailIAutocomplete(): void
    {
        $html = view('auth/login', ['blad' => null]);

        $this->assertMatchesRegularExpression(
            '/<input[^>]*id="email"[^>]*type="email"[^>]*name="email"[^>]*autocomplete="username"[^>]*>/s',
            $html,
        );
        // inputmode="email" jest zbędne przy type="email"
        $this->assertStringNotContainsString('inputmode="email"', $html);
        $this->assertStringNotContainsString('type="text" name="email"', $html);
        $this->assertStringContainsString('type="password"', $html);
        $this->assertStringContainsString('autocomplete="current-password"', $html);
        $this->assertStringContainsString('autocomplete="on"', $html);
    }

    public function testFormularzLogowaniaPokazujeBlad(): void
    {
        $html = view('auth/login', ['blad' => 'Nieprawidłowy e-mail lub hasło.']);

        $this->assertStringContainsString('role="alert"', $html);
        $this->assertStringContainsString('Nieprawidłowy e-mail lub hasło.', $html);
    }
}
