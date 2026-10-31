<?php

declare(strict_types=1);

namespace App\Domain\Storefront\Exceptions;

use RuntimeException;

/**
 * A slot assignment named a slot the page does not have, or a platform
 * slot a theme may not supply content for. Composition fails loudly
 * rather than letting a commerce action be replaced silently.
 */
final class SlotCompositionRefused extends RuntimeException {}
