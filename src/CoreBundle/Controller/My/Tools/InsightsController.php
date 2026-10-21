<?php

namespace Runalyze\Bundle\CoreBundle\Controller\My\Tools;

use Runalyze\Bundle\CoreBundle\Component\Tool\Insights\BulkEditor;
use Runalyze\Bundle\CoreBundle\Component\Tool\Insights\CadenceCorrection;
use Runalyze\Bundle\CoreBundle\Component\Tool\Insights\DayStatistics;
use Runalyze\Bundle\CoreBundle\Component\Tool\Insights\OriginalFileMerger;
use Runalyze\Bundle\CoreBundle\Component\Tool\Insights\PerformanceCurve;
use Runalyze\Bundle\CoreBundle\Entity\Account;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Security;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\File\UploadedFile;
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
    /**
     * @Route("/my/tools/bulk-edit", name="tools-bulk-edit")
     * @Security("has_role('ROLE_USER')")
     */
    public function bulkEditAction(Request $request, Account $account)
    {
        $prefix = $this->getParameter('database_prefix');
        $connection = $this->getDoctrine()->getConnection();
        $filter = [
            'sport' => max(0, (int)$request->query->get('sport', 0)),
            'from' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$request->query->get('from')) ? (string)$request->query->get('from') : '',
            'to' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$request->query->get('to')) ? (string)$request->query->get('to') : '',
            'q' => trim((string)$request->query->get('q', '')),
        ];

        $where = 't.`accountid` = ?';
        $params = [$account->getId()];

        if ($filter['sport'] > 0) {
            $where .= ' AND t.`sportid` = ?';
            $params[] = $filter['sport'];
        }

        if ('' !== $filter['from']) {
            $where .= ' AND t.`time` >= ?';
            $params[] = strtotime($filter['from'].' 00:00:00');
        }

        if ('' !== $filter['to']) {
            $where .= ' AND t.`time` <= ?';
            $params[] = strtotime($filter['to'].' 23:59:59');
        }

        if ('' !== $filter['q']) {
            $where .= ' AND t.`title` LIKE ?';
            $params[] = '%'.addcslashes($filter['q'], '%_\\').'%';
        }

        $rows = $connection->fetchAll(
            'SELECT t.`id`, t.`time`, t.`title`, t.`distance`, t.`is_public`, s.`name` AS `sport`, ty.`name` AS `type`
             FROM `'.$prefix.'training` t
             JOIN `'.$prefix.'sport` s ON s.`id` = t.`sportid`
             LEFT JOIN `'.$prefix.'type` ty ON ty.`id` = t.`typeid`
             WHERE '.$where.' ORDER BY t.`time` DESC LIMIT 300',
            $params
        );

        foreach ($rows as &$row) {
            $row['dateText'] = date('d.m.Y H:i', (int)$row['time']);
        }

        return $this->render('tools/insights/bulk_edit.html.twig', [
            'filter' => $filter,
            'rows' => $rows,
            'sports' => $connection->fetchAll('SELECT `id`, `name` FROM `'.$prefix.'sport` WHERE `accountid` = ? ORDER BY `name`', [$account->getId()]),
            'types' => $connection->fetchAll(
                'SELECT ty.`id`, ty.`name`, s.`name` AS `sport` FROM `'.$prefix.'type` ty JOIN `'.$prefix.'sport` s ON s.`id` = ty.`sportid`
                 WHERE ty.`accountid` = ? ORDER BY s.`name`, ty.`name`',
                [$account->getId()]
            ),
            'tags' => $connection->fetchAll('SELECT `id`, `tag` FROM `'.$prefix.'tag` WHERE `accountid` = ? ORDER BY `tag`', [$account->getId()]),
            'maxPerRequest' => BulkEditor::MAX_ACTIVITIES,
        ]);
    }

    /**
     * @Route("/my/tools/bulk-edit/apply", name="tools-bulk-edit-apply")
     * @Method("POST")
     * @Security("has_role('ROLE_USER')")
     */
    public function bulkEditApplyAction(Request $request, Account $account)
    {
        if (!$request->isXmlHttpRequest()) {
            return new JsonResponse(['error' => 'Nur per Ajax erlaubt.'], 400);
        }

        @set_time_limit(120);

        $editor = new BulkEditor($this->getDoctrine()->getManager(), $this->get('app.legacy_cache'));
        $result = $editor->apply($account, (array)$request->request->get('ids', []), (array)$request->request->get('changes', []));

        if ($result['changed'] > 0) {
            $this->get('app.automatic_reload_flag_setter')->set(\Runalyze\Bundle\CoreBundle\Services\AutomaticReloadFlagSetter::FLAG_ALL);
        }

        return new JsonResponse($result);
    }
    /**
     * @return OriginalFileMerger
     */
    private function originalFileMerger()
    {
        return new OriginalFileMerger(
            $this->get('app.file_importer'),
            $this->get('app.activity_data_container.filter'),
            $this->get('app.activity_data_container.converter'),
            $this->getDoctrine()->getManager(),
            $this->get('app.legacy_cache')
        );
    }

    /**
     * @return string directory for uploaded files that wait for the user's decision
     */
    private function mergeDirectory()
    {
        $directory = $this->getParameter('data_directory').'/import/merge/';

        if (!is_dir($directory)) {
            @mkdir($directory, 0775, true);
        }

        foreach (glob($directory.'*.fit') ?: [] as $old) {
            if (filemtime($old) < time() - 3600) {
                @unlink($old);
            }
        }

        return $directory;
    }

    /**
     * @Route("/my/tools/merge-original", name="tools-merge-original")
     * @Security("has_role('ROLE_USER')")
     */
    public function mergeOriginalAction()
    {
        $serverParams = new \Runalyze\Util\ServerParams();
        $maxFileSize = min($serverParams->getPostMaxSizeInBytes(), $serverParams->getUploadMaxFilesize());

        return $this->render('tools/insights/merge_original.html.twig', [
            'maxFileSize' => $maxFileSize < PHP_INT_MAX ? $maxFileSize : false,
        ]);
    }

    /**
     * @Route("/my/tools/merge-original/preview", name="tools-merge-original-preview")
     * @Method("POST")
     * @Security("has_role('ROLE_USER')")
     */
    public function mergeOriginalPreviewAction(Request $request, Account $account)
    {
        if (!$request->isXmlHttpRequest()) {
            return new JsonResponse(['error' => 'Nur per Ajax erlaubt.'], 400);
        }

        @set_time_limit(120);

        $file = $request->files->get('file');

        if (is_array($file)) {
            $file = reset($file);
        }

        if (!$file instanceof UploadedFile || !$file->isValid()) {
            return new JsonResponse(['error' => 'Die Datei konnte nicht hochgeladen werden (zu groß oder kein Upload).'], 400);
        }

        if ('fit' !== strtolower((string)$file->getClientOriginalExtension())) {
            return new JsonResponse(['error' => 'Bitte eine Originaldatei im FIT-Format (.fit) wählen.'], 400);
        }

        $token = bin2hex(random_bytes(16));
        $directory = $this->mergeDirectory();
        $file->move($directory, $token.'.fit');
        $path = $directory.$token.'.fit';
        $merger = $this->originalFileMerger();

        try {
            $new = $merger->parse($path, $account);
        } catch (\Throwable $e) {
            @unlink($path);

            return new JsonResponse(['error' => 'Die Datei konnte nicht gelesen werden: '.$e->getMessage()], 422);
        }

        if (null === $new) {
            @unlink($path);

            return new JsonResponse(['error' => 'In der Datei wurde keine Aktivität gefunden.'], 422);
        }

        $candidates = [];

        foreach ($merger->candidates($new, $account) as $candidate) {
            $activity = $candidate['activity'];
            $missing = [];

            foreach ($merger->missing($activity, $new) as $values) {
                $missing[] = ['label' => $values['label'], 'value' => OriginalFileMerger::format($values['new'])];
            }

            $candidates[] = [
                'id' => $activity->getId(),
                'dateText' => date('d.m.Y H:i', (int)$activity->getTime()),
                'title' => (string)$activity->getTitle(),
                'duration' => self::duration((int)$activity->getS()),
                'km' => number_format((float)$activity->getDistance(), 2, ',', '.'),
                'reason' => $candidate['reason'],
                'missing' => $missing,
            ];
        }

        return new JsonResponse([
            'token' => $token,
            'file' => $file->getClientOriginalName(),
            'new' => [
                'dateText' => date('d.m.Y H:i', (int)$new->getTime()),
                'duration' => self::duration((int)$new->getS()),
                'km' => number_format((float)$new->getDistance(), 2, ',', '.'),
            ],
            'candidates' => $candidates,
        ]);
    }

    /**
     * @Route("/my/tools/merge-original/apply", name="tools-merge-original-apply")
     * @Method("POST")
     * @Security("has_role('ROLE_USER')")
     */
    public function mergeOriginalApplyAction(Request $request, Account $account)
    {
        if (!$request->isXmlHttpRequest()) {
            return new JsonResponse(['error' => 'Nur per Ajax erlaubt.'], 400);
        }

        @set_time_limit(120);

        $token = (string)$request->request->get('token', '');
        $activityId = (int)$request->request->get('activity', 0);

        if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
            return new JsonResponse(['error' => 'Ungültige Anfrage.'], 400);
        }

        $path = $this->mergeDirectory().$token.'.fit';

        if (!is_file($path)) {
            return new JsonResponse(['error' => 'Die hochgeladene Datei ist abgelaufen, bitte erneut wählen.'], 404);
        }

        $merger = $this->originalFileMerger();

        try {
            $new = $merger->parse($path, $account);
        } catch (\Throwable $e) {
            return new JsonResponse(['error' => 'Die Datei konnte nicht gelesen werden.'], 422);
        }

        if (null === $new) {
            return new JsonResponse(['error' => 'In der Datei wurde keine Aktivität gefunden.'], 422);
        }

        // only activities that were offered as a match for this file
        foreach ($merger->candidates($new, $account) as $candidate) {
            if ($candidate['activity']->getId() === $activityId) {
                $changed = $merger->merge($candidate['activity'], $new);
                @unlink($path);

                if (!empty($changed)) {
                    $this->get('app.automatic_reload_flag_setter')->set(\Runalyze\Bundle\CoreBundle\Services\AutomaticReloadFlagSetter::FLAG_ALL);
                }

                return new JsonResponse(['changed' => $changed]);
            }
        }

        return new JsonResponse(['error' => 'Diese Aktivität passt nicht zur Datei.'], 400);
    }
}
