<?php

declare(strict_types=1);

namespace App\Http\Requests\Lms;

/**
 * Updating validates exactly like creating, including the publish privilege.
 */
final class UpdateCourseRequest extends StoreCourseRequest {}
