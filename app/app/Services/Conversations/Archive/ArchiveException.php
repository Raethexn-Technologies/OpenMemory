<?php

namespace App\Services\Conversations\Archive;

use RuntimeException;

/**
 * Raised when an archive cannot be opened, or when it violates a safety limit.
 *
 * These failures are always reported to the operator. An archive that trips a
 * limit is refused outright rather than partially imported, because a partial
 * import of a hostile archive is still a successful attack.
 */
class ArchiveException extends RuntimeException {}
