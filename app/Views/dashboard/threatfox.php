<?= $this->extend('layouts/mcs') ?>
<?= $this->section('content') ?>

<?php
$d         = is_array($d ?? null) ? $d : [];
$stan      = is_array($d['stan'] ?? null) ? $d['stan'] : [];
$trafienia = is_array($d['trafienia'] ?? null) ? $d['trafienia'] : [];
$ioc       = is_array($d['ioc'] ?? null) ? $d['ioc'] : [];
$historia  = is_array($d['historia'] ?? null) ? $d['historia'] : [];
$blad      = $blad ?? null;
$wynik     = (string) ($stan['wynik'] ?? 'brak');
$okAt      = \App\Libraries\CzasUi::dataGodzina((string) ($stan['ostatnie_ok_at'] ?? ''));
$nastepne  = \App\Libraries\CzasUi::dataGodzina((string) ($stan['nastepne_po'] ?? ''));
$bledy     = (int) ($stan['bledy_z_rzedu'] ?? 0);
$rekordy   = (int) ($stan['rekordy'] ?? 0);
$healthOk  = $d['health_ok'] ?? null;
$healthMsg = (string) ($d['health_msg'] ?? '');
$fmtLiczba = static fn (int $n): string => number_format($n, 0, ',', "\u{00A0}");
$podsumowanie = null;
if (preg_match(
    '/^ThreatFox (?:czysto|TRAFIENIE): ioc=(\\d+) \\(z ip=(\\d+)\\), trafienia=(\\d+) \\(nowe=(\\d+), zdjęte=(\\d+)\\), prefiksów=(\\d+), dni=(\\d+)$/u',
    $healthMsg,
    $mh,
)) {
    $podsumowanie = [
        ['IOC (wszystkie)', $fmtLiczba((int) $mh[1]), ''],
        ['IOC z IP', $fmtLiczba((int) $mh[2]), ''],
        ['Trafienia', $fmtLiczba((int) $mh[3]), (int) $mh[3] > 0 ? 'text-danger' : ''],
        ['Nowe', $fmtLiczba((int) $mh[4]), (int) $mh[4] > 0 ? 'text-danger' : ''],
        ['Zdjęte', $fmtLiczba((int) $mh[5]), (int) $mh[5] > 0 ? 'text-success' : ''],
        ['Prefiksy', $fmtLiczba((int) $mh[6]), ''],
        ['Okno dni', $mh[7], ''],
    ];
}
$aktywne   = array_values(array_filter($trafienia, static fn ($t) => \App\Models\PluginModel::jakoBool($t['aktywne'] ?? false)));
[$wynikKlasa, $wynikTekst] = match ($wynik) {
    'czysto'    => ['text-bg-success', 'czysto'],
    'trafienie' => ['text-bg-danger', 'TRAFIENIE (' . count($aktywne) . ')'],
    default     => ['text-bg-secondary', 'brak udanego pobrania'],
};
?>

<div class="mb-4">
    <nav aria-label="Okruszki" class="mb-2">
        <ol class="breadcrumb mb-0 small">
            <li class="breadcrumb-item"><a href="<?= site_url('/') ?>">Przegląd</a></li>
            <li class="breadcrumb-item active" aria-current="page">ThreatFox IOC</li>
        </ol>
    </nav>
    <h1 class="h3 mb-1">ThreatFox IOC <span class="badge <?= $wynikKlasa ?> align-middle fs-6" id="threatfox-wynik"><?= esc($wynikTekst) ?></span></h1>
    <p class="text-body-secondary mb-0">
        Zbiorcze IOC abuse.ch ThreatFox (get_iocs, ostatnie 1–7 dni) pobierane cyklicznie i dopasowane lokalnie do prefiksów AS
        (<a href="<?= site_url('admin/ustawienia') ?>">as198050_cidry</a>). Tylko ioc_type <code>ip</code> / <code>ip:port</code>
        dają trafienia — trafienie tworzy incydent (źródło ThreatFox IOC).
    </p>
</div>

<?php if (! empty($blad)): ?>
    <div class="alert alert-danger border-0 shadow-sm rounded-3" role="alert"><?= esc((string) $blad) ?></div>
<?php endif; ?>

