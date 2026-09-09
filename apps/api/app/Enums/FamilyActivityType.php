<?php

namespace App\Enums;

enum FamilyActivityType: string
{
    case PhotosAddedToAlbum = 'photos_added_to_album';
    case StoryAdded = 'story_added';
    case PersonIdentityConfirmed = 'person_identity_confirmed';
    case AlbumCreated = 'album_created';
    case EventCreated = 'event_created';
}
