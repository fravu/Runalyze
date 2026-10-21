<?php

namespace Runalyze\Bundle\CoreBundle\Component\Tool\Insights;

use Doctrine\ORM\EntityManagerInterface;
use Runalyze\Bundle\CoreBundle\Entity\Account;
use Runalyze\Bundle\CoreBundle\Entity\Sport;
use Runalyze\Bundle\CoreBundle\Entity\Tag;
use Runalyze\Bundle\CoreBundle\Entity\Training;
use Runalyze\Bundle\CoreBundle\Entity\TrainingRepository;
use Runalyze\Bundle\CoreBundle\Entity\Type;

/**
 * Changes sport, activity type, visibility and tags of several activities at once.
 *
 * Activities are saved like in the activity editor (entity and repository), so the usual listeners
 * (recalculations after a change of the sport) run and the caches are cleared.
 */
class BulkEditor
{
    /** @var int */
    const MAX_ACTIVITIES = 200;

    /** @var EntityManagerInterface */
    private $EntityManager;

    /** @var object|null service with clearActivityCache(Training) */
    private $LegacyCache;

    /**
     * @param EntityManagerInterface $entityManager
     * @param object|null $legacyCache e.g. service 'app.legacy_cache'
     */
    public function __construct(EntityManagerInterface $entityManager, $legacyCache = null)
    {
        $this->EntityManager = $entityManager;
        $this->LegacyCache = $legacyCache;
    }

    /**
     * @param Account $account
     * @param int[] $ids
     * @param array $changes 'sport' => int, 'type' => int|'none', 'public' => '1'|'0', 'addTag' => int, 'removeTag' => int (all optional)
     * @return array ['changed' => int, 'skipped' => string[]]
     */
    public function apply(Account $account, array $ids, array $changes)
    {
        $ids = array_slice(array_values(array_unique(array_filter(array_map('intval', $ids)))), 0, self::MAX_ACTIVITIES);
        $result = ['changed' => 0, 'skipped' => []];

        $sport = $this->findOwned(Sport::class, isset($changes['sport']) ? (int)$changes['sport'] : 0, $account);
        $addTag = $this->findOwned(Tag::class, isset($changes['addTag']) ? (int)$changes['addTag'] : 0, $account);
        $removeTag = $this->findOwned(Tag::class, isset($changes['removeTag']) ? (int)$changes['removeTag'] : 0, $account);
        $type = null;
        $clearType = isset($changes['type']) && 'none' === $changes['type'];

        if (isset($changes['type']) && !$clearType && (int)$changes['type'] > 0) {
            $type = $this->findOwned(Type::class, (int)$changes['type'], $account);

            if (null === $type) {
                $result['skipped'][] = 'Aktivitätstyp nicht gefunden.';

                return $result;
            }
        }

        if (isset($changes['sport']) && (int)$changes['sport'] > 0 && null === $sport) {
            $result['skipped'][] = 'Sportart nicht gefunden.';

            return $result;
        }

        $public = isset($changes['public']) && in_array((string)$changes['public'], ['0', '1'], true)
            ? (bool)(int)$changes['public'] : null;

        /** @var TrainingRepository $repository */
        $repository = $this->EntityManager->getRepository(Training::class);

        foreach ($ids as $id) {
            /** @var Training|null $activity */
            $activity = $repository->find($id);

            if (null === $activity || $activity->getAccount()->getId() !== $account->getId()) {
                $result['skipped'][] = 'Aktivität '.$id.' nicht gefunden.';
                continue;
            }

            if (null !== $sport && $activity->getSport()->getId() !== $sport->getId()) {
                $activity->setSport($sport);

                // a type belongs to one sport
                if (null !== $activity->getType() && $activity->getType()->getSport()->getId() !== $sport->getId()) {
                    $activity->setType(null);
                }
            }

            if ($clearType) {
                $activity->setType(null);
            } elseif (null !== $type) {
                if ($type->getSport()->getId() !== $activity->getSport()->getId()) {
                    $result['skipped'][] = 'Aktivität '.$id.': der Typ gehört zu einer anderen Sportart.';
                    continue;
                }

                $activity->setType($type);
            }

            if (null !== $public) {
                $activity->setPublic($public);
            }

            if (null !== $addTag && !$activity->getTag()->contains($addTag)) {
                $activity->addTag($addTag);
            }

            if (null !== $removeTag && $activity->getTag()->contains($removeTag)) {
                $activity->removeTag($removeTag);
            }

            $repository->save($activity);

            if (null !== $this->LegacyCache) {
                $this->LegacyCache->clearActivityCache($activity);
            }

            ++$result['changed'];
        }

        return $result;
    }

    /**
     * @param string $class
     * @param int $id
     * @param Account $account
     * @return object|null entity of the account or null
     */
    private function findOwned($class, $id, Account $account)
    {
        if ($id <= 0) {
            return null;
        }

        $entity = $this->EntityManager->getRepository($class)->find($id);

        if (null === $entity || !method_exists($entity, 'getAccount') || $entity->getAccount()->getId() !== $account->getId()) {
            return null;
        }

        return $entity;
    }
}
