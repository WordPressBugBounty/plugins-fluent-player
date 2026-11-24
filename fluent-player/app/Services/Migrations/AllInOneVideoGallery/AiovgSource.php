<?php

namespace FluentPlayer\App\Services\Migrations\AllInOneVideoGallery;

if (!defined('ABSPATH')) {
    exit;
}

use FluentPlayer\App\Services\Migrations\Contracts\MigrationSourceInterface;
use FluentPlayer\App\Services\Migrations\MigrationMap;
use FluentPlayer\Framework\Support\Arr;

/**
 * All-in-One Video Gallery as a registry source.
 *
 * Media only for this slice. Content rewrite, categories/tags, email leads and
 * view/like counters are deferred — capabilities() says so, so the card cannot
 * render a row that does nothing.
 */
class AiovgSource implements MigrationSourceInterface
{
    /** @var array|null */
    private $detected = null;

    /** @var bool|null Memoised isAvailable(); called several times per request. */
    private $available = null;

    public function key(): string
    {
        return 'aiovg';
    }

    public function label(): string
    {
        return 'All-in-One Video Gallery';
    }

    public function version()
    {
        return Scanner::version();
    }

    public function isAvailable(): bool
    {
        if ($this->available !== null) {
            return $this->available;
        }

        // Indexed COUNT first, memoised per request. The migration map is read
        // ONLY when there are no source videos: it can hold thousands of entries,
        // and this gates the Migration nav item on every FluentPlayer admin page
        // load — unserialising the whole map just to test it for emptiness was
        // pure waste on the common path.
        //
        // Deliberately NOT short-circuited on the AIOVG plugin constant: a site can
        // deactivate AIOVG while its aiovg_videos rows remain, and that user still
        // needs the card to import them.
        if (Scanner::videoCount() > 0) {
            return $this->available = true;
        }

        // Nothing left to import, but a previous import may still be resettable.
        return $this->available = !empty(MigrationMap::bucket($this->key(), 'media', []));
    }

    public function capabilities(): array
    {
        return ['media'];
    }

    public function detect(): array
    {
        if ($this->detected === null) {
            $this->detected = Scanner::detect();
        }

        return $this->detected;
    }

    public function scan(array $filters = []): array
    {
        return Scanner::scan(
            Arr::get($filters, 'type', 'all'),
            Arr::get($filters, 'search', '')
        );
    }
}
