<?php

namespace App\Console\Commands;

use App\Services\FamilySpaceCreationCapability;

class RevokeFamilySpaceCreation extends GrantFamilySpaceCreation
{
    protected $signature = 'fambam:revoke-family-space-creation
                            {email : Email address of the account}
                            {--operator= : Stable operator reference recorded in the security audit}';

    protected $description = 'Revoke the narrow platform capability to create new Family Spaces';

    public function handle(FamilySpaceCreationCapability $capability): int
    {
        return $this->updateCapability($capability, false);
    }
}
