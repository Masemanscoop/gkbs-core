<?php

declare(strict_types=1);

namespace GKBS\Core\Pricing;

/**
 * Calculator — zentrale Berechnungsklasse fuer Mobilfunk-Angebote.
 *
 * Alle Zahlen werden einmal hier berechnet. Konsumenten (PDF-Templates,
 * Frontend-Views, REST-Responses) greifen nur auf fertige Werte zu und
 * rechnen selbst nichts. Jede Berechnung wird gegengeprueft: berechneter
 * Effektivpreis muss mit dem Daten-Effektivpreis uebereinstimmen.
 * Abweichungen werden als Warnungen zurueckgegeben.
 *
 * Stateless, keine WP-Abhaengigkeit — wiederverwendbar von beiden Plugins
 * (mb-business-suite, gksimcheck-business-suite).
 */
final class Calculator
{
    /**
     * Standard-Vertragslaufzeit in Monaten. Dient als Default/Fallback, wenn ein
     * Tarif keine eigene Laufzeit liefert. Die Laufzeit-Hochrechnung selbst nutzt
     * die pro Tarif ausgelesene Laufzeit ($nutzmonate), nicht diese Konstante.
     */
    private const CONTRACT_TERM_MONTHS = 24;

    /**
     * Berechne alle Werte fuer ein Angebot.
     *
     * @param array{tarife?: list<array<string,mixed>>} $quote Quote-Daten mit 'tarife' Array
     *
     * @return array{
     *   tarife: list<array<string,mixed>>,
     *   gesamt_monatlich: float,
     *   gesamt_listenpreis: float,
     *   gesamt_sims: int,
     *   ersparnis_monatlich: float,
     *   ersparnis_jahr: float,
     *   ersparnis_bezug: string,
     *   gleiche_laufzeit: bool,
     *   combined: array<string,mixed>|null,
     *   warnungen: list<string>
     * }
     */
    public static function compute(array $quote): array
    {
        $tarifeRaw = $quote['tarife'] ?? [];
        $result    = [
            'tarife'              => [],
            'gesamt_monatlich'    => 0.0,
            'gesamt_listenpreis'  => 0.0,
            'gesamt_sims'         => 0,
            'ersparnis_monatlich' => 0.0,
            'ersparnis_jahr'      => 0.0,
            'ersparnis_bezug'     => 'ggü. Listenpreis',
            'gleiche_laufzeit'    => true,
            'combined'            => null,
            'warnungen'           => [],
        ];

        if (empty($tarifeRaw)) {
            return $result;
        }

        $firstNutzmonate = null;

        foreach ($tarifeRaw as $i => $t) {
            $calc                  = self::computeTarif($t, (int) $i);
            $result['tarife'][]    = $calc;
            $result['gesamt_monatlich']   += $calc['effektivpreis'] * $calc['sims'];
            $result['gesamt_listenpreis'] += $calc['grundpreis'] * $calc['sims'];
            $result['gesamt_sims']        += $calc['sims'];

            if ($calc['warnung'] !== '') {
                $result['warnungen'][] = $calc['warnung'];
            }

            if ($firstNutzmonate === null) {
                $firstNutzmonate = $calc['nutzmonate'];
            } elseif ($calc['nutzmonate'] !== $firstNutzmonate) {
                $result['gleiche_laufzeit'] = false;
            }
        }

        // Float-Summen auf Cents festklopfen, bevor daraus die Ersparnis abgeleitet
        // wird — sonst kann binaere Float-Akkumulation um Bruchteile von Cents driften.
        $result['gesamt_monatlich']    = round($result['gesamt_monatlich'], 2);
        $result['gesamt_listenpreis']  = round($result['gesamt_listenpreis'], 2);
        $result['ersparnis_monatlich'] = round($result['gesamt_listenpreis'] - $result['gesamt_monatlich'], 2);
        // Aggregat ueber alle Tarife (ggf. gemischte Laufzeiten) -> Vertragslaufzeit-
        // Konstante statt einer einzelnen Tarif-Laufzeit. Feldname "jahr" ist historisch,
        // der Wert bezieht sich auf CONTRACT_TERM_MONTHS.
        $result['ersparnis_jahr']      = round($result['ersparnis_monatlich'] * self::CONTRACT_TERM_MONTHS, 2);

        if ($result['gleiche_laufzeit'] && $firstNutzmonate !== null && $firstNutzmonate > 0) {
            $result['combined'] = self::computeCombined($result['tarife'], $firstNutzmonate);
        }

        return $result;
    }

