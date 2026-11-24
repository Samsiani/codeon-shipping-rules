<?php

declare(strict_types=1);

namespace CodeOn\ShippingRules\Integrations;

use CodeOn\Core\Locations\Data\DisplayFormatter;
use CodeOn\Core\Locations\Data\Repository;
use CodeOn\Core\Locations\Data\Transliterator;
use CodeOn\Core\Locations\Settings\TbilisiMode;

final class CoreLocations
{
    public static function enrich(array $destination): array
    {
        if (($destination['country'] ?? '') !== 'GE' || !class_exists(Repository::class)) {
            return $destination;
        }

        $city = trim((string) ($destination['city'] ?? ''));
        $state = (string) ($destination['state'] ?? '');
        if ($city === '' || $state === '') {
            return $destination;
        }

        $area = class_exists(TbilisiMode::class) ? TbilisiMode::resolveAreaKey($city) : null;
        if ($area && $area['state'] === $state) {
            $destination['municipality'] = $area['muni_id'];
            $destination['settlement'] = (string) ($area['settlement_id'] ?? '');
            return $destination;
        }

        $repository = Repository::instance();
        $region = $repository->regionByWcCode($state);
        if (!$region) {
            return $destination;
        }

        $matches = [];
        $city = mb_strtolower($city);
        $formatter = class_exists(DisplayFormatter::class) ? DisplayFormatter::fromOptions() : null;
        foreach ($repository->municipalitiesOf($region['id'], false) as $municipality) {
            foreach ($repository->settlementsOf($municipality['id']) as $settlement) {
                $name = $settlement['name_ka'];
                $aliases = [$name, $settlement['name_en'] ?? '', (string) $settlement['id']];
                if ($formatter) {
                    $aliases[] = $formatter->label($settlement);
                }
                if (class_exists(Transliterator::class)) {
                    $aliases[] = Transliterator::toLatin($name);
                    $aliases[] = Transliterator::toLatin($name, false);
                }
                if (in_array($city, array_map('mb_strtolower', $aliases), true)) {
                    $matches[] = [$municipality['id'], (string) $settlement['id']];
                }
            }
        }
        if (count($matches) === 1) {
            [$destination['municipality'], $destination['settlement']] = $matches[0];
        }
        return $destination;
    }
}
