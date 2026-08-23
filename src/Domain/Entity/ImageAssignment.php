<?php

declare(strict_types=1);

namespace JuryTool\Domain\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * One image allotted to one juror to judge.
 *
 * Created when a round is activated: each qualified image is dealt to
 * exactly `quorum` jurors, so every image is guaranteed the required number
 * of independent opinions and every juror gets an equal, defined workload.
 *
 * A juror who exhausts their own list may still pick up unassigned work
 * from absent jurors — see VotingService::nextImagesFor — so the round can
 * finish even when someone stops participating.
 */
#[ORM\Entity]
#[ORM\Table(name: 'image_assignment')]
#[ORM\UniqueConstraint(name: 'uniq_assignment', columns: ['image_id', 'juror_id'])]
#[ORM\Index(name: 'idx_assignment_juror', columns: ['juror_id'])]
class ImageAssignment
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: RoundImage::class)]
    #[ORM\JoinColumn(name: 'image_id', nullable: false, onDelete: 'CASCADE')]
    private RoundImage $image;

    #[ORM\ManyToOne(targetEntity: RoundJuror::class)]
    #[ORM\JoinColumn(name: 'juror_id', nullable: false, onDelete: 'CASCADE')]
    private RoundJuror $juror;

    #[ORM\Column(name: 'assigned_at', type: 'datetime_immutable')]
    private DateTimeImmutable $assignedAt;

    public function __construct(RoundImage $image, RoundJuror $juror)
    {
        $this->image = $image;
        $this->juror = $juror;
        $this->assignedAt = new DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getImage(): RoundImage
    {
        return $this->image;
    }

    public function getJuror(): RoundJuror
    {
        return $this->juror;
    }

    public function getAssignedAt(): DateTimeImmutable
    {
        return $this->assignedAt;
    }
}