    /**
     * Berechne alle Werte fuer einen einzelnen Tarif.
     *
     * @param array<string,mixed> $t
     *
     * @return array<string,mixed>
     */
    private static function computeTarif(array $t, int $index): array
    {
        $sims            = max(1, (int) ($t['sims'] ?? 1));
        $effektivpreis   = (float) ($t['preis_num'] ?? 0);
        $grundpreis      = (float) ($t['preis_regular_num'] ?? 0);
        $rabattEffektiv  = (int) ($t['rabatt_num'] ?? 0);
        $name            = (string) ($t['tarif'] ?? $t['name'] ?? '');
        $netz            = (string) ($t['netz'] ?? '');
        $label           = (string) ($t['label'] ?? '');
        $features        = $t['detail_features'] ?? $t['features'] ?? [];
        $groupName       = (string) ($t['group_name'] ?? '');

        $rabattPct       = (float) ($t['rabatt_prozent'] ?? 0);
        $startguthaben   = (float) ($t['startguthaben'] ?? -1);
        $gratisMonate    = (int) ($t['gratis_monate'] ?? -1);
        $laufzeitMonate  = (int) ($t['laufzeit_monate'] ?? 0);
        $laufzeitMax     = (int) ($t['laufzeit_max'] ?? 0);

        if ($laufzeitMax > 0 && $laufzeitMonate > 0) {
            $zahlmonate   = $laufzeitMonate;
            $nutzmonate   = $laufzeitMax;
            $gratismonate = $gratisMonate >= 0 ? $gratisMonate : max(0, $nutzmonate - $zahlmonate);
        } else {
            $laufzeitStr = (string) ($t['laufzeit'] ?? (string) self::CONTRACT_TERM_MONTHS);
            if (preg_match('/(\d+)-(\d+)/', $laufzeitStr, $lzParts) === 1) {
                $zahlmonate   = (int) $lzParts[1];
                $nutzmonate   = (int) $lzParts[2];
                $gratismonate = $nutzmonate - $zahlmonate;
            } else {
                preg_match('/(\d+)/', $laufzeitStr, $lzSingle);
                $zahlmonate   = (int) ($lzSingle[1] ?? self::CONTRACT_TERM_MONTHS);
                $nutzmonate   = $zahlmonate;
                $gratismonate = 0;
            }
        }
        if ($gratismonate < 0) {
            $gratismonate = 0;
        }
        if ($nutzmonate <= 0) {
            $nutzmonate = self::CONTRACT_TERM_MONTHS;
        }

        $hasNewFields = $rabattPct > 0;
        if ($hasNewFields) {
            // Monatlicher Rechnungsbetrag pro SIM = real abgerechneter Cent-Betrag.
            // Sofort auf Cents runden, sonst driftet Rechnungsbetrag-gesamt (× SIMs)
            // um bis zu einen Cent gegen den angezeigten Pro-SIM-Preis.
            $nachRabatt    = round($grundpreis * (1 - $rabattPct / 100), 2);
            $startguthaben = $startguthaben >= 0 ? $startguthaben : 0.0;
        } else {
            $rabattPct     = $rabattEffektiv;
            $nachRabatt    = $grundpreis > 0 ? round($grundpreis * (1 - $rabattPct / 100), 2) : $effektivpreis;
            $totalFb       = $nachRabatt * $zahlmonate;
            $startguthaben = max(0.0, $totalFb - ($effektivpreis * $nutzmonate));
        }

        // Rabattbetrag aus dem gerundeten Rechnungsbetrag ableiten, damit im
        // Rechenweg Listenpreis - Rabatt = Rechnungsbetrag cent-genau aufgeht.
        $rabattBetrag    = round($grundpreis - $nachRabatt, 2);
        $totalZahlung    = $nachRabatt * $zahlmonate;
        $totalNachSg     = round($totalZahlung - $startguthaben, 2);
        $gesamtkostenRoh = $totalNachSg;

        $effektivBerechnet = $nutzmonate > 0 ? round($gesamtkostenRoh / $nutzmonate, 2) : 0.0;

        $warnung = '';
        if ($hasNewFields && $effektivpreis > 0 && $effektivBerechnet > 0) {
            $diff = abs($effektivpreis - $effektivBerechnet);
            if ($diff > 0.02) {
                $warnung = sprintf(
                    'Tarif #%d "%s": Effektivpreis aus Daten (%.2f) weicht vom berechneten Wert (%.2f) ab (Diff: %.2f). Bitte Tarifdaten prüfen.',
                    $index + 1,
                    $name,
                    $effektivpreis,
                    $effektivBerechnet,
                    $diff
                );
            }
        }

        $effektivFinal = ($hasNewFields && $warnung !== '' && $effektivpreis > 0)
            ? $effektivpreis
            : $effektivBerechnet;

        $gesamtMonatlichRoh  = round($effektivFinal * $sims, 2);
        $gesamtLaufzeitRoh   = round($gesamtkostenRoh * $sims, 2);
        // Hochrechnung auf die tatsaechliche Laufzeit ($nutzmonate), NICHT pauschal 24:
        // effektivFinal = gesamtkosten / nutzmonate, daher ergibt effektivFinal * nutzmonate
        // exakt die echten Gesamtkosten (gesamt_laufzeit). Bei 24-Monats-Tarifen identisch.
        $listenpreisLaufzeit = round($grundpreis * $sims * $nutzmonate, 2);
        $ersparnisLaufzeit   = round($listenpreisLaufzeit - ($effektivFinal * $sims * $nutzmonate), 2);

        $rechnungsbetragSim    = $nachRabatt;
        $rechnungsbetragGesamt = round($nachRabatt * $sims, 2);

        $rechenweg = [
            ['label' => 'Listenpreis',                              'wert' => $grundpreis],
            ['label' => '-' . $rabattPct . '% Rabatt',              'wert' => -$rabattBetrag, 'gruen' => true],
            ['label' => '= Monatspreis nach Rabatt',                'wert' => $nachRabatt],
            ['label' => '× ' . $zahlmonate . ' Zahlmonate',         'wert' => $totalZahlung],
        ];
        if ($startguthaben > 0) {
            $rechenweg[] = ['label' => '- Startguthaben', 'wert' => -$startguthaben, 'gruen' => true];
        }
        if ($gratismonate > 0) {
            $rechenweg[] = ['label' => $gratismonate . ' Gratismonate (kostenlos)', 'wert' => 0.0, 'gruen' => true];
        }
        $rechenweg[] = ['label' => '÷ ' . $nutzmonate . ' Nutzmonate', 'wert' => null];
        $rechenweg[] = ['label' => 'Effektivpreis netto/Mon.', 'wert' => $effektivBerechnet, 'bold' => true];

        return [
            'name'                 => $name,
            'netz'                 => $netz,
            'sims'                 => $sims,
            'label'                => $label,
            'group_name'           => $groupName,
            'features'             => $features,
            'grundpreis'           => $grundpreis,
            'rabatt_pct'           => $rabattPct,
            'rabatt_effektiv'      => $rabattEffektiv,
            'rabatt_betrag'        => $rabattBetrag,
            'nach_rabatt'          => $nachRabatt,
            'startguthaben'        => $startguthaben,
            'effektivpreis'        => $effektivFinal,
            'effektivpreis_daten'  => $effektivpreis,
            'has_new_fields'       => $hasNewFields,
            'zahlmonate'           => $zahlmonate,
            'nutzmonate'           => $nutzmonate,
            'gratismonate'         => $gratismonate,
            'laufzeit_anzeige'     => $nutzmonate . ' Monate',
            'total_zahlung'        => $totalZahlung,
            'gesamtkosten'         => $gesamtkostenRoh,
            'rechnungsbetrag'      => $rechnungsbetragGesamt,
            'rechnungsbetrag_sim'  => $rechnungsbetragSim,
            'gesamt_monatlich'     => $gesamtMonatlichRoh,
            'gesamt_laufzeit'      => $gesamtLaufzeitRoh,
            'listenpreis_laufzeit' => $listenpreisLaufzeit,
            'ersparnis_laufzeit'   => $ersparnisLaufzeit,
            'rechenweg'            => $rechenweg,
            'warnung'              => $warnung,
            'cancel_notice'        => (string) ($t['cancel_notice'] ?? ''),
            '_raw'                 => $t,
        ];
    }

