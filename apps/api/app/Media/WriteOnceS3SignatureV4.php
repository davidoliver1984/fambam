<?php

namespace App\Media;

use Aws\Signature\S3SignatureV4;

final class WriteOnceS3SignatureV4 extends S3SignatureV4
{
    /** @return array<string, bool> */
    protected function getHeaderBlacklist(): array
    {
        $blacklist = parent::getHeaderBlacklist();
        unset($blacklist['if-none-match']);

        return $blacklist;
    }
}
