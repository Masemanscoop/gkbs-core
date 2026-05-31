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

    public function test_rechnungsbetrag_is_cent_accurate_per_sim(): void
    {
        // Listenpreis 29,99 EUR, 10% Rabatt → 26,991 EUR roh.
        // Muss auf 26,99 EUR (abrechenbarer Cent-Betrag) gerundet werden.
        // Sonst zeigt das Angebot pro SIM 26,99, aber gesamt 5 × = 134,955 → 134,96
        // statt 5 × 26,99 = 134,95 (Ein-Cent-Drift im rechtsverbindlichen Dokument).
        $quote = [
            'tarife' => [
                [
                    'tarif'             => 'Drift-Tarif',
                    'sims'              => 5,
                    'preis_num'         => 26.99,
                    'preis_regular_num' => 29.99,
                    'rabatt_prozent'    => 10,
                    'gratis_monate'     => 0,
                    'laufzeit_monate'   => 24,
                    'laufzeit_max'      => 24,
                ],
            ],
        ];

        $result = Calculator::compute($quote);
        $calc   = $result['tarife'][0];

        // Monatlicher Rechnungsbetrag pro SIM ist cent-genau gerundet.
        self::assertSame(26.99, $calc['nach_rabatt']);
        self::assertSame(26.99, $calc['rechnungsbetrag_sim']);
        // Rabatt aus gerundetem Rechnungsbetrag: 29,99 - 26,99 = 3,00.
        self::assertSame(3.0, $calc['rabatt_betrag']);
        // Gesamt-Rechnungsbetrag = exakt 5 × Pro-SIM-Betrag (keine Drift).
        self::assertSame(134.95, $calc['rechnungsbetrag']);
        self::assertSame($calc['rechnungsbetrag_sim'] * 5, $calc['rechnungsbetrag']);
        // Rechenweg geht cent-genau auf: Listenpreis - Rabatt = Rechnungsbetrag.
        self::assertSame(
            $calc['grundpreis'] - $calc['rabatt_betrag'],
            $calc['nach_rabatt']
        );
    }

    public function test_aggregates_have_no_fractional_cents(): void
    {
        // Zehn Tarife à 0,99 EUR: rohe Float-Akkumulation ergibt 9,8999999…,
        // nicht 9,90. Die Summen muessen auf Cents festgeklopft sein.
        $tarife = [];
        for ($i = 0; $i < 10; $i++) {
            $tarife[] = [
                'tarif'             => 'Cent-Tarif ' . $i,
                'sims'              => 1,
                'preis_num'         => 0.99,
                'preis_regular_num' => 0.99,
                'rabatt_prozent'    => 0,
                'rabatt_num'        => 0,
                'laufzeit_monate'   => 24,
                'laufzeit_max'      => 24,
            ];
        }

        $result = Calculator::compute(['tarife' => $tarife]);

        // 10 × 0,99 = 9,90 — exakt, keine Float-Schweife.
        self::assertSame(9.9, $result['gesamt_monatlich']);
        self::assertSame(9.9, $result['gesamt_listenpreis']);
        self::assertSame(0.0, $result['ersparnis_monatlich']);
        self::assertSame(0.0, $result['ersparnis_jahr']);

        // Jeder monetaere Endwert hat hoechstens zwei Nachkommastellen.
        foreach (['gesamt_monatlich', 'gesamt_listenpreis', 'ersparnis_monatlich', 'ersparnis_jahr'] as $key) {
            self::assertSame(
                round($result[$key], 2),
                $result[$key],
                $key . ' hat Bruchteile von Cents'
            );
        }
    }

    /**
     * Regression: Die Laufzeit-Hochrechnung (listenpreis_laufzeit / ersparnis_laufzeit)
     * muss die tatsaechliche Laufzeit (nutzmonate) verwenden, nicht pauschal 24.
     *
     * Szenario glatt gewaehlt: Listenpreis 30 EUR, 2 SIMs, Laufzeit 24-36
     * (zahlmonate 24, nutzmonate 36). Mit dem alten hardcodierten *24 waeren es
     * 30*2*24 = 1440 statt 30*2*36 = 2160 — der Test faellt also bei Rueckfall.
     */
    public function test_laufzeit_hochrechnung_uses_nutzmonate_not_hardcoded_24(): void
    {
        $quote = [
            'tarife' => [
                [
                    'tarif'             => 'Lange Laufzeit',
                    'sims'              => 2,
                    'preis_num'         => 20.0,
                    'preis_regular_num' => 30.0,
                    'laufzeit'          => '24-36',
                ],
            ],
        ];

        $result = Calculator::compute($quote);
        $calc   = $result['tarife'][0];

        self::assertSame(36, $calc['nutzmonate'], 'Laufzeit-Spanne 24-36 muss nutzmonate=36 ergeben.');

        // Kern-Beweis: Listenpreis-Hochrechnung skaliert mit 36 Monaten (30*2*36).
        self::assertSame(2160.0, $calc['listenpreis_laufzeit']);
        // Gesamtkosten ueber die Laufzeit: gesamtkosten 720 EUR * 2 SIMs.
        self::assertSame(1440.0, $calc['gesamt_laufzeit']);
        // Ersparnis = Listenpreis-Laufzeit minus echte Gesamtkosten (2160 - 1440).
        self::assertSame(720.0, $calc['ersparnis_laufzeit']);

        // Konsistenz-Identitaet: effektivpreis * sims * nutzmonate == gesamt_laufzeit.
        self::assertSame(
            $calc['gesamt_laufzeit'],
            round($calc['effektivpreis'] * $calc['sims'] * $calc['nutzmonate'], 2),
            'effektivpreis * nutzmonate muss die echten Gesamtkosten ergeben.'
        );
    }

    /**
     * Regression: Auch die Gesamt-Hochrechnung in computeCombined() (listenpreis_total /
     * ersparnis_total) muss die gemeinsame Laufzeit nutzen, nicht 24.
     */
    public function test_combined_laufzeit_hochrechnung_uses_nutzmonate_not_hardcoded_24(): void
    {
        $tarif = [
            'tarif'             => 'Lange Laufzeit',
            'sims'              => 2,
            'preis_num'         => 20.0,
            'preis_regular_num' => 30.0,
            'laufzeit'          => '24-36',
        ];

        $result   = Calculator::compute(['tarife' => [$tarif, $tarif]]);
        $combined = $result['combined'];

        self::assertNotNull($combined, 'Gleiche Laufzeit muss combined erzeugen.');
        self::assertSame(36, $combined['nutzmonate']);

        // Direkter Beweis: 2x (30 EUR * 2 SIMs) = 120 Grundpreise, * 36 Monate = 4320.
        self::assertSame(120.0, $combined['total_grundpreise']);
        self::assertSame(4320.0, $combined['listenpreis_total']);

        // Identitaet aus den zurueckgegebenen Werten: ersparnis_total nutzt dieselbe
        // Laufzeit wie listenpreis_total (gegen ein erneutes Auseinanderlaufen).
        self::assertSame(
            $combined['ersparnis_total'],
            round($combined['listenpreis_total'] - ($combined['effektiv_gesamt'] * $combined['nutzmonate']), 2),
            'ersparnis_total muss auf nutzmonate basieren, nicht auf 24.'
        );
    }
}
