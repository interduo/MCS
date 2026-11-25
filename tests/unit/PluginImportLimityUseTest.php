<?php

use CodeIgniter\Test\CIUnitTestCase;

/**
 * Issue #470: wtyczki w namespace'ach McsPluginy\{zrodlo} używają ImportLimity /
 * PrzekroczonyLimitImportu z App\Libraries — bez `use` PHP szuka klasy w namespace
 * wtyczki i import pada "Class not found" (tak było na prod dla cert_n6).
 *
 * @internal
 */
final class PluginImportLimityUseTest extends CIUnitTestCase
{
    public function testKazdeUzycieImportLimityMaUseAppLibraries(): void
    {
        $braki = [];
        foreach (glob(ROOTPATH . 'plugins/*/*.php') ?: [] as $plik) {
            $src = (string) file_get_contents($plik);
            foreach (['ImportLimity', 'PrzekroczonyLimitImportu'] as $klasa) {
                $uzywa = str_contains($src, $klasa . '::') || str_contains($src, 'catch (' . $klasa);
                $maUse = str_contains($src, 'use App\\Libraries\\' . $klasa . ';');
                if ($uzywa && ! $maUse) {
                    $braki[] = str_replace(ROOTPATH, '', $plik) . " → brak use App\\Libraries\\{$klasa};";
                }
            }
        }

        $this->assertSame([], $braki, "Pliki bez use:\n" . implode("\n", $braki));
    }
}
