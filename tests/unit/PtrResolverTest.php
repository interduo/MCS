<?php

use App\Libraries\Dnsbl\DnsPakiet;
use App\Libraries\Siec\PtrResolver;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * PtrResolver (issue #447): zamiennik blokującego gethostbyaddr — UDP + twardy timeout.
 *
 * @internal
 */
final class PtrResolverTest extends CIUnitTestCase
{
    public function testNazwaPtrIpv4(): void
    {
        $this->assertSame('10.100.51.198.in-addr.arpa', PtrResolver::nazwaPtr('198.51.100.10'));
    }

    public function testNazwaPtrIpv6(): void
    {
        $this->assertSame(
            '1.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.8.b.d.0.1.0.0.2.ip6.arpa',
            PtrResolver::nazwaPtr('2001:db8::1'),
        );
    }

    public function testNieprawidlowyIpZwracaNullBezSieci(): void
    {
        $r = new PtrResolver('127.0.0.1', 53, 100);

        $this->assertNull($r->lookup('nie-ip'));
        $this->assertNull($r->lookup(''));
    }

    /**
     * DoD issue #447: przy braku odpowiedzi DNS request nie trwa dłużej niż timeout.
     * (W środowisku bez resolvert na 127.0.0.1:53 lookup kończy się od razu przez odmowę;
     * przy „cichym” resolverze pilnuje tego pętla stream_select do terminu.)
     */
    public function testLookupNiePrzekraczaTwardegoTimeoutu(): void
    {
        $timeoutMs = 120;
        $r         = new PtrResolver('127.0.0.1', 53, $timeoutMs);

        $start = microtime(true);
        $wynik = $r->lookup('203.0.113.7'); // TEST-NET-3 — bez PTR
        $czas  = microtime(true) - $start;

        $this->assertNull($wynik);
        $this->assertLessThan(2.0, $czas, 'lookup PTR musi być ograniczony twardym timeoutem');
    }

    public function testParsujPtrZwracaNazweZRekorduPtr(): void
    {
        $id    = 0x1234;
        $query = DnsPakiet::zapytanie($id, '10.100.51.198.in-addr.arpa', PtrResolver::TYP_PTR);
        $rdata = $this->kodujNazwe('host.example.test');

        // odpowiedź: QR=1, RD/RA, qd=1, an=1; owner = wskaźnik kompresji do pytania (0xC00C)
        $odp = pack('nnnnnn', $id, 0x8180, 1, 1, 0, 0)
            . substr($query, 12)
            . "\xC0\x0C" . pack('nnNn', PtrResolver::TYP_PTR, 1, 300, strlen($rdata)) . $rdata;

        $this->assertSame('host.example.test', PtrResolver::parsujPtr($odp, $id));
    }

    public function testParsujPtrCzytaWskaznikKompresjiWRdata(): void
    {
        $id    = 0x00FF;
        $query = DnsPakiet::zapytanie($id, '7.113.0.203.in-addr.arpa', PtrResolver::TYP_PTR);

        // rdata = wskaźnik do nazwy pytania (0xC00C) — PTR wskazuje na in-addr.arpa
        $odp = pack('nnnnnn', $id, 0x8180, 1, 1, 0, 0)
            . substr($query, 12)
            . "\xC0\x0C" . pack('nnNn', PtrResolver::TYP_PTR, 1, 300, 2) . "\xC0\x0C";

        $this->assertSame('7.113.0.203.in-addr.arpa', PtrResolver::parsujPtr($odp, $id));
    }

    public function testParsujPtrNxdomainIbrakRekorduZwracaNull(): void
    {
        $id    = 0x0007;
        $query = DnsPakiet::zapytanie($id, '10.100.51.198.in-addr.arpa', PtrResolver::TYP_PTR);

        $nx = pack('nnnnnn', $id, 0x8183, 1, 0, 0, 0) . substr($query, 12); // NXDOMAIN
        $this->assertNull(PtrResolver::parsujPtr($nx, $id));

        $ok = pack('nnnnnn', $id, 0x8180, 1, 0, 0, 0) . substr($query, 12); // NOERROR, brak odpowiedzi
        $this->assertNull(PtrResolver::parsujPtr($ok, $id));
    }

    public function testParsujPtrOdrzucaObcyPakiet(): void
    {
        $id    = 0x1234;
        $query = DnsPakiet::zapytanie($id, '10.100.51.198.in-addr.arpa', PtrResolver::TYP_PTR);
        $rdata = $this->kodujNazwe('host.example.test');
        $odp   = pack('nnnnnn', $id, 0x8180, 1, 1, 0, 0)
            . substr($query, 12)
            . "\xC0\x0C" . pack('nnNn', PtrResolver::TYP_PTR, 1, 300, strlen($rdata)) . $rdata;

        // zły ID
        $this->assertNull(PtrResolver::parsujPtr($odp, 0x9999));
        // brak bitu QR (to zapytanie, nie odpowiedź)
        $zle = pack('nnnnnn', $id, 0x0100, 1, 1, 0, 0) . substr($odp, 12);
        $this->assertNull(PtrResolver::parsujPtr($zle, $id));
        // za krótki bufor
        $this->assertNull(PtrResolver::parsujPtr('abc', $id));
    }

    private function kodujNazwe(string $nazwa): string
    {
        $q = '';
        foreach (explode('.', rtrim($nazwa, '.')) as $etykieta) {
            $q .= chr(strlen($etykieta)) . $etykieta;
        }

        return $q . "\0";
    }
}
