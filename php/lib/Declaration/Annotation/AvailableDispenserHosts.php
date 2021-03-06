<?php

namespace HeavyCodeGroup\LinkPub\Consumer\Declaration\Annotation;

use Doctrine\Common\Annotations\Annotation;

/**
 * Class AvailableDispenserHosts
 * @package HeavyCodeGroup\LinkPub\Consumer\Declaration\Annotation
 *
 * @Annotation
 * @Target("METHOD")
 */
class AvailableDispenserHosts extends Annotation implements MethodOverrideInterface
{
    public function getOverrideContext()
    {
        return 'available_dispenser_hosts';
    }
}
