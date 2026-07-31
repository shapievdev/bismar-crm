<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\KnowledgeArticle;
use App\Models\User;

class KnowledgeArticlePolicy
{
    /**
     * Readers see published articles; unpublished drafts are visible only to
     * those who could edit them.
     */
    public function view(User $user, KnowledgeArticle $article): bool
    {
        if ($article->status->isPubliclyReadable()) {
            return $user->can(Permission::ViewKnowledge->value);
        }

        return $user->can(Permission::UpdateKnowledge->value);
    }

    public function update(User $user, KnowledgeArticle $article): bool
    {
        return $user->can(Permission::UpdateKnowledge->value);
    }

    public function delete(User $user, KnowledgeArticle $article): bool
    {
        return $user->can(Permission::DeleteKnowledge->value);
    }
}