    /**
     * Gesamtrechnung ueber alle Tarife (nur bei gleicher Laufzeit).
     *
     * @param list<array<string,mixed>> $tarife
     *
     * @return array<string,mixed>
     */
    private static function computeCombined(array $tarife, int $nutzmonate): array
    {
        $totalGrundpreise   = 0.0;
        $totalNachRabatt    = 0.0;
        $totalStartguthaben = 0.0;
        $totalZahlung       = 0.0;
        $totalSims          = 0;
        $zahlmonate         = 0;
        $gratismonate       = 0;

        foreach ($tarife as $t) {
            $totalGrundpreise   += $t['grundpreis'] * $t['sims'];
            $totalNachRabatt    += $t['nach_rabatt'] * $t['sims'];
            $totalStartguthaben += $t['startguthaben'] * $t['sims'];
            $totalZahlung       += $t['nach_rabatt'] * $t['sims'] * $t['zahlmonate'];
            $totalSims          += $t['sims'];
            $zahlmonate          = $t['zahlmonate'];
            $gratismonate        = $t['gratismonate'];
        }

        // Float-Summen auf Cents festklopfen, damit Rechenweg, Gesamtkosten und
        // Ersparnis cent-genau aufeinander aufbauen (keine binaere Akkumulations-Drift).
        $totalGrundpreise   = round($totalGrundpreise, 2);
        $totalNachRabatt    = round($totalNachRabatt, 2);
        $totalStartguthaben = round($totalStartguthaben, 2);
        $totalZahlung       = round($totalZahlung, 2);

        $totalNachSg     = round($totalZahlung - $totalStartguthaben, 2);
        $effektivGesamt  = $nutzmonate > 0 ? round($totalNachSg / $nutzmonate, 2) : 0.0;
        $effektivProSim  = ($totalSims > 0 && $nutzmonate > 0)
            ? round($totalNachSg / $nutzmonate / $totalSims, 2)
            : 0.0;
        // Auf die tatsaechliche Laufzeit ($nutzmonate, hier der Methoden-Parameter) statt
        // pauschal 24: effektivGesamt = totalNachSg / nutzmonate, damit effektivGesamt *
        // nutzmonate die echten Gesamt-Zahlungen ergibt.
        $listenpreisTotal = round($totalGrundpreise * $nutzmonate, 2);
        $ersparnisTotal   = round($listenpreisTotal - ($effektivGesamt * $nutzmonate), 2);

        $rechenweg = [];
        foreach ($tarife as $t) {
            $rechenweg[] = [
                'label' => $t['sims'] . '× ' . $t['name'] . ' (' . self::eur($t['grundpreis']) . ' Listenprs.)',
                'wert'  => $t['grundpreis'] * $t['sims'],
            ];
        }
        $rechenweg[] = ['label' => 'Alle Grundpreise', 'wert' => $totalGrundpreise, 'bold' => true];

        $totalRabattBetrag = $totalGrundpreise - $totalNachRabatt;
        if ($totalRabattBetrag > 0) {
            $rechenweg[] = ['label' => '- Rabatte gesamt',        'wert' => -$totalRabattBetrag, 'gruen' => true];
            $rechenweg[] = ['label' => '= Nach Rabatt monatlich', 'wert' => $totalNachRabatt];
        }
        $rechenweg[] = ['label' => '× ' . $zahlmonate . ' Zahlmonate', 'wert' => $totalZahlung];
        if ($totalStartguthaben > 1) {
            $rechenweg[] = ['label' => '- Startguthaben gesamt', 'wert' => -$totalStartguthaben, 'gruen' => true];
        }
        if ($gratismonate > 0) {
            $rechenweg[] = ['label' => '+ ' . $gratismonate . ' Gratismonate', 'wert' => 0.0, 'gruen' => true];
        }
        $rechenweg[] = ['label' => '= Gesamtkosten ' . $nutzmonate . ' Mon.', 'wert' => $totalNachSg, 'bold' => true];
        $rechenweg[] = ['label' => '÷ ' . $nutzmonate . ' Nutzmonate',         'wert' => null];
        $rechenweg[] = ['label' => 'Effektivpreis gesamt/Mon.',                 'wert' => $effektivGesamt, 'bold' => true];
        $rechenweg[] = ['label' => '÷ ' . $totalSims . ' SIM = pro SIM',        'wert' => $effektivProSim, 'bold' => true];

        return [
            'total_grundpreise'   => $totalGrundpreise,
            'total_nach_rabatt'   => $totalNachRabatt,
            'total_startguthaben' => $totalStartguthaben,
            'total_sims'          => $totalSims,
            'zahlmonate'          => $zahlmonate,
            'nutzmonate'          => $nutzmonate,
            'gratismonate'        => $gratismonate,
            'total_zahlung'       => $totalZahlung,
            'gesamtkosten'        => $totalNachSg,
            'effektiv_gesamt'     => $effektivGesamt,
            'effektiv_pro_sim'    => $effektivProSim,
            'listenpreis_total'   => $listenpreisTotal,
            'ersparnis_total'     => $ersparnisTotal,
            'rechenweg'           => $rechenweg,
        ];
    }

