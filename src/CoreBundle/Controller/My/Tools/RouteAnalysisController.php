<?php

namespace Runalyze\Bundle\CoreBundle\Controller\My\Tools;

use Runalyze\Bundle\CoreBundle\Component\Tool\Climb\ClimbStore;
use Runalyze\Bundle\CoreBundle\Component\Tool\Geo\GeoUtil;
use Runalyze\Bundle\CoreBundle\Component\Tool\Heatmap\HeatmapData;
use Runalyze\Bundle\CoreBundle\Component\Tool\RouteAnalysis\RouteAnalysisSchema;
use Runalyze\Bundle\CoreBundle\Component\Tool\Segment\SegmentStore;
use Runalyze\Bundle\CoreBundle\Entity\Account;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Security;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Heatmap per sport, climbs (climb score) and segments
 */
class RouteAnalysisController extends Controller
{
    /** @var float seconds per request for analysing existing activities */
    const TIME_BUDGET = 15.0;

    /**
     * @return ClimbStore
     */
    private function climbStore()
    {
        RouteAnalysisSchema::ensureTables($this->getDoctrine()->getConnection(), $this->getParameter('database_prefix'));

        return new ClimbStore($this->getDoctrine()->getConnection(), $this->getParameter('database_prefix'));
    }

    /**
     * @return SegmentStore
     */
    private function segmentStore()
    {
        RouteAnalysisSchema::ensureTables($this->getDoctrine()->getConnection(), $this->getParameter('database_prefix'));

        return new SegmentStore($this->getDoctrine()->getConnection(), $this->getParameter('database_prefix'));
    }

    /**
     * @return string
     */
    private function mapLayer()
    {
        return (string)$this->get('app.configuration_manager')->getList()->get('activity-view.TRAINING_LEAFLET_LAYER');
    }

    /**
     * @Route("/my/tools/heatmap", name="tools-heatmap")
     * @Security("has_role('ROLE_USER')")
     */
    public function heatmapAction()
    {
        return $this->render('tools/route_analysis/heatmap.html.twig', [
            'layer' => $this->mapLayer(),
        ]);
    }

    /**
     * @Route("/my/tools/heatmap/data", name="tools-heatmap-data")
     * @Security("has_role('ROLE_USER')")
     */
    public function heatmapDataAction(Account $account)
    {
        @set_time_limit(300);

        $data = new HeatmapData(
            $this->getDoctrine()->getConnection(),
            $this->getParameter('database_prefix'),
            $this->getParameter('kernel.cache_dir').'/heatmap'
        );

        return new Response($data->json($account->getId()), 200, ['Content-Type' => 'application/json']);
    }

    /**
     * @Route("/my/tools/track/{activityId}", name="tools-route-track", requirements={"activityId" = "\d+"})
     * @Security("has_role('ROLE_USER')")
     */
    public function trackAction($activityId, Request $request, Account $account)
    {
        $track = GeoUtil::loadTrack($this->getDoctrine()->getConnection(), $this->getParameter('database_prefix'), (int)$activityId, $account->getId());

        if (null === $track) {
            return new JsonResponse(['error' => 'Keine GPS-Daten für diese Aktivität.'], 404);
        }

        $num = count($track['lat']);
        $from = max(0, min($num - 1, (int)$request->query->get('from', 0)));
        $to = max($from, min($num - 1, (int)$request->query->get('to', $num - 1)));
        $points = [];

        foreach (GeoUtil::sampleIndices($from, $to, (int)$request->query->get('max', 600)) as $i) {
            $points[] = [
                $i,
                round($track['dist'][$i], 3),
                empty($track['elev']) ? null : round($track['elev'][$i], 1),
                null === $track['lat'][$i] ? null : round($track['lat'][$i], 6),
                null === $track['lng'][$i] ? null : round($track['lng'][$i], 6),
                empty($track['time']) ? null : (int)$track['time'][$i],
            ];
        }

        return new JsonResponse([
            'title' => $track['title'],
            'sportid' => $track['sportid'],
            'start' => $track['start'],
            'points' => $points,
        ]);
    }

