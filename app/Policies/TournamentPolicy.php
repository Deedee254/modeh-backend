<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Tournament;
use Illuminate\Auth\Access\HandlesAuthorization;

class TournamentPolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the user can view any models.
     *
     * @param  \App\Models\User  $user
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function viewAny(?User $user)
    {
        return true;
    }

    /**
     * Determine whether the user can view the model.
     *
     * @param  \App\Models\User  $user
     * @param  \App\Models\Tournament  $tournament
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function view(?User $user, Tournament $tournament)
    {
        return true;
    }

    /**
     * Determine whether the user can create models.
     *
     * @param  \App\Models\User  $user
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function create(User $user)
    {
        return $user->is_admin;
    }

    /**
     * Determine whether the user can update the model.
     *
     * @param  \App\Models\User  $user
     * @param  \App\Models\Tournament  $tournament
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function update(User $user, Tournament $tournament)
    {
        return $user->is_admin;
    }

    /**
     * Determine whether the user can delete the model.
     *
     * @param  \App\Models\User  $user
     * @param  \App\Models\Tournament  $tournament
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function delete(User $user, Tournament $tournament)
    {
        return $user->is_admin;
    }

    /**
     * Determine whether the user can restore the model.
     *
     * @param  \App\Models\User  $user
     * @param  \App\Models\Tournament  $tournament
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function restore(User $user, Tournament $tournament)
    {
        return $user->is_admin;
    }

    /**
     * Determine whether the user can permanently delete the model.
     *
     * @param  \App\Models\User  $user
     * @param  \App\Models\Tournament  $tournament
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function forceDelete(User $user, Tournament $tournament)
    {
        return $user->is_admin;
    }

    /**
     * Determine whether the user can join the tournament.
     *
     * @param  \App\Models\User  $user
     * @param  \App\Models\Tournament  $tournament
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function join(User $user, Tournament $tournament)
    {
        if ($tournament->grade_id && $user->quizeeProfile && $user->quizeeProfile->grade_id !== $tournament->grade_id) {
            return $this->deny('You are not in the correct grade for this tournament.');
        }

        return true;
    }

    /**
     * Determine whether the user can approve a registration for the tournament.
     *
     * @param  \App\Models\User  $user
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function approveRegistration(User $user)
    {
        return $user->is_admin;
    }

    /**
     * Determine whether the user can reject a registration for the tournament.
     *
     * @param  \App\Models\User  $user
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function rejectRegistration(User $user)
    {
        return $user->is_admin;
    }
}
