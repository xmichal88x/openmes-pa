<?php

namespace App\Http\Controllers\Web\Packaging;

/**
 * The packing station consumes the same JSON contract as the mobile API.
 * Kept as a thin subclass so the web routes stay in the Web namespace without
 * duplicating validation, locking or serialisation.
 */
class PackagingBoxController extends \App\Http\Controllers\Api\V1\PackagingBoxController {}
