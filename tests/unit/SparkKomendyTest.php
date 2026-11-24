<?php

use CodeIgniter\Test\CIUnitTestCase;

/**
 * Smoke test CLI (zastępuje usuniętą komendę `mcs:hello`, issue #466):
 * spark odkrywa komendy MCS, a martwe komendy nie wiszą na liście.
 *
 * @internal
 */
final class SparkKomendyTest extends CIUnitTestCase
{
    public function testKomendyMcsSaOdkrywanePrzezSparka(): void
    {
        $komendy = service('commands')->getCommands();

        foreach ([
            'mcs:abuseipdb',
            'mcs:create-user',
            'mcs:digest',
            'mcs:dnsbl',
            'mcs:dnsbl-skan',
            'mcs:greynoise',
            'mcs:ismalicious',
            'mcs:lms-mapowanie',
            'mcs:pobierz',
            'mcs:seed-pluginy',
            'mcs:spamrats',
        ] as $nazwa) {
            $this->assertArrayHasKey($nazwa, $komendy, 'Brak komendy ' . $nazwa . ' na liście spark');
        }
    }

    public function testMcsHelloNieMaNaLiscieSparka(): void
    {
        $komendy = service('commands')->getCommands();

        $this->assertArrayNotHasKey('mcs:hello', $komendy);
        $this->assertFileDoesNotExist(APPPATH . 'Commands/McsHello.php');
    }
}
