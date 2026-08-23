<?php

declare(strict_types=1);

namespace JuryTool\Service;

use Doctrine\ORM\EntityManagerInterface;
use JuryTool\Domain\Entity\Round;
use JuryTool\Domain\Entity\RoundImage;
use JuryTool\Domain\Entity\RoundJuror;
use JuryTool\Domain\Entity\Vote;
use JuryTool\Domain\Enum\VotingMethod;

/**
 * Aggregate figures for the round dashboard and juror progress panels.
 *
 * Everything here is computed with aggregate SQL rather than by walking
 * entity collections, since a round can hold tens of thousands of images.
 */
class StatisticsService
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /**
     * Headline counters for the round file information panel.
     *
     * @return array<string, int|float|null>
     */
    public function roundSummary(Round $round): array
    {
        $counts = $this->em->createQuery(
            'SELECT
                COUNT(ri.id) AS total,
                SUM(CASE WHEN ri.isDisqualified = true THEN 1 ELSE 0 END) AS disqualified
             FROM ' . RoundImage::class . ' ri
             WHERE ri.round = :round'
        )->setParameter('round', $round)->getSingleResult();

        $total = (int) ($counts['total'] ?? 0);
        $disqualified = (int) ($counts['disqualified'] ?? 0);
        $qualified = $total - $disqualified;

        $jurors = $round->activeJurorCount();
        $quorum = $round->effectiveQuorum();

        // A "task" is one juror judging one image; the round is complete
        // when every qualified image has met quorum.
        $totalTasks = $qualified * $quorum;
        $completedTasks = $this->voteCount($round);

        $imagesAtQuorum = $this->imagesMeetingQuorum($round, $quorum);

        return [
            'files' => $total,
            'qualifiedFiles' => $qualified,
            'disqualifiedFiles' => $disqualified,
            'jurors' => $jurors,
            'quorum' => $quorum,
            'tasks' => $totalTasks,
            'completedTasks' => $completedTasks,
            'openTasks' => max(0, $totalTasks - $completedTasks),
            'percentComplete' => $totalTasks > 0
                ? round($completedTasks / $totalTasks * 100, 1)
                : 0.0,
            'imagesAtQuorum' => $imagesAtQuorum,
            'imagesRemaining' => max(0, $qualified - $imagesAtQuorum),
            'uploaders' => $this->uploaderCount($round),
            'fileTypes' => $this->fileTypes($round),
        ];
    }

    /**
     * Distinct file types in the round, as short labels ("jpeg", "png").
     *
     * @return list<string>
     */
    private function fileTypes(Round $round): array
    {
        $rows = $this->em->createQuery(
            'SELECT DISTINCT ci.mimeType AS mimeType
             FROM ' . RoundImage::class . ' ri
             JOIN ri.image ci
             WHERE ri.round = :round AND ci.mimeType IS NOT NULL'
        )->setParameter('round', $round)->getResult();

        $types = [];
        foreach ($rows as $row) {
            // "image/jpeg" reads better as just "jpeg" in the panel.
            $types[] = str_replace('image/', '', (string) $row['mimeType']);
        }

        sort($types);

        return $types;
    }

    /**
     * Per-juror progress: how many of their assigned images they have
     * judged, and how many remain.
     *
     * @return list<array<string, mixed>>
     */
    public function jurorProgress(Round $round): array
    {
        // Progress is measured against every qualified file in the round.
        // Quorum caps how many jurors must see each image, but a juror is
        // free to judge any of them, so "211 out of 1057" is the figure
        // that matches what a juror actually faces.
        $expectedPerJuror = $round->qualifiedImageCount();

        $rows = $this->em->createQuery(
            'SELECT IDENTITY(v.juror) AS jurorId, COUNT(v.id) AS votes
             FROM ' . Vote::class . ' v
             JOIN ' . RoundImage::class . ' ri WITH v.image = ri
             WHERE ri.round = :round
             GROUP BY v.juror'
        )->setParameter('round', $round)->getResult();

        $votesByJuror = [];
        foreach ($rows as $row) {
            $votesByJuror[(int) $row['jurorId']] = (int) $row['votes'];
        }

        $progress = [];

        foreach ($round->getJurors() as $juror) {
            /** @var RoundJuror $juror */
            $cast = $votesByJuror[(int) $juror->getId()] ?? 0;

            $progress[] = [
                'id' => $juror->getId(),
                'username' => $juror->getUsername(),
                'isActive' => $juror->isActive(),
                'hasLoggedIn' => $juror->getUser() !== null,
                'replacedUsername' => $juror->getReplacedUsername(),
                'votesCast' => $cast,
                'expected' => $expectedPerJuror,
                'remaining' => max(0, $expectedPerJuror - $cast),
                'percentComplete' => $expectedPerJuror > 0
                    ? round($cast / $expectedPerJuror * 100, 1)
                    : 0.0,
            ];
        }

        return $progress;
    }

    /**
     * One juror's own tally, for the "show own statistics" panel.
     *
     * @return array<string, mixed>
     */
    public function ownStatistics(Round $round, RoundJuror $juror): array
    {
        $rows = $this->em->createQuery(
            'SELECT v.score AS score, COUNT(v.id) AS total
             FROM ' . Vote::class . ' v
             JOIN ' . RoundImage::class . ' ri WITH v.image = ri
             WHERE ri.round = :round AND v.juror = :juror
             GROUP BY v.score
             ORDER BY v.score ASC'
        )->setParameters(['round' => $round, 'juror' => $juror])->getResult();

        $tally = [];
        $total = 0;

        foreach ($rows as $row) {
            $score = (int) $row['score'];
            $count = (int) $row['total'];
            $tally[$score] = $count;
            $total += $count;
        }

        $stats = [
            'totalVotes' => $total,
            'tally' => $tally,
        ];

        // Yes/no rounds read more naturally as accepted vs declined.
        if ($round->getVotingMethod() === VotingMethod::YesNo) {
            $stats['accepted'] = $tally[1] ?? 0;
            $stats['declined'] = $tally[0] ?? 0;
        }

        return $stats;
    }

    /**
     * Results for a round: each qualified image with its aggregate score.
     *
     * Ordered best-first, which for ranking rounds means the lowest average
     * position rather than the highest score.
     *
     * @return list<array<string, mixed>>
     */
    public function results(Round $round, bool $includeDisqualified = false): array
    {
        $dql = 'SELECT
                    ri.id AS roundImageId,
                    ci.commonsPageId AS pageId,
                    ci.title AS title,
                    ci.descriptionUrl AS descriptionUrl,
                    ci.thumbUrl AS thumbUrl,
                    ci.uploader AS uploader,
                    ci.width AS width,
                    ci.height AS height,
                    ri.isDisqualified AS isDisqualified,
                    ri.disqualificationReason AS disqualificationReason,
                    COUNT(v.id) AS voteCount,
                    AVG(v.score) AS averageScore,
                    SUM(v.score) AS totalScore
                FROM ' . RoundImage::class . ' ri
                JOIN ri.image ci
                LEFT JOIN ri.votes v
                WHERE ri.round = :round';

        if (!$includeDisqualified) {
            $dql .= ' AND ri.isDisqualified = false';
        }

        $dql .= ' GROUP BY ri.id, ci.commonsPageId, ci.title, ci.descriptionUrl,
                          ci.thumbUrl, ci.uploader, ci.width, ci.height,
                          ri.isDisqualified, ri.disqualificationReason';

        $dql .= $round->getVotingMethod() === VotingMethod::RankOrder
            ? ' ORDER BY averageScore ASC'
            : ' ORDER BY averageScore DESC, voteCount DESC';

        $rows = $this->em->createQuery($dql)
            ->setParameter('round', $round)
            ->getResult();

        $results = [];
        $position = 1;

        foreach ($rows as $row) {
            $results[] = [
                'position' => $position++,
                'roundImageId' => (int) $row['roundImageId'],
                'pageId' => (int) $row['pageId'],
                'title' => $row['title'],
                'descriptionUrl' => $row['descriptionUrl'],
                'thumbUrl' => $row['thumbUrl'],
                'uploader' => $row['uploader'],
                'width' => (int) $row['width'],
                'height' => (int) $row['height'],
                'isDisqualified' => (bool) $row['isDisqualified'],
                'disqualificationReason' => $row['disqualificationReason'],
                'voteCount' => (int) $row['voteCount'],
                'averageScore' => $row['averageScore'] !== null ? round((float) $row['averageScore'], 3) : null,
                'totalScore' => $row['totalScore'] !== null ? (int) $row['totalScore'] : 0,
            ];
        }

        return $results;
    }

    private function voteCount(Round $round): int
    {
        return (int) $this->em->createQuery(
            'SELECT COUNT(v.id)
             FROM ' . Vote::class . ' v
             JOIN ' . RoundImage::class . ' ri WITH v.image = ri
             WHERE ri.round = :round'
        )->setParameter('round', $round)->getSingleScalarResult();
    }

    /** Qualified images that have collected at least `quorum` votes. */
    private function imagesMeetingQuorum(Round $round, int $quorum): int
    {
        $rows = $this->em->createQuery(
            'SELECT ri.id
             FROM ' . RoundImage::class . ' ri
             LEFT JOIN ri.votes v
             WHERE ri.round = :round AND ri.isDisqualified = false
             GROUP BY ri.id
             HAVING COUNT(v.id) >= :quorum'
        )->setParameters(['round' => $round, 'quorum' => $quorum])->getResult();

        return count($rows);
    }

    private function uploaderCount(Round $round): int
    {
        return (int) $this->em->createQuery(
            'SELECT COUNT(DISTINCT ci.uploader)
             FROM ' . RoundImage::class . ' ri
             JOIN ri.image ci
             WHERE ri.round = :round'
        )->setParameter('round', $round)->getSingleScalarResult();
    }
}
