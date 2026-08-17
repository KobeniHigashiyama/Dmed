<?php

namespace App\Domain\Images\Policies;

use App\Domain\Images\Models\Image;
use App\Domain\Users\Models\User;
use Illuminate\Auth\Access\Response;

class ImagePolicy
{
    /**
     * Someone else's image is a 404, not a 403.
     *
     * A 403 confirms the identifier exists and turns the API into an oracle
     * for probing other people's libraries.
     */
    public function view(User $user, Image $image): Response
    {
        return $this->owns($user, $image);
    }

    public function delete(User $user, Image $image): Response
    {
        return $this->owns($user, $image);
    }

    private function owns(User $user, Image $image): Response
    {
        return $image->user_id === $user->id
            ? Response::allow()
            : Response::denyAsNotFound();
    }
}
