<?php

use CodeIgniter\Test\CIUnitTestCase;

/**
 * Issue #457: połączenie PostgreSQL ma timeouty sesji — statement_timeout, lock_timeout,
 * idle_in_transaction_session_timeout (options) oraz connect_timeout w DSN.
 *
 * @internal
 */
final class DatabaseTimeoutsTest extends CIUnitTestCase
{
    public function testDomyslneOpcjeMajaTimeoutySesji(): void
    {
        $db = new Config\Database();

        $options = (string) ($db->default['options'] ?? '');
        $this->assertStringContainsString('-c timezone=UTC', $options);
        $this->assertStringContainsString('-c statement_timeout=30000', $options);
        $this->assertStringContainsString('-c lock_timeout=5000', $options);
        $this->assertStringContainsString('-c idle_in_transaction_session_timeout=60000', $options);
        $this->assertSame('10', (string) ($db->default['connect_timeout'] ?? ''), 'connect_timeout ląduje w DSN sterownika Postgre');
    }

    public function testEnvNadpisujeOpcjePolaczenia(): void
    {
        putenv("database.default.options=-c timezone=UTC -c statement_timeout=1000");
        $_ENV['database.default.options'] = '-c timezone=UTC -c statement_timeout=1000';
        putenv('database.default.connect_timeout=3');
        $_ENV['database.default.connect_timeout'] = '3';
        try {
            $db = new Config\Database();
            $this->assertStringContainsString('-c statement_timeout=1000', (string) $db->default['options']);
            $this->assertSame('3', (string) $db->default['connect_timeout']);
        } finally {
            putenv('database.default.options');
            unset($_ENV['database.default.options']);
            putenv('database.default.connect_timeout');
            unset($_ENV['database.default.connect_timeout']);
        }
    }

    public function testEnvExampleMaWzorZkomentarzem(): void
    {
        $env = (string) file_get_contents(ROOTPATH . '.env.example');
        $this->assertStringContainsString('database.default.options', $env);
        $this->assertStringContainsString('statement_timeout=30000', $env);
        $this->assertStringContainsString('lock_timeout=5000', $env);
        $this->assertStringContainsString('idle_in_transaction_session_timeout=60000', $env);
        $this->assertStringContainsString('database.default.connect_timeout', $env);
    }
}