    /**
     * @Route("/my/tools/climbs", name="tools-climbs")
     * @Security("has_role('ROLE_USER')")
     */
    public function climbsAction(Request $request, Account $account)
    {
        $store = $this->climbStore();
        $filter = [
            'sport' => (int)$request->query->get('sport', 0),
            'year' => (int)$request->query->get('year', 0),
            'category' => (string)$request->query->get('category', ''),
        ];

        return $this->render('tools/route_analysis/climbs.html.twig', [
            'pending' => $store->countPending($account->getId()),
            'groups' => $store->groups($account->getId(), $filter),
            'filter' => $filter,
            'sports' => $this->sportsWithClimbs($account),
            'years' => $this->yearsWithClimbs($account),
        ]);
    }

    /**
     * @Route("/my/tools/climbs/scan", name="tools-climbs-scan")
     * @Method("POST")
     * @Security("has_role('ROLE_USER')")
     */
    public function climbsScanAction(Account $account)
    {
        @set_time_limit(120);
        $store = $this->climbStore();
        $done = $store->scanPending($account->getId(), self::TIME_BUDGET);

        return new JsonResponse(['done' => $done, 'pending' => $store->countPending($account->getId())]);
    }

    /**
     * @Route("/my/tools/climbs/reset", name="tools-climbs-reset")
     * @Method("POST")
     * @Security("has_role('ROLE_USER')")
     */
    public function climbsResetAction(Account $account)
    {
        $this->climbStore()->reset($account->getId());

        return new JsonResponse(['ok' => true]);
    }

    /**
     * @Route("/my/tools/climbs/{id}", name="tools-climb", requirements={"id" = "\d+"})
     * @Security("has_role('ROLE_USER')")
     */
    public function climbAction($id, Account $account)
    {
        $group = $this->climbStore()->groupFor($account->getId(), (int)$id);

        if (null === $group) {
            throw $this->createNotFoundException();
        }

        $ranked = $group['climbs'];
        usort($ranked, function ($a, $b) {
            return (null === $a['duration'] ? PHP_INT_MAX : (int)$a['duration']) - (null === $b['duration'] ? PHP_INT_MAX : (int)$b['duration']);
        });

        return $this->render('tools/route_analysis/climb.html.twig', [
            'group' => $group,
            'ranked' => $ranked,
            'reference' => $group['climbs'][0],
            'layer' => $this->mapLayer(),
        ]);
    }

    /**
     * @Route("/my/tools/segments", name="tools-segments")
     * @Security("has_role('ROLE_USER')")
     */
    public function segmentsAction(Account $account)
    {
        $store = $this->segmentStore();

        return $this->render('tools/route_analysis/segments.html.twig', [
            'segments' => $store->all($account->getId()),
            'pending' => $store->countPending($account->getId()),
        ]);
    }

    /**
     * @Route("/my/tools/segments/scan", name="tools-segments-scan")
     * @Method("POST")
     * @Security("has_role('ROLE_USER')")
     */
    public function segmentsScanAction(Account $account)
    {
        @set_time_limit(120);
        $store = $this->segmentStore();
        $complete = $store->scan($account->getId(), self::TIME_BUDGET);

        return new JsonResponse(['complete' => $complete, 'pending' => $complete ? 0 : $store->countPending($account->getId())]);
    }

    /**
     * @Route("/my/tools/segments/new", name="tools-segment-new")
     * @Security("has_role('ROLE_USER')")
     */
    public function segmentNewAction(Request $request, Account $account)
    {
        $prefix = $this->getParameter('database_prefix');
        $activities = $this->getDoctrine()->getConnection()->fetchAll(
            'SELECT t.`id`, t.`time`, t.`title`, t.`distance`, s.`name` AS `sportname`, r.`name` AS `routename`
             FROM `'.$prefix.'training` t
             JOIN `'.$prefix.'route` r ON r.`id` = t.`routeid`
             LEFT JOIN `'.$prefix.'sport` s ON s.`id` = t.`sportid`
             WHERE t.`accountid` = ? AND r.`geohashes` IS NOT NULL AND r.`geohashes` != ""
             ORDER BY t.`time` DESC LIMIT 500',
            [$account->getId()]
        );

        return $this->render('tools/route_analysis/segment_new.html.twig', [
            'activities' => $activities,
            'activityId' => (int)$request->query->get('activity', 0),
            'from' => (int)$request->query->get('from', -1),
            'to' => (int)$request->query->get('to', -1),
            'layer' => $this->mapLayer(),
        ]);
    }

