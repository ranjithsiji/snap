<?php

declare(strict_types=1);

namespace JuryTool\Domain\Entity;

use Doctrine\ORM\Mapping as ORM;
use JuryTool\Domain\Enum\ParticipantRole;

/**
 * The "Round file settings" block: which images are disqualified at import
 * time, and what metadata jurors are allowed to see while voting.
 *
 * Embedded in Round rather than a separate table — these are always loaded
 * with their round and never queried independently.
 */
#[ORM\Embeddable]
class RoundFileSettings
{
    /** Default floor used when the resolution rule is switched on: 2 megapixels. */
    public const DEFAULT_MIN_RESOLUTION_PIXELS = 2_000_000;

    /** Exclude images uploaded by users who are jurors in this round. */
    #[ORM\Column(name: 'disqualify_jurors', type: 'boolean')]
    private bool $disqualifyJurors = false;

    /**
     * Whether the minimum-resolution rule is active. Kept separate from the
     * threshold so a coordinator can toggle the rule off without losing the
     * number they configured.
     */
    #[ORM\Column(name: 'disqualify_by_resolution', type: 'boolean')]
    private bool $disqualifyByResolution = false;

    /** Minimum total pixels (width x height); only applied when the rule is on. */
    #[ORM\Column(name: 'min_resolution_pixels', type: 'integer')]
    private int $minResolutionPixels = self::DEFAULT_MIN_RESOLUTION_PIXELS;

    /** Whether the upload-date window is active. */
    #[ORM\Column(name: 'disqualify_by_upload_date', type: 'boolean')]
    private bool $disqualifyByUploadDate = false;

    /** Images uploaded before this instant are disqualified. */
    #[ORM\Column(name: 'upload_date_from', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $uploadDateFrom = null;

    /** Images uploaded after this instant are disqualified. */
    #[ORM\Column(name: 'upload_date_to', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $uploadDateTo = null;

    /** Exclude uploads by campaign coordinators. */
    #[ORM\Column(name: 'disqualify_coordinators', type: 'boolean')]
    private bool $disqualifyCoordinators = false;

    /** Exclude uploads by campaign maintainers. */
    #[ORM\Column(name: 'disqualify_maintainers', type: 'boolean')]
    private bool $disqualifyMaintainers = false;

    /** Exclude uploads by campaign organizers. */
    #[ORM\Column(name: 'disqualify_organizers', type: 'boolean')]
    private bool $disqualifyOrganizers = false;

    /** Show the Commons filename to jurors while they vote. */
    #[ORM\Column(name: 'show_filename', type: 'boolean')]
    private bool $showFilename = false;

    /** Show a link back to the Commons file page. */
    #[ORM\Column(name: 'show_link', type: 'boolean')]
    private bool $showLink = false;

    /** Show pixel dimensions. */
    #[ORM\Column(name: 'show_resolution', type: 'boolean')]
    private bool $showResolution = false;

    /**
     * Show who uploaded the file. Off by default: withholding this is what
     * keeps judging blind, so a coordinator has to choose to give it up
     * rather than discover afterwards that it was on.
     */
    #[ORM\Column(name: 'show_uploader', type: 'boolean')]
    private bool $showUploader = false;

    public function disqualifiesByResolution(): bool
    {
        return $this->disqualifyByResolution;
    }

    public function setDisqualifyByResolution(bool $value): void
    {
        $this->disqualifyByResolution = $value;
    }

    public function getMinResolutionPixels(): int
    {
        return $this->minResolutionPixels;
    }

    public function setMinResolutionPixels(int $pixels): void
    {
        // A zero or negative floor would disqualify nothing while leaving the
        // rule apparently enabled; clamp so the setting stays meaningful.
        $this->minResolutionPixels = max(1, $pixels);
    }

    public function disqualifiesByUploadDate(): bool
    {
        return $this->disqualifyByUploadDate;
    }

