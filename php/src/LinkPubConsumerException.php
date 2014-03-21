<?php

/**
 * Class LinkPubConsumerException
 * @LinkPubConsumer\Component
 */
class LinkPubConsumerException extends \Exception
{
    protected $data;
    protected $lastVersionDate;

    public function __construct($code = 0, $message = '', $data = null, $lastVersionDate = null)
    {
        parent::__construct($message, $code);

        $this->data = $data;
        $this->lastVersionDate = $lastVersionDate;
    }

    public function getData()
    {
        return $this->data;
    }

    public function getLastVersionDate()
    {
        return $this->lastVersionDate;
    }
}
