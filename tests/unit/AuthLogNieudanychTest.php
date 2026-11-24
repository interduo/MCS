<?php

use App\Controllers\Auth;
use App\Libraries\UzytkownicySerwis;
use App\Models\UserModel;
use CodeIgniter\Config\Services;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\ControllerTestTrait;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\Mock\MockCache;
use CodeIgniter\Test\TestLogger;
use CodeIgniter\Throttle\Throttler;

/**
 * #430: ślad audytowy nieudanych prób logowania (ip + email, bez hasła)
 * oraz zmian kont w UzytkownicySerwis.
 *
 * @internal
 */
final class AuthLogNieudanychTest extends CIUnitTestCase
{
    use ControllerTestTrait;
    use DatabaseTestTrait;

    protected $migrate   = false;
    protected $refresh   = false;
    protected $namespace = '';

    private Throttler $throttler;

    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION        = [];
        $this->throttler = new Throttler(new MockCache());
        $this->throttler->setTestTime(1_800_000_000);
        Services::injectMock('throttler', $this->throttler);

        $tabela = $this->db->prefixTable('mcs_users');
        $this->db->query("DROP TABLE IF EXISTS {$tabela}");
        $this->db->query(
            "CREATE TABLE {$tabela} (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                email VARCHAR(255) NOT NULL UNIQUE,
                password_hash VARCHAR(255) NOT NULL,
                rola VARCHAR(20) NOT NULL,
                aktywny BOOLEAN NOT NULL DEFAULT 1,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )",
        );
    }

    protected function tearDown(): void
    {
        $tabela = $this->db->prefixTable('mcs_users');
        $this->db->query("DROP TABLE IF EXISTS {$tabela}");
        Services::resetSingle('throttler');
        parent::tearDown();
    }

    public function testNieudaneLogowanieLogujeWarningZIpIemailem(): void
    {
        model(UserModel::class)->insert([
            'email'         => 'jest@example.net',
            'password_hash' => password_hash('dobrehaslo1', PASSWORD_DEFAULT),
            'rola'          => 'operator',
            'aktywny'       => 1,
        ]);

        $this->zaloguj('jest@example.net', 'zlehaslo11');

        $this->assertSame('Nieprawidłowy e-mail lub hasło.', session()->getFlashdata('blad'));
        $this->assertLogContains('warning', 'MCS login nieudane');
        $this->assertLogContains('warning', 'ip=');
        $this->assertLogContains('warning', 'email=jest@example.net');
    }

    public function testNieudaneLogowanieNieistniejacegoKontaTezLoguje(): void
    {
        $this->zaloguj('brak@example.net', 'cokolwiek1');

        $this->assertSame('Nieprawidłowy e-mail lub hasło.', session()->getFlashdata('blad'));
        $this->assertLogContains('warning', 'MCS login nieudane');
        $this->assertLogContains('warning', 'email=brak@example.net');
    }

    public function testWLoguNieMaHasla(): void
    {
        model(UserModel::class)->insert([
            'email'         => 'jest@example.net',
            'password_hash' => password_hash('dobrehaslo1', PASSWORD_DEFAULT),
            'rola'          => 'operator',
            'aktywny'       => 1,
        ]);

        $this->zaloguj('jest@example.net', 'tajne-zle-haslo-987');

        foreach ($this->logi() as $wpis) {
            $this->assertStringNotContainsString('tajne-zle-haslo-987', $wpis['message']);
        }
    }

    public function testOdrzuceniePrzezLimitLogujeWarning(): void
    {
        model(UserModel::class)->insert([
            'email'         => 'jest@example.net',
            'password_hash' => password_hash('dobrehaslo1', PASSWORD_DEFAULT),
            'rola'          => 'operator',
            'aktywny'       => 1,
        ]);

        for ($i = 0; $i < \App\Libraries\LimitLogowania::PROBY; $i++) {
            $this->zaloguj('jest@example.net', 'zlehaslo11');
        }
        $this->zaloguj('jest@example.net', 'dobrehaslo1');

        $this->assertSame(Auth::KOMUNIKAT_LIMIT, session()->getFlashdata('blad'));
        $this->assertNull(session()->get('mcs_user_id'));
        $this->assertLogContains('warning', 'MCS login odrzucony przez limit');
        $this->assertLogContains('warning', 'email=jest@example.net');
    }

    public function testUdaneLogowanieLogujeInfo(): void
    {
        model(UserModel::class)->insert([
            'email'         => 'jest@example.net',
            'password_hash' => password_hash('dobrehaslo1', PASSWORD_DEFAULT),
            'rola'          => 'operator',
            'aktywny'       => 1,
        ]);

        $this->zaloguj('jest@example.net', 'dobrehaslo1');

        $this->assertNotNull(session()->get('mcs_user_id'));
        $this->assertLogContains('info', 'MCS login ok');
        $this->assertLogContains('info', 'email=jest@example.net');
    }

    public function testZmianaHaslaLogujeInfoBezHasla(): void
    {
        $serwis = new UzytkownicySerwis();
        $wynik  = $serwis->utworz('user@example.net', 'pierwsze-haslo', 'operator');
        $this->assertTrue($wynik['ok']);
        $id = (int) $wynik['id'];

        $wynik = $serwis->ustawHaslo($id, 'drugie-haslo-123');
        $this->assertTrue($wynik['ok']);

        $this->assertLogContains('info', 'MCS konto haslo zmienione');
        $this->assertLogContains('info', 'email=user@example.net');
        foreach ($this->logi() as $wpis) {
            $this->assertStringNotContainsString('drugie-haslo-123', $wpis['message']);
        }
    }

    public function testZmianaRoliIAktywnosciIUsuniecieLogujaInfo(): void
    {
        $serwis = new UzytkownicySerwis();
        $id     = (int) $serwis->utworz('user2@example.net', 'pierwsze-haslo', 'operator')['id'];

        $this->assertTrue($serwis->zmienRole($id, 'admin')['ok']);
        $this->assertLogContains('info', 'MCS konto rola zmieniona');
        $this->assertLogContains('info', 'rola=admin');

        $this->assertTrue($serwis->ustawAktywny($id, false)['ok']);
        $this->assertLogContains('info', 'MCS konto aktywny zmienione');
        $this->assertLogContains('info', 'aktywny=nie');

        $this->assertTrue($serwis->ustawAktywny($id, true)['ok']);
        $this->assertTrue($serwis->usun($id, 999)['ok']);
        $this->assertLogContains('info', 'MCS konto usuniete');
        $this->assertLogContains('info', 'przez_id=999');
    }

    /**
     * @return list<array{level: mixed, message: string, file: string|null}>
     */
    private function logi(): array
    {
        $prop = new ReflectionProperty(TestLogger::class, 'op_logs');
        $prop->setAccessible(true);

        return $prop->getValue();
    }

    private function zaloguj(string $email, string $haslo): void
    {
        $_SESSION = [];
        $request  = service('incomingrequest', null, false);
        $request->setMethod('post');
        $request->setGlobal('post', ['email' => $email, 'haslo' => $haslo]);

        $this->withRequest($request)->controller(Auth::class)->execute('loginSubmit');
    }
}
