<?php

declare(strict_types=1);

namespace JuryTool\Service;

use Doctrine\ORM\EntityManagerInterface;
use JuryTool\Domain\Entity\ImageAssignment;
use JuryTool\Domain\Entity\Round;
use JuryTool\Domain\Entity\RoundImage;
use JuryTool\Domain\Entity\RoundJuror;
use JuryTool\Support\DomainException;
use Psr\Log\LoggerInterface;

/**
 * Divides a round's images among its jurors.
 *
 * Each qualified image is dealt to exactly `quorum` jurors, so every image
 * collects the required number of independent opinions and each juror
 * carries an equal share of the work.
 */
class AssignmentService
{
    private const BATCH_SIZE = 500;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Deals every unassigned qualified image to `quorum` jurors.
     *
     * Safe to re-run: images that already have a full set of assignments
     * are left alone, so adding jurors or importing more images mid-round
     * only allocates the new work.
     *
     * @return array{images: int, assignments: int, perJuror: array<string, int>}
     */
    public function allocate(Round $round): array
    {
        $jurors = $this->activeJurors($round);
        $jurorCount = count($jurors);

        if ($jurorCount === 0) {
            throw new DomainException('The round has no active jurors to assign images to.');
        }

        $perImage = $round->effectiveQuorum();

        // A quorum above the panel size cannot be satisfied — no juror may
        // judge the same image twice. Silently capping it would hide the
        // misconfiguration, so it is reported instead.
        if ($perImage > $jurorCount) {
            throw new DomainException(sprintf(
                'Quorum of %d cannot be met by %d juror(s). Lower the quorum or add jurors.',
                $perImage,
                $jurorCount,
            ));
        }

        // Shuffling breaks any correlation between import order and who
        // judges what: without it, juror A always sees the images that
        // happened to be uploaded first.
        shuffle($jurors);

        $existing = $this->assignmentCounts($round);
        $load = $this->currentLoad($round, $jurors);

        // Dealing in a shuffled order stops consecutive uploads by the same
        // photographer from landing on the same juror.
        $images = $this->em->createQuery(
            'SELECT ri FROM ' . RoundImage::class . ' ri
             WHERE ri.round = :round AND ri.isDisqualified = false
             ORDER BY RAND()'
        )->setParameter('round', $round)->toIterable();

        $assigned = 0;
        $touched = 0;
        $cursor = 0;

        foreach ($images as $image) {
            $imageId = (int) $image->getId();
            $already = $existing[$imageId] ?? [];
            $needed = $perImage - count($already);

            if ($needed <= 0) {
                continue;
            }

            // Only jurors who do not already hold this image are eligible —
            // nobody may judge the same photograph twice, which also makes
            // this safe to re-run after a juror is added or removed.
            //
            // Candidates are gathered from a rotating cursor and then sorted
            // by current load, so the split stays even when the image count
            // does not divide cleanly by jurors × quorum.
            $candidates = [];
            for ($i = 0; $i < $jurorCount; $i++) {
                $juror = $jurors[($cursor + $i) % $jurorCount];
                $jurorId = (int) $juror->getId();

                if (!in_array($jurorId, $already, true)) {
                    $candidates[] = $juror;
                }
            }

            if (count($candidates) < $needed) {
                // Can only happen if the panel shrank below the quorum after
                // images were already dealt out.
                throw new DomainException(sprintf(
                    'Not enough eligible jurors to give image %d its %d votes.',
                    $imageId,
                    $perImage,
                ));
            }

            usort(
                $candidates,
                static fn (RoundJuror $a, RoundJuror $b): int
                    => $load[(int) $a->getId()] <=> $load[(int) $b->getId()],
            );

            foreach (array_slice($candidates, 0, $needed) as $juror) {
                $this->em->persist(new ImageAssignment($image, $juror));

                $load[(int) $juror->getId()]++;
                $assigned++;
            }

            $cursor++;
            $touched++;

            // Flush in batches to bound memory. Deliberately no clear():
            // the juror entities are held across the whole loop, and
            // detaching them would make Doctrine treat them as new.
            if (($assigned % self::BATCH_SIZE) === 0) {
                $this->em->flush();
            }
        }

        $this->em->flush();

        $perJuror = [];
        foreach ($jurors as $juror) {
            $perJuror[$juror->getUsername()] = $load[(int) $juror->getId()];
        }

        $this->logger->info('Round images allocated', [
            'round' => $round->getId(),
            'images' => $touched,
            'assignments' => $assigned,
            'perImage' => $perImage,
        ]);

        return ['images' => $touched, 'assignments' => $assigned, 'perJuror' => $perJuror];
    }