<div class="card border-0 shadow-sm rounded-3 mb-4" id="threatfox-karta">
    <div class="card-body">
        <div class="row g-3">
            <div class="col-sm-6 col-lg-3">
                <div class="text-body-secondary small mb-1">Status wtyczki</div>
                <span class="badge <?= $healthOk === true ? 'text-bg-success' : ($healthOk === false ? 'text-bg-danger' : 'text-bg-secondary') ?>">
                    <?= $healthOk === true ? 'OK' : ($healthOk === false ? 'błąd' : 'brak przebiegu') ?>
                </span>
                <?php if ($healthMsg !== '' && $podsumowanie === null): ?><div class="small text-body-secondary mt-1 text-break"><?= esc($healthMsg) ?></div><?php endif; ?>
            </div>
            <div class="col-sm-6 col-lg-3">
                <div class="text-body-secondary small mb-1">Ostatnie udane pobranie</div>
                <div class="fw-semibold"><?= $okAt !== '' ? esc($okAt) : 'jeszcze nie było' ?></div>
            </div>
            <div class="col-sm-6 col-lg-3">
                <div class="text-body-secondary small mb-1">IOC w ostatnim imporcie</div>
                <div class="fw-semibold"><?= $rekordy > 0 ? $fmtLiczba($rekordy) : '—' ?></div>
            </div>
            <div class="col-sm-6 col-lg-3">
                <div class="text-body-secondary small mb-1">Następne pobranie</div>
                <div class="fw-semibold"><?= $nastepne !== '' ? esc($nastepne) : 'przy najbliższym cronie' ?></div>
                <?php if ($bledy > 0): ?>
                    <div class="small text-danger">błędy z rzędu: <?= $bledy ?> — <?= esc((string) ($stan['ostatni_blad'] ?? '')) ?></div>
                <?php endif; ?>
            </div>
        </div>
        <?php if ($podsumowanie !== null): ?>
            <div class="row row-cols-2 row-cols-md-4 g-3 mt-1 pt-3 border-top" id="threatfox-podsumowanie">
                <?php foreach ($podsumowanie as [$etykieta, $wartosc, $klasa]): ?>
                    <div class="col">
                        <div class="text-body-secondary small mb-1"><?= esc($etykieta) ?></div>
                        <div class="fw-semibold <?= esc($klasa, 'attr') ?>"><?= esc($wartosc) ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<h2 class="h5">Trafienia</h2>
<div class="card border-0 shadow-sm rounded-3 mb-4"><div class="card-body p-0"><div class="table-responsive">
    <table class="table table-sm align-middle mb-0">
        <thead class="table-light"><tr><th class="ps-3">Stan</th><th>IOC</th><th>IP:port</th><th>Threat type</th><th>Kategoria</th><th>Nasz prefiks</th><th>Od</th><th class="pe-3">Ostatnio / zdjęte</th></tr></thead>
        <tbody>
        <?php if ($trafienia === []): ?>
            <tr><td colspan="8" class="ps-3 text-body-secondary">Brak trafień<?= $wynik === 'czysto' ? ' — żaden IOC typu ip/ip:port nie należy do naszych prefiksów.' : '.' ?></td></tr>
        <?php endif; ?>
        <?php foreach ($trafienia as $t): ?>
            <?php $akt = \App\Models\PluginModel::jakoBool($t['aktywne'] ?? false); ?>
            <?php $ipPort = (string) $t['ip'] . ($t['port'] !== null && $t['port'] !== '' ? ':' . (int) $t['port'] : ''); ?>
            <tr>
                <td class="ps-3"><span class="badge <?= $akt ? 'text-bg-danger' : 'text-bg-secondary' ?>"><?= $akt ? 'aktywne' : 'zdjęte' ?></span></td>
                <td class="font-monospace text-break"><?= esc((string) $t['ioc']) ?></td>
                <td class="font-monospace"><?= esc($ipPort) ?></td>
                <td class="small"><?= esc((string) ($t['threat_type'] ?? '')) ?></td>
                <td><span class="badge text-bg-light"><?= esc((string) ($t['kategoria'] ?? '')) ?></span></td>
                <td class="font-monospace">
                    <?php if (! empty($t['incydent_id'])): ?>
                        <a href="<?= site_url('ip/' . rawurlencode((string) $t['ip']) . '/incydent/' . (int) $t['incydent_id']) ?>"><?= esc((string) $t['nasz_prefiks']) ?></a>
                    <?php else: ?>
                        <?= esc((string) $t['nasz_prefiks']) ?>
                    <?php endif; ?>
                </td>
                <td class="text-nowrap small"><?= esc(\App\Libraries\CzasUi::dataGodzina((string) ($t['pierwsze_at'] ?? ''))) ?></td>
                <td class="pe-3 text-nowrap small"><?= esc(\App\Libraries\CzasUi::dataGodzina((string) ($akt ? ($t['ostatnie_at'] ?? '') : ($t['zdjete_at'] ?? '')))) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div></div></div>