    /**
     * Erzeuge den rechtlichen Erklaerungstext fuer einen Tarif.
     * Nutzt exakt dieselben Variablen die auch die Tabelle befuellen.
     *
     * @param array<string,mixed> $calcTarif
     */
    public static function buildExplanation(array $calcTarif): string
    {
        $t    = $calcTarif;
        $text = '';

        $simHinweis = $t['sims'] > 1 ? ' für ' . $t['sims'] . ' SIM-Karten' : '';
        if ($t['gratismonate'] > 0) {
            $text .= self::eur($t['rechnungsbetrag']) . ' netto' . $simHinweis
                . ' entspricht dem monatlichen Rechnungsbetrag ab dem '
                . ($t['gratismonate'] + 1) . '. Monat der Vertragslaufzeit.';
        } else {
            $text .= self::eur($t['rechnungsbetrag']) . ' netto' . $simHinweis
                . ' ist der monatliche Rechnungsbetrag über die gesamte Vertragslaufzeit.';
        }

        $text .= ' Der reguläre Monatspreis beträgt ' . self::eur($t['grundpreis'])
            . ' pro SIM (abzgl. ' . $t['rabatt_pct'] . '% Rabatt = ' . self::eur($t['nach_rabatt']) . ').';

        if ($t['gratismonate'] > 0 || $t['startguthaben'] > 0) {
            $text  .= ' Unter Einbezug';
            $parts  = [];
            if ($t['gratismonate'] > 0) {
                $parts[] = 'der ' . $t['gratismonate'] . '-monatigen Basispreisbefreiung';
            }
            if ($t['startguthaben'] > 0) {
                $parts[] = 'des Startguthabens von ' . self::eur($t['startguthaben']);
            }
            $text .= ' ' . implode(' und ', $parts);
            $text .= ' bei einer Laufzeit von ' . $t['nutzmonate'] . ' Monaten ergibt sich rechnerisch ein monatlicher Effektivpreis von '
                . self::eur($t['effektivpreis']) . ' netto';
            if ($t['sims'] > 1) {
                $text .= ' (pro SIM ' . self::eur($t['effektivpreis']) . ', gesamt ' . self::eur($t['effektivpreis'] * $t['sims']) . ')';
            }
            $text .= '.';
        }

        $cancel = ! empty($t['cancel_notice']) ? (string) $t['cancel_notice'] : '1 Monat zum Laufzeitende';
        $text  .= ' Nach Ablauf der Vertragslaufzeit verlängert sich der Vertrag monatlich bis zur Kündigung (Kündigungsfrist: ' . $cancel . ').';
        $text  .= ' Alle Preise netto zzgl. 19% MwSt.';

        return $text;
    }

