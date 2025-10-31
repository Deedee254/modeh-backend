<?php

namespace App\Repositories;

use App\Models\Tournament;

class TournamentRepository
{
    /**
     * Find a tournament by id.
     */
    public function find(int $id)
    {
        return Tournament::find($id);
    }
}
