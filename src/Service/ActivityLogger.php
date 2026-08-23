<?php

declare(strict_types=1);

namespace JuryTool\Service;

use Doctrine\ORM\EntityManagerInterface;
use JuryTool\Domain\Entity\ActivityLog;
use JuryTool\Domain\Entity\User;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Records what users do, for the admin dashboard's audit feed.
 */
class ActivityLogger
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /**
     * Writes a log entry.
     *
     * @param array<string, mixed>|null $context Extra structured detail.
     */
    public function record(
        ?User $actor,
        string $action,
        string $summary,
        ?string $subjectType = null,
        ?int $subjectId = null,
        ?array $context = null,
        ?Request $request = null,
    ): void {
        $entry = new ActivityLog($actor, $action, $summary);

        if ($subjectType !== null) {
            $entry->setSubject($subjectType, $subjectId);
        }

        $entry->setContext($context);

        if ($request !== null) {
            $entry->setIpAddress($this->clientIp($request));
        }

        $this->em->persist($entry);
        $this->em->flush();
    }

    /**
     * A page of log entries, newest first.
     *
     * @return array{entries: list<array<string, mixed>>, total: int}
     */
    public function recent(
        int $limit = 50,
        int $offset = 0,
        ?string $action = null,
        ?string $actor = null,
    ): array {
        $build = function (bool $counting) use ($action, $actor) {
            $qb = $this->em->createQueryBuilder()
                ->from(ActivityLog::class, 'l')
                ->select($counting ? 'COUNT(l.id)' : 'l');

            if ($action !== null && $action !== '') {
                // Prefix match, so "round" selects every round.* action.
                $qb->andWhere('l.action LIKE :action')
                    ->setParameter('action', $action . '%');
            }

            if ($actor !== null && $actor !== '') {
                $qb->andWhere('l.actorUsername = :actor')
                    ->setParameter('actor', User::canonicaliseUsername($actor));
            }

            return $qb;
        };

        $total = (int) $build(true)->getQuery()->getSingleScalarResult();

        $rows = $build(false)
            ->orderBy('l.createdAt', 'DESC')
            ->addOrderBy('l.id', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->getQuery()
            ->getResult();

        return [
            'entries' => array_map(
                static fn (ActivityLog $l): array => [
                    'id' => $l->getId(),
                    'actor' => $l->getActorUsername(),
                    'action' => $l->getAction(),
                    'summary' => $l->getSummary(),
                    'subjectType' => $l->getSubjectType(),
                    'subjectId' => $l->getSubjectId(),
                    'context' => $l->getContext(),
                    'ipAddress' => $l->getIpAddress(),
                    'createdAt' => $l->getCreatedAt()->format(\DateTimeInterface::ATOM),
                ],
                $rows,
            ),
            'total' => $total,
        ];
    }

    /**
     * Distinct action names present in the log, for the filter dropdown.
     *
     * @return list<string>
     */
    public function knownActions(): array
    {
        $rows = $this->em->createQuery(
            'SELECT DISTINCT l.action FROM ' . ActivityLog::class . ' l ORDER BY l.action ASC'
        )->getResult();

        return array_map(static fn (array $row): string => (string) $row['action'], $rows);
    }

    /**
     * Best guess at the caller's address.
     *
     * Proxy headers are only trusted when the tool sits behind one — on
     * Toolforge it does — so the direct address is preferred and the
     * forwarded chain used only as a fallback.
     */
    private function clientIp(Request $request): ?string
    {
        $server = $request->getServerParams();

        $forwarded = $request->getHeaderLine('X-Forwarded-For');

        if ($forwarded !== '') {
            // Left-most entry is the original client.
            $first = trim(explode(',', $forwarded)[0]);

            if (filter_var($first, FILTER_VALIDATE_IP) !== false) {
                return $first;
            }
        }

        $remote = $server['REMOTE_ADDR'] ?? null;

        return is_string($remote) ? $remote : null;
    }
}
