<?php

namespace HeavyCodeGroup\LinkPub\Consumer\Declaration\Annotation;

use Doctrine\Common\Annotations\Annotation;

/**
 * Class ConsumerGUID
 * @package HeavyCodeGroup\LinkPub\Consumer\Declaration\Annotation
 *
 * @Annotation
 * @Target("METHOD")
 */
class ConsumerGUID extends Annotation implements ConsumerIdentificationInterface
{
}
