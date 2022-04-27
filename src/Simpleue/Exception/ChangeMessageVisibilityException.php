<?php

namespace Simpleue\Exception;

class ChangeMessageVisibilityException extends \Exception
{
    private $visibilityTimeout;
    
    public function __construct($visibilityTimeout = 30, $message = '')
    {
        $this->visibilityTimeout = $visibilityTimeout;
        parent::__construct($message);
    }
    
    public function getVisibilityTimeout()
    {
        return $this->visibilityTimeout;
    }
}
