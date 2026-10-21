<?php

namespace Runalyze\Bundle\CoreBundle\Component\Tool\Insights;

use Doctrine\ORM\EntityManagerInterface;
use Runalyze\Bundle\CoreBundle\Entity\Account;
use Runalyze\Bundle\CoreBundle\Entity\Training;
use Runalyze\Bundle\CoreBundle\Entity\TrainingRepository;
use Runalyze\Bundle\CoreBundle\Services\Import\ActivityDataContainerFilter;
use Runalyze\Bundle\CoreBundle\Services\Import\ActivityDataContainerToActivityContextConverter;
use Runalyze\Bundle\CoreBundle\Services\Import\FileImporter;

/**
 * Completes existing activities with values from the original file.
 *
 * Some values do not come through synchronization services (for example the VO2max estimate, recovery time
 * or performance condition of a Garmin watch). The original file contains them. Only values that are
 * missing in the existing activity are added, nothing that is already there is changed.
 */
class OriginalFileMerger
{
    /** @var int seconds the start times may differ */
    const TIME_TOLERANCE = 900;

    /** @var string[] other fields that are copied, besides all fields starting with "fit" */
    const ADDITIONAL_FIELDS = ['avgRespirationRate', 'maxRespirationRate', 'hrZoneBounds', 'maxHrUser'];

    /** @var string[] labels */
    const LABELS = [
        'fitVO2maxEstimate' => 'VO2max (Schätzung der Uhr)',
        'fitRecoveryTime' => 'Erholungszeit',
        'fitHrvAnalysis' => 'HRV-Analyse',
        'fitTrainingEffectBenefit' => 'Training Effect (Nutzen)',
        'fitTrainingEffect' => 'Training Effect (aerob)',
        'fitAnaerobicTrainingEffect' => 'Training Effect (anaerob)',
        'fitPerformanceCondition' => 'Performance Condition (Start)',
        'fitPerformanceConditionEnd' => 'Performance Condition (Ende)',
        'fitLactateThresholdHR' => 'Laktatschwelle (Puls)',
        'fitLactateThresholdPace' => 'Laktatschwelle (Tempo)',
        'fitTotalAscent' => 'Anstieg (Uhr)',
        'fitTotalDescent' => 'Abstieg (Uhr)',
        'fitSelfEvaluationFeeling' => 'Gefühl',
        'fitSelfEvaluationPreceivedEffort' => 'Empfundene Anstrengung',
        'fitLoadPeak' => 'Spitzenbelastung (EPOC)',
        'fitRunTime' => 'Laufzeit',
        'fitWalkTime' => 'Gehzeit',
        'fitStandTime' => 'Stehzeit',
        'fitSecondsInHrZones' => 'Sekunden in Pulszonen',
        'fitSweatLoss' => 'Schweißverlust',
        'avgRespirationRate' => 'Ø Atemfrequenz',
        'maxRespirationRate' => 'Max. Atemfrequenz',
        'hrZoneBounds' => 'Pulszonen des Geräts',
        'maxHrUser' => 'Maximalpuls (Nutzer)',
    ];

    /** @var FileImporter */
    private $Importer;

    /** @var ActivityDataContainerFilter */
    private $Filter;

    /** @var ActivityDataContainerToActivityContextConverter */
    private $Converter;

    /** @var EntityManagerInterface */
    private $EntityManager;

    /** @var object|null service with clearActivityCache(Training) */
    private $LegacyCache;

    /**
     * @param FileImporter $importer
     * @param ActivityDataContainerFilter $filter
     * @param ActivityDataContainerToActivityContextConverter $converter
     * @param EntityManagerInterface $entityManager
     * @param object|null $legacyCache
     */
    public function __construct(
        FileImporter $importer,
        ActivityDataContainerFilter $filter,
        ActivityDataContainerToActivityContextConverter $converter,
        EntityManagerInterface $entityManager,
        $legacyCache = null
    ) {
        $this->Importer = $importer;
        $this->Filter = $filter;
        $this->Converter = $converter;
        $this->EntityManager = $entityManager;
        $this->LegacyCache = $legacyCache;
    }

