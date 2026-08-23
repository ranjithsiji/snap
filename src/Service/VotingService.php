<?php

declare(strict_types=1);

namespace JuryTool\Service;

use Doctrine\ORM\EntityManagerInterface;
use JuryTool\Domain\Entity\ImageAssignment;
use JuryTool\Domain\Entity\JurorImageAction;
use JuryTool\Domain\Entity\Round;
use JuryTool\Domain\Entity\RoundImage;
use JuryTool\Domain\Entity\RoundJuror;
use JuryTool\Domain\Entity\User;
use JuryTool\Domain\Entity\Vote;
use JuryTool\Domain\Enum\VotingMethod;
use JuryTool\Support\DomainException;

/**
 * Casting and revising votes, and choosing what a juror sees next.
 */
class VotingService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Records a juror's judgement of one image, replacing any earlier vote
     * they cast on it.
     *
     * @throws DomainException when the round is closed, the juror is not on
     *                         the roster, or the score is out of range.
     */
    public function castVote(RoundImage $image, User $user, int $score, ?string $comment = null): Vote
    {
        $round = $image->getRound();

        if (!$round->acceptsVotes()) {
            throw new DomainException(
                $round->hasDeadlinePassed()
                    ? 'The voting deadline for this round has passed.'
                    : sprintf('This round is %s and is not accepting votes.', $round->getState()->value),
            );
        }

        if ($image->isDisqualified()) {
            throw new DomainException('This image has been disqualified and cannot be voted on.');
        }

        $juror = $this->resolveJuror($round, $user);
        $this->assertScoreInRange($round, $score);

        $existing = $this->em->getRepository(Vote::class)->findOneBy([
            'image' => $image,
            'juror' => $juror,
        ]);

        if ($existing !== null) {
            $existing->setScore($score);
            $existing->setComment($comment);
            $this->em->flush();

            return $existing;
        }

        $vote = new Vote($image, $juror, $score);
        $vote->setComment($comment);

        $this->em->persist($vote);
        $this->em->flush();

        return $vote;
    }

    /**
     * Applies a juror's ordering of images in a ranking round.
     *
     * Takes the whole ordered list at once rather than one position at a
     * time: ranks are only meaningful relative to each other, and writing
     * them individually would leave the set briefly inconsistent.
     *
     * @param list<int> $orderedImageIds Image ids, best first.
     * @return list<Vote>
     */
    public function submitRanking(Round $round, User $user, array $orderedImageIds): array
    {
        if ($round->getVotingMethod() !== VotingMethod::RankOrder) {
            throw new DomainException('This round does not use rank ordering.');
        }

        if (!$round->acceptsVotes()) {
            throw new DomainException('This round is not accepting votes.');
        }

        $juror = $this->resolveJuror($round, $user);

        if (count($orderedImageIds) !== count(array_unique($orderedImageIds))) {
            throw new DomainException('The same image appears more than once in the ranking.');
        }

        /** @var array<int, RoundImage> $images */
        $images = [];
        foreach ($this->em->getRepository(RoundImage::class)->findBy(['id' => $orderedImageIds]) as $image) {
            $images[(int) $image->getId()] = $image;
        }

        $votes = [];
        $position = 1;

        foreach ($orderedImageIds as $imageId) {
            $image = $images[$imageId] ?? null;

            if ($image === null || $image->getRound()->getId() !== $round->getId()) {
                throw new DomainException(sprintf('Image %d is not part of this round.', $imageId));
            }

            if ($image->isDisqualified()) {
                throw new DomainException('A disqualified image cannot be ranked.');
            }

            $existing = $this->em->getRepository(Vote::class)->findOneBy([
                'image' => $image,
                'juror' => $juror,
            ]);

            if ($existing !== null) {
                $existing->setScore($position);
                $votes[] = $existing;
            } else {
                $vote = new Vote($image, $juror, $position);
                $this->em->persist($vote);
                $votes[] = $vote;
            }

            $position++;
        }

        $this->em->flush();

        return $votes;
    }

    /**
     * The next images this juror should judge.
     *
     * Images the juror has already voted on are excluded, as are
     * disqualified ones and those that have already met quorum. Ordering by
     * vote count ascending spreads jurors across the pool rather than
     * having them all converge on the same first image.
     *
     * @return list<RoundImage>
     */
    public function nextImagesFor(Round $round, User $user, int $limit = 1): array
    {
        $juror = $this->resolveJuror($round, $user);
        $quorum = $round->effectiveQuorum();

        // Ranking rounds ask the juror to order everything at once, so the
        // quorum-based drip feed does not apply.
        $applyQuorum = $round->getVotingMethod() !== VotingMethod::RankOrder;

        $qb = $this->em->createQueryBuilder()
            ->select('ri', 'ci', 'COUNT(v.id) AS HIDDEN voteCount')
            // Images dealt to this juror come first. Once their own list is
            // done they spill over onto work left by absent jurors, so a
            // round still finishes if someone stops participating.
            ->addSelect('(CASE WHEN EXISTS (
                SELECT 1 FROM ' . ImageAssignment::class . ' mineAssigned
                WHERE mineAssigned.image = ri AND mineAssigned.juror = :juror
            ) THEN 0 ELSE 1 END) AS HIDDEN isSpillover')
            // Skipped images sort last rather than disappearing, so a juror
            // who defers everything still gets shown their deferrals.
            ->addSelect('(CASE WHEN EXISTS (
                SELECT 1 FROM ' . JurorImageAction::class . ' skipped
                WHERE skipped.image = ri AND skipped.juror = :juror AND skipped.isSkipped = true
            ) THEN 1 ELSE 0 END) AS HIDDEN wasSkipped')
            ->from(RoundImage::class, 'ri')
            ->join('ri.image', 'ci')
            ->leftJoin('ri.votes', 'v')
            ->where('ri.round = :round')
            ->andWhere('ri.isDisqualified = false')
            ->andWhere('NOT EXISTS (
                SELECT 1 FROM ' . Vote::class . ' mine
                WHERE mine.image = ri AND mine.juror = :juror
            )')
            ->groupBy('ri.id', 'ci.id')
            ->orderBy('isSpillover', 'ASC')
            ->addOrderBy('wasSkipped', 'ASC')
            ->addOrderBy('voteCount', 'ASC')
            ->addOrderBy('ri.id', 'ASC')
            ->setParameter('round', $round)
            ->setParameter('juror', $juror)
            ->setMaxResults($limit);

        if ($applyQuorum) {
            // Spillover must not push an image past its quorum; the juror's
            // own assignments are exempt because they were dealt precisely
            // to satisfy it.
            $qb->having('COUNT(v.id) < :quorum OR MIN(CASE WHEN EXISTS (
                SELECT 1 FROM ' . ImageAssignment::class . ' a2
                WHERE a2.image = ri AND a2.juror = :juror
            ) THEN 1 ELSE 0 END) = 1')->setParameter('quorum', $quorum);
        }

        /** @var list<RoundImage> $result */
        $result = $qb->getQuery()->getResult();

        return $result;
    }

    /**
     * Defers an image, or restores it to its normal place in the queue.
     *
     * Skipping records no judgement, so the image still needs its quorum.
     */
    public function setSkipped(RoundImage $image, User $user, bool $skipped): void
    {
        $action = $this->actionFor($image, $user);
        $action->setSkipped($skipped);

        $this->persistOrDiscard($action);
    }

    /** Marks or unmarks an image as one of this juror's favourites. */
    public function setFavorite(RoundImage $image, User $user, bool $favorite): void
    {
        $action = $this->actionFor($image, $user);
        $action->setFavorite($favorite);

        $this->persistOrDiscard($action);
    }

    /**
     * Votes this juror has already cast in a round, so they can review and
     * revise earlier decisions.
     *
     * @return list<Vote>
     */
    public function previousVotes(Round $round, User $user, int $limit = 100, int $offset = 0): array
    {
        $juror = $this->resolveJuror($round, $user);

        /** @var list<Vote> $votes */
        $votes = $this->em->createQueryBuilder()
            ->select('v', 'ri', 'ci')
            ->from(Vote::class, 'v')
            ->join('v.image', 'ri')
            ->join('ri.image', 'ci')
            ->where('ri.round = :round')
            ->andWhere('v.juror = :juror')
            ->orderBy('v.updatedAt', 'DESC')
            ->setParameter('round', $round)
            ->setParameter('juror', $juror)
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->getQuery()
            ->getResult();

        return $votes;
    }

    /** This juror's favourites in a round. */
    public function favorites(Round $round, User $user): array
    {
        $juror = $this->resolveJuror($round, $user);

        return $this->em->createQueryBuilder()
            ->select('a', 'ri', 'ci')
            ->from(JurorImageAction::class, 'a')
            ->join('a.image', 'ri')
            ->join('ri.image', 'ci')
            ->where('ri.round = :round')
            ->andWhere('a.juror = :juror')
            ->andWhere('a.isFavorite = true')
            ->setParameter('round', $round)
            ->setParameter('juror', $juror)
            ->getQuery()
            ->getResult();
    }

    /**
     * A page of round images filtered by how this juror voted on them,
     * for the gallery view's Unrated / Selected / Rejected tabs.
     *
     * @param 'unrated'|'selected'|'rejected'|'favorites'|'all' $filter
     * @return array{images: list<array{image: RoundImage, score: int|null, isFavorite: bool}>, total: int}
     */
    public function gallery(
        Round $round,
        User $user,
        string $filter = 'unrated',
        int $limit = 60,
        int $offset = 0,
    ): array {
        $juror = $this->resolveJuror($round, $user);

        $build = function (bool $counting) use ($round, $juror, $filter) {
            $qb = $this->em->createQueryBuilder()
                ->from(RoundImage::class, 'ri')
                ->leftJoin(
                    Vote::class,
                    'mine',
                    'WITH',
                    'mine.image = ri AND mine.juror = :juror',
                )
                ->where('ri.round = :round')
                ->andWhere('ri.isDisqualified = false')
                ->setParameter('round', $round)
                ->setParameter('juror', $juror);

            $qb->select($counting ? 'COUNT(ri.id)' : 'ri, ci, mine.score AS score');

            if (!$counting) {
                $qb->join('ri.image', 'ci')->orderBy('ri.id', 'ASC');
            }

            match ($filter) {
                'unrated' => $qb->andWhere('mine.id IS NULL'),
                'selected' => $qb->andWhere('mine.score >= :threshold')
                    ->setParameter('threshold', $this->selectionThreshold($round)),
                'rejected' => $qb->andWhere('mine.score < :threshold')
                    ->setParameter('threshold', $this->selectionThreshold($round)),
                'favorites' => $qb->andWhere('EXISTS (
                    SELECT 1 FROM ' . JurorImageAction::class . ' fav
                    WHERE fav.image = ri AND fav.juror = :juror AND fav.isFavorite = true
                )'),
                default => null,
            };

            return $qb;
        };

        $total = (int) $build(true)->getQuery()->getSingleScalarResult();

        $rows = $build(false)
            ->getQuery()
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->getResult();

        $favorites = $this->favoriteImageIds($round, $juror);

        $images = [];
        foreach ($rows as $row) {
            $image = $row[0] ?? $row;

            $images[] = [
                'image' => $image,
                'score' => isset($row['score']) ? (int) $row['score'] : null,
                'isFavorite' => isset($favorites[(int) $image->getId()]),
            ];
        }

        return ['images' => $images, 'total' => $total];
    }

    /**
     * Counts for each gallery tab.
     *
     * @return array<string, int>
     */
    public function galleryCounts(Round $round, User $user): array
    {
        $juror = $this->resolveJuror($round, $user);
        $threshold = $this->selectionThreshold($round);

        $row = $this->em->createQuery(
            'SELECT
                COUNT(ri.id) AS total,
                SUM(CASE WHEN mine.id IS NULL THEN 1 ELSE 0 END) AS unrated,
                SUM(CASE WHEN mine.score >= :threshold THEN 1 ELSE 0 END) AS selected,
                SUM(CASE WHEN mine.score < :threshold THEN 1 ELSE 0 END) AS rejected
             FROM ' . RoundImage::class . ' ri
             LEFT JOIN ' . Vote::class . ' mine WITH mine.image = ri AND mine.juror = :juror
             WHERE ri.round = :round AND ri.isDisqualified = false'
        )->setParameters([
            'round' => $round,
            'juror' => $juror,
            'threshold' => $threshold,
        ])->getSingleResult();

        return [
            'all' => (int) ($row['total'] ?? 0),
            'unrated' => (int) ($row['unrated'] ?? 0),
            'selected' => (int) ($row['selected'] ?? 0),
            'rejected' => (int) ($row['rejected'] ?? 0),
        ];
    }

    /**
     * Score at or above which an image counts as "selected".
     *
     * Yes/no rounds accept on 1; rating rounds treat the upper half of the
     * scale as a selection, which is what the gallery tabs imply.
     */
    private function selectionThreshold(Round $round): int
    {
        return $round->getVotingMethod() === VotingMethod::Rating
            ? (int) ceil($round->getMaxRating() / 2) + 1
            : 1;
    }

    /** @return array<int, true> */
    private function favoriteImageIds(Round $round, RoundJuror $juror): array
    {
        $rows = $this->em->createQuery(
            'SELECT IDENTITY(a.image) AS imageId
             FROM ' . JurorImageAction::class . ' a
             JOIN ' . RoundImage::class . ' ri WITH a.image = ri
             WHERE ri.round = :round AND a.juror = :juror AND a.isFavorite = true'
        )->setParameters(['round' => $round, 'juror' => $juror])->getScalarResult();

        $ids = [];
        foreach ($rows as $row) {
            $ids[(int) $row['imageId']] = true;
        }

        return $ids;
    }

    /** How many qualified images this juror has still to judge. */
    public function remainingCount(Round $round, User $user): int
    {
        $juror = $this->resolveJuror($round, $user);

        return (int) $this->em->createQuery(
            'SELECT COUNT(ri.id) FROM ' . RoundImage::class . ' ri
             WHERE ri.round = :round
               AND ri.isDisqualified = false
               AND NOT EXISTS (
                   SELECT 1 FROM ' . Vote::class . ' v
                   WHERE v.image = ri AND v.juror = :juror
               )'
        )->setParameters(['round' => $round, 'juror' => $juror])->getSingleScalarResult();
    }

    private function actionFor(RoundImage $image, User $user): JurorImageAction
    {
        $juror = $this->resolveJuror($image->getRound(), $user);

        return $this->em->getRepository(JurorImageAction::class)->findOneBy([
            'image' => $image,
            'juror' => $juror,
        ]) ?? new JurorImageAction($image, $juror);
    }

    /** Keeps the table free of rows that no longer record anything. */
    private function persistOrDiscard(JurorImageAction $action): void
    {
        if ($action->isEmpty()) {
            if ($action->getId() !== null) {
                $this->em->remove($action);
            }
        } elseif ($action->getId() === null) {
            $this->em->persist($action);
        }

        $this->em->flush();
    }

    /**
     * Confirms this user is an active juror on the round and returns their
     * roster entry, binding the User to the invitation on first vote.
     */
    public function resolveJuror(Round $round, User $user): RoundJuror
    {
        foreach ($round->getJurors() as $juror) {
            $linked = $juror->getUser();

            if ($linked !== null && $linked->getId() === $user->getId()) {
                if (!$juror->isActive()) {
                    throw new DomainException('You are no longer an active juror in this round.');
                }

                return $juror;
            }

            // Invited by username but never linked: bind on first use.
            if ($linked === null && $juror->getUsername() === $user->getUsername()) {
                if (!$juror->isActive()) {
                    throw new DomainException('You are no longer an active juror in this round.');
                }

                $juror->linkUser($user);
                $this->em->flush();

                return $juror;
            }
        }

        throw new DomainException('You are not a juror in this round.');
    }

    private function assertScoreInRange(Round $round, int $score): void
    {
        $method = $round->getVotingMethod();

        if ($method === VotingMethod::RankOrder) {
            throw new DomainException('Ranking rounds are scored by submitting a full ordering.');
        }

        [$min, $max] = $round->scoreRange();

        if ($score < $min || $score > $max) {
            throw new DomainException(
                sprintf('Score %d is outside the allowed range %d–%d.', $score, $min, $max)
            );
        }
    }
}
