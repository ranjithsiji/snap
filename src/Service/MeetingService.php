<?php

declare(strict_types=1);

namespace JuryTool\Service;

use Doctrine\ORM\EntityManagerInterface;
use JuryTool\Domain\Entity\ConflictOpinion;
use JuryTool\Domain\Entity\ConsensusRank;
use JuryTool\Domain\Entity\MeetingComment;
use JuryTool\Domain\Entity\MeetingProposal;
use JuryTool\Domain\Entity\OpinionEndorsement;
use JuryTool\Domain\Entity\Round;
use JuryTool\Domain\Entity\RoundImage;
use JuryTool\Domain\Entity\RoundJuror;
use JuryTool\Domain\Entity\User;
use JuryTool\Domain\Enum\RoundState;
use JuryTool\Domain\Enum\UserRole;
use JuryTool\Domain\Enum\VotingMethod;
use JuryTool\Support\DomainException;
use Psr\Log\LoggerInterface;

/**
 * The final jury meeting: the panel discusses the shortlist and agrees one
 * shared ranking together, rather than voting independently.
 *
 * Everyone who judged the source round takes part, plus organizers. The
 * meeting starts from the source round's aggregate order and the panel
 * edits it collaboratively until an organizer finalizes the result — which
 * can be reopened if the panel needs to revisit it.
 */
