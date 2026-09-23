<?php

namespace Runalyze\Bundle\CoreBundle\Component\Tool\Climb;

/**
 * Finds climbs in an elevation profile.
 *
 * 1. elevation is smoothed over +/- 25 m distance
 * 2. valleys and peaks are found with a hysteresis of 10 m (smaller dips are ignored)
 * 3. neighbouring ascents separated only by a small dip are merged
 * 4. an ascent counts as climb if it is >= 300 m long, >= 3 % steep and gains >= 20 m
 *
 * Score = length [m] * average grade [%] (own formula, runalyze.com's is not public).
 */
class ClimbDetector
{
    const VERSION = 2;

    const SMOOTHING_RADIUS_KM = 0.025;
    const HYSTERESIS_M = 10.0;
    const MIN_LENGTH_KM = 0.3;
    const MIN_GRADE = 3.0;
    const MIN_GAIN_M = 20.0;
    const MAX_GRADE_WINDOW_KM = 0.1;

    /** @var array category => minimal score, for cycling (Tour de France like) */
    const CATEGORIES_CYCLING = ['HC' => 80000, '1' => 64000, '2' => 32000, '3' => 16000, '4' => 8000, '5' => 4000];

    /** @var array category => minimal score, for running, hiking and everything else */
    const CATEGORIES_FOOT = ['HC' => 40000, '1' => 32000, '2' => 16000, '3' => 8000, '4' => 4000, '5' => 2000];

    /**
     * @param float[] $dist cumulative distance [km]
     * @param float[] $elev elevation [m]
     * @param bool $isCycling
     * @return array[] each with start, end (indices), distance [km], gain [m], avg_grade, max_grade, score, category
     */
    public function detect(array $dist, array $elev, $isCycling = false)
    {
        $num = count($elev);

        if ($num < 10 || count($dist) != $num || $dist[$num - 1] < self::MIN_LENGTH_KM) {
            return [];
        }

        $smooth = $this->smooth($dist, $elev);
        $ascents = $this->mergeAscents($smooth, $this->findAscents($smooth));
        $climbs = [];

        foreach ($ascents as $ascent) {
            list($from, $to) = $this->trimFlatEnds($dist, $smooth, $ascent[0], $ascent[1]);
            $lengthKm = $dist[$to] - $dist[$from];
            $gain = $smooth[$to] - $smooth[$from];

            if ($lengthKm < self::MIN_LENGTH_KM || $gain < self::MIN_GAIN_M) {
                continue;
            }

            $avgGrade = $gain / ($lengthKm * 1000) * 100;

            if ($avgGrade < self::MIN_GRADE) {
                continue;
            }

            $score = (int)round($lengthKm * 1000 * $avgGrade);
            $category = self::categoryFor($score, $isCycling);

            if (null === $category) {
                continue;
            }

            $climbs[] = [
                'start' => $from,
                'end' => $to,
                'distance' => round($lengthKm, 3),
                'gain' => (int)round($gain),
                'avg_grade' => round($avgGrade, 1),
                'max_grade' => round(max($avgGrade, $this->maxGrade($dist, $smooth, $from, $to)), 1),
                'score' => $score,
                'category' => $category,
            ];
        }

        return $climbs;
    }

    /**
     * @param int $score
     * @param bool $isCycling
     * @return string|null
     */
    public static function categoryFor($score, $isCycling)
    {
        foreach ($isCycling ? self::CATEGORIES_CYCLING : self::CATEGORIES_FOOT as $category => $minScore) {
            if ($score >= $minScore) {
                return (string)$category;
            }
        }

        return null;
    }

    /**
     * Moving average over a distance window
     *
     * @return float[]
     */
    private function smooth(array $dist, array $elev)
    {
        $num = count($elev);
        $smooth = [];
        $left = 0;
        $right = 0;
        $sum = 0.0;

        for ($i = 0; $i < $num; ++$i) {
            while ($right < $num && $dist[$right] - $dist[$i] <= self::SMOOTHING_RADIUS_KM) {
                $sum += $elev[$right];
                ++$right;
            }

            while ($dist[$i] - $dist[$left] > self::SMOOTHING_RADIUS_KM) {
                $sum -= $elev[$left];
                ++$left;
            }

            $smooth[] = $sum / max(1, $right - $left);
        }

        return $smooth;
    }

