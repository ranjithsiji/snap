<?php

declare(strict_types=1);

namespace JuryTool\Tests\Integration;

use Doctrine\ORM\EntityManagerInterface;
use JuryTool\Domain\Entity\Round;
use JuryTool\Domain\Entity\RoundImage;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The query behind deriving one round from another.
 *
 * It shipped selecting a joined alias without the root, which DQL refuses
 * outright — so every derivation failed with a semantical error and the
 * "next round from this one" step had never worked. The failure only
 * appears when the query is executed, so it needs a real database.
 *
 * @see \JuryTool\Service\RoundDerivationService::selectImages()
 */
#[Group('integration')]
class DerivationQueryTest extends TestCase
{
    private ?EntityManagerInterface $em = null;

    protected function setUp(): void
    {
        if ((getenv('TEST_DB_NAME') ?: '') === '') {
            $this->markTestSkipped('Set TEST_DB_NAME to run the derivation query test.');
        }

        $app = require dirname(__DIR__, 2) . '/config/bootstrap.php';
        $this->em = $app->getContainer()->get(EntityManagerInterface::class);
    }

    /**
     * Executes the same shape selectImages() builds. What is asserted is
     * that it runs at all and hands back the entity plus its aggregates —
     * a semantical error would throw before any assertion is reached.
     */
    #[Test]
    public function theSelectionQueryExecutesAndReturnsImagesWithAggregates(): void
    {
        // A round that actually holds images: picking any round at all
        // usually found an empty one and skipped before asserting
        // anything, which made the test look green without running.
        $round = $this->em->createQuery(
            'SELECT r FROM ' . Round::class . ' r
             WHERE (SELECT COUNT(i.id) FROM ' . RoundImage::class . ' i WHERE i.round = r) > 0'
        )->setMaxResults(1)->getOneOrNullResult();

        if ($round === null) {
            self::markTestSkipped('No round in the test database holds images.');
        }

        $rows = $this->em->createQueryBuilder()
            ->select('ri', 'COUNT(v.id) AS voteCount', 'AVG(v.score) AS averageScore')
            ->addSelect('SUM(CASE WHEN v.score = 1 THEN 1 ELSE 0 END) AS acceptCount')
            ->from(RoundImage::class, 'ri')
            ->join('ri.image', 'ci')
            ->leftJoin('ri.votes', 'v')
            ->where('ri.round = :round')
            ->groupBy('ri.id')
            ->setParameter('round', $round)
            ->setMaxResults(5)
            ->getQuery()
            ->getResult();

        self::assertIsArray($rows);

        if ($rows === []) {
            self::markTestSkipped('The round holds no images.');
        }

        // The entity arrives under key 0, which is what selectImages()
        // unwraps to reach the campaign image.
        self::assertInstanceOf(RoundImage::class, $rows[0][0]);
        self::assertArrayHasKey('voteCount', $rows[0]);
        self::assertArrayHasKey('averageScore', $rows[0]);
        self::assertArrayHasKey('acceptCount', $rows[0]);
    }
}