class MeetingService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly StatisticsService $statistics,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Creates a meeting round from a finalized judging round, seeding the
     * consensus list with that round's aggregate ranking.
     */
    public function createFromRound(Round $source, string $name, ?int $topN = null): Round
    {
        if (!$source->getState()->isFinal()) {
            throw new DomainException(
                'Finalize the judging round before opening the final jury meeting.'
            );
        }

        $results = $this->statistics->results($source);

        if ($topN !== null && $topN > 0) {
            $results = array_slice($results, 0, $topN);
        }

        if ($results === []) {
            throw new DomainException('The source round has no results to discuss.');
        }

        $meeting = new Round($source->getCampaign(), $name);
        $meeting->setVotingMethod(VotingMethod::Meeting);
        $meeting->setDerivedFrom($source);
        $meeting->setDerivationCriteria(
            $topN !== null
                ? sprintf('final meeting on the top %d of %s', $topN, $source->getName())
                : sprintf('final meeting on all results of %s', $source->getName())
        );

        $this->em->persist($meeting);
        $this->em->flush();

        // The same people who did the assessment attend the meeting.
        foreach ($source->getJurors() as $sourceJuror) {
            if (!$sourceJuror->isActive()) {
                continue;
            }

            $juror = new RoundJuror($meeting, $sourceJuror->getUsername());

            if ($sourceJuror->getUser() !== null) {
                $juror->linkUser($sourceJuror->getUser());
            }

            $this->em->persist($juror);
        }

        // Carry the shortlisted images across and seed the shared ordering
        // from where the source round left them.
        $position = 1;

        foreach ($results as $row) {
            $sourceImage = $this->em->getRepository(RoundImage::class)->find($row['roundImageId']);

            if ($sourceImage === null) {
                continue;
            }

            $meetingImage = new RoundImage($meeting, $sourceImage->getImage());
            $this->em->persist($meetingImage);
            $this->em->persist(new ConsensusRank($meeting, $meetingImage, $position));

            $position++;
        }

        $this->em->flush();

        $this->logger->info('Final jury meeting created', [
            'meeting' => $meeting->getId(),
            'source' => $source->getId(),
            'images' => $position - 1,
        ]);

        return $meeting;
    }

    /**
     * The shared ranking, in agreed order.
     *
     * @return list<array<string, mixed>>
     */
    public function consensus(Round $meeting): array
    {
        $this->assertMeeting($meeting);

        $ranks = $this->em->createQuery(
            'SELECT cr, ri, ci
             FROM ' . ConsensusRank::class . ' cr
             JOIN cr.image ri
             JOIN ri.image ci
             WHERE cr.round = :round
             ORDER BY cr.position ASC'
        )->setParameter('round', $meeting)->getResult();

        $commentCounts = $this->commentCountsByImage($meeting);

        return array_map(
            static fn (ConsensusRank $rank): array => [
                'position' => $rank->getPosition(),
                'initialPosition' => $rank->getInitialPosition(),
                'drift' => $rank->getDrift(),
                'imageId' => $rank->getImage()->getId(),
                'title' => $rank->getImage()->getDisplayName(),
                'thumbUrl' => $rank->getImage()->getThumbUrl(),
                'fileUrl' => $rank->getImage()->getFileUrl(),
                'descriptionUrl' => $rank->getImage()->getDescriptionUrl(),
                'width' => $rank->getImage()->getWidth(),
                'height' => $rank->getImage()->getHeight(),
                'movedBy' => $rank->getMovedBy(),
                'movedAt' => $rank->getMovedAt()?->format(\DateTimeInterface::ATOM),
                'commentCount' => $commentCounts[(int) $rank->getImage()->getId()] ?? 0,
            ],
            $ranks,
        );
    }

    /**
     * Rewrites the shared ordering.
     *
     * The whole list is submitted at once so it can never be left with a
     * gap or a duplicate position, and each moved image is credited to the
     * juror who moved it.
     *
     * @param list<int> $orderedImageIds Round image ids, best first.
     * @param string|null $expectedRevision The revision the juror was
     *        working from. The meeting is asynchronous — jurors may submit
     *        hours apart — so a stale submission is rejected rather than
     *        silently discarding someone else's changes.
     */
    public function reorder(
        Round $meeting,
        User $user,
        array $orderedImageIds,
        ?string $expectedRevision = null,
    ): void {
        $this->assertMeeting($meeting);
        $this->assertOpen($meeting);
        $this->assertParticipant($meeting, $user);

        if ($expectedRevision !== null && $expectedRevision !== $this->revision($meeting)) {
            throw new DomainException(
                'Someone else changed the ranking since you loaded it. '
                . 'Reload to see their changes, then reapply yours.',
                409,
            );
        }

        if (count($orderedImageIds) !== count(array_unique($orderedImageIds))) {
            throw new DomainException('The same image appears more than once in the ranking.');
        }

        /** @var array<int, ConsensusRank> $ranks */
        $ranks = [];

        foreach ($this->em->getRepository(ConsensusRank::class)->findBy(['round' => $meeting]) as $rank) {
            $ranks[(int) $rank->getImage()->getId()] = $rank;
        }

        if (count($orderedImageIds) !== count($ranks)) {
            throw new DomainException(
                'The ranking must include every image in the meeting exactly once.'
            );
        }

        $position = 1;

        foreach ($orderedImageIds as $imageId) {
            $rank = $ranks[(int) $imageId] ?? null;

            if ($rank === null) {
                throw new DomainException(sprintf('Image %d is not part of this meeting.', $imageId));
            }

            $rank->moveTo($position, $user);
            $position++;
        }

        $this->em->flush();
    }

    /**
     * Comments in the meeting.
     *
     * @param 'image'|'general'|'all' $scope
     * @return list<array<string, mixed>>
     */
    public function comments(Round $meeting, string $scope = 'all', ?int $imageId = null): array
    {
        $this->assertMeeting($meeting);

        $qb = $this->em->createQueryBuilder()
            ->select('c')
            ->from(MeetingComment::class, 'c')
            ->where('c.round = :round')
            ->orderBy('c.createdAt', 'ASC')
            ->setParameter('round', $meeting);

        if ($imageId !== null) {
            $qb->andWhere('c.image = :image')->setParameter('image', $imageId);
        } elseif ($scope === 'general') {
            $qb->andWhere('c.image IS NULL');
        } elseif ($scope === 'image') {
            $qb->andWhere('c.image IS NOT NULL');
        }

        return array_map(
            static fn (MeetingComment $c): array => [
                'id' => $c->getId(),
                'author' => $c->getAuthorUsername(),
                'body' => $c->getBody(),
                'imageId' => $c->getImage()?->getId(),
                'referencedImages' => $c->getReferencedImages(),
                'createdAt' => $c->getCreatedAt()->format(\DateTimeInterface::ATOM),
                'editedAt' => $c->getEditedAt()?->format(\DateTimeInterface::ATOM),
            ],
            $qb->getQuery()->getResult(),
        );
    }

    /**
     * Posts a comment, either against one image or to the general thread.
     *
     * @param list<int> $referencedImages Image ids to illustrate a general post.
     */
    public function comment(
        Round $meeting,
        User $user,
        string $body,
        ?int $imageId = null,
        array $referencedImages = [],
    ): MeetingComment {
        $this->assertMeeting($meeting);
        $this->assertOpen($meeting);
        $this->assertParticipant($meeting, $user);

        $body = trim($body);

        if ($body === '') {
            throw DomainException::badRequest('A comment cannot be empty.');
        }

        $comment = new MeetingComment($meeting, $user, $body);

        if ($imageId !== null) {
            $image = $this->em->getRepository(RoundImage::class)->find($imageId);

            if ($image === null || $image->getRound()->getId() !== $meeting->getId()) {
                throw DomainException::notFound('Image');
            }

            $comment->setImage($image);
        } else {
            $comment->setReferencedImages(
                $this->validReferences($meeting, $referencedImages)
            );
        }

        $this->em->persist($comment);
        $this->em->flush();

        return $comment;
    }

    /** Lets an author correct their own comment. */
    public function editComment(MeetingComment $comment, User $user, string $body): void
    {
        $this->assertOpen($comment->getRound());

        $isAuthor = $comment->getAuthor()?->getId() === $user->getId();

        if (!$isAuthor) {
            throw DomainException::forbidden('You can only edit your own comments.');
        }

        $body = trim($body);

        if ($body === '') {
            throw DomainException::badRequest('A comment cannot be empty.');
        }

        $comment->edit($body);
        $this->em->flush();
    }

    /**
     * Locks the agreed result.
     *
     * Organizers finalize; the meeting can be reopened afterwards if the
     * panel needs to revisit it, which is why this is a state change rather
     * than a destructive step.
     */
    public function finalize(Round $meeting, User $user): void
    {
        $this->assertMeeting($meeting);
        $this->assertOrganizer($user);

        if ($meeting->getState()->isFinal()) {
            throw new DomainException('This meeting is already finalized.');
        }

        $meeting->setState(RoundState::Finalized);
        $this->em->flush();

        $this->logger->info('Jury meeting finalized', [
            'meeting' => $meeting->getId(),
            'by' => $user->getUsername(),
        ]);
    }

    /** Reopens a finalized meeting so the panel can keep working. */
    public function reopen(Round $meeting, User $user): void
    {
        $this->assertMeeting($meeting);
        $this->assertOrganizer($user);

        if (!$meeting->getState()->isFinal()) {
            throw new DomainException('This meeting is not finalized.');
        }

        // Reopening is the one transition out of Finalized, and it exists
        // only for meetings — a judging round stays closed once closed.
        $meeting->reopenMeeting();
        $this->em->flush();

        $this->logger->info('Jury meeting reopened', [
            'meeting' => $meeting->getId(),
            'by' => $user->getUsername(),
        ]);
    }

    /** Whether this user may take part in the meeting. */
    public function canParticipate(Round $meeting, User $user): bool
    {
        if ($user->hasRole(UserRole::Organizer)) {
            return true;
        }

        foreach ($meeting->getJurors() as $juror) {
            if (!$juror->isActive()) {
                continue;
            }

            if (
                $juror->getUser()?->getId() === $user->getId()
                || $juror->getUsername() === $user->getUsername()
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Records this juror's proposed ordering.
     *
     * Every juror's proposal is kept separately, so a disagreement between
     * two of them is visible rather than resolved by whoever saved last.
     * The consensus list is recomputed from all proposals afterwards.
     *
     * @param list<int> $orderedImageIds Round image ids, best first.
     */
    public function propose(Round $meeting, User $user, array $orderedImageIds): void
    {
        $this->assertMeeting($meeting);
        $this->assertOpen($meeting);
        $this->assertParticipant($meeting, $user);

        $juror = $this->jurorSeat($meeting, $user);

        if ($juror === null) {
            throw DomainException::forbidden(
                'Only jurors who judged the source round can propose a ranking.'
            );
        }

        if (count($orderedImageIds) !== count(array_unique($orderedImageIds))) {
            throw new DomainException('The same image appears more than once in your ranking.');
        }

        /** @var array<int, RoundImage> $images */
        $images = [];
        foreach ($this->em->getRepository(RoundImage::class)->findBy(['round' => $meeting]) as $image) {
            $images[(int) $image->getId()] = $image;
        }

        if (count($orderedImageIds) !== count($images)) {
            throw new DomainException(
                'Your ranking must include every image in the meeting exactly once.'
            );
        }

        /** @var array<int, MeetingProposal> $existing */
        $existing = [];
        foreach (
            $this->em->getRepository(MeetingProposal::class)
                ->findBy(['round' => $meeting, 'juror' => $juror]) as $proposal
        ) {
            $existing[(int) $proposal->getImage()->getId()] = $proposal;
        }

        $position = 1;

        foreach ($orderedImageIds as $imageId) {
            $image = $images[(int) $imageId] ?? null;

            if ($image === null) {
                throw new DomainException(sprintf('Image %d is not part of this meeting.', $imageId));
            }

            $proposal = $existing[(int) $imageId] ?? null;

            if ($proposal === null) {
                $this->em->persist(new MeetingProposal($meeting, $image, $juror, $position));
            } else {
                $proposal->setPosition($position);
            }

            $position++;
        }

        $this->em->flush();
        $this->rebuildConsensus($meeting);
    }

    /**
     * Every juror's proposal for each image, alongside the agreed position.
     *
     * This is what the meeting screen renders: one row per image showing
     * where each juror put it, so differences are immediately visible.
     *
     * @return list<array<string, mixed>>
     */
    public function proposalMatrix(Round $meeting): array
    {
        $this->assertMeeting($meeting);

        $rows = $this->em->createQuery(
            'SELECT p, ri, ci
             FROM ' . MeetingProposal::class . ' p
             JOIN p.image ri
             JOIN ri.image ci
             WHERE p.round = :round
             ORDER BY p.position ASC'
        )->setParameter('round', $meeting)->getResult();

        /** @var array<int, list<array<string, mixed>>> $byImage */
        $byImage = [];

        foreach ($rows as $proposal) {
            /** @var MeetingProposal $proposal */
            $byImage[(int) $proposal->getImage()->getId()][] = [
                'juror' => $proposal->getJurorUsername(),
                'position' => $proposal->getPosition(),
                'rationale' => $proposal->getRationale(),
                'updatedAt' => $proposal->getUpdatedAt()->format(\DateTimeInterface::ATOM),
            ];
        }

        $opinions = $this->opinionsByImage($meeting);
        $threshold = $this->conflictThreshold($meeting);

        $matrix = [];

        foreach ($this->consensus($meeting) as $row) {
            $imageId = (int) $row['imageId'];
            $proposals = $byImage[$imageId] ?? [];
            $positions = array_column($proposals, 'position');

            $spread = $positions !== [] ? max($positions) - min($positions) : 0;

            $matrix[] = $row + [
                'proposals' => $proposals,
                'spread' => $spread,
                // A wide spread means the panel genuinely disagrees about
                // this photograph, which is what the meeting is for.
                'isConflict' => $spread >= $threshold,
                'opinions' => $opinions[$imageId] ?? [],
            ];
        }

        return $matrix;
    }

    /**
     * Only the images the panel disagrees about, worst first.
     *
     * @return list<array<string, mixed>>
     */
    public function conflicts(Round $meeting): array
    {
        $conflicts = array_values(array_filter(
            $this->proposalMatrix($meeting),
            static fn (array $row): bool => $row['isConflict'],
        ));

        usort($conflicts, static fn (array $a, array $b): int => $b['spread'] <=> $a['spread']);

        return $conflicts;
    }

    /**
     * Records a juror's view on a disputed image.
     *
     * Separate from a proposal: a juror can argue for a position without
     * changing their own ranking, which is how a panel talks a dispute
     * through.
     */
    public function addOpinion(
        Round $meeting,
        User $user,
        int $imageId,
        string $body,
        ?int $suggestedPosition = null,
        ?string $supports = null,
    ): ConflictOpinion {
        $this->assertMeeting($meeting);
        $this->assertOpen($meeting);
        $this->assertParticipant($meeting, $user);

        $body = trim($body);

        if ($body === '') {
            throw DomainException::badRequest('An opinion cannot be empty.');
        }

        $image = $this->em->getRepository(RoundImage::class)->find($imageId);

        if ($image === null || $image->getRound()->getId() !== $meeting->getId()) {
            throw DomainException::notFound('Image');
        }

        // One opinion per juror per image: revising replaces it, so the
        // record stays a clear statement of where each person stands.
        $opinion = $this->em->getRepository(ConflictOpinion::class)->findOneBy([
            'image' => $image,
            'author' => $user,
        ]);

        if ($opinion === null) {
            $opinion = new ConflictOpinion($meeting, $image, $user, $body);
            $this->em->persist($opinion);
        } else {
            $opinion->setBody($body);
        }

        $opinion->setSuggestedPosition($suggestedPosition);
        $opinion->setSupportsUsername($supports);

        $this->em->flush();

        return $opinion;
    }

    /**
     * Recomputes the agreed order from all proposals.
     *
     * An image's consensus position is the mean of the positions jurors
     * gave it — the standard way to combine rank ballots, and stable when
     * only some jurors have proposed so far. Ties break on the earlier
     * initial position so the order never jitters arbitrarily.
     */
    public function rebuildConsensus(Round $meeting): void
    {
        $rows = $this->em->createQuery(
            'SELECT IDENTITY(p.image) AS imageId, AVG(p.position) AS meanPosition
             FROM ' . MeetingProposal::class . ' p
             WHERE p.round = :round
             GROUP BY p.image'
        )->setParameter('round', $meeting)->getResult();

        if ($rows === []) {
            return;
        }

        $means = [];
        foreach ($rows as $row) {
            $means[(int) $row['imageId']] = (float) $row['meanPosition'];
        }

        /** @var list<ConsensusRank> $ranks */
        $ranks = $this->em->getRepository(ConsensusRank::class)->findBy(['round' => $meeting]);

        usort($ranks, static function (ConsensusRank $a, ConsensusRank $b) use ($means): int {
            $left = $means[(int) $a->getImage()->getId()] ?? PHP_FLOAT_MAX;
            $right = $means[(int) $b->getImage()->getId()] ?? PHP_FLOAT_MAX;

            return $left <=> $right ?: $a->getInitialPosition() <=> $b->getInitialPosition();
        });

        $position = 1;

        foreach ($ranks as $rank) {
            $rank->renumber($position++);
        }

        $this->em->flush();
    }

    /**
     * How far apart two proposals must be to count as a disagreement.
     *
     * Scaled to the shortlist: on a list of 10, three places apart is a
     * real difference of opinion; on a list of 200 it is noise.
     */
    private function conflictThreshold(Round $meeting): int
    {
        $count = (int) $this->em->createQuery(
            'SELECT COUNT(cr.id) FROM ' . ConsensusRank::class . ' cr WHERE cr.round = :round'
        )->setParameter('round', $meeting)->getSingleScalarResult();

        return max(2, (int) ceil($count * 0.15));
    }

    /**
     * Agrees or disagrees with someone's opinion.
     *
     * Repeating the same value clears it, so the control behaves like a
     * toggle; sending the opposite value switches sides.
     */
    public function endorseOpinion(ConflictOpinion $opinion, User $user, int $value): void
    {
        $meeting = $opinion->getRound();

        $this->assertOpen($meeting);
        $this->assertParticipant($meeting, $user);

        $existing = $this->em->getRepository(OpinionEndorsement::class)->findOneBy([
            'opinion' => $opinion,
            'juror' => $user,
        ]);

        $normalised = $value >= 0 ? 1 : -1;

        if ($existing === null) {
            $this->em->persist(new OpinionEndorsement($opinion, $user, $normalised));
        } elseif ($existing->getValue() === $normalised) {
            $this->em->remove($existing);
        } else {
            $existing->setValue($normalised);
        }

        $this->em->flush();
    }

    /** @return array<int, list<array<string, mixed>>> */
    private function opinionsByImage(Round $meeting): array
    {
        $rows = $this->em->getRepository(ConflictOpinion::class)->findBy(['round' => $meeting]);

        if ($rows === []) {
            return [];
        }

        $tallies = $this->endorsementTallies($rows);
        $byImage = [];

        foreach ($rows as $opinion) {
            $tally = $tallies[(int) $opinion->getId()] ?? ['agree' => 0, 'disagree' => 0, 'voters' => []];

            $byImage[(int) $opinion->getImage()->getId()][] = [
                'id' => $opinion->getId(),
                'author' => $opinion->getAuthorUsername(),
                'body' => $opinion->getBody(),
                'suggestedPosition' => $opinion->getSuggestedPosition(),
                'supports' => $opinion->getSupportsUsername(),
                'agree' => $tally['agree'],
                'disagree' => $tally['disagree'],
                'score' => $tally['agree'] - $tally['disagree'],
                'voters' => $tally['voters'],
                'createdAt' => $opinion->getCreatedAt()->format(\DateTimeInterface::ATOM),
            ];
        }

        // Best-supported argument first, so the panel sees where opinion has
        // settled without reading every entry.
        foreach ($byImage as &$opinions) {
            usort($opinions, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);
        }

        return $byImage;
    }

    /**
     * Agree/disagree counts per opinion.
     *
     * @param list<ConflictOpinion> $opinions
     * @return array<int, array{agree: int, disagree: int, voters: array<string, int>}>
     */
    private function endorsementTallies(array $opinions): array
    {
        $rows = $this->em->createQuery(
            'SELECT IDENTITY(e.opinion) AS opinionId, e.jurorUsername AS juror, e.value AS value
             FROM ' . OpinionEndorsement::class . ' e
             WHERE e.opinion IN (:opinions)'
        )->setParameter('opinions', $opinions)->getResult();

        $tallies = [];

        foreach ($rows as $row) {
            $id = (int) $row['opinionId'];
            $value = (int) $row['value'];

            $tallies[$id] ??= ['agree' => 0, 'disagree' => 0, 'voters' => []];
            $tallies[$id][$value > 0 ? 'agree' : 'disagree']++;
            $tallies[$id]['voters'][(string) $row['juror']] = $value;
        }

        return $tallies;
    }

    /** This user's juror seat in the meeting, if they hold one. */
    private function jurorSeat(Round $meeting, User $user): ?RoundJuror
    {
        foreach ($meeting->getJurors() as $juror) {
            if (!$juror->isActive()) {
                continue;
            }

            if ($juror->getUser()?->getId() === $user->getId()) {
                return $juror;
            }

            if ($juror->getUser() === null && $juror->getUsername() === $user->getUsername()) {
                $juror->linkUser($user);
                $this->em->flush();

                return $juror;
            }
        }

        return null;
    }

    /**
     * A fingerprint of the current ordering.
     *
     * Sent with the list and passed back on submit, so a juror who has been
     * looking at a stale page is told rather than overwriting the panel's
     * more recent decisions.
     */
    public function revision(Round $meeting): string
    {
        $row = $this->em->createQuery(
            'SELECT COUNT(cr.id) AS total, MAX(cr.movedAt) AS lastMove
             FROM ' . ConsensusRank::class . ' cr
             WHERE cr.round = :round'
        )->setParameter('round', $meeting)->getSingleResult();

        return substr(hash('sha256', json_encode([
            $meeting->getId(),
            $row['total'] ?? 0,
            $row['lastMove'] ?? '',
        ])), 0, 16);
    }

    /** @return array<int, int> image id => comment count */
    private function commentCountsByImage(Round $meeting): array
    {
        $rows = $this->em->createQuery(
            'SELECT IDENTITY(c.image) AS imageId, COUNT(c.id) AS total
             FROM ' . MeetingComment::class . ' c
             WHERE c.round = :round AND c.image IS NOT NULL
             GROUP BY c.image'
        )->setParameter('round', $meeting)->getResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['imageId']] = (int) $row['total'];
        }

        return $counts;
    }

    /**
     * Filters referenced ids down to images actually in this meeting.
     *
     * @param list<int> $imageIds
     * @return list<int>
     */
    private function validReferences(Round $meeting, array $imageIds): array
    {
        if ($imageIds === []) {
            return [];
        }

        $rows = $this->em->createQuery(
            'SELECT ri.id FROM ' . RoundImage::class . ' ri
             WHERE ri.round = :round AND ri.id IN (:ids)'
        )->setParameters(['round' => $meeting, 'ids' => $imageIds])->getResult();

        return array_map(static fn (array $row): int => (int) $row['id'], $rows);
    }

    private function assertMeeting(Round $round): void
    {
        if ($round->getVotingMethod() !== VotingMethod::Meeting) {
            throw DomainException::badRequest('This round is not a final jury meeting.');
        }
    }

    private function assertOpen(Round $meeting): void
    {
        if ($meeting->getState()->isFinal()) {
            throw new DomainException(
                'This meeting has been finalized. Reopen it to make further changes.'
            );
        }
    }

    private function assertParticipant(Round $meeting, User $user): void
    {
        if (!$this->canParticipate($meeting, $user)) {
            throw DomainException::forbidden('You are not part of this jury meeting.');
        }
    }

    private function assertOrganizer(User $user): void
    {
        if (!$user->hasRole(UserRole::Organizer)) {
            throw DomainException::forbidden(
                'Only an organizer can finalize or reopen a jury meeting.'
            );
        }
    }
}
