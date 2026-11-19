<?php

namespace App\Policies;

use App\Models\Document;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class DocumentPolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the user can view any documents.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('documents.view');
    }

    /**
     * Determine whether the user can view the document.
     */
    public function view(User $user, Document $document): bool
    {
        if ($user->can('documents.view')) {
            return true;
        }

        if ($document->access_level === 'public' && $document->status === 'published') {
            return true;
        }

        return false;
    }

    /**
     * Determine whether the user can create documents.
     */
    public function create(User $user): bool
    {
        return $user->can('documents.create');
    }

    /**
     * Determine whether the user can update the document metadata.
     */
    public function update(User $user, Document $document): bool
    {
        return $user->can('documents.edit');
    }

    /**
     * Determine whether the user can upload a revised version.
     */
    public function revise(User $user, Document $document): bool
    {
        return $user->can('documents.revise') || $user->can('documents.create');
    }

    /**
     * Determine whether the user can delete the document.
     */
    public function delete(User $user, Document $document): bool
    {
        return $user->can('documents.delete');
    }

    /**
     * Determine whether the user can restore the document.
     */
    public function restore(User $user, Document $document): bool
    {
        return $user->can('documents.restore') || $user->can('documents.delete');
    }

    /**
     * Determine whether the user can download the document.
     */
    public function download(User $user, Document $document): bool
    {
        if ($user->can('documents.download') || $user->can('documents.view')) {
            return true;
        }

        if ($document->access_level === 'public' && $document->status === 'published') {
            return true;
        }

        return false;
    }

    /**
     * Determine whether the user can export documents.
     */
    public function export(User $user): bool
    {
        return $user->can('documents.export') || $user->can('documents.view');
    }

    /**
     * Determine whether the user can import documents.
     */
    public function import(User $user): bool
    {
        return $user->can('documents.import') || $user->can('documents.create');
    }
}