    /**
     * Valley/peak detection with hysteresis
     *
     * @return array[] [[valleyIndex, peakIndex], ...]
     */
    private function findAscents(array $e)
    {
        $num = count($e);
        $direction = 0;
        $low = 0;
        $high = 0;
        $valley = null;
        $ascents = [];

        for ($i = 1; $i < $num; ++$i) {
            if ($e[$i] > $e[$high]) {
                $high = $i;
            }

            if ($e[$i] < $e[$low]) {
                $low = $i;
            }

            if ($direction <= 0 && $e[$i] - $e[$low] >= self::HYSTERESIS_M) {
                $valley = $low;
                $direction = 1;
                $high = $i;
            } elseif ($direction >= 0 && $e[$high] - $e[$i] >= self::HYSTERESIS_M) {
                if (1 === $direction && null !== $valley) {
                    $ascents[] = [$valley, $high];
                }

                $valley = null;
                $direction = -1;
                $low = $i;
            }
        }

        if (1 === $direction && null !== $valley && $high > $valley) {
            $ascents[] = [$valley, $high];
        }

        return $ascents;
    }

    /**
     * Merge ascents if the dip between them is small compared to their gain
     *
     * @return array[]
     */
    private function mergeAscents(array $e, array $ascents)
    {
        $merged = [];

        foreach ($ascents as $ascent) {
            $last = count($merged) - 1;

            if ($last >= 0) {
                list($prevFrom, $prevTo) = $merged[$last];
                $dip = $e[$prevTo] - $e[$ascent[0]];
                $gain = ($e[$prevTo] - $e[$prevFrom]) + ($e[$ascent[1]] - $e[$ascent[0]]);

                $prevGain = $e[$prevTo] - $e[$prevFrom];

                if ($e[$ascent[1]] > $e[$prevTo] && $dip <= max(self::HYSTERESIS_M * 2, 0.1 * $gain) && $dip < 0.5 * $prevGain) {
                    $merged[$last] = [$prevFrom, $ascent[1]];
                    continue;
                }
            }

            $merged[] = $ascent;
        }

        return $merged;
    }

    /**
     * Cut off flat approach and flat top: move start/end inwards while the next/previous
     * 100 m are less steep than MIN_GRADE
     *
     * @return int[] [from, to]
     */
    private function trimFlatEnds(array $dist, array $e, $from, $to)
    {
        $window = self::MAX_GRADE_WINDOW_KM;

        while ($from < $to) {
            $j = $from;
            while ($j < $to && $dist[$j] - $dist[$from] < $window) {
                ++$j;
            }

            $d = $dist[$j] - $dist[$from];

            if ($d < $window * 0.8 || ($e[$j] - $e[$from]) / ($d * 1000) * 100 >= self::MIN_GRADE) {
                break;
            }

            ++$from;
        }

        while ($to > $from) {
            $j = $to;
            while ($j > $from && $dist[$to] - $dist[$j] < $window) {
                --$j;
            }

            $d = $dist[$to] - $dist[$j];

            if ($d < $window * 0.8 || ($e[$to] - $e[$j]) / ($d * 1000) * 100 >= self::MIN_GRADE) {
                break;
            }

            --$to;
        }

        return [$from, $to];
    }

    /**
     * @return float steepest grade over MAX_GRADE_WINDOW_KM within [from, to]
     */
    private function maxGrade(array $dist, array $e, $from, $to)
    {
        $max = 0.0;
        $j = $from;

        for ($i = $from; $i < $to; ++$i) {
            if ($j < $i) {
                $j = $i;
            }

            while ($j < $to && $dist[$j] - $dist[$i] < self::MAX_GRADE_WINDOW_KM) {
                ++$j;
            }

            $d = $dist[$j] - $dist[$i];

            if ($d >= self::MAX_GRADE_WINDOW_KM * 0.8) {
                $max = max($max, ($e[$j] - $e[$i]) / ($d * 1000) * 100);
            }
        }

        return $max;
    }
}
