<?php

namespace Runalyze\Bundle\CoreBundle\Controller\My\Tools;

use Runalyze\Bundle\CoreBundle\Component\Tool\Insights\CadenceCorrection;
use Runalyze\Bundle\CoreBundle\Component\Tool\Insights\DayStatistics;
use Runalyze\Bundle\CoreBundle\Component\Tool\Insights\PerformanceCurve;
use Runalyze\Bundle\CoreBundle\Entity\Account;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Security;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Streaks, Eddington number, moving average mileage and performance curves
 */
class InsightsController extends Controller
{
    /**
     * @return DayStatistics
     */
    private function dayStatistics()
    {
        return new DayStatistics($this->getDoctrine()->getConnection(), $this->getParameter('database_prefix'));
    }

    /**
     * @param Account $account
     * @return array[] sports with at least one activity
     */
    private function sportsWithActivities(Account $account)
    {
        $prefix = $this->getParameter('database_prefix');

        return $this->getDoctrine()->getConnection()->fetchAll(
            'SELECT s.`id`, s.`name`, COUNT(t.`id`) AS `num`
             FROM `'.$prefix.'sport` s
             JOIN `'.$prefix.'training` t ON t.`sportid` = s.`id`
             WHERE s.`accountid` = ? GROUP BY s.`id` ORDER BY `num` DESC, s.`name`',
            [$account->getId()]
        );
    }

    /**
     * @param int $seconds
     * @return string
     */
    private static function duration($seconds)
    {
        $seconds = (int)round($seconds);
        $h = intdiv($seconds, 3600);
        $m = intdiv($seconds % 3600, 60);
        $s = $seconds % 60;

        return $h > 0 ? sprintf('%d:%02d:%02d', $h, $m, $s) : sprintf('%d:%02d', $m, $s);
    }

    /**
     * @param string $date Y-m-d
     * @return string
     */
    private static function germanDate($date)
    {
        return date('d.m.Y', strtotime($date.' 12:00:00 UTC'));
    }

    /**
     * @param array|null $streak
     * @return array|null
     */
    private static function prepareStreak($streak)
    {
        if (null === $streak) {
            return null;
        }

        $streak['startText'] = self::germanDate($streak['start']);
        $streak['endText'] = self::germanDate($streak['end']);
        $streak['kmText'] = number_format($streak['km'], 1, ',', '.');
        $streak['durationText'] = self::duration($streak['seconds']);

        return $streak;
    }

    /**
     * @Route("/my/tools/streaks", name="tools-streaks")
     * @Security("has_role('ROLE_USER')")
     */
    public function streaksAction(Request $request, Account $account)
    {
        $sport = max(0, (int)$request->query->get('sport', 0));
        $minKm = max(0.0, (float)str_replace(',', '.', (string)$request->query->get('min', '0')));
        $stats = $this->dayStatistics();
        $result = $stats->streaks($stats->days($account->getId(), $sport), $minKm);

        return $this->render('tools/insights/streaks.html.twig', [
            'sport' => $sport,
            'min' => $minKm,
            'sports' => $this->sportsWithActivities($account),
            'activeDays' => $result['activeDays'],
            'longest' => self::prepareStreak($result['longest']),
            'current' => self::prepareStreak($result['current']),
            'streaks' => array_map([self::class, 'prepareStreak'], $result['streaks']),
        ]);
    }

    /**
     * @Route("/my/tools/eddington", name="tools-eddington")
     * @Security("has_role('ROLE_USER')")
     */
    public function eddingtonAction(Request $request, Account $account)
    {
        $sport = max(0, (int)$request->query->get('sport', 0));
        $miles = 'mi' === $request->query->get('unit');
        $stats = $this->dayStatistics();

        return $this->render('tools/insights/eddington.html.twig', [
            'sport' => $sport,
            'miles' => $miles,
            'sports' => $this->sportsWithActivities($account),
            'result' => $stats->eddington($stats->days($account->getId(), $sport), $miles),
        ]);
    }

    /**
     * @Route("/my/tools/moving-mileage", name="tools-moving-mileage")
     * @Security("has_role('ROLE_USER')")
     */
    public function movingMileageAction(Request $request, Account $account)
    {
        $sport = max(0, (int)$request->query->get('sport', 0));
        $stats = $this->dayStatistics();

        return $this->render('tools/insights/moving_mileage.html.twig', [
            'sport' => $sport,
            'sports' => $this->sportsWithActivities($account),
            'series' => $stats->dailySeries($stats->days($account->getId(), $sport)),
        ]);
    }

