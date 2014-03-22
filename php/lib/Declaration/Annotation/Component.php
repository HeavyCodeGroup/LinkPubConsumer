<?php

namespace HeavyCodeGroup\LinkPub\Consumer\Declaration\Annotation;

use Doctrine\Common\Annotations\Annotation;

/**
 * Class Component
 * @package HeavyCodeGroup\LinkPub\Consumer\Declaration\Annotation
 *
 * @Annotation
 * @Target("CLASS")
 */
class Component extends Annotation implements ConsumerComponentInterface
{
}
