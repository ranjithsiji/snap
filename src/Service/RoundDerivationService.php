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

        $qb = $this->em->createQueryBuilder()
            ->select('ci AS image', 'COUNT(v.id) AS voteCount', 'AVG(v.score) AS averageScore')
            ->addSelect('SUM(CASE WHEN v.score = 1 THEN 1 ELSE 0 END) AS acceptCount')
            ->from(RoundImage::class, 'ri')
            ->join('ri.image', 'ci')
            ->leftJoin('ri.votes', 'v')
            ->where('ri.round = :round')
            ->groupBy('ci.id')
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

        $images = [];
        foreach ($qb->getQuery()->getResult() as $row) {
            $images[] = $row['image'];
        }

        return $images;
    }

    /** How many images the criteria would carry over, without creating anything. */
    public function previewCount(Round $source, DerivationCriteria $criteria): int
    {
        return count($this->selectImages($source, $criteria));
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

        $images = $this->selectImages($source, $criteria);

        if ($images === []) {
            throw new DomainException('No images match those criteria.');
        }

        $round = new Round($source->getCampaign(), $name);
        $round->setDerivedFrom($source);
        $round->setDerivationCriteria($criteria->describe($source->getVotingMethod()));

        // Carry the source round's configuration forward as a starting
        // point; the coordinator edits it before activating.
        $round->setVotingMethod($source->getVotingMethod());
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
            'criteria' => $criteria->describe($source->getVotingMethod()),
        ]);

        return $round;
    }
}
