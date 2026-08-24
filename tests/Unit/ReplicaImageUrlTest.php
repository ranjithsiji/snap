<?php

declare(strict_types=1);

namespace JuryTool\Tests\Unit;

use JuryTool\Infrastructure\Commons\ReplicaClient;
use JuryTool\Support\Presenter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * The image URLs the replica path builds by hand, since it has no
 * imageinfo API response to read them from.
 *
 * An earlier version computed the upload path's hash-bucket directory
 * itself — two hex characters of md5($title) — and got the input wrong:
 * MediaWiki hashes the DB-form title, this hashed something else, and
 * every file on Toolforge 404'd. Nothing caught it locally, because the
 * API-backed CommonsClient path (used off the replica) gets real URLs
 * from Wikimedia directly and was never wrong. Building through
 * Special:FilePath removes the bucket from the equation rather than
 * getting it right, so there is nothing left here to drift.
 */
class ReplicaImageUrlTest extends TestCase
{
    private function client(): ReplicaClient
    {
        return (new ReflectionClass(ReplicaClient::class))->newInstanceWithoutConstructor();
    }

    private function call(string $method, mixed ...$args): mixed
    {
        $reflection = new ReflectionMethod(ReplicaClient::class, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($this->client(), ...$args);
    }

    #[Test]
    public function the_file_url_redirects_through_special_file_path(): void
    {
        $url = $this->call('fileUrl', 'A_Great_Hornbill.jpg');

        $this->assertSame(
            'https://commons.wikimedia.org/wiki/Special:FilePath/A_Great_Hornbill.jpg',
            $url,
        );
    }

    #[Test]
    public function the_thumb_url_carries_a_width_parameter(): void
    {
        $url = $this->call('thumbUrl', 'A_Great_Hornbill.jpg', 640);

        $this->assertSame(
            'https://commons.wikimedia.org/wiki/Special:FilePath/A_Great_Hornbill.jpg?width=640',
            $url,
        );
    }

    /** A comma, as in the file that first surfaced this, must survive encoding. */
    #[Test]
    public function punctuation_in_the_title_is_encoded_not_lost(): void
    {
        $url = $this->call('fileUrl', 'A_Hornbill,_Palakkad_01.jpg');

        $this->assertStringContainsString('A_Hornbill%2C_Palakkad_01.jpg', $url);
    }

    #[Test]
    public function resizing_a_special_file_path_url_rewrites_the_width(): void
    {
        $thumb = 'https://commons.wikimedia.org/wiki/Special:FilePath/Example.jpg?width=1024';

        $this->assertSame(
            'https://commons.wikimedia.org/wiki/Special:FilePath/Example.jpg?width=480',
            Presenter::resizeThumb($thumb, 480),
        );
    }

    /**
     * The other shape reaching resizeThumb: the API path's real
     * upload.wikimedia.org thumbnail. Both must resize, since a URL of one
     * shape silently surviving unchanged is exactly how this bug hid —
     * it never errored, it just always served the original width.
     */
    #[Test]
    public function resizing_an_upload_wikimedia_thumb_still_works(): void
    {
        $thumb = 'https://upload.wikimedia.org/wikipedia/commons/thumb/d/d5/Example.jpg/640px-Example.jpg';

        $this->assertSame(
            'https://upload.wikimedia.org/wikipedia/commons/thumb/d/d5/Example.jpg/480px-Example.jpg',
            Presenter::resizeThumb($thumb, 480),
        );
    }
}
