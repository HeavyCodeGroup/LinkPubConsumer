<?php

namespace HeavyCodeGroup\LinkPub\Consumer\Declaration\Annotation;

use Doctrine\Common\Annotations\Annotation;

/**
 * Class InstanceGUID
 * @package HeavyCodeGroup\LinkPub\Consumer\Declaration\Annotation
 *
 * @Annotation
 * @Target("METHOD")
 */
class InstanceGUID extends Annotation implements MethodOverrideInterface
{
    public function getOverrideContext()
    {
        return 'instance_guid';
    }
}
