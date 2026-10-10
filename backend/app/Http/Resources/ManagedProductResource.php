<?php

namespace App\Http\Resources;

/**
 * Product view for the owning seller and administrators, including moderation status.
 */
class ManagedProductResource extends ProductResource
{
    protected bool $withModeration = true;
}
