<?php

use CodeIgniter\Test\CIUnitTestCase;
use McsPluginy\mojecert\ApiClient;

/**
 * Issue #471: retry przejściowych HTTP 5xx / błędów sieciowych w ApiClient::request()
 * (throttle 429 już był retryowany). 4xx (poza 429) bez retry.
 *
 * @internal
 */
final class MojecertApiClientRetryTest extends CIUnitTestCase
{
    /**
     * @return array{ok:bool,http_code:int,data:mixed,error:string,raw_body:string}
     */
    private function odpowiedz(int $kod, string $blad = ''): array
    {
        return [
            'ok'        => $kod >= 200 && $kod < 300,
            'http_code' => $kod,
            'data'      => null,
            'error'     => $blad,
            'raw_body'  => '',
        ];
    }

    public function testRetry5xxPotemOk(): void
    {
        $wywolania = 0;
        $klient    = new ApiClient('https://example.test', 'token', function () use (&$wywolania) {
            ++$wywolania;

            return $wywolania === 1
                ? $this->odpowiedz(500)
                : ['ok' => true, 'http_code' => 200, 'data' => ['ok' => 1], 'error' => '', 'raw_body' => '{"ok":1}'];
        });

        $r = $klient->request('GET', '/api/v1/organizations/');

        $this->assertTrue($r['ok']);
        $this->assertSame(2, $wywolania);
    }

    public function testRetry5xxMaksymalnieDwieExtraProby(): void
    {
        $wywolania = 0;
        $klient    = new ApiClient('https://example.test', 'token', function () use (&$wywolania) {
            ++$wywolania;

            return $this->odpowiedz(500);
        });

        $r = $klient->request('GET', '/api/v1/organizations/');

        $this->assertFalse($r['ok']);
        $this->assertSame(500, $r['http_code']);
        $this->assertSame(3, $wywolania, '1 próba + 2 retry 5xx');
    }

    public function testBladSieciowyJestRetryowany(): void
    {
        $wywolania = 0;
        $klient    = new ApiClient('https://example.test', 'token', function () use (&$wywolania) {
            ++$wywolania;

            return $wywolania === 1
                ? $this->odpowiedz(0, 'Network error: timeout')
                : ['ok' => true, 'http_code' => 200, 'data' => [], 'error' => '', 'raw_body' => '[]'];
        });

        $r = $klient->request('GET', '/api/v1/organizations/');

        $this->assertTrue($r['ok']);
        $this->assertSame(2, $wywolania);
    }

    public function testBlad4xxNieJestRetryowany(): void
    {
        $wywolania = 0;
        $klient    = new ApiClient('https://example.test', 'token', function () use (&$wywolania) {
            ++$wywolania;

            return $this->odpowiedz(404);
        });

        $r = $klient->request('GET', '/api/v1/organizations/');

        $this->assertFalse($r['ok']);
        $this->assertSame(1, $wywolania, '404 failuje od razu');
    }
}
