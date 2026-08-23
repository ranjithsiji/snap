<?php

declare(strict_types=1);

namespace JuryTool\Service;

use JuryTool\Domain\Entity\CampaignImage;
use JuryTool\Domain\Entity\CampaignParticipant;
use JuryTool\Domain\Entity\Round;
use JuryTool\Domain\Entity\User;
use JuryTool\Domain\Enum\ParticipantRole;

/**
 * Decides whether a campaign image is disqualified under a round's file
 * settings, and why.
 *
 * Built once per round so the participant and juror lookups are resolved a
 * single time rather than per image — a round can carry tens of thousands
 * of images.
 */
class DisqualificationEvaluator
{
    /** @var array<string, list<ParticipantRole>> username => roles held */
    private array $rolesByUser = [];

    /** @var array<string, true> usernames of this round's jurors */
    private array $jurorUsernames = [];

    /**
     * @param list<string> $globalOrganizers Usernames holding the global
     *        organizer or administrator role. Passed in rather than queried
     *        so this class stays free of persistence concerns.
     */
    public function __construct(
        private readonly Round $round,
        array $globalOrganizers = [],
    ) {
        $settings = $round->getFileSettings();

        foreach ($round->getCampaign()->getParticipants() as $participant) {
            /** @var CampaignParticipant $participant */
            $this->rolesByUser[$participant->getUsername()][] = $participant->getRole();
        }

        // Someone running the contest tool-wide counts as an organizer even
        // if nobody added them to this campaign's participant list.
        if ($settings->disqualifiesOrganizers()) {
            foreach ($globalOrganizers as $username) {
                $canonical = User::canonicaliseUsername($username);

                if (!in_array(ParticipantRole::Organizer, $this->rolesByUser[$canonical] ?? [], true)) {
                    $this->rolesByUser[$canonical][] = ParticipantRole::Organizer;
                }
            }
        }

        // Jurors are disqualified across the whole campaign, not just this
        // round: someone who judged round 1 should not win round 2.
        if ($settings->disqualifiesJurors()) {
            foreach ($this->campaignJurorUsernames() as $username) {
                $this->jurorUsernames[$username] = true;
            }
        }
    }

    /**
     * Every juror seat across the campaign, including seats that were later
     * handed to someone else — the original holder still took part.
     *
     * @return list<string>
     */
    private function campaignJurorUsernames(): array
    {
        $usernames = [];

        foreach ($this->round->getCampaign()->getRounds() as $round) {
            foreach ($round->getJurors() as $juror) {
                $usernames[] = $juror->getUsername();

                if ($juror->getReplacedUsername() !== null) {
                    $usernames[] = $juror->getReplacedUsername();
                }
            }
        }

        return array_values(array_unique($usernames));
    }

    /**
     * The reason this image fails the round's rules, or null when it passes.
     *
     * Rules are checked cheapest-first, and the first failure wins — an
     * image excluded for several reasons reports only the first, which is
     * what a coordinator auditing the list wants to see.
     */
    public function reasonFor(CampaignImage $image): ?string
    {
        $settings = $this->round->getFileSettings();

        if ($settings->disqualifiesByResolution()) {
            $minimum = $settings->getMinResolutionPixels();

            if ($image->getPixelCount() < $minimum) {
                return sprintf(
                    'Resolution %s px is below the %s px minimum',
                    number_format($image->getPixelCount()),
                    number_format($minimum),
                );
            }
        }

        if ($settings->disqualifiesByUploadDate()) {
            $uploadedAt = $image->getUploadedAt();
            $from = $settings->getUploadDateFrom();
            $to = $settings->getUploadDateTo();

            // An image with no known upload date cannot satisfy a date
            // window, so it is excluded rather than silently admitted.
            if ($uploadedAt === null && ($from !== null || $to !== null)) {
                return 'Upload date is unknown';
            }

            if ($uploadedAt !== null && $from !== null && $uploadedAt < $from) {
                return sprintf('Uploaded before %s', $from->format('Y-m-d'));
            }

            if ($uploadedAt !== null && $to !== null && $uploadedAt > $to) {
                return sprintf('Uploaded after %s', $to->format('Y-m-d'));
            }
        }

        $uploader = $image->getUploader();

        if ($uploader === null) {
            return null;
        }

        if (isset($this->jurorUsernames[$uploader])) {
            return 'Uploaded by a juror in this campaign';
        }

        $disqualifiedRoles = $settings->disqualifiedParticipantRoles();

        if ($disqualifiedRoles !== []) {
            foreach ($this->rolesByUser[$uploader] ?? [] as $role) {
                if (in_array($role, $disqualifiedRoles, true)) {
                    return sprintf('Uploaded by a campaign %s', $role->value);
                }
            }
        }

        return null;
    }

    public function isDisqualified(CampaignImage $image): bool
    {
        return $this->reasonFor($image) !== null;
    }

    /**
     * Whether any rule is active at all. Lets callers skip the whole pass
     * when a round disqualifies nothing.
     */
    public function hasActiveRules(): bool
    {
        return !$this->round->getFileSettings()->hasNoDisqualificationRules();
    }
}
