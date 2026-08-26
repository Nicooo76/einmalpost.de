<?php

declare(strict_types=1);

namespace Einmalpost;

/**
 * Was im Kopfbereich einer Seite steht.
 *
 * Pro Seite eigene Angaben: Ein Titel, der überall gleich ist, hilft weder
 * Besuchern noch Suchmaschinen.
 */
final class PageMeta
{
    /**
     * @param bool $indexierbar Auf /s/* und /api/* ausdrücklich false.
     * @param bool $mitOpenGraph Auf /s/* false - dort gibt es nichts zu teilen.
     */
    public function __construct(
        public readonly string $title,
        public readonly string $description,
        public readonly string $bodyClass,
        public readonly bool $indexierbar = true,
        public readonly bool $mitOpenGraph = false,
        public readonly string $canonical = '',
        public readonly string $sprache = Sprache::DEUTSCH,
    ) {
    }
}
