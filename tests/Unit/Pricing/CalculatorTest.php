<?php

declare(strict_types=1);

namespace GKBS\Core\Tests\Unit\Pricing;

use GKBS\Core\Pricing\Calculator;
use PHPUnit\Framework\TestCase;

final class CalculatorTest extends TestCase
{
    public function test_compute_returns_zero_totals_for_empty_quote(): void
    {
        $result = Calculator::compute(['tarife' => []]);

        self::assertSame([], $result['tarife']);
        self::assertSame(0.0, $result['gesamt_monatlich']);
        self::assertSame(0.0, $result['gesamt_listenpreis']);
        self::assertSame(0, $result['gesamt_sims']);
        self::assertSame(0.0, $result['ersparnis_monatlich']);
        self::assertTrue($result['gleiche_laufzeit']);
        self::assertNull($result['combined']);
        self::assertSame([], $result['warnungen']);
    }

    public function test_compute_handles_single_tariff_with_new_fields(): void
    {
        // Listenpreis 100 EUR, 35% Rabatt → 65 EUR nach Rabatt.
        // 24 Zahlmonate × 65 = 1560, minus 100 Startguthaben = 1460.
        // 1460 / 24 Nutzmonate = 60.83 EUR Effektivpreis.
        $quote = [
            'tarife' => [
                [
                    'tarif'             => 'Business Premium',
                    'sims'              => 1,
                    'preis_num'         => 60.83,
                    'preis_regular_num' => 100.0,
                    'rabatt_prozent'    => 35,
                    'startguthaben'     => 100.0,
                    'gratis_monate'     => 0,
                    'laufzeit_monate'   => 24,
                    'laufzeit_max'      => 24,
                ],
            ],
        ];

        $result = Calculator::compute($quote);

        self::assertCount(1, $result['tarife']);
        $calc = $result['tarife'][0];
        self::assertSame('Business Premium', $calc['name']);
        self::assertSame(65.0, $calc['nach_rabatt']);
        self::assertSame(1560.0, $calc['total_zahlung']);
        self::assertSame(1460.0, $calc['gesamtkosten']);
        self::assertSame(60.83, $calc['effektivpreis']);
        self::assertSame(60.83, $calc['effektivpreis_daten']);
        self::assertTrue($calc['has_new_fields']);
        self::assertSame('', $calc['warnung']);
        self::assertSame(60.83, $result['gesamt_monatlich']);
        self::assertSame(100.0, $result['gesamt_listenpreis']);
    }

    public function test_compute_handles_multi_sim_tariff(): void
    {
        $quote = [
            'tarife' => [
                [
                    'tarif'             => 'Team-Tarif',
                    'sims'              => 3,
                    'preis_num'         => 20.0,
                    'preis_regular_num' => 30.0,
                    'rabatt_prozent'    => 33,
                    'gratis_monate'     => 0,
                    'laufzeit_monate'   => 24,
                    'laufzeit_max'      => 24,
                ],
            ],
        ];

        $result = Calculator::compute($quote);
        $calc   = $result['tarife'][0];

        self::assertSame(3, $calc['sims']);
        // 30 × 3 = 90 Listenpreis monatlich.
        self::assertSame(90.0, $result['gesamt_listenpreis']);
        // gesamt_monatlich = effektivpreis (final, gerundet) × 3.
        self::assertSame($calc['effektivpreis'] * 3, $result['gesamt_monatlich']);
        self::assertSame(3, $result['gesamt_sims']);
    }

    public function test_compute_combined_is_built_when_laufzeit_matches(): void
    {
        $quote = [
            'tarife' => [
                [
                    'tarif'             => 'Tarif A',
                    'sims'              => 1,
                    'preis_num'         => 25.0,
                    'preis_regular_num' => 50.0,
                    'rabatt_prozent'    => 50,
                    'laufzeit_monate'   => 24,
                    'laufzeit_max'      => 24,
                ],
                [
                    'tarif'             => 'Tarif B',
                    'sims'              => 2,
                    'preis_num'         => 15.0,
                    'preis_regular_num' => 30.0,
                    'rabatt_prozent'    => 50,
                    'laufzeit_monate'   => 24,
                    'laufzeit_max'      => 24,
                ],
            ],
        ];

        $result = Calculator::compute($quote);

        self::assertTrue($result['gleiche_laufzeit']);
        self::assertNotNull($result['combined']);
        self::assertSame(24, $result['combined']['nutzmonate']);
        self::assertSame(3, $result['combined']['total_sims']);
    }

