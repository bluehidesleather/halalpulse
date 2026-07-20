<?php

declare(strict_types=1);

namespace HalalPulse\Government;

final class GovernmentSectorClassifier
{
    /** @var array<string, list<string>> */
    private const SECTORS = [
        'Renewable energy' => ['renewable', 'solar', 'wind energy', 'green hydrogen', 'clean energy'],
        'Power' => ['power sector', 'electricity', 'power transmission', 'power distribution'],
        'Railways' => ['railway', 'railways', 'rolling stock', 'rail infrastructure'],
        'Roads and infrastructure' => ['highway', 'road infrastructure', 'infrastructure project', 'capital expenditure'],
        'Defence' => ['defence', 'defense', 'military procurement', 'aerospace'],
        'Electronics and semiconductors' => ['semiconductor', 'electronics manufacturing', 'chip fabrication', 'electronic components'],
        'Telecom and digital' => ['telecom', 'telecommunication', 'broadband', 'digital infrastructure', 'data centre'],
        'Pharmaceuticals and healthcare' => ['pharmaceutical', 'pharma', 'healthcare', 'medical device', 'hospital'],
        'Agriculture and fertilisers' => ['agriculture', 'agri ', 'fertiliser', 'fertilizer', 'crop', 'irrigation'],
        'Food processing' => ['food processing', 'cold chain', 'food park'],
        'Textiles' => ['textile', 'apparel', 'garment', 'technical textiles'],
        'Housing and construction' => ['housing', 'real estate', 'construction sector', 'urban development'],
        'Banking and financial services' => ['banking', 'bank ', 'non-banking financial', 'nbfc', 'financial services'],
        'Automotive and electric vehicles' => ['automobile', 'automotive', 'electric vehicle', 'ev manufacturing', 'auto component'],
        'Ports and shipping' => ['port sector', 'shipping', 'shipbuilding', 'maritime'],
        'Mining and metals' => ['mining', 'steel sector', 'aluminium', 'critical mineral'],
    ];

    private const SUPPORTIVE = [
        'approved', 'allocation', 'allocates', 'incentive', 'subsidy', 'scheme', 'investment',
        'production linked incentive', 'tax relief', 'reform', 'mission', 'expansion', 'development',
        'funding', 'support', 'boost', 'promotion', 'outlay', 'credit guarantee',
    ];

    private const RESTRICTIVE = [
        'penalty', 'restriction', 'prohibition', 'withdrawal', 'suspension', 'tightening',
        'higher duty', 'investigation', 'enforcement action', 'adverse', 'ban ',
    ];

    public function classify(GovernmentAnnouncement $announcement): GovernmentClassification
    {
        $text = mb_strtolower($announcement->title . ' ' . $announcement->summary);
        $sectorHits = [];
        foreach (self::SECTORS as $sector => $keywords) {
            $hits = array_values(array_filter($keywords, static fn (string $keyword): bool => str_contains($text, $keyword)));
            if ($hits !== []) {
                $sectorHits[$sector] = $hits;
            }
        }

        if ($sectorHits === []) {
            return new GovernmentClassification(null, 'unclassified', 0, 'No configured sector phrase matched; manual review may still classify it.');
        }

        uasort($sectorHits, static fn (array $left, array $right): int => count($right) <=> count($left));
        $sector = (string) array_key_first($sectorHits);
        $supportive = array_values(array_filter(self::SUPPORTIVE, static fn (string $keyword): bool => str_contains($text, $keyword)));
        $restrictive = array_values(array_filter(self::RESTRICTIVE, static fn (string $keyword): bool => str_contains($text, $keyword)));

        if ($supportive !== [] && $restrictive !== []) {
            return new GovernmentClassification($sector, 'neutral', 25, 'Both supportive and restrictive policy phrases matched; human interpretation is required.');
        }
        if ($supportive === [] && $restrictive === []) {
            return new GovernmentClassification($sector, 'unclassified', 20, 'A sector phrase matched without a directional policy phrase.');
        }

        $impact = $supportive !== [] ? 'tailwind' : 'headwind';
        $directionHits = $supportive !== [] ? $supportive : $restrictive;
        $confidence = min(85, 45 + (count($sectorHits[$sector]) - 1) * 10 + (count($directionHits) - 1) * 5);
        return new GovernmentClassification(
            $sector,
            $impact,
            $confidence,
            sprintf('Matched sector phrase(s): %s. Matched %s phrase(s): %s. This is only a review suggestion.', implode(', ', $sectorHits[$sector]), $impact, implode(', ', $directionHits)),
        );
    }
}
