<?php

declare(strict_types=1);

namespace JuryTool\Support;

use JuryTool\Domain\Entity\Campaign;
use JuryTool\Domain\Entity\Project;
use JuryTool\Domain\Entity\Round;
use JuryTool\Domain\Entity\RoundImage;
use JuryTool\Domain\Entity\User;

/**
 * Turns entities into the JSON shapes the SPA consumes.
 *
 * Kept in one place so a field is exposed identically wherever it appears,
 * and so juror-facing payloads can be filtered by the round's display
 * settings in a single, auditable spot.
 */
final class Presenter
{
    /** @return array<string, mixed> */
    public static function user(User $user): array
    {
        return [
            'id' => $user->getId(),
            'username' => $user->getUsername(),
            'role' => $user->getRole()->value,
            'isWikimediaLinked' => $user->getCentralAuthId() !== null,
        ];
    }

    /** @return array<string, mixed> */
    public static function project(Project $project, bool $withCampaigns = false): array
    {
        $data = [
            'id' => $project->getId(),
            'name' => $project->getName(),
            'slug' => $project->getSlug(),
            'description' => $project->getDescription(),
            'homepageUrl' => $project->getHomepageUrl(),
            'isArchived' => $project->isArchived(),
            'leads' => $project->leadUsernames(),
            'campaignCount' => $project->getCampaigns()->count(),
            'createdAt' => self::date($project->getCreatedAt()),
        ];

        if ($withCampaigns) {
            $data['campaigns'] = array_map(
                static fn (Campaign $c): array => self::campaign($c),
                $project->getCampaigns()->toArray(),
            );
        }

        return $data;
    }

    /** @return array<string, mixed> */
    public static function campaign(Campaign $campaign, bool $withRounds = false): array
    {
        $data = [
            'id' => $campaign->getId(),
            'projectId' => $campaign->getProject()->getId(),
            'projectName' => $campaign->getProject()->getName(),
            'name' => $campaign->getName(),
            'slug' => $campaign->getSlug(),
            'description' => $campaign->getDescription(),
            'year' => $campaign->getYear(),
            'startsAt' => self::date($campaign->getStartsAt()),
            'endsAt' => self::date($campaign->getEndsAt()),
            'isClosed' => $campaign->isClosed(),
            'isArchived' => $campaign->isArchived(),
            'sourceType' => $campaign->getSourceType()->value,
            'sourceCategory' => $campaign->getSourceCategory(),
            'sourceUrl' => $campaign->getSourceUrl(),
            // Carried so the edit form can load a file-list campaign and
            // save it back unchanged; without it, editing anything else
            // would submit an empty list and wipe the source.
            'sourceFileList' => $campaign->getSourceFileList(),
            'sourceSummary' => $campaign->sourceSummary(),
            'importedAt' => self::date($campaign->getImportedAt()),
            'imageCount' => $campaign->getImages()->count(),
            'createdAt' => self::date($campaign->getCreatedAt()),
        ];

        if ($withRounds) {
            $data['rounds'] = array_map(
                static fn (Round $r): array => self::round($r),
                $campaign->getRounds()->toArray(),
            );
        }

        return $data;
    }

    /** @return array<string, mixed> */
    public static function round(Round $round): array
    {
        $settings = $round->getFileSettings();

        return [
            'id' => $round->getId(),
            'campaignId' => $round->getCampaign()->getId(),
            'campaignName' => $round->getCampaign()->getName(),
            'name' => $round->getName(),
            'sequence' => $round->getSequence(),
            'details' => $round->getDetails(),
            'votingDeadline' => self::date($round->getVotingDeadline()),
            'deadlinePassed' => $round->hasDeadlinePassed(),
            'votingMethod' => $round->getVotingMethod()->value,
            'votingMethodLabel' => $round->getVotingMethod()->label(),
            'maxRating' => $round->getMaxRating(),
            'quorum' => $round->getQuorum(),
            'effectiveQuorum' => $round->effectiveQuorum(),
            'showOwnStatistics' => $round->showsOwnStatistics(),
            'state' => $round->getState()->value,
            'acceptsVotes' => $round->acceptsVotes(),
            'derivedFromRoundId' => $round->getDerivedFrom()?->getId(),
            'derivedFromRoundName' => $round->getDerivedFrom()?->getName(),
            'derivationCriteria' => $round->getDerivationCriteria(),
            'sourceType' => $round->getSourceType()?->value,
            'sourceCategory' => $round->getSourceCategory(),
            'sourceUrl' => $round->getSourceUrl(),
            'sourceSummary' => $round->sourceSummary(),
            'hasOwnSource' => $round->hasOwnSource(),
            'importedAt' => self::date($round->getImportedAt()),
            // A round that continues another is shown as part of that
            // sequence in the UI rather than as a standalone round.
            'isContinuation' => $round->getDerivedFrom() !== null,
            'createdAt' => self::date($round->getCreatedAt()),
            'jurorUsernames' => $round->jurorUsernames(),
            'fileSettings' => [
                'disqualifyJurors' => $settings->disqualifiesJurors(),
                'disqualifyByResolution' => $settings->disqualifiesByResolution(),
                'minResolutionPixels' => $settings->getMinResolutionPixels(),
                'disqualifyByUploadDate' => $settings->disqualifiesByUploadDate(),
                'uploadDateFrom' => self::date($settings->getUploadDateFrom()),
                'uploadDateTo' => self::date($settings->getUploadDateTo()),
                'disqualifyCoordinators' => $settings->disqualifiesCoordinators(),
                'disqualifyMaintainers' => $settings->disqualifiesMaintainers(),
                'disqualifyOrganizers' => $settings->disqualifiesOrganizers(),
                'showFilename' => $settings->showsFilename(),
                'showLink' => $settings->showsLink(),
                'showResolution' => $settings->showsResolution(),
                'showUploader' => $settings->showsUploader(),
            ],
        ];
    }

