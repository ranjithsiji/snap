<?php

declare(strict_types=1);

namespace JuryTool\Service;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use JuryTool\Domain\Entity\CampaignImage;
use JuryTool\Domain\Entity\Round;
use JuryTool\Domain\Entity\RoundImage;
use JuryTool\Domain\Entity\User;
use JuryTool\Domain\Enum\RoundState;
use JuryTool\Domain\Enum\UserRole;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Puts images into a round.
 *
 * A first round draws the whole campaign pool; a derived round receives a
 * pre-selected subset from RoundDerivationService. Either way the round's
 * file settings decide which of those images are disqualified.
 */
class RoundPopulationService
{
    private const BATCH_SIZE = 500;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AssignmentService $assignments,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Fills a round from its campaign's master pool.
     *
     * @return PopulationResult
     */
    public function populateFromCampaignPool(Round $round): PopulationResult
    {
        $campaign = $round->getCampaign();

        if (!$campaign->hasBeenImported()) {
            throw new RuntimeException('Campaign images have not been imported yet.');
        }

        $images = $this->em->getRepository(CampaignImage::class)
            ->findBy(['campaign' => $campaign]);

        return $this->populate($round, $images);
    }

    /**
     * Adds the given campaign images to the round, marking each as
     * qualified or disqualified per the round's file settings.
     *
     * @param iterable<CampaignImage> $images
     */
    public function populate(Round $round, iterable $images): PopulationResult
    {
        if ($round->getState()->isFinal()) {
            throw new RuntimeException('A finalized round cannot be repopulated.');
        }

        $evaluator = new DisqualificationEvaluator($round, $this->globalOrganizers());
        $existing = $this->existingImageIds($round);

        $added = 0;
        $disqualified = 0;
        $skipped = 0;
        $processed = 0;

        foreach ($images as $image) {
            if (isset($existing[$image->getId()])) {
                $skipped++;
                continue;
            }

            $roundImage = new RoundImage($round, $image);
            $reason = $evaluator->reasonFor($image);

            if ($reason !== null) {
                $roundImage->disqualify($reason);
                $disqualified++;
            }

            $this->em->persist($roundImage);
            $existing[$image->getId()] = true;
            $added++;

            if ((++$processed % self::BATCH_SIZE) === 0) {
                $this->em->flush();
            }
        }

        $this->em->flush();

        $this->logger->info('Round populated', [
            'round' => $round->getId(),
            'added' => $added,
            'disqualified' => $disqualified,
            'skipped' => $skipped,
        ]);

        return new PopulationResult($added, $disqualified, $skipped);
    }

    /**
     * Re-applies the round's file settings to images already in it.
     *
     * Needed when a coordinator edits the settings after populating: images
     * that now pass are requalified, and newly failing ones are excluded.
     * Images that already carry votes are left alone, since retroactively
     * disqualifying them would discard real juror work.
     */
    public function reapplyRules(Round $round): PopulationResult
    {
        if ($round->getState()->isFinal()) {
            throw new RuntimeException('A finalized round cannot be re-evaluated.');
        }

        $evaluator = new DisqualificationEvaluator($round, $this->globalOrganizers());

        $changed = 0;
        $disqualified = 0;
        $preserved = 0;

        foreach ($round->getImages() as $roundImage) {
            if ($roundImage->getVotes()->count() > 0) {
                $preserved++;
                continue;
            }

            $reason = $evaluator->reasonFor($roundImage->getImage());

            if ($reason !== null) {
                $disqualified++;

                if (!$roundImage->isDisqualified() || $roundImage->getDisqualificationReason() !== $reason) {
                    $roundImage->disqualify($reason);
                    $changed++;
                }
            } elseif ($roundImage->isDisqualified()) {
                $roundImage->requalify();
                $changed++;
            }
        }

        $this->em->flush();

        $this->logger->info('Round rules re-applied', [
            'round' => $round->getId(),
            'changed' => $changed,
            'disqualified' => $disqualified,
            'skipped_with_votes' => $preserved,
        ]);

        return new PopulationResult($changed, $disqualified, $preserved);
    }

    /**
     * Usernames holding a tool-wide organizing role.
     *
     * The "disqualify organizers" rule should catch anyone running the
     * contest, whether or not somebody remembered to add them to this
     * campaign's participant list.
     *
     * @return list<string>
     */
    private function globalOrganizers(): array
    {
        // The array type is stated rather than inferred: DBAL 4 requires an
        // ArrayParameterType enum here, and inference has been seen to hand
        // it the old integer constant instead — which aborts the import
        // with a TypeError from deep inside ExpandArrayParameters.
        $rows = $this->em->createQuery(
            'SELECT u.username FROM ' . User::class . ' u WHERE u.role IN (:roles)'
        )->setParameter(
            'roles',
            [UserRole::Organizer->value, UserRole::Admin->value],
            ArrayParameterType::STRING,
        )->getScalarResult();

        return array_map(static fn (array $row): string => (string) $row['username'], $rows);
    }

    /**
     * Ids of campaign images already present in the round.
     *
     * @return array<int, true>
     */
    private function existingImageIds(Round $round): array
    {
        if ($round->getId() === null) {
            return [];
        }

        $rows = $this->em->createQuery(
            'SELECT IDENTITY(ri.image) AS imageId FROM ' . RoundImage::class . ' ri WHERE ri.round = :round'
        )->setParameter('round', $round)->getScalarResult();

        $ids = [];
        foreach ($rows as $row) {
            $ids[(int) $row['imageId']] = true;
        }

        return $ids;
    }

    /**
     * Opens a round for voting. Guards the transition so a round cannot go
     * live with nothing to vote on.
     */
    public function activate(Round $round): void
    {
        if (!$round->getState()->canTransitionTo(RoundState::Active)) {
            throw new RuntimeException(
                sprintf('A %s round cannot be activated.', $round->getState()->value)
            );
        }

        if ($round->qualifiedImageCount() === 0) {
            throw new RuntimeException('The round has no qualified images to vote on.');
        }

        if ($round->activeJurorCount() === 0) {
            throw new RuntimeException('The round has no jurors assigned.');
        }

        $round->setState(RoundState::Active);
        $this->em->flush();

        // Deal the images out now that the panel and the qualified set are
        // both settled, so every juror starts with a defined workload and
        // each image is guaranteed its quorum of opinions.
        $this->assignments->allocate($round);
    }

    /** Moves a round between states, validating the transition. */
    public function transition(Round $round, RoundState $target): void
    {
        if ($target === RoundState::Active) {
            $this->activate($round);

            return;
        }

        if (!$round->getState()->canTransitionTo($target)) {
            throw new RuntimeException(sprintf(
                'Cannot move a %s round to %s.',
                $round->getState()->value,
                $target->value,
            ));
        }

        $round->setState($target);
        $this->em->flush();
    }
}