    /**
     * @Route("/my/tools/segments/create", name="tools-segment-create")
     * @Method("POST")
     * @Security("has_role('ROLE_USER')")
     */
    public function segmentCreateAction(Request $request, Account $account)
    {
        $id = $this->segmentStore()->create(
            $account->getId(),
            (int)$request->request->get('activity'),
            (int)$request->request->get('from'),
            (int)$request->request->get('to'),
            (string)$request->request->get('name', ''),
            (bool)$request->request->get('only_sport', true)
        );

        if (null === $id) {
            return new JsonResponse(['error' => 'Das Segment konnte nicht angelegt werden. Ist der gewählte Abschnitt mindestens 50 m lang und hat GPS-Daten?'], 400);
        }

        // check existing activities right away (new segment has scanned_until = 0, so it comes first)
        @set_time_limit(120);
        $this->segmentStore()->scan($account->getId(), self::TIME_BUDGET);

        return new JsonResponse(['id' => $id, 'url' => $this->generateUrl('tools-segment', ['id' => $id])]);
    }

    /**
     * @Route("/my/tools/segments/{id}", name="tools-segment", requirements={"id" = "\d+"})
     * @Security("has_role('ROLE_USER')")
     */
    public function segmentAction($id, Account $account)
    {
        $store = $this->segmentStore();
        $segment = $store->find($account->getId(), (int)$id);

        if (false === $segment) {
            throw $this->createNotFoundException();
        }

        $efforts = $store->efforts($account->getId(), (int)$id);

        return $this->render('tools/route_analysis/segment.html.twig', [
            'segment' => $segment,
            'efforts' => $efforts,
            'chart' => array_map(function ($effort) {
                return [(int)$effort['time'], null === $effort['duration'] ? null : (int)$effort['duration']];
            }, $efforts),
            'pending' => $store->countPending($account->getId()),
            'layer' => $this->mapLayer(),
        ]);
    }

    /**
     * @Route("/my/tools/segments/{id}/{action}", name="tools-segment-action", requirements={"id" = "\d+", "action" = "delete|rename|rescan"})
     * @Method("POST")
     * @Security("has_role('ROLE_USER')")
     */
    public function segmentModifyAction($id, $action, Request $request, Account $account)
    {
        $store = $this->segmentStore();

        if (false === $store->find($account->getId(), (int)$id)) {
            throw $this->createNotFoundException();
        }

        if ('delete' == $action) {
            $store->delete($account->getId(), (int)$id);
        } elseif ('rename' == $action) {
            $store->rename($account->getId(), (int)$id, (string)$request->request->get('name', ''));
        } else {
            $store->resetScan($account->getId(), (int)$id);
        }

        return new JsonResponse(['ok' => true]);
    }

    /**
     * @return array[]
     */
    private function sportsWithClimbs(Account $account)
    {
        RouteAnalysisSchema::ensureTables($this->getDoctrine()->getConnection(), $this->getParameter('database_prefix'));
        $prefix = $this->getParameter('database_prefix');

        return $this->getDoctrine()->getConnection()->fetchAll(
            'SELECT s.`id`, s.`name`, COUNT(c.`id`) AS `num` FROM `'.$prefix.'climb` c JOIN `'.$prefix.'sport` s ON s.`id` = c.`sportid`
             WHERE c.`accountid` = ? GROUP BY s.`id` ORDER BY `num` DESC',
            [$account->getId()]
        );
    }

    /**
     * @return int[]
     */
    private function yearsWithClimbs(Account $account)
    {
        $prefix = $this->getParameter('database_prefix');

        return array_map(function ($row) {
            return (int)$row['y'];
        }, $this->getDoctrine()->getConnection()->fetchAll(
            'SELECT DISTINCT YEAR(FROM_UNIXTIME(`time`)) AS `y` FROM `'.$prefix.'climb` WHERE `accountid` = ? ORDER BY `y` DESC',
            [$account->getId()]
        ));
    }
}
