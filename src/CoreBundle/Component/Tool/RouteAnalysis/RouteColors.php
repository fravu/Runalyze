<?php

namespace Runalyze\Bundle\CoreBundle\Component\Tool\RouteAnalysis;

use Runalyze\Bundle\CoreBundle\Entity\Account;
use Runalyze\Bundle\CoreBundle\Entity\ConfRepository;

/**
 * User defined colors for heatmap, climbs and segments.
 *
 * Defaults are colorblind friendly (yellow/blue/black, no red/green).
 * Stored in `conf` as compact hex lists, because `conf`.`value` has only 255 chars.
 */
class RouteColors
{
    const CONF_CATEGORY = 'route_analysis';
    const CONF_BASE = 'RA_COLORS';
    const CONF_SPORTS = 'RA_SPORT_COLORS';

    /** @var array key => [default, label, group] */
    const BASE = [
        'grade_down' => ['#d9d9d9', 'bergab', 'Steigung im Profil'],
        'grade_1' => ['#ffe14d', 'unter 5 %', 'Steigung im Profil'],
        'grade_2' => ['#8cc8ff', '5–10 %', 'Steigung im Profil'],
        'grade_3' => ['#1f5fd1', '10–15 %', 'Steigung im Profil'],
        'grade_4' => ['#0b1f66', 'über 15 %', 'Steigung im Profil'],
        'cat_5' => ['#fff3a8', 'Kategorie 5', 'Kategorien'],
        'cat_4' => ['#ffd000', 'Kategorie 4', 'Kategorien'],
        'cat_3' => ['#8cc8ff', 'Kategorie 3', 'Kategorien'],
        'cat_2' => ['#1f5fd1', 'Kategorie 2', 'Kategorien'],
        'cat_1' => ['#0b1f66', 'Kategorie 1', 'Kategorien'],
        'cat_HC' => ['#000000', 'HC', 'Kategorien'],
        'line' => ['#0047b3', 'Strecke', 'Karte'],
        'start' => ['#ffd000', 'Start', 'Karte'],
        'end' => ['#0b1f66', 'Ende', 'Karte'],
        'selection' => ['#ffb000', 'Auswahl / Hervorhebung', 'Karte'],
    ];

    /** @var string[] defaults for sports in the heatmap, in order of their number of activities */
    const SPORT_PALETTE = ['#0047b3', '#d4a200', '#000000', '#00a0e0', '#8a2be2', '#ff8c00', '#e600c8', '#7f7f7f'];

    /** @var ConfRepository */
    protected $Repository;

    /**
     * @param ConfRepository $repository
     */
    public function __construct(ConfRepository $repository)
    {
        $this->Repository = $repository;
    }

    /**
     * @param Account $account
     * @return array ['base' => [key => '#rrggbb'], 'sports' => [sportId => '#rrggbb'], 'text' => [key => '#000'|'#fff'], 'palette' => []]
     */
    public function load(Account $account)
    {
        $base = [];

        foreach (self::BASE as $key => $definition) {
            $base[$key] = $definition[0];
        }

        $stored = $this->storedValue($account, self::CONF_BASE);

        if ('' !== $stored) {
            foreach (explode(',', $stored) as $index => $hex) {
                $keys = array_keys(self::BASE);

                if (isset($keys[$index]) && self::isHex($hex)) {
                    $base[$keys[$index]] = '#'.strtolower($hex);
                }
            }
        }

        $sports = [];

        foreach (explode(',', $this->storedValue($account, self::CONF_SPORTS)) as $pair) {
            $parts = explode(':', $pair);

            if (2 == count($parts) && ctype_digit($parts[0]) && self::isHex($parts[1])) {
                $sports[(int)$parts[0]] = '#'.strtolower($parts[1]);
            }
        }

        $text = [];

        foreach ($base as $key => $color) {
            $text[$key] = self::textColorFor($color);
        }

        return ['base' => $base, 'sports' => $sports, 'text' => $text, 'palette' => self::SPORT_PALETTE];
    }

    /**
     * @param Account $account
     * @param array $base key => '#rrggbb'
     * @param array $sports sportId => '#rrggbb'
     */
    public function save(Account $account, array $base, array $sports)
    {
        $values = [];

        foreach (self::BASE as $key => $definition) {
            $hex = isset($base[$key]) ? ltrim($base[$key], '#') : '';
            $values[] = self::isHex($hex) ? strtolower($hex) : ltrim($definition[0], '#');
        }

        $pairs = [];

        foreach ($sports as $sportId => $color) {
            $hex = ltrim((string)$color, '#');

            if (ctype_digit((string)$sportId) && self::isHex($hex)) {
                $pairs[] = (int)$sportId.':'.strtolower($hex);
            }
        }

        $this->Repository->updateOrInsert($account, self::CONF_CATEGORY, self::CONF_BASE, implode(',', $values));
        $this->Repository->updateOrInsert($account, self::CONF_CATEGORY, self::CONF_SPORTS, substr(implode(',', $pairs), 0, 255));
    }

    /**
     * @param Account $account
     */
    public function reset(Account $account)
    {
        $this->Repository->updateOrInsert($account, self::CONF_CATEGORY, self::CONF_BASE, '');
        $this->Repository->updateOrInsert($account, self::CONF_CATEGORY, self::CONF_SPORTS, '');
    }

    /**
     * @param string $hex '#rrggbb'
     * @return string '#000000' or '#ffffff', whichever contrasts better
     */
    public static function textColorFor($hex)
    {
        $hex = ltrim($hex, '#');
        $channel = function ($value) {
            $c = $value / 255;

            return $c <= 0.03928 ? $c / 12.92 : pow(($c + 0.055) / 1.055, 2.4);
        };
        $luminance = 0.2126 * $channel(hexdec(substr($hex, 0, 2))) + 0.7152 * $channel(hexdec(substr($hex, 2, 2))) + 0.0722 * $channel(hexdec(substr($hex, 4, 2)));

        return $luminance > 0.179 ? '#000000' : '#ffffff';
    }

    /**
     * @param string $hex without '#'
     * @return bool
     */
    private static function isHex($hex)
    {
        return 1 === preg_match('/^[0-9a-fA-F]{6}$/', (string)$hex);
    }

    /**
     * @return string
     */
    private function storedValue(Account $account, $key)
    {
        $conf = $this->Repository->findByAccountAndKey($account, $key);

        return null === $conf ? '' : trim((string)$conf->getValue());
    }
}
