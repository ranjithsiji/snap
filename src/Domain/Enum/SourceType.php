<?php

declare(strict_types=1);

namespace JuryTool\Domain\Enum;

/**
 * Where a round's images come from.
 */
enum SourceType: string
{
    /** A category on Wikimedia Commons that gathers all contest images. */
    case Category = 'category';

    /** A URL pointing at a newline-separated list of file names. */
    case FileListUrl = 'filelist_url';

    /** A newline-separated list of file names pasted or uploaded directly. */
    case FileList = 'filelist';

    /** Images carried over from an earlier round by criteria. */
    case PreviousRound = 'previous_round';

    public function label(): string
    {
        return match ($this) {
            self::Category => 'Category on Wikimedia Commons',
            self::FileListUrl => 'File List URL',
            self::FileList => 'File List',
            self::PreviousRound => 'Previous round',
        };
    }

    /** Whether this source is fetched from Commons by file title rather than by category. */
    public function isTitleList(): bool
    {
        return $this === self::FileListUrl || $this === self::FileList;
    }
}
