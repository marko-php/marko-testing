<?php

declare(strict_types=1);

/*
 * Backward-compatible shim. The expectations are registered automatically by
 * Marko\Testing\Pest\ExpectationsPlugin, a Pest plugin declared in composer.json
 * (extra.pest.plugins), so Pest.php needs no require. Requiring this file still
 * works: registration is idempotent.
 */

use Marko\Testing\Pest\ExpectationsPlugin;

ExpectationsPlugin::registerExpectations();
