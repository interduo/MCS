<?php

use CodeIgniter\Test\CIUnitTestCase;

/**
 * Issue #455: Bootstrap 5.3.3 + bootstrap-icons 1.11.3 serwowane lokalnie z public/vendor/
 * (self-host) zamiast z cdn.jsdelivr.net — brak requestów do domen zewnętrznych przy renderze.
 *
 * @internal
 */
final class LayoutVendorTest extends CIUnitTestCase
{
    private const LAYOUT = APPPATH . 'Views/layouts/mcs.php';

    private const LOGIN = APPPATH . 'Views/auth/login.php';

    private const PUBLIC = FCPATH;

    /** Oczekiwane SHA-384 (byłe atrybuty SRI z CDN) — pilnują dokładnie tych samych wersji. */
    private const SRI = [
        'vendor/bootstrap-5.3.3/dist/css/bootstrap.min.css'        => 'sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH',
        'vendor/bootstrap-5.3.3/dist/js/bootstrap.bundle.min.js'   => 'sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz',
        'vendor/bootstrap-icons-1.11.3/font/bootstrap-icons.min.css' => 'sha384-XGjxtQfXaH2tnPFa9x+ruJTuLE3Aa6LhHSWRr1XeTyhezb4abCG4ccI5AkVDxqC+',
    ];

    public function testLayoutIloginNieOdwolujaSieDoCdn(): void
    {
        foreach ([self::LAYOUT, self::LOGIN] as $plik) {
            $src = (string) file_get_contents($plik);
            $this->assertStringNotContainsString('cdn.jsdelivr.net', $src, $plik);
            $this->assertStringNotContainsString('integrity=', $src, $plik . ' — lokalne pliki nie potrzebują SRI');
            $this->assertStringContainsString("base_url('vendor/bootstrap-5.3.3/dist/css/bootstrap.min.css')", $src);
            $this->assertStringContainsString("base_url('vendor/bootstrap-icons-1.11.3/font/bootstrap-icons.min.css')", $src);
            $this->assertStringContainsString("base_url('vendor/bootstrap-5.3.3/dist/js/bootstrap.bundle.min.js')", $src);
        }
    }

    public function testPlikiVendorIstniejaISaZgodneZBylymiHashamiSRI(): void
    {
        foreach (self::SRI as $rel => $integrity) {
            $path = self::PUBLIC . $rel;
            $this->assertFileExists($path, $rel);
            $hash = 'sha384-' . base64_encode((string) openssl_digest((string) file_get_contents($path), 'sha384', true));
            $this->assertSame($integrity, $hash, $rel . ' — zawartość musi być identyczna jak z CDN (ta sama wersja)');
        }
        // fonty bootstrap-icons (ścieżki względne fonts/… z CSS)
        $this->assertFileExists(self::PUBLIC . 'vendor/bootstrap-icons-1.11.3/font/fonts/bootstrap-icons.woff2');
        $this->assertFileExists(self::PUBLIC . 'vendor/bootstrap-icons-1.11.3/font/fonts/bootstrap-icons.woff');
    }

    public function testVendorMaDlugieCacheWhtaccess(): void
    {
        $ht = (string) file_get_contents(self::PUBLIC . 'vendor/.htaccess');
        $this->assertStringContainsString('max-age=31536000', $ht);
        $this->assertStringContainsString('immutable', $ht);
    }
}