    /**
     * Moves a juror's outstanding assignments to the rest of the panel.
     *
     * Used when a juror withdraws: their unjudged images are redealt so the
     * round can still reach quorum without them.
     */
    public function reassignFrom(RoundJuror $juror): int
    {
        $round = $juror->getRound();

        $orphaned = $this->em->createQuery(
            'SELECT a FROM ' . ImageAssignment::class . ' a
             WHERE a.juror = :juror
               AND NOT EXISTS (
                   SELECT 1 FROM ' . \JuryTool\Domain\Entity\Vote::class . ' v
                   WHERE v.image = a.image AND v.juror = :juror
               )'
        )->setParameter('juror', $juror)->getResult();

        foreach ($orphaned as $assignment) {
            $this->em->remove($assignment);
        }

        $this->em->flush();

        // Re-running the allocator fills the gaps this just opened.
        $result = $this->allocate($round);

        return $result['assignments'];
    }

    /** How many images each juror has been given, and how many they have done. */
    public function workload(Round $round): array
    {
        $rows = $this->em->createQuery(
            'SELECT IDENTITY(a.juror) AS jurorId, COUNT(a.id) AS assigned
             FROM ' . ImageAssignment::class . ' a
             JOIN ' . RoundImage::class . ' ri WITH a.image = ri
             WHERE ri.round = :round
             GROUP BY a.juror'
        )->setParameter('round', $round)->getResult();

        $workload = [];
        foreach ($rows as $row) {
            $workload[(int) $row['jurorId']] = (int) $row['assigned'];
        }

        return $workload;
    }

    /** Whether this round has had its images dealt out at all. */
    public function hasAssignments(Round $round): bool
    {
        return (int) $this->em->createQuery(
            'SELECT COUNT(a.id)
             FROM ' . ImageAssignment::class . ' a
             JOIN ' . RoundImage::class . ' ri WITH a.image = ri
             WHERE ri.round = :round'
        )->setParameter('round', $round)->getSingleScalarResult() > 0;
    }

    /** @return list<RoundJuror> */
    private function activeJurors(Round $round): array
    {
        return array_values(
            $round->getJurors()
                ->filter(static fn (RoundJuror $j): bool => $j->isActive())
                ->toArray()
        );
    }

    /**
     * Juror ids already assigned to each image.
     *
     * @return array<int, list<int>>
     */
    private function assignmentCounts(Round $round): array
    {
        $rows = $this->em->createQuery(
            'SELECT IDENTITY(a.image) AS imageId, IDENTITY(a.juror) AS jurorId
             FROM ' . ImageAssignment::class . ' a
             JOIN ' . RoundImage::class . ' ri WITH a.image = ri
             WHERE ri.round = :round'
        )->setParameter('round', $round)->getScalarResult();

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['imageId']][] = (int) $row['jurorId'];
        }

        return $map;
    }

    /**
     * Current assignment count per juror, so re-running the allocator adds
     * to an even split rather than restarting from zero.
     *
     * @param list<RoundJuror> $jurors
     * @return array<int, int>
     */
    private function currentLoad(Round $round, array $jurors): array
    {
        $load = [];
        foreach ($jurors as $juror) {
            $load[(int) $juror->getId()] = 0;
        }

        foreach ($this->workload($round) as $jurorId => $count) {
            if (array_key_exists($jurorId, $load)) {
                $load[$jurorId] = $count;
            }
        }

        return $load;
    }
}