    /**
     * An image as a coordinator sees it — everything, including who
     * uploaded it and why it may have been excluded.
     *
     * @return array<string, mixed>
     */
    public static function imageForAdmin(RoundImage $image): array
    {
        return [
            'id' => $image->getId(),
            'pageId' => $image->getCommonsPageId(),
            'title' => $image->getTitle(),
            'name' => $image->getDisplayName(),
            'fileUrl' => $image->getFileUrl(),
            'thumbUrl' => $image->getThumbUrl(),
            'descriptionUrl' => $image->getDescriptionUrl(),
            'width' => $image->getWidth(),
            'height' => $image->getHeight(),
            'uploader' => $image->getUploader(),
            'uploadedAt' => self::date($image->getUploadedAt()),
            'isDisqualified' => $image->isDisqualified(),
            'disqualificationReason' => $image->getDisqualificationReason(),
            'voteCount' => $image->getVotes()->count(),
        ];
    }

    /**
     * An image as a juror sees it while voting.
     *
     * Filename, file link, resolution and the uploader are all withheld
     * unless the round enables them — that is what keeps the judging
     * blind by default, while still letting a coordinator who wants
     * open judging turn any of it on.
     *
     * @return array<string, mixed>
     */
    public static function imageForJuror(RoundImage $image, bool $lowBandwidth = false): array
    {
        $settings = $image->getRound()->getFileSettings();
        $thumb = $image->getThumbUrl();

        $data = [
            'id' => $image->getId(),
            // Served straight from Wikimedia's CDN — this tool stores the
            // URL only and never copies the file.
            'thumbUrl' => $lowBandwidth
                ? (self::resizeThumb($thumb, 640) ?? $image->getFileUrl())
                : ($thumb ?? $image->getFileUrl()),
            // A grid tile needs far less than the voting stage does.
            'gridUrl' => self::resizeThumb($thumb, 480) ?? $thumb,
            // The full-size original, for the "Show full-size" action. This
            // is the same file the thumbnail derives from, so it reveals
            // nothing the juror cannot already see.
            'fileUrl' => $image->getFileUrl(),
        ];

        if ($settings->showsFilename()) {
            $data['name'] = $image->getDisplayName();
        }

        if ($settings->showsLink()) {
            $data['descriptionUrl'] = $image->getDescriptionUrl();
        }

        if ($settings->showsResolution()) {
            $data['width'] = $image->getWidth();
            $data['height'] = $image->getHeight();
            $data['megapixels'] = round($image->getPixelCount() / 1_000_000, 1);
        }

        if ($settings->showsUploader()) {
            $data['uploader'] = $image->getUploader();
        }

        return $data;
    }

    /**
     * Rewrites a Commons thumbnail URL to a different width.
     *
     * Images are never copied into this tool — only their URLs are stored,
     * and browsers fetch them straight from Wikimedia's CDN. Commons serves
     * any width from the same path, so a smaller thumbnail costs nothing to
     * produce and lets a juror on a slow connection work comfortably.
     *
     * Two shapes reach here: the API path's real upload.wikimedia.org thumb
     * URL (.../thumb/x/xx/File.jpg/640px-File.jpg), and the replica path's
     * Special:FilePath redirect (.../Special:FilePath/File.jpg?width=640).
     * A URL of one shape silently surviving unchanged when only the other
     * is handled is how the replica path lost its resizing before — it
     * never errored, it just always served the default width.
     */
    public static function resizeThumb(?string $thumbUrl, int $width): ?string
    {
        if ($thumbUrl === null) {
            return null;
        }

        if (str_contains($thumbUrl, 'Special:FilePath')) {
            return preg_replace('#([?&])width=\d+#', "\$1width={$width}", $thumbUrl, 1) ?? $thumbUrl;
        }

        return preg_replace('#/\d+px-#', "/{$width}px-", $thumbUrl, 1) ?? $thumbUrl;
    }

    private static function date(?\DateTimeInterface $date): ?string
    {
        return $date?->format(\DateTimeInterface::ATOM);
    }
}
