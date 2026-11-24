<?php

use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;

/**
 * Okna dat i dzień w osi czasu zakładają UTC w sesji bazy (PostgreSQL),
 * więc połączenie wymusza timezone=UTC niezależnie od ustawień serwera.
 * Issue #457: oprócz timezone sesja ma też timeouty (statement/lock/idle_in_transaction).
 *
 * @internal
 */
final class BazaStrefaCzasuTest extends CIUnitTestCase
{
    public function testPolaczenieWymuszaUtcWSesjiPostgreSql(): void
    {
        $options = (string) (new Database())->default['options'];
        $this->assertStringContainsString('-c timezone=UTC', $options);
        $this->assertStringContainsString('-c statement_timeout=30000', $options);
        $this->assertStringContainsString('-c lock_timeout=5000', $options);
        $this->assertStringContainsString('-c idle_in_transaction_session_timeout=60000', $options);
        $this->assertSame('10', (string) (new Database())->default['connect_timeout']);
    }
}