    /**
     * @Route("/my/tools/performance-curves", name="tools-performance-curves")
     * @Security("has_role('ROLE_USER')")
     */
    public function performanceCurvesAction(Request $request, Account $account)
    {
        @set_time_limit(120);

        $sport = max(0, (int)$request->query->get('sport', 0));
        $days = (int)$request->query->get('days', 90);
        $days = in_array($days, [30, 90, 180, 365, 0], true) ? $days : 90;
        $metric = (string)$request->query->get('metric', PerformanceCurve::METRIC_POWER);
        $metric = in_array($metric, [PerformanceCurve::METRIC_POWER, PerformanceCurve::METRIC_HEART_RATE, PerformanceCurve::METRIC_PACE], true)
            ? $metric : PerformanceCurve::METRIC_POWER;

        $curve = new PerformanceCurve($this->getDoctrine()->getConnection(), $this->getParameter('database_prefix'));
        $result = $curve->calculate($account->getId(), $sport, $days, $metric);
        $rows = [];
        $chart = [];

        foreach ($result['rows'] as $row) {
            $row['durationText'] = $row['duration'] >= 60 ? ($row['duration'] / 60).' min' : $row['duration'].' s';
            $row['dateText'] = date('d.m.Y', $row['date']);
            $row['valueText'] = PerformanceCurve::METRIC_PACE === $metric
                ? self::duration($row['value']).' /km'
                : (PerformanceCurve::METRIC_POWER === $metric ? round($row['value']).' W' : round($row['value']).' bpm');
            $rows[] = $row;
            $chart[] = [
                'd' => $row['duration'],
                'v' => round(PerformanceCurve::METRIC_PACE === $metric ? $row['speed'] : $row['value'], 3),
                'label' => $row['valueText'],
            ];
        }

        return $this->render('tools/insights/performance_curves.html.twig', [
            'chart' => $chart,
            'sport' => $sport,
            'days' => $days,
            'metric' => $metric,
            'sports' => $this->sportsWithActivities($account),
            'rows' => $rows,
            'activities' => $result['activities'],
            'used' => $result['used'],
            'truncated' => $result['truncated'],
        ]);
    }
    /**
     * @return CadenceCorrection
     */
    private function cadenceCorrection()
    {
        return new CadenceCorrection($this->getDoctrine()->getConnection(), $this->getParameter('database_prefix'));
    }

    /**
     * @Route("/my/tools/cadence-correction", name="tools-cadence-correction")
     * @Security("has_role('ROLE_USER')")
     */
    public function cadenceCorrectionAction(Request $request, Account $account)
    {
        $prefix = $this->getParameter('database_prefix');
        $connection = $this->getDoctrine()->getConnection();
        $sports = $connection->fetchAll(
            'SELECT s.`id`, s.`name`, s.`internal_sport_id` AS `internal`, COUNT(t.`id`) AS `num`
             FROM `'.$prefix.'sport` s
             JOIN `'.$prefix.'training` t ON t.`sportid` = s.`id` AND t.`cadence` > 0
             WHERE s.`accountid` = ? GROUP BY s.`id` ORDER BY `num` DESC',
            [$account->getId()]
        );

        $sport = (int)$request->query->get('sport', 0);

        if ($sport <= 0) {
            foreach ($sports as $row) {
                if (1 === (int)$row['internal']) {
                    $sport = (int)$row['id'];
                    break;
                }
            }

            if ($sport <= 0 && !empty($sports)) {
                $sport = (int)$sports[0]['id'];
            }
        }

        $low = max(1, (int)$request->query->get('low', 50));
        $high = max($low + 1, (int)$request->query->get('high', 110));
        $candidates = $sport > 0 ? $this->cadenceCorrection()->candidates($account->getId(), $sport, $low, $high) : [];

        foreach ($candidates as &$row) {
            $row['dateText'] = date('d.m.Y', (int)$row['time']);
            $row['newCadence'] = (int)round($row['cadence'] * $row['direction']);
        }

        return $this->render('tools/insights/cadence_correction.html.twig', [
            'sports' => $sports,
            'sport' => $sport,
            'low' => $low,
            'high' => $high,
            'candidates' => $candidates,
            'limit' => CadenceCorrection::MAX_CANDIDATES,
        ]);
    }

    /**
     * @Route("/my/tools/cadence-correction/apply", name="tools-cadence-correction-apply")
     * @Method("POST")
     * @Security("has_role('ROLE_USER')")
     */
    public function cadenceCorrectionApplyAction(Request $request, Account $account)
    {
        if (!$request->isXmlHttpRequest()) {
            return new JsonResponse(['error' => 'Nur per Ajax erlaubt.'], 400);
        }

        $factor = (float)$request->request->get('factor', 0);

        try {
            $changed = $this->cadenceCorrection()->apply($account->getId(), (array)$request->request->get('ids', []), $factor);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => 'Ungültiger Faktor.'], 400);
        }

        return new JsonResponse(['changed' => $changed]);
    }
}