    public function test_compute_combined_is_null_when_laufzeit_differs(): void
    {
        $quote = [
            'tarife' => [
                [
                    'tarif'             => 'Kurz',
                    'sims'              => 1,
                    'preis_regular_num' => 30.0,
                    'rabatt_prozent'    => 0,
                    'laufzeit_monate'   => 12,
                    'laufzeit_max'      => 12,
                ],
                [
                    'tarif'             => 'Lang',
                    'sims'              => 1,
                    'preis_regular_num' => 30.0,
                    'rabatt_prozent'    => 0,
                    'laufzeit_monate'   => 24,
                    'laufzeit_max'      => 24,
                ],
            ],
        ];

        $result = Calculator::compute($quote);

        self::assertFalse($result['gleiche_laufzeit']);
        self::assertNull($result['combined']);
    }

    public function test_compute_records_warning_when_effektivpreis_differs(): void
    {
        // Berechnet ~65 EUR (100 × 35% off, keine Gratis/SG), aber Daten sagen 50 → Diff 15 EUR.
        $quote = [
            'tarife' => [
                [
                    'tarif'             => 'Falsch-Preisig',
                    'sims'              => 1,
                    'preis_num'         => 50.0,
                    'preis_regular_num' => 100.0,
                    'rabatt_prozent'    => 35,
                    'laufzeit_monate'   => 24,
                    'laufzeit_max'      => 24,
                ],
            ],
        ];

        $result = Calculator::compute($quote);
        $calc   = $result['tarife'][0];

        self::assertNotSame('', $calc['warnung']);
        self::assertStringContainsString('Diff:', $calc['warnung']);
        // Fallback: API-Wert (50) gewinnt bei Abweichung.
        self::assertSame(50.0, $calc['effektivpreis']);
        self::assertCount(1, $result['warnungen']);
        self::assertTrue(Calculator::hasCriticalWarnings($result));
    }

    public function test_compute_no_warning_for_minor_rounding_diff(): void
    {
        // Genauer Wert: 100 × 0.65 = 65.0. Daten geben 65.01 → Diff 0.01 < 0.02.
        $quote = [
            'tarife' => [
                [
                    'tarif'             => 'Rundung',
                    'sims'              => 1,
                    'preis_num'         => 65.01,
                    'preis_regular_num' => 100.0,
                    'rabatt_prozent'    => 35,
                    'laufzeit_monate'   => 24,
                    'laufzeit_max'      => 24,
                ],
            ],
        ];

        $result = Calculator::compute($quote);
        $calc   = $result['tarife'][0];

        self::assertSame('', $calc['warnung']);
        self::assertFalse(Calculator::hasCriticalWarnings($result));
    }

    public function test_compute_falls_back_to_legacy_fields(): void
    {
        $quote = [
            'tarife' => [
                [
                    'tarif'             => 'Legacy',
                    'sims'              => 1,
                    'preis_num'         => 20.0,
                    'preis_regular_num' => 30.0,
                    'rabatt_num'        => 33,
                    'laufzeit'          => '24-24',
                ],
            ],
        ];

        $result = Calculator::compute($quote);
        $calc   = $result['tarife'][0];

        self::assertFalse($calc['has_new_fields']);
        self::assertSame(33, $calc['rabatt_pct']);
        self::assertSame(24, $calc['zahlmonate']);
        self::assertSame(24, $calc['nutzmonate']);
    }

    public function test_compute_parses_laufzeit_string_range(): void
    {
        $quote = [
            'tarife' => [
                [
                    'tarif'             => 'Spanne',
                    'sims'              => 1,
                    'preis_num'         => 10.0,
                    'preis_regular_num' => 15.0,
                    'rabatt_num'        => 33,
                    'laufzeit'          => '24-36',
                ],
            ],
        ];

        $result = Calculator::compute($quote);
        $calc   = $result['tarife'][0];

        self::assertSame(24, $calc['zahlmonate']);
        self::assertSame(36, $calc['nutzmonate']);
        self::assertSame(12, $calc['gratismonate']);
    }