    /**
     * Erzeuge den kombinierten Erklaerungstext fuer alle Tarife zusammen.
     *
     * @param array<string,mixed> $calcResult
     */
    public static function buildCombinedExplanation(array $calcResult): string
    {
        if (empty($calcResult['gleiche_laufzeit']) || empty($calcResult['combined'])) {
            return '';
        }

        $c    = $calcResult['combined'];
        $text = 'Effektivpreis-Berechnung über alle Tarife: ';
        $text .= 'Alle Grundpreise (' . self::eur($c['total_grundpreise']) . '/Mon.)';

        $totalRabatt = $c['total_grundpreise'] - $c['total_nach_rabatt'];
        if ($totalRabatt > 0) {
            $text .= ' abzgl. Rabatte (' . self::eur($totalRabatt) . ')';
        }
        $text .= ' = ' . self::eur($c['total_nach_rabatt']) . '/Mon. nach Rabatt.';
        $text .= ' × ' . $c['zahlmonate'] . ' Zahlmonate = ' . self::eur($c['total_zahlung']) . '.';

        if ($c['total_startguthaben'] > 0) {
            $text .= ' Abzgl. Startguthaben: ' . self::eur($c['total_startguthaben']) . '.';
        }
        $text .= ' Gesamtkosten über ' . $c['nutzmonate'] . ' Monate: ' . self::eur($c['gesamtkosten']) . '.';
        $text .= ' Effektivpreis: ' . self::eur($c['effektiv_gesamt']) . '/Mon. gesamt';
        $text .= ' (' . self::eur($c['effektiv_pro_sim']) . '/SIM/Mon. bei ' . $c['total_sims'] . ' SIM).';

        return $text;
    }

