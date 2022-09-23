<?php

namespace Craymend\Dexcom;

/**
 * Return data or errors from API calls
 */
final class Response
{
    /**
     * @var bool
     */
    private $successful;

    /**
     * @var array
     */
    private $data = [];

    /**
     * @var array
     */
    private $errors = [];

    /**
     * Contructor
     */
    public function __construct($status = true, array $response = [], array $errors = [])
    {
        $this->status = $status;

        if ($status) {
            $this->data = $response;
        } else {
            $this->errors = $errors;
        }
    }

    /**
     * Returns true if the response was successful
     *
     * @return bool
     */
    public function getStatus()
    {
        return $this->status;
    }

    /**
     * This method returns an empty array if the client failed.
     *
     * @return array
     */
    public function getData()
    {
        return $this->data;
    }

    /**
     * This method returns an empty array if response was successful.
     *
     * @return array
     */
    public function getErrors()
    {
        return $this->errors;
    }
}
