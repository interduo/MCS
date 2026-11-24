<?php

namespace App\Libraries;

use RuntimeException;

/**
 * Import odrzucony przez limit (rozmiar pliku/załącznika albo liczba wierszy — issue #453).
 *
 * Komunikat jest zaufany dla UI (BladUi::KLASY_ZAUFANE): zawiera tylko nasz tekst,
 * nazwę pliku/załącznika i rozmiary — bez ścieżek serwera czy danych feedu.
 */
class PrzekroczonyLimitImportu extends RuntimeException
{
}
