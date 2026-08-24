<?php

declare(strict_types=1);

namespace JuryTool\Service;

use Doctrine\ORM\EntityManagerInterface;
use JuryTool\Domain\Entity\CampaignImage;
use JuryTool\Domain\Entity\Round;
use JuryTool\Domain\Entity\RoundImage;
use JuryTool\Domain\Entity\RoundJuror;
use JuryTool\Domain\Enum\VotingMethod;
use JuryTool\Support\DerivationCriteria;
use JuryTool\Support\DomainException;
use Psr\Log\LoggerInterface;

/**
 * Creates a follow-on round from the results of an earlier one, carrying
 * over the images that met the given criteria.
 */
class RoundDerivationService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly RoundPopulationService $population,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Images from the source round that satisfy the criteria, best first.
     *
     * Exposed separately from derive() so the UI can preview how many
     * images a proposed shortlist would contain before committing to it.
     *
     * @return list<CampaignImage>
     */
    public function selectImages(Round $source, DerivationCriteria $criteria): array
    {
        $method = $source->getVotingMethod();

        // Selected through the root alias, and grouped by it. Selecting the
        // joined 'ci' alone is a semantical error — "cannot select entity
        // through identification variables without choosing at least one
        // root entity alias" — which made every derivation fail outright.
        $qb = $this->em->createQueryBuilder()
            ->select('ri', 'COUNT(v.id) AS voteCount', 'AVG(v.score) AS averageScore')
            ->addSelect('SUM(CASE WHEN v.score = 1 THEN 1 ELSE 0 END) AS acceptCount')
            ->from(RoundImage::class, 'ri')
            ->join('ri.image', 'ci')
            ->leftJoin('ri.votes', 'v')
            ->where('ri.round = :round')
            ->groupBy('ri.id')
            ->setParameter('round', $source);

        if (!$criteria->includeDisqualified) {
            $qb->andWhere('ri.isDisqualified = false');
        }

        if ($criteria->minVoteCount !== null) {
            $qb->andHaving('COUNT(v.id) >= :minVotes')
                ->setParameter('minVotes', $criteria->minVoteCount);
        }

        if ($criteria->minAcceptCount !== null) {
            if ($method !== VotingMethod::YesNo) {
                throw DomainException::badRequest(
                    'An accept-count criterion only applies to yes/no rounds.'
                );
            }

            $qb->andHaving('SUM(CASE WHEN v.score = 1 THEN 1 ELSE 0 END) >= :minAccepts')
                ->setParameter('minAccepts', $criteria->minAcceptCount);
        }

        // "Half the jury scored it 4 or above" travels between levels
        // better than an absolute count, because the panel size changes.
        if ($criteria->hasVoterFractionRule()) {
            $threshold = $criteria->effectiveScoreThreshold($method, $source->getMaxRating());

            $qb->andHaving(
                'SUM(CASE WHEN v.score >= :scoreThreshold THEN 1 ELSE 0 END) >= '
                . ':voterFraction * COUNT(v.id)'
            )
                ->setParameter('scoreThreshold', $threshold)
                ->setParameter('voterFraction', $criteria->minVoterFraction);
        }

        if ($criteria->minAverageScore !== null) {
            // In a ranking round a lower average position is better, so the
            // threshold is a ceiling rather than a floor.
            $comparison = $method === VotingMethod::RankOrder ? '<=' : '>=';

            $qb->andHaving("AVG(v.score) $comparison :minAverage")
                ->setParameter('minAverage', $criteria->minAverageScore);
        }

        $qb->orderBy(
            'averageScore',
            $method === VotingMethod::RankOrder ? 'ASC' : 'DESC'
        )->addOrderBy('voteCount', 'DESC');

        if ($criteria->topN !== null && $criteria->topN > 0) {
            $qb->setMaxResults($criteria->topN);
        }

        // With the root selected, the entity arrives under key 0 and the
        // aggregates alongside it. The campaign image is what the new round
        // is built from, so it is unwrapped here.
        $images = [];
        foreach ($qb->getQuery()->getResult() as $row) {
            $images[] = $row[0]->getImage();
        }

        return $images;
    }

    /** How many images the criteria would carry over, without creating anything. */
    public function previewCount(Round $source, DerivationCriteria $criteria): int
    {
        return count($this->selectImages($source, $criteria));
    }

    /**
     * Candidate thresholds with the number of images each would carry over.
     *
     * Choosing a cutoff in the abstract is guesswork — "average 5 of 10"
     * means nothing until you know it keeps 458 photographs. Offering the
     * count beside each option lets a coordinator pick by the size of the
     * shortlist they want.
     *
     * @return list<array{threshold: float, label: string, count: int}>
     */
    public function thresholdOptions(Round $source): array
    {
        $method = $source->getVotingMethod();

        // One pass over the round's aggregate scores; the options are then
        // counted in PHP rather than with a query per candidate value.
        $rows = $this->em->createQuery(
            'SELECT AVG(v.score) AS averageScore
             FROM ' . RoundImage::class . ' ri
             LEFT JOIN ri.votes v
             WHERE ri.round = :round AND ri.isDisqualified = false
             GROUP BY ri.id'
        )->setParameter('round', $source)->getResult();

        $averages = [];
        foreach ($rows as $row) {
            if ($row['averageScore'] !== null) {
                $averages[] = (float) $row['averageScore'];
            }
        }

        if ($averages === []) {
            return [];
        }

        [$min, $max] = $method === VotingMethod::Rating
            ? [1.0, (float) $source->getMaxRating()]
            : [0.0, 1.0];

        // Rating rounds get one option per half-star; yes/no rounds are
        // better served by acceptance proportions.
        $steps = $method === VotingMethod::Rating
            ? $this->rangeSteps($min, $max, 0.5)
            : [0.0, 0.25, 0.5, 0.75, 1.0];

        $options = [];

        foreach ($steps as $threshold) {
            $count = count(array_filter(
                $averages,
                static fn (float $average): bool => $average >= $threshold,
            ));

            $options[] = [
                'threshold' => $threshold,
                'label' => $method === VotingMethod::Rating
                    ? sprintf('%.2f / %d', $threshold, $source->getMaxRating())
                    : sprintf('%d%% accepted', (int) round($threshold * 100)),
                'count' => $count,
            ];
        }

        return $options;
    }

    /**
     * Inclusive range of thresholds.
     *
     * @return list<float>
     */
    private function rangeSteps(float $min, float $max, float $step): array
    {
        $steps = [];

        for ($value = $min; $value <= $max + 0.001; $value += $step) {
            $steps[] = round($value, 2);
        }

        return $steps;
    }

    /**
     * Creates the next round and fills it with the qualifying images.
     *
     * The new round starts in Draft so the coordinator can adjust its
     * settings and jurors before activating it.
     */
    public function derive(Round $source, string $name, DerivationCriteria $criteria): Round
    {
        if (!$source->getState()->isFinal()) {
            throw new DomainException(
                'Finalize the source round before deriving a new round from it.'
            );
        }

        // The jury meeting settles the result, so nothing follows it.
        if ($source->getVotingMethod() === VotingMethod::Meeting) {
            throw new DomainException(
                'A jury meeting is the final step — no further round can follow it.'
            );
        }

        $images = $this->selectImages($source, $criteria);

        if ($images === []) {
            throw new DomainException('No images match those criteria.');
        }

        $round = new Round($source->getCampaign(), $name);
        $round->setDerivedFrom($source);
        $round->setDerivationCriteria($criteria->describe($source->getVotingMethod(), $source->getMaxRating()));

        // Carry the source round's configuration forward as a starting
        // point; the coordinator edits it before activating. The voting
        // method defaults to the next step in the usual funnel — Yes/No
        // then Rating then Ranking — rather than repeating the source's
        // own method, since a derived round is normally meant to narrow
        // the field further, not re-run the same pass on fewer images.
        $round->setVotingMethod($source->getVotingMethod()->nextInFunnel());
        $round->setMaxRating($source->getMaxRating());
        $round->setQuorum($source->getQuorum());
        $round->setShowOwnStatistics($source->showsOwnStatistics());
        $round->getFileSettings()->copyFrom($source->getFileSettings());

        // The same panel normally continues to the next round. Only active
        // jurors carry over — someone who withdrew is not re-invited.
        foreach ($source->getJurors() as $sourceJuror) {
            if (!$sourceJuror->isActive()) {
                continue;
            }

            $juror = new RoundJuror($round, $sourceJuror->getUsername());

            if ($sourceJuror->getUser() !== null) {
                $juror->linkUser($sourceJuror->getUser());
            }

            $this->em->persist($juror);
        }

        $this->em->persist($round);
        $this->em->flush();

        $this->population->populate($round, $images);

        $this->logger->info('Round derived', [
            'source' => $source->getId(),
            'round' => $round->getId(),
            'images' => count($images),
            'criteria' => $criteria->describe($source->getVotingMethod(), $source->getMaxRating()),
        ]);

        return $round;
    }
}