    public function test_build_explanation_contains_key_values(): void
    {
        $calc = [
            'sims'             => 2,
            'rechnungsbetrag'  => 130.0,
            'grundpreis'       => 100.0,
            'rabatt_pct'       => 35,
            'nach_rabatt'      => 65.0,
            'gratismonate'     => 6,
            'startguthaben'    => 200.0,
            'effektivpreis'    => 48.75,
            'nutzmonate'       => 30,
            'cancel_notice'    => '3 Monate zum Laufzeitende',
        ];

        $text = Calculator::buildExplanation($calc);

        self::assertStringContainsString('130,00 €', $text);
        self::assertStringContainsString('für 2 SIM-Karten', $text);
        self::assertStringContainsString('ab dem 7. Monat', $text);
        self::assertStringContainsString('35% Rabatt', $text);
        self::assertStringContainsString('48,75 €', $text);
        self::assertStringContainsString('3 Monate zum Laufzeitende', $text);
        self::assertStringContainsString('19% MwSt.', $text);
    }

    public function test_build_combined_explanation_returns_empty_when_unequal_laufzeit(): void
    {
        self::assertSame('', Calculator::buildCombinedExplanation(['gleiche_laufzeit' => false]));
        self::assertSame('', Calculator::buildCombinedExplanation(['gleiche_laufzeit' => true, 'combined' => null]));
    }

    public function test_build_combined_explanation_renders_summary(): void
    {
        $result = [
            'gleiche_laufzeit' => true,
            'combined'         => [
                'total_grundpreise'   => 130.0,
                'total_nach_rabatt'   => 80.0,
                'total_startguthaben' => 100.0,
                'total_sims'          => 3,
                'zahlmonate'          => 24,
                'nutzmonate'          => 30,
                'total_zahlung'       => 1920.0,
                'gesamtkosten'        => 1820.0,
                'effektiv_gesamt'     => 60.67,
                'effektiv_pro_sim'    => 20.22,
            ],
        ];

        $text = Calculator::buildCombinedExplanation($result);

        self::assertStringContainsString('Effektivpreis-Berechnung über alle Tarife', $text);
        self::assertStringContainsString('60,67 €', $text);
        self::assertStringContainsString('3 SIM', $text);
    }

    public function test_compute_display_enriches_detail_fields(): void
    {
        $tarif = [
            'name'              => 'Solo-Tarif',
            'netz'              => 'Telekom',
            'preis_num'         => 25.0,
            'preis_regular_num' => 50.0,
            'rabatt_prozent'    => 50,
            'laufzeit_monate'   => 24,
            'laufzeit_max'      => 24,
            'cancel_notice'     => '1 Monat',
            'eu_detail'         => 'EU-Roaming inklusive',
            'daten'             => '20 GB',
            'daten_num'         => 20.0,
            'has_eu'            => true,
            'link'              => 'https://example.test/tarif',
        ];

        $calc = Calculator::computeDisplay($tarif);

        self::assertSame('Solo-Tarif', $calc['name']);
        self::assertSame('Telekom', $calc['netz']);
        self::assertSame(1, $calc['sims']);
        self::assertSame('EU-Roaming inklusive', $calc['eu_detail']);
        self::assertSame('20 GB', $calc['daten']);
        self::assertTrue($calc['has_eu']);
        self::assertSame('https://example.test/tarif', $calc['link']);
        // brutto = effektivpreis × 1.19, gerundet.
        self::assertSame(round($calc['effektivpreis'] * 1.19, 2), $calc['brutto']);
    }

    public function test_calc_provision_fixed_type_multiplies_by_sims(): void
    {
        $tarif = ['tarif' => 'Tarif X', 'preis_num' => 30.0];
        $rates = ['Tarif X' => 80.0];

        self::assertSame(160.0, Calculator::calcProvision($tarif, 2, 'fixed', $rates));
        self::assertSame(240.0, Calculator::calcProvision($tarif, 3, 'fixed', $rates));
    }

    public function test_calc_provision_percentage_applies_to_total_volume(): void
    {
        $tarif = ['tarif' => 'Tarif Y', 'preis_num' => 50.0];
        $rates = ['Tarif Y' => 10.0];

        // 50 × 2 SIM × 10% = 10.
        self::assertSame(10.0, Calculator::calcProvision($tarif, 2, 'percentage', $rates));
    }

    public function test_calc_provision_returns_zero_for_missing_rate(): void
    {
        $tarif = ['tarif' => 'Unbekannt'];
        self::assertSame(0.0, Calculator::calcProvision($tarif, 5, 'fixed', []));
    }
}