    /**
     * Pruefe ob kritische Warnungen vorliegen (> 1 EUR Abweichung).
     *
     * @param array<string,mixed> $calcResult
     */
    public static function hasCriticalWarnings(array $calcResult): bool
    {
        foreach (($calcResult['warnungen'] ?? []) as $w) {
            if (preg_match('/Diff: ([\d.]+)/', (string) $w, $m) === 1 && (float) $m[1] > 1.0) {
                return true;
            }
        }
        return false;
    }

    /**
     * Berechne Display-Werte fuer einen einzelnen Tarif (Frontend-Anzeige).
     *
     * Wrapper um computeTarif() fuer Tarif-Page und /angebot/.
     * Nimmt ein Roh-Tarif-Array und gibt ein flaches Array mit allen
     * berechneten + enrichten Werten zurueck.
     *
     * @param array<string,mixed> $tarif
     *
     * @return array<string,mixed>
     */
    public static function computeDisplay(array $tarif): array
    {
        $entry = [
            'tarif'             => (string) ($tarif['name'] ?? ''),
            'name'              => (string) ($tarif['name'] ?? ''),
            'sims'              => 1,
            'netz'              => (string) ($tarif['netz'] ?? ''),
            'label'             => (string) ($tarif['label'] ?? ''),
            'features'          => $tarif['features'] ?? [],
            'detail_features'   => $tarif['detail_features'] ?? $tarif['features'] ?? [],
            'preis_num'         => (float) ($tarif['preis_num'] ?? 0),
            'preis_regular_num' => (float) ($tarif['preis_regular_num'] ?? 0),
            'rabatt_num'        => (int) ($tarif['rabatt_num'] ?? 0),
            'rabatt_prozent'    => (int) ($tarif['rabatt_prozent'] ?? 0),
            'startguthaben'     => (float) ($tarif['startguthaben'] ?? 0),
            'gratis_monate'     => (int) ($tarif['gratis_monate'] ?? 0),
            'laufzeit_monate'   => (int) ($tarif['laufzeit_monate'] ?? 0),
            'laufzeit_max'      => (int) ($tarif['laufzeit_max'] ?? 0),
            'laufzeit'          => (string) ($tarif['laufzeit'] ?? ''),
        ];

        $calc = self::computeTarif($entry, 0);

        $detailFields = [
            'cancel_notice', 'telephony', 'telephony_takt', 'sms',
            'eu_detail', 'eu_countries_url', 'throttle', 'max_speed',
            'multi_cards', 'multi_card_price', 'office_number',
            'wifi_calling', 'secure_net', 'giga_depot', 'giga_depot_price',
            'giga_kombi', 'world_data', 'setup_fee', 'rating_score',
            'rating_count', 'infodok_url',
        ];
        foreach ($detailFields as $field) {
            $calc[$field] = $tarif[$field] ?? null;
        }

        $calc['brutto']            = round($calc['effektivpreis'] * 1.19, 2);
        $calc['daten']             = (string) ($tarif['daten'] ?? '');
        $calc['daten_num']         = (float) ($tarif['daten_num'] ?? 0);
        $calc['has_eu']            = ! empty($tarif['has_eu']);
        $calc['link']              = (string) ($tarif['link'] ?? '');
        $calc['effektivpreis_api'] = (float) ($tarif['preis_num'] ?? 0);

        return $calc;
    }

    /**
     * Provision pro Tarif berechnen (zentrale Methode).
     * Wird von Admin, PWA und Views verwendet.
     *
     * @param array<string,mixed>          $tarif
     * @param array<string,float|int|null> $tarifRates Tarif-Name => Rate (EUR oder %)
     */
    public static function calcProvision(array $tarif, int $sims, string $type, array $tarifRates): float
    {
        $tarifName = (string) ($tarif['tarif'] ?? $tarif['name'] ?? '');
        $rate      = (float) ($tarifRates[$tarifName] ?? 0);
        if ($rate <= 0) {
            return 0.0;
        }
        if ($type === 'fixed') {
            return round($rate * $sims, 2);
        }
        return round(((float) ($tarif['preis_num'] ?? 0) * $sims) * ($rate / 100), 2);
    }

    /**
     * Formatierung: Euro-Betrag.
     */
    private static function eur(float $v): string
    {
        return number_format($v, 2, ',', '.') . ' €';
    }
}
