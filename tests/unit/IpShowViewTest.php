<?php

use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class IpShowViewTest extends CIUnitTestCase
{
    public function testWidokMaStatusProblemyIPanelAbuseipdb(): void
    {
        $html = view('ip/show', [
            'title'           => 'IP 198.51.100.10 — MCS',
            'navAktywny'      => 'ip',
            'ipNav'           => '198.51.100.10',
            'szukajIp'        => '198.51.100.10',
            'ip'              => '198.51.100.10',
            'blad'            => null,
            'statusIp'        => [
                'max_waga'         => 'high',
                'liczba_problemow' => 1,
                'top_tytuly'       => ['Test'],
            ],
            'problemy'        => [
                [
                    'klucz'              => 'k1',
                    'zrodlo'             => 'shadowserver',
                    'zrodlo_etykieta'    => 'Shadowserver',
                    'waga'               => 'high',
                    'kategoria'          => 'exposure',
                    'kategoria_etykieta' => 'ekspozycja',
                    'tytul'              => 'Test',
                    'port_tekst'         => '22/tcp',
                    'liczba_zdarzen'     => 2,
                    'pierwsze_widziane'  => '2026-09-01 10:00:00',
                    'ostatnio_widziane'  => '2026-09-11 10:00:00',
                    'timeline'           => [
                        ['dzien' => '2026-09-11', 'aktywny' => true],
                        ['dzien' => '2026-09-12', 'aktywny' => false],
                    ],
                    'czlonkowie'         => [
                        [
                            'id'                => 42,
                            'waga'              => 'high',
                            'ostatnio_widziane' => '2026-09-11 10:00:00',
                            'url'               => 'http://example.test/ip/198.51.100.10/incydent/42',
                        ],
                    ],
                ],
            ],
            'zrodla'             => ['shadowserver'],
            'filtrZrodlo'        => null,
            'asMatchWlaczone'    => false,
            'naszeIp'            => false,
            'email'              => 'op@example.com',
            'rola'               => 'operator',
            'abuseipdbTryb'      => 'brak_klucza',
            'abuseipdbCache'     => null,
            'abuseipdbKomunikat' => 'Brak mcs.abuseipdb.apiKey — ustaw w .env albo fixturePath.',
            'ismaliciousTryb'      => 'brak_klucza',
            'ismaliciousCache'     => null,
            'ismaliciousKomunikat' => null,
            'spamratsTryb'         => 'brak_klucza',
            'spamratsCache'        => null,
            'spamratsKomunikat'    => null,
            'dnsblTryb'            => 'dns',
            'dnsblCache'           => null,
            'dnsblKomunikat'       => null,
            'lmsTryb'            => 'brak_konfiguracji',
            'lmsCache'           => null,
            'lmsKomunikat'       => 'Brak konfiguracji LMS — ustaw mcs.lms.baseUrl+mapToken.',
            'flashOk'            => null,
            'flashBlad'          => null,
            'revDnsWlaczone'     => false,
            'revDns'             => null,
        ]);

        $this->assertStringContainsString('1 problem', $html);
        $this->assertStringContainsString('Problemy', $html);
        $this->assertStringContainsString('Shadowserver', $html);
        $this->assertStringContainsString('ekspozycja', $html);
        $this->assertStringContainsString('mini-timeline', $html);
        $this->assertStringContainsString('incydent', $html);
        $this->assertStringContainsString('#42', $html);
        $this->assertStringContainsString('Timeline i pełne dane', $html);
        $this->assertStringContainsString('Ukryj w UI', $html);
        $this->assertStringContainsString('incydent/42/ukryj', $html);
        $this->assertStringNotContainsString('Karty źródeł', $html);
        $this->assertStringNotContainsString('Skróty incydentów', $html);
        $this->assertStringContainsString('LMS — komputer / abonent', $html);
        $this->assertStringContainsString('Sprawdź w LMS', $html);
        $this->assertStringContainsString('AbuseIPDB', $html);
        $this->assertStringContainsString('isMalicious', $html);
        $this->assertStringContainsString('SpamRats', $html);
        $this->assertStringContainsString('Sprawdź SpamRats', $html);
        $this->assertStringContainsString('id="panel-dnsbl"', $html);
        $this->assertStringContainsString('Sprawdź DNSBL', $html);
        $this->assertStringContainsString('mcs.abuseipdb.apiKey', $html);
        $this->assertStringContainsString('Sprawdź AbuseIPDB', $html);
        $this->assertStringNotContainsString('id="panel-speedtest"', $html);
        $this->assertStringNotContainsString('brak testów Speedtest dla tego IP', $html);
        $this->assertStringNotContainsString('Sprawdź Speedtest', $html);
    }

    public function testWidokPokazujeCacheAbuseipdb(): void
    {
        $html = view('ip/show', [
            'title'           => 'IP 198.51.100.10 — MCS',
            'navAktywny'      => 'ip',
            'ipNav'           => '198.51.100.10',
            'szukajIp'        => '198.51.100.10',
            'ip'              => '198.51.100.10',
            'blad'            => null,
            'statusIp'        => [
                'max_waga'         => '',
                'liczba_problemow' => 0,
                'top_tytuly'       => [],
            ],
            'problemy'        => [],
            'zrodla'          => [],
            'filtrZrodlo'     => null,
            'asMatchWlaczone' => false,
            'naszeIp'         => false,
            'email'           => 'op@example.com',
            'rola'            => 'operator',
            'lmsTryb'         => 'cache',
            'lmsCache'        => [
                'trafiony'    => true,
                'komputer'    => 'Kochanowskiego-8',
                'abonent'     => 'MICHALAK Łukasz',
                'node_id'     => 11106,
                'customer_id' => 6603,
                'lms_url'     => 'http://lms.test/?m=nodeinfo&id=11106',
            ],
            'lmsKomunikat'    => null,
            'abuseipdbTryb'   => 'cache',
            'abuseipdbCache'  => [
                'abuse_confidence_score' => 42,
                'total_reports'          => 3,
                'country_code'           => 'PL',
                'isp'                    => 'Example ISP',
                'checked_at'             => '2026-09-11 12:00:00+00',
                'zrodlo_danych'          => 'fixture',
            ],
            'abuseipdbKomunikat' => null,
            'ismaliciousTryb'      => 'brak_klucza',
            'ismaliciousCache'     => null,
            'ismaliciousKomunikat' => null,
            'spamratsTryb'         => 'brak_klucza',
            'spamratsCache'        => null,
            'spamratsKomunikat'    => null,
            'dnsblTryb'            => 'dns',
            'dnsblCache'           => null,
            'dnsblKomunikat'       => null,
            'flashOk'            => null,
            'flashBlad'          => null,
            'revDnsWlaczone'     => false,
            'revDns'             => null,
        ]);

        $this->assertStringContainsString('42', $html);
        $this->assertStringContainsString('Example ISP', $html);
        $this->assertStringContainsString('PL', $html);
        $this->assertStringContainsString('Kochanowskiego-8', $html);
        $this->assertStringContainsString('MICHALAK Łukasz', $html);
        $this->assertStringContainsString('otwórz w LMS', $html);
    }

    public function testWidokPokazujeNotkeSpeedtestDla24(): void
    {
        $html = view('ip/show', [
            'title'              => 'IP 91.231.80.10 — MCS',
            'navAktywny'         => 'ip',
            'ipNav'              => '91.231.80.10',
            'szukajIp'           => '91.231.80.10',
            'ip'                 => '91.231.80.10',
            'blad'               => null,
            'statusIp'           => [
                'max_waga'         => '',
                'liczba_problemow' => 0,
                'top_tytuly'       => [],
            ],
            'problemy'           => [],
            'zrodla'             => [],
            'filtrZrodlo'        => null,
            'asMatchWlaczone'    => true,
            'naszeIp'            => true,
            'email'              => 'op@example.com',
            'rola'               => 'operator',
            'lmsTryb'            => 'brak_konfiguracji',
            'lmsCache'           => null,
            'lmsKomunikat'       => null,
            'abuseipdbTryb'      => 'brak_klucza',
            'abuseipdbCache'     => null,
            'abuseipdbKomunikat' => null,
            'ismaliciousTryb'      => 'brak_klucza',
            'ismaliciousCache'     => null,
            'ismaliciousKomunikat' => null,
            'spamratsTryb'         => 'brak_klucza',
            'spamratsCache'        => null,
            'spamratsKomunikat'    => null,
            'dnsblTryb'            => 'dns',
            'dnsblCache'           => null,
            'dnsblKomunikat'       => null,
            'speedtestCidr'        => '91.231.80.0/24',
            'speedtestLiczba24'    => 8,
            'flashOk'            => null,
            'flashBlad'          => null,
            'revDnsWlaczone'     => false,
            'revDns'             => null,
        ]);

        $this->assertStringNotContainsString('id="panel-speedtest"', $html);
        $this->assertStringContainsString('8 testów w Twoim /24', $html);
        $this->assertStringContainsString('91.231.80.0/24', $html);
        $this->assertStringContainsString('dashboard/speedtest', $html);
        $this->assertStringNotContainsString('st-1001', $html);
        $this->assertStringNotContainsString('Sprawdź Speedtest', $html);
    }

    public function testWidokUkrywaNotkeSpeedtestGdyBrakDanych(): void
    {
        $html = view('ip/show', [
            'title'              => 'IP 198.51.100.10 — MCS',
            'navAktywny'         => 'ip',
            'ipNav'              => '198.51.100.10',
            'szukajIp'           => '198.51.100.10',
            'ip'                 => '198.51.100.10',
            'blad'               => null,
            'statusIp'           => [
                'max_waga'         => '',
                'liczba_problemow' => 0,
                'top_tytuly'       => [],
            ],
            'problemy'           => [],
            'zrodla'             => [],
            'filtrZrodlo'        => null,
            'asMatchWlaczone'    => false,
            'naszeIp'            => false,
            'email'              => 'op@example.com',
            'rola'               => 'operator',
            'lmsTryb'            => 'brak_konfiguracji',
            'lmsCache'           => null,
            'lmsKomunikat'       => null,
            'abuseipdbTryb'      => 'brak_klucza',
            'abuseipdbCache'     => null,
            'abuseipdbKomunikat' => null,
            'ismaliciousTryb'      => 'brak_klucza',
            'ismaliciousCache'     => null,
            'ismaliciousKomunikat' => null,
            'spamratsTryb'         => 'brak_klucza',
            'spamratsCache'        => null,
            'spamratsKomunikat'    => null,
            'dnsblTryb'            => 'dns',
            'dnsblCache'           => null,
            'dnsblKomunikat'       => null,
            'speedtestCidr'        => null,
            'speedtestLiczba24'    => 0,
            'flashOk'            => null,
            'flashBlad'          => null,
            'revDnsWlaczone'     => false,
            'revDns'             => null,
        ]);

        $this->assertStringNotContainsString('id="panel-speedtest"', $html);
        $this->assertStringNotContainsString('testy w Twoim /24', $html);
    }

    public function testWidokPokazujeRevDnsGdyWlaczone(): void
    {
        $html = view('ip/show', [
            'title'              => 'IP 198.51.100.10 — MCS',
            'navAktywny'         => 'ip',
            'ipNav'              => '198.51.100.10',
            'szukajIp'           => '198.51.100.10',
            'ip'                 => '198.51.100.10',
            'blad'               => null,
            'statusIp'           => [
                'max_waga'         => '',
                'liczba_problemow' => 0,
                'top_tytuly'       => [],
            ],
            'problemy'           => [],
            'zrodla'             => [],
            'filtrZrodlo'        => null,
            'asMatchWlaczone'    => false,
            'naszeIp'            => false,
            'email'              => 'op@example.com',
            'rola'               => 'operator',
            'lmsTryb'            => 'brak_konfiguracji',
            'lmsCache'           => null,
            'lmsKomunikat'       => null,
            'abuseipdbTryb'      => 'brak_klucza',
            'abuseipdbCache'     => null,
            'abuseipdbKomunikat' => null,
            'ismaliciousTryb'      => 'brak_klucza',
            'ismaliciousCache'     => null,
            'ismaliciousKomunikat' => null,
            'spamratsTryb'         => 'brak_klucza',
            'spamratsCache'        => null,
            'spamratsKomunikat'    => null,
            'dnsblTryb'            => 'dns',
            'dnsblCache'           => null,
            'dnsblKomunikat'       => null,
            'flashOk'            => null,
            'flashBlad'          => null,
            'revDnsWlaczone'     => true,
            'revDns'             => 'ptr.example.test',
        ]);

        $this->assertStringContainsString('ptr.example.test', $html);
        $this->assertStringContainsString('reverse DNS', $html);
    }

    public function testWidokUkrywaRevDnsGdyWylaczone(): void
    {
        $html = view('ip/show', [
            'title'              => 'IP 198.51.100.10 — MCS',
            'navAktywny'         => 'ip',
            'ipNav'              => '198.51.100.10',
            'szukajIp'           => '198.51.100.10',
            'ip'                 => '198.51.100.10',
            'blad'               => null,
            'statusIp'           => [
                'max_waga'         => '',
                'liczba_problemow' => 0,
                'top_tytuly'       => [],
            ],
            'problemy'           => [],
            'zrodla'             => [],
            'filtrZrodlo'        => null,
            'asMatchWlaczone'    => false,
            'naszeIp'            => false,
            'email'              => 'op@example.com',
            'rola'               => 'operator',
            'lmsTryb'            => 'brak_konfiguracji',
            'lmsCache'           => null,
            'lmsKomunikat'       => null,
            'abuseipdbTryb'      => 'brak_klucza',
            'abuseipdbCache'     => null,
            'abuseipdbKomunikat' => null,
            'ismaliciousTryb'      => 'brak_klucza',
            'ismaliciousCache'     => null,
            'ismaliciousKomunikat' => null,
            'spamratsTryb'         => 'brak_klucza',
            'spamratsCache'        => null,
            'spamratsKomunikat'    => null,
            'dnsblTryb'            => 'dns',
            'dnsblCache'           => null,
            'dnsblKomunikat'       => null,
            'flashOk'            => null,
            'flashBlad'          => null,
            'revDnsWlaczone'     => false,
            'revDns'             => 'ptr.example.test',
        ]);

        $this->assertStringNotContainsString('ptr.example.test', $html);
    }

    public function testStatusPokazujeSkrotLmsGdyTrafiony(): void
    {
        $html = view('ip/show', [
            'title'           => 'IP 198.51.100.10 — MCS',
            'navAktywny'      => 'ip',
            'ipNav'           => '198.51.100.10',
            'szukajIp'        => '198.51.100.10',
            'ip'              => '198.51.100.10',
            'blad'            => null,
            'statusIp'        => [
                'max_waga'         => 'high',
                'liczba_problemow' => 1,
                'top_tytuly'       => ['Test'],
            ],
            'problemy'        => [
                [
                    'klucz'              => 'k1',
                    'zrodlo'             => 'shadowserver',
                    'zrodlo_etykieta'    => 'Shadowserver',
                    'waga'               => 'high',
                    'kategoria'          => 'exposure',
                    'kategoria_etykieta' => 'ekspozycja',
                    'tytul'              => 'Test',
                    'port_tekst'         => '22/tcp',
                    'liczba_zdarzen'     => 2,
                    'pierwsze_widziane'  => '2026-09-01 10:00:00',
                    'ostatnio_widziane'  => '2026-09-11 10:00:00',
                    'timeline'           => [
                        ['dzien' => '2026-09-11', 'aktywny' => true],
                        ['dzien' => '2026-09-12', 'aktywny' => false],
                    ],
                    'czlonkowie'         => [
                        [
                            'id'                => 42,
                            'waga'              => 'high',
                            'ostatnio_widziane' => '2026-09-11 10:00:00',
                            'url'               => 'http://example.test/ip/198.51.100.10/incydent/42',
                        ],
                    ],
                ],
            ],
            'zrodla'             => ['shadowserver'],
            'filtrZrodlo'        => null,
            'asMatchWlaczone'    => false,
            'naszeIp'            => false,
            'email'              => 'op@example.com',
            'rola'               => 'operator',
            'abuseipdbTryb'      => 'brak_klucza',
            'abuseipdbCache'     => null,
            'abuseipdbKomunikat' => 'Brak mcs.abuseipdb.apiKey — ustaw w .env albo fixturePath.',
            'ismaliciousTryb'      => 'brak_klucza',
            'ismaliciousCache'     => null,
            'ismaliciousKomunikat' => null,
            'spamratsTryb'         => 'brak_klucza',
            'spamratsCache'        => null,
            'spamratsKomunikat'    => null,
            'dnsblTryb'            => 'dns',
            'dnsblCache'           => null,
            'dnsblKomunikat'       => null,
            'lmsTryb'      => 'cache',
            'lmsCache'     => [
                'trafiony'     => true,
                'komputer'     => 'Host-ABC',
                'abonent'      => 'Jan Kowalski',
                'node_id'      => 9910,
                'customer_id'  => 100,
                'lms_url'      => 'http://lms.test/node/9910',
            ],
            'lmsKomunikat' => null,
            'flashOk'            => null,
            'flashBlad'          => null,
            'revDnsWlaczone'     => false,
            'revDns'             => null,
        ]);

        $this->assertStringContainsString('Host-ABC', $html);
        $this->assertMatchesRegularExpression(
            '/Status IP[\s\S]*id="status-lms-skrot"[\s\S]*Host-ABC/',
            $html,
        );
    }

    public function testDnsblBladZapytaniaNieJestCzysty(): void
    {
        $html = view('ip/show', [
            'title'              => 'IP 198.51.100.10 — MCS',
            'navAktywny'         => 'ip',
            'ipNav'              => '198.51.100.10',
            'szukajIp'           => '198.51.100.10',
            'ip'                 => '198.51.100.10',
            'blad'               => null,
            'statusIp'           => ['max_waga' => '', 'liczba_problemow' => 0, 'top_tytuly' => []],
            'problemy'           => [],
            'zrodla'             => [],
            'filtrZrodlo'        => null,
            'asMatchWlaczone'    => false,
            'naszeIp'            => false,
            'email'              => 'op@example.com',
            'rola'               => 'operator',
            'lmsTryb'            => 'brak_konfiguracji',
            'lmsCache'           => null,
            'lmsKomunikat'       => null,
            'abuseipdbTryb'      => 'brak_klucza',
            'abuseipdbCache'     => null,
            'abuseipdbKomunikat' => null,
            'ismaliciousTryb'      => 'brak_klucza',
            'ismaliciousCache'     => null,
            'ismaliciousKomunikat' => null,
            'spamratsTryb'         => 'brak_klucza',
            'spamratsCache'        => null,
            'spamratsKomunikat'    => null,
            'dnsblTryb'            => 'cache',
            'dnsblCache'           => [
                'ip'         => '198.51.100.10',
                'listed_any' => false,
                'listy'      => [
                    'spamcop' => ['listed' => false, 'kody' => [], 'error' => 'błąd DNS'],
                    'dronebl' => ['listed' => false, 'kody' => [], 'error' => ''],
                ],
                'checked_at'    => '2026-10-14 10:00:00+00',
                'zrodlo_danych' => 'dns',
            ],
            'dnsblKomunikat'     => null,
            'flashOk'            => null,
            'flashBlad'          => null,
            'revDnsWlaczone'     => false,
            'revDns'             => null,
        ]);

        $this->assertStringContainsString('nieznane (błąd zapytania: spamcop)', $html);
        $this->assertStringContainsString('spamcop (błąd)', $html);
        $this->assertStringNotContainsString('id="dnsbl-listed">nie<', $html);
    }

    /**
     * #435: kontenery wyników enrichment mają aria-live="polite"; skrypt ustawia stan
     * ładowania (aria-busy + spinner + „Sprawdzam…”); przyciski disabled mają title.
     */
    public function testPaneleEnrichmentAriaLiveStanLadowaniaITitleNaDisabled(): void
    {
        $html = view('ip/show', $this->daneWidokuIp([
            'abuseipdbTryb'      => 'brak_klucza',
            'abuseipdbKomunikat' => 'Brak mcs.abuseipdb.apiKey — ustaw w .env albo fixturePath.',
        ]));

        // aria-live="polite" na wszystkich sześciu kontenerach wyników
        $this->assertSame(6, substr_count($html, 'aria-live="polite"'));
        foreach (['abuseipdb', 'ismalicious', 'greynoise', 'spamrats', 'dnsbl', 'lms'] as $panel) {
            $this->assertMatchesRegularExpression(
                '/<div id="' . $panel . '-wynik"[^>]*aria-live="polite"/',
                $html,
            );
        }

        // stan ładowania: helper + aria-busy + spinner + tekst, podpięty pod wszystkie 6 POST
        $this->assertStringContainsString('window.mcsPrzyciskZajety', $html);
        $this->assertStringContainsString("btn.setAttribute('aria-busy', 'true')", $html);
        $this->assertStringContainsString('spinner-border spinner-border-sm', $html);
        $this->assertStringContainsString('Sprawdzam…', $html);
        $this->assertSame(6, substr_count($html, 'mcsPrzyciskZajety(btn, true)'));
        $this->assertSame(6, substr_count($html, 'mcsPrzyciskZajety(btn, false)'));

        // przyciski disabled (brak_klucza AbuseIPDB) mają title z wyjaśnieniem
        $this->assertMatchesRegularExpression(
            '/<button[^>]*id="abuseipdb-sprawdz"[^>]*disabled title="Brak klucza mcs\.abuseipdb\.apiKey[^"]*"/s',
            $html,
        );
        $this->assertMatchesRegularExpression(
            '/<button[^>]*id="abuseipdb-force"[^>]*disabled title="[^"]+"/s',
            $html,
        );
        // alerty błędów są w drzewie DOM (role="alert"), nie tylko doklejane JS-em
        $this->assertMatchesRegularExpression(
            '/<div class="alert alert-danger[^"]*" id="abuseipdb-komunikat" role="alert"/',
            $html,
        );
    }

    /**
     * #435: dla IPv6 przyciski SpamRats/DNSBL są disabled z title (nie samo disabled).
     */
    public function testPrzyciskiSpamratsIDnsblDisabledDlaIpv6MajaTitle(): void
    {
        $html = view('ip/show', $this->daneWidokuIp([
            'title'    => 'IP 2001:db8::1 — MCS',
            'ipNav'    => '2001:db8::1',
            'szukajIp' => '2001:db8::1',
            'ip'       => '2001:db8::1',
        ]));

        $this->assertMatchesRegularExpression(
            '/<button[^>]*id="spamrats-sprawdz"[^>]*disabled title="SpamRats DNSBL obsługuje tylko IPv4"/s',
            $html,
        );
        $this->assertMatchesRegularExpression(
            '/<button[^>]*id="dnsbl-sprawdz"[^>]*disabled title="DNSBL w MCS obsługuje tylko IPv4"/s',
            $html,
        );
    }

    /**
     * #436: bilans <div>/</div> w źródle widoku karty IP wynosi 0.
     */
    public function testZrodloWidokuIpMaZbilansowaneDivy(): void
    {
        $zrodlo = (string) file_get_contents(APPPATH . 'Views/ip/show.php');

        $this->assertSame(substr_count($zrodlo, '<div'), substr_count($zrodlo, '</div>'));
    }

    /**
     * @param array<string, mixed> $nadpisz
     *
     * @return array<string, mixed>
     */
    private function daneWidokuIp(array $nadpisz = []): array
    {
        return array_merge([
            'title'           => 'IP 198.51.100.10 — MCS',
            'navAktywny'      => 'ip',
            'ipNav'           => '198.51.100.10',
            'szukajIp'        => '198.51.100.10',
            'ip'              => '198.51.100.10',
            'blad'            => null,
            'statusIp'        => ['max_waga' => '', 'liczba_problemow' => 0, 'top_tytuly' => []],
            'problemy'        => [],
            'zrodla'          => [],
            'filtrZrodlo'     => null,
            'asMatchWlaczone' => false,
            'naszeIp'         => false,
            'email'           => 'op@example.com',
            'rola'            => 'operator',
            'lmsTryb'         => 'cache',
            'lmsCache'        => null,
            'lmsKomunikat'    => null,
            'abuseipdbTryb'      => 'cache',
            'abuseipdbCache'     => null,
            'abuseipdbKomunikat' => null,
            'ismaliciousTryb'      => 'cache',
            'ismaliciousCache'     => null,
            'ismaliciousKomunikat' => null,
            'greynoiseTryb'        => 'cache',
            'greynoiseCache'       => null,
            'greynoiseKomunikat'   => null,
            'spamratsTryb'         => 'cache',
            'spamratsCache'        => null,
            'spamratsKomunikat'    => null,
            'dnsblTryb'            => 'dns',
            'dnsblCache'           => null,
            'dnsblKomunikat'       => null,
            'flashOk'            => null,
            'flashBlad'          => null,
            'revDnsWlaczone'     => false,
            'revDns'             => null,
        ], $nadpisz);
    }
}
