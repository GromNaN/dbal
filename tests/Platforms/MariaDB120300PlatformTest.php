<?php

declare(strict_types=1);

namespace Doctrine\DBAL\Tests\Platforms;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MariaDB120300Platform;

class MariaDB120300PlatformTest extends MariaDB110700PlatformTest
{
    public function createPlatform(): AbstractPlatform
    {
        return new MariaDB120300Platform();
    }

    public function testMariaDb123KeywordList(): void
    {
        $keywordList = $this->platform->getReservedKeywordsList();

        self::assertTrue($keywordList->isKeyword('to_date'));
        self::assertTrue($keywordList->isKeyword('vector'));
        self::assertTrue($keywordList->isKeyword('distinctrow'));
    }
}