    public function setDisqualifyByUploadDate(bool $value): void
    {
        $this->disqualifyByUploadDate = $value;
    }

    public function getUploadDateFrom(): ?\DateTimeImmutable
    {
        return $this->uploadDateFrom;
    }

    public function setUploadDateFrom(?\DateTimeImmutable $from): void
    {
        $this->uploadDateFrom = $from;
    }

    public function getUploadDateTo(): ?\DateTimeImmutable
    {
        return $this->uploadDateTo;
    }

    public function setUploadDateTo(?\DateTimeImmutable $to): void
    {
        $this->uploadDateTo = $to;
    }

    public function disqualifiesJurors(): bool
    {
        return $this->disqualifyJurors;
    }

    public function setDisqualifyJurors(bool $value): void
    {
        $this->disqualifyJurors = $value;
    }

    public function disqualifiesCoordinators(): bool
    {
        return $this->disqualifyCoordinators;
    }

    public function setDisqualifyCoordinators(bool $value): void
    {
        $this->disqualifyCoordinators = $value;
    }

    public function disqualifiesMaintainers(): bool
    {
        return $this->disqualifyMaintainers;
    }

    public function setDisqualifyMaintainers(bool $value): void
    {
        $this->disqualifyMaintainers = $value;
    }

    public function disqualifiesOrganizers(): bool
    {
        return $this->disqualifyOrganizers;
    }

    public function setDisqualifyOrganizers(bool $value): void
    {
        $this->disqualifyOrganizers = $value;
    }

    public function showsFilename(): bool
    {
        return $this->showFilename;
    }

    public function setShowFilename(bool $value): void
    {
        $this->showFilename = $value;
    }

    public function showsLink(): bool
    {
        return $this->showLink;
    }

    public function setShowLink(bool $value): void
    {
        $this->showLink = $value;
    }

    public function showsResolution(): bool
    {
        return $this->showResolution;
    }

    public function setShowResolution(bool $value): void
    {
        $this->showResolution = $value;
    }

    public function showsUploader(): bool
    {
        return $this->showUploader;
    }

    public function setShowUploader(bool $value): void
    {
        $this->showUploader = $value;
    }

    /**
     * Campaign participant roles whose uploads this round rejects.
     *
     * @return list<ParticipantRole>
     */
    public function disqualifiedParticipantRoles(): array
    {
        $roles = [];

        if ($this->disqualifyCoordinators) {
            $roles[] = ParticipantRole::Coordinator;
        }
        if ($this->disqualifyMaintainers) {
            $roles[] = ParticipantRole::Maintainer;
        }
        if ($this->disqualifyOrganizers) {
            $roles[] = ParticipantRole::Organizer;
        }

        return $roles;
    }

    /** Copies every setting from another instance, for round derivation. */
    public function copyFrom(self $other): void
    {
        $this->disqualifyJurors = $other->disqualifyJurors;
        $this->disqualifyByResolution = $other->disqualifyByResolution;
        $this->minResolutionPixels = $other->minResolutionPixels;
        $this->disqualifyByUploadDate = $other->disqualifyByUploadDate;
        $this->uploadDateFrom = $other->uploadDateFrom;
        $this->uploadDateTo = $other->uploadDateTo;
        $this->disqualifyCoordinators = $other->disqualifyCoordinators;
        $this->disqualifyMaintainers = $other->disqualifyMaintainers;
        $this->disqualifyOrganizers = $other->disqualifyOrganizers;
        $this->showFilename = $other->showFilename;
        $this->showLink = $other->showLink;
        $this->showResolution = $other->showResolution;
        $this->showUploader = $other->showUploader;
    }

    /** True when no filter is active, letting the caller skip the whole pass. */
    public function hasNoDisqualificationRules(): bool
    {
        return !$this->disqualifyByResolution
            && !$this->disqualifyByUploadDate
            && !$this->disqualifyJurors
            && $this->disqualifiedParticipantRoles() === [];
    }
}
