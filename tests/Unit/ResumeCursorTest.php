<?php

declare(strict_types=1);

namespace JuryTool\Tests\Unit;

use JuryTool\Domain\Entity\RoundSource;
use JuryTool\Infrastructure\Commons\CommonsFile;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * The resume cursor decides which part of a category a retry skips, so a
 * wrong value here does not fail loudly — it silently leaves photographs
 * out of a round.
 */
class ResumeCursorTest extends TestCase
{
    private function file(): CommonsFile
    {
        return new CommonsFile(
            pageId: 1,
            title: 'File:Example.jpg',
            fileUrl: 'https://example.org/a.jpg',
            descriptionUrl: null,
            thumbUrl: null,
            width: 100,
            height: 100,
            mimeType: 'image/jpeg',
            uploader: null,
            uploadedAt: null,
        );
    }

    private function source(): RoundSource
    {
        // The constructor needs a Round; none of this touches it.
        return (new ReflectionClass(RoundSource::class))->newInstanceWithoutConstructor();
    }

    /**
     * The API pages on an opaque continue token that does not map onto
     * individual files. Its generator keys are auto-numbered positions,
     * which look like cursors but are not — recording one would make a
     * retry skip to an unrelated point in the category.
     */
    #[Test]
    public function a_file_carries_no_cursor_unless_given_one(): void
    {
        $this->assertNull($this->file()->resumeCursor);
    }

    #[Test]
    public function tagging_a_file_records_where_it_was_read(): void
    {
        $tagged = $this->file()->withResumeCursor(48127);

        $this->assertSame(48127, $tagged->resumeCursor);
    }

    #[Test]
    public function tagging_leaves_the_original_alone(): void
    {
        $file = $this->file();
        $file->withResumeCursor(48127);

        $this->assertNull($file->resumeCursor);
    }

    #[Test]
    public function tagging_preserves_every_other_field(): void
    {
        $tagged = $this->file()->withResumeCursor(1);

        $this->assertSame(1, $tagged->pageId);
        $this->assertSame('File:Example.jpg', $tagged->title);
        $this->assertSame('https://example.org/a.jpg', $tagged->fileUrl);
        $this->assertSame(100, $tagged->width);
        $this->assertSame('image/jpeg', $tagged->mimeType);
    }

    /**
     * Rows arrive ordered, but the guard matters anyway: a cursor that
     * could move backwards would re-read files already written, and worse,
     * a later resume could start behind where it safely could.
     */
    #[Test]
    public function the_cursor_only_moves_forward(): void
    {
        $source = $this->source();

        foreach ([100, 500, 300, 900, 200] as $cursor) {
            $source->recordResumeCursor($cursor);
        }

        $this->assertSame(900, $source->getResumeCursor());
    }

    /** Files with no cursor must not disturb one already recorded. */
    #[Test]
    public function recording_null_changes_nothing(): void
    {
        $source = $this->source();
        $source->recordResumeCursor(500);
        $source->recordResumeCursor(null);

        $this->assertSame(500, $source->getResumeCursor());
    }

    #[Test]
    public function clearing_forgets_the_position(): void
    {
        $source = $this->source();
        $source->recordResumeCursor(500);
        $source->clearResumeCursor();

        $this->assertNull($source->getResumeCursor());
    }

    /**
     * A finished source has nothing to resume from. Reporting one would
     * make the next import of the same category skip everything before it
     * and miss files added since.
     */
    #[Test]
    public function a_completed_source_reports_no_cursor(): void
    {
        $source = $this->source();
        $source->recordResumeCursor(500);
        $source->markComplete();

        $this->assertNull($source->getResumeCursor());
    }
}