    /**
     * @param string $path
     * @param Account $account
     * @return Training|null the first activity of the file (not saved)
     */
    public function parse($path, Account $account)
    {
        $this->Importer->disableFileDeletion();
        $results = $this->Importer->importSingleFile($path);
        $results->completeAndFilterResults($this->Filter);

        if ($results->getTotalNumberOfActivities() < 1) {
            return null;
        }

        foreach ($results as $result) {
            if (!$result->isFailed() && count($result->getContainer()) > 0) {
                return $this->Converter->getActivityFor($result->getContainer()[0], $account);
            }
        }

        return null;
    }

    /**
     * @param Training $new activity from the file
     * @param Account $account
     * @return array[] 'activity' => Training, 'reason' => 'id'|'time'
     */
    public function candidates(Training $new, Account $account)
    {
        /** @var TrainingRepository $repository */
        $repository = $this->EntityManager->getRepository(Training::class);
        $found = [];

        if (null !== $new->getActivityId() && '' !== (string)$new->getActivityId()) {
            foreach ($repository->findBy(['account' => $account, 'activityId' => $new->getActivityId()], null, 3) as $activity) {
                $found[$activity->getId()] = ['activity' => $activity, 'reason' => 'id'];
            }
        }

        $duration = (int)$new->getS();
        $query = $repository->createQueryBuilder('t')
            ->where('t.account = :account AND t.time BETWEEN :from AND :to')
            ->setParameter('account', $account)
            ->setParameter('from', (int)$new->getTime() - self::TIME_TOLERANCE)
            ->setParameter('to', (int)$new->getTime() + self::TIME_TOLERANCE)
            ->orderBy('t.time', 'ASC')
            ->setMaxResults(10)
            ->getQuery();

        foreach ($query->getResult() as $activity) {
            $other = (int)$activity->getS();
            $tolerance = max(120, (int)round(0.1 * max($duration, $other)));

            if (!isset($found[$activity->getId()]) && abs($other - $duration) <= $tolerance) {
                $found[$activity->getId()] = ['activity' => $activity, 'reason' => 'time'];
            }
        }

        return array_values($found);
    }

    /**
     * @return string[] field names that may be completed
     */
    public function fieldNames()
    {
        $meta = $this->EntityManager->getClassMetadata(Training::class);
        $fields = [];

        foreach ($meta->getFieldNames() as $field) {
            if (0 === strpos($field, 'fit') || in_array($field, self::ADDITIONAL_FIELDS, true)) {
                if (method_exists(Training::class, 'get'.ucfirst($field)) && method_exists(Training::class, 'set'.ucfirst($field))) {
                    $fields[] = $field;
                }
            }
        }

        return $fields;
    }

    /**
     * @param Training $old existing activity
     * @param Training $new activity from the file
     * @return array[] field => ['old' => mixed, 'new' => mixed, 'label' => string] for values the old activity lacks
     */
    public function missing(Training $old, Training $new)
    {
        $missing = [];

        foreach ($this->fieldNames() as $field) {
            $getter = 'get'.ucfirst($field);
            $newValue = $new->$getter();

            if (self::isEmpty($newValue)) {
                continue;
            }

            $oldValue = $old->$getter();

            if (self::isEmpty($oldValue)) {
                $missing[$field] = [
                    'old' => $oldValue,
                    'new' => $newValue,
                    'label' => isset(self::LABELS[$field]) ? self::LABELS[$field] : $field,
                ];
            }
        }

        return $missing;
    }

    /**
     * @param Training $old
     * @param Training $new
     * @return string[] labels of the completed values
     */
    public function merge(Training $old, Training $new)
    {
        $missing = $this->missing($old, $new);

        if (empty($missing)) {
            return [];
        }

        foreach ($missing as $field => $values) {
            $setter = 'set'.ucfirst($field);
            $old->$setter($values['new']);
        }

        $this->EntityManager->getRepository(Training::class)->save($old);

        if (null !== $this->LegacyCache) {
            $this->LegacyCache->clearActivityCache($old);
        }

        return array_values(array_map(function ($values) {
            return $values['label'];
        }, $missing));
    }

    /**
     * @param mixed $value
     * @return bool null, empty string, 0 and empty arrays count as "no value"
     */
    public static function isEmpty($value)
    {
        if (is_array($value)) {
            return empty($value);
        }

        return null === $value || '' === $value || (is_numeric($value) && 0.0 === (float)$value);
    }

    /**
     * @param mixed $value
     * @return string
     */
    public static function format($value)
    {
        if (is_array($value)) {
            return implode(' | ', $value);
        }

        return (string)$value;
    }
}