<h2 class="h5">Ostatnie IOC (limit 100)</h2>
<div class="card border-0 shadow-sm rounded-3 mb-4"><div class="card-body p-0"><div class="table-responsive">
    <table class="table table-sm align-middle mb-0">
        <thead class="table-light"><tr><th class="ps-3">Stan</th><th>IOC</th><th>Typ IOC</th><th>IP</th><th>Threat type</th><th>Malware</th><th>Ufność</th><th class="pe-3">Ostatni import</th></tr></thead>
        <tbody>
        <?php if ($ioc === []): ?>
            <tr><td colspan="8" class="ps-3 text-body-secondary">Brak udanego pobrania.</td></tr>
        <?php endif; ?>
        <?php foreach ($ioc as $w): ?>
            <?php $akt = \App\Models\PluginModel::jakoBool($w['aktywne'] ?? false); ?>
            <tr>
                <td class="ps-3"><span class="badge <?= $akt ? 'text-bg-success' : 'text-bg-secondary' ?>"><?= $akt ? 'aktywne' : 'zdjęte' ?></span></td>
                <td class="font-monospace text-break"><?= esc((string) $w['ioc']) ?></td>
                <td><?= esc((string) ($w['ioc_type'] ?? '')) ?></td>
                <td class="font-monospace"><?= esc((string) ($w['ip'] ?? '')) ?></td>
                <td class="small"><?= esc((string) ($w['threat_type'] ?? '')) ?><?= (string) ($w['threat_type_desc'] ?? '') !== '' ? ' — ' . esc((string) $w['threat_type_desc']) : '' ?></td>
                <td class="small"><?= esc((string) ($w['malware_printable'] ?? $w['malware'] ?? '')) ?></td>
                <td><?= $w['confidence_level'] !== null && $w['confidence_level'] !== '' ? (int) $w['confidence_level'] . '/100' : '—' ?></td>
                <td class="pe-3 text-nowrap small"><?= esc(\App\Libraries\CzasUi::dataGodzina((string) ($w['ostatni_import_at'] ?? ''))) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div></div></div>

<h2 class="h5">Historia</h2>
<div class="card border-0 shadow-sm rounded-3 mb-4"><div class="card-body p-0"><div class="table-responsive">
    <table class="table table-sm align-middle mb-0">
        <thead class="table-light"><tr><th class="ps-3">Czas</th><th>Zdarzenie</th><th>IOC</th><th class="pe-3">Opis</th></tr></thead>
        <tbody>
        <?php if ($historia === []): ?>
            <tr><td colspan="4" class="ps-3 text-body-secondary">Brak wpisów.</td></tr>
        <?php endif; ?>
        <?php foreach ($historia as $h): ?>
            <?php $z = (string) ($h['zdarzenie'] ?? ''); ?>
            <tr>
                <td class="ps-3 text-nowrap small"><?= esc(\App\Libraries\CzasUi::dataGodzina((string) ($h['at'] ?? ''))) ?></td>
                <td><span class="badge <?= match ($z) { 'nowe' => 'text-bg-danger', 'zdjete' => 'text-bg-success', 'blad' => 'text-bg-warning', default => 'text-bg-secondary' } ?>"><?= esc($z) ?></span></td>
                <td class="font-monospace small text-break"><?= esc((string) ($h['ioc'] ?? '')) ?></td>
                <td class="pe-3 small text-break"><?= esc((string) ($h['opis'] ?? '')) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div></div></div>

<p class="small text-body-secondary border-top pt-2" id="threatfox-atrybucja">
    Źródło: <a href="https://threatfox.abuse.ch/" rel="noopener noreferrer" target="_blank">ThreatFox</a>
    (<a href="https://threatfox-api.abuse.ch/api/v1/" rel="noopener noreferrer" target="_blank">threatfox-api.abuse.ch/api/v1/</a>,
    zapytanie <code>get_iocs</code>), <?= esc(\McsPluginy\threatfox\Plugin::ATRYBUCJA) ?>.
    Warunki: <a href="https://www.abuse.ch/faq/" rel="noopener noreferrer" target="_blank">abuse.ch FAQ</a>.
</p>

<?= $this->endSection() ?>
