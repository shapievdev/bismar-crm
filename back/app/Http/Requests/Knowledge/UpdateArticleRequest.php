<?php

declare(strict_types=1);

namespace App\Http\Requests\Knowledge;

/**
 * Updating an article validates exactly like creating one, including the
 * publish-privilege check.
 */
final class UpdateArticleRequest extends StoreArticleRequest {}
