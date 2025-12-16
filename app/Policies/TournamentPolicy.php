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
        // access_type controls who may join: public, grade, level
        $access = $tournament->access_type ?? 'public';

        if ($access === 'public') {
            return true;
        }

        // Grade-restricted: enforce grade match
        if ($access === 'grade') {
            if ($tournament->grade_id) {
                if (! $user->quizeeProfile || ($user->quizeeProfile->grade_id ?? null) !== $tournament->grade_id) {
                    return $this->deny('You are not in the correct grade for this tournament.');
                }
            }
            return true;
        }

        // Level-restricted: enforce level match
        if ($access === 'level') {
            if ($tournament->level_id) {
                if (! $user->quizeeProfile || ($user->quizeeProfile->level_id ?? null) !== $tournament->level_id) {
                    return $this->deny('You are not in the correct level for this tournament.');
                }
            }
            return true;
        }

        // Fallback conservative deny
        return $this->deny('You are not eligible to join this tournament.');
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
