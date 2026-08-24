<?php

namespace App\Policies;

use App\Models\Ticket;
use App\Models\User;

class TicketPolicy
{
    /**
     * Determine whether the user can view any tickets.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view the specific ticket.
     */
    public function view(User $user, Ticket $ticket): bool
    {
        if ($user->hasRole('Super Administrator') || $user->hasRole('Administrator') || $user->can('tickets.view')) {
            return true;
        }

        return $ticket->user_id === $user->id;
    }

    /**
     * Determine whether the user can create tickets.
     */
    public function create(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can post replies to the ticket.
     */
    public function reply(User $user, Ticket $ticket): bool
    {
        if ($user->hasRole('Super Administrator') || $user->hasRole('Administrator') || $user->can('tickets.reply')) {
            return true;
        }

        return $ticket->user_id === $user->id;
    }

    /**
     * Determine whether the user can add internal staff-only notes.
     */
    public function addInternalNote(User $user, Ticket $ticket): bool
    {
        return $user->hasRole('Super Administrator') || $user->hasRole('Administrator') || $user->can('tickets.internal_notes');
    }

    /**
     * Determine whether the user can assign/reassign tickets.
     */
    public function assign(User $user, Ticket $ticket): bool
    {
        return $user->hasRole('Super Administrator') || $user->hasRole('Administrator') || $user->can('tickets.assign');
    }

    /**
     * Determine whether the user can manage status, priorities, categories, and settings.
     */
    public function manage(User $user, Ticket $ticket): bool
    {
        return $user->hasRole('Super Administrator') || $user->hasRole('Administrator') || $user->can('tickets.manage');
    }

    /**
     * Determine whether the user can delete the ticket.
     */
    public function delete(User $user, Ticket $ticket): bool
    {
        return $user->hasRole('Super Administrator') || $user->hasRole('Administrator') || $user->can('tickets.manage');
    }

    /**
     * Determine whether the user can submit a satisfaction rating.
     */
    public function rate(User $user, Ticket $ticket): bool
    {
        return $ticket->user_id === $user->id && $ticket->isClosed();
    }
}
