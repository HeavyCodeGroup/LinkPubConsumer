<?php

abstract class AbstractLinkPubConsumer
{
    const FETCH_METHOD_FILE_GET_CONTENTS = 'file_get_contents';
    const FETCH_METHOD_CURL = 'curl';
    const FETCH_METHOD_SOCKET = 'socket';

    const RETRIEVAL_CONCLUSION_SUCCESS = 0;
    const RETRIEVAL_CONCLUSION_FAIL = 1;

    /**
     * @var string
     */
    private $consumerGUID = '91734bd4-dd94-498d-808a-d05235c853f9';

    /**
     * @var string|null
     */
    private $siteGUID = null;

    /**
     * @var array
     */
    protected $linkData = array();

    /**
     * @var int
     */
    protected $cacheLifetime = 3600;

    /**
     * @var int
     */
    protected $cacheRetryTimeout = 600;

    /**
     * @var string
     */
    protected $cacheLastVersionDate = null;

    /**
     * @var \DateTime
     */
    protected $cacheLastRetrieveDate = null;

    /**
     * @var int
     */
    protected $cacheLastRetrieveConclusion = null;

    /**
     * @var boolean
     */
    protected $cacheChanged = false;

    /**
     * @var string
     */
    protected $fetchUserAgent = 'LinkPub client';

    /**
     * Fetch connect timeout
     * @var int
     */
    protected $fetchConnectTimeout = 6;

    /**
     * Fetch method
     * @var string|null
     */
    protected $fetchMethod = null;

    public function __construct()
    {
        $this->loadCache();
    }

    public function __destruct()
    {
        if ($this->cacheChanged) {
            $this->saveCache();
        }
    }

    /**
     * @return string
     */
    public function getConsumerGUID()
    {
        return $this->consumerGUID;
    }

    /**
     * @param null|string $siteGUID
     * @return AbstractLinkPubConsumer
     */
    public function setSiteGUID($siteGUID)
    {
        $this->siteGUID = $siteGUID;

        return $this;
    }

    /**
     * @return null|string
     */
    public function getSiteGUID()
    {
        return $this->siteGUID;
    }

    /**
     * @return string
     */
    protected function getPageUrl()
    {
        return $_SERVER['REQUEST_URI'];
    }

    /**
     * @return array
     */
    abstract protected function getAvailableDispenserHosts();

    /**
     * @return string
     */
    protected function getDispenserHost()
    {
        static $dispenserHosts = null;
        if ($dispenserHosts === null) {
            $dispenserHosts = $this->getAvailableDispenserHosts();
        }

        $dispenserHost = array_shift($dispenserHosts);
        array_push($dispenserHosts, $dispenserHost);

        return $dispenserHost;
    }

    /**
     * @return string|null
     */
    abstract protected function getInstanceGUID();

    /**
     * @param string $host
     * @param string $get
     * @throws LinkPubConsumerException
     */
    protected function fetchLinkDataUsingFileGetContents($host, $get = '/')
    {
        $url = 'http://' . $host . $get;
        $httpContextOptions = array(
            'timeout' => $this->fetchConnectTimeout,
            'header' => "User-Agent: {$this->fetchUserAgent}\r\n",
        );
        if ($this->cacheLastVersionDate) {
            $httpContextOptions['header'] .= "If-Modified-Since: {$this->cacheLastVersionDate}\r\n";
        }
        $context = stream_context_create(array('http' => $httpContextOptions));

        $statusCode = null;
        $lastVersionDate = null;
        $data = @file_get_contents($url, false, $context);

        array_walk($http_response_header, function (&$header) use (&$statusCode, &$lastVersionDate) {
            if (($statusCode === null) && preg_match('/^HTTP\/\d\.\d (\d{3}).*$/', $header, $matches)) {
                $statusCode = $matches[1];
            } elseif (($lastVersionDate === null) && preg_match('/^Last\-Modified: (.*)$/', $header, $matches)) {
                $lastVersionDate = $matches[1];
                if ($lastVersionDate[strlen($lastVersionDate) - 1] == "\r") {
                    $lastVersionDate = substr($lastVersionDate, 0, strlen($lastVersionDate) - 1);
                }
            }
        });

        // Failure if we have no data received
        if ($data === false) {
            throw new LinkPubConsumerException(
                self::RETRIEVAL_CONCLUSION_FAIL,
                'Failure during execution of \'file_get_contents\''
            );
        }

        // Failure if no status code received
        if ($statusCode === null) {
            throw new LinkPubConsumerException(
                self::RETRIEVAL_CONCLUSION_FAIL,
                'There is no status code received'
            );
        }

        // Failure if we received unexpected status code
        if (($statusCode != '200') && ($statusCode != '304')) {
            throw new LinkPubConsumerException(
                self::RETRIEVAL_CONCLUSION_FAIL,
                sprintf('Unexpected HTTP status code: %s', $statusCode)
            );
        }

        // Do not update data if server reported 'Not modified'
        if ($statusCode == '304') {
            throw new LinkPubConsumerException(
                self::RETRIEVAL_CONCLUSION_SUCCESS,
                'No changes on server detected'
            );
        }

        throw new LinkPubConsumerException(
            self::RETRIEVAL_CONCLUSION_SUCCESS,
            'Got new data', $data, $lastVersionDate
        );
    }

    /**
     * @param string $host
     * @param string $get
     * @throws LinkPubConsumerException
     */
    protected function fetchLinkDataUsingCurl($host, $get = '/')
    {
        $url = 'http://' . $host . $get;
        if ($hCurl = @curl_init()) {
            @curl_setopt($hCurl, CURLOPT_URL, $url);
            @curl_setopt($hCurl, CURLOPT_HEADER, false);
            @curl_setopt($hCurl, CURLOPT_RETURNTRANSFER, true);
            @curl_setopt($hCurl, CURLOPT_CONNECTTIMEOUT, $this->fetchConnectTimeout);
            if ($this->cacheLastVersionDate) {
                @curl_setopt($hCurl, CURLOPT_HTTPHEADER, array(
                    'If-Modified-Since: ' . $this->cacheLastVersionDate,
                ));
            }
            @curl_setopt($hCurl, CURLOPT_USERAGENT, $this->fetchUserAgent);
            $statusCode = null;
            $lastVersionDate = null;
            @curl_setopt($hCurl, CURLOPT_HEADERFUNCTION, function ($hCurl, $header) use (&$statusCode, &$lastVersionDate) {
                if (($statusCode === null) && preg_match('/^HTTP\/\d\.\d (\d{3}).*$/', $header, $matches)) {
                    $statusCode = $matches[1];
                } elseif (($lastVersionDate === null) && preg_match('/^Last\-Modified: (.*)$/', $header, $matches)) {
                    $lastVersionDate = $matches[1];
                    if ($lastVersionDate[strlen($lastVersionDate) - 1] == "\r") {
                        $lastVersionDate = substr($lastVersionDate, 0, strlen($lastVersionDate) - 1);
                    }
                }
                return strlen($header);
            });

            $data = @curl_exec($hCurl);
            @curl_close($hCurl);

            // Failure if we have no data received
            if ($data === false) {
                throw new LinkPubConsumerException(
                    self::RETRIEVAL_CONCLUSION_FAIL,
                    'Failure during execution of \'curl_exec\''
                );
            }

            // Failure if no status code received
            if ($statusCode === null) {
                throw new LinkPubConsumerException(
                    self::RETRIEVAL_CONCLUSION_FAIL,
                    'There is no status code received'
                );
            }

            // Failure if we received unexpected status code
            if (($statusCode != '200') && ($statusCode != '304')) {
                throw new LinkPubConsumerException(
                    self::RETRIEVAL_CONCLUSION_FAIL,
                    sprintf('Unexpected HTTP status code: %s', $statusCode)
                );
            }

            // Do not update data if server reported 'Not modified'
            if ($statusCode == '304') {
                throw new LinkPubConsumerException(
                    self::RETRIEVAL_CONCLUSION_SUCCESS,
                    'No changes on server detected'
                );
            }

            throw new LinkPubConsumerException(
                self::RETRIEVAL_CONCLUSION_SUCCESS,
                'Got new data', $data, $lastVersionDate
            );
        }

        throw new LinkPubConsumerException(
            self::RETRIEVAL_CONCLUSION_FAIL,
            'Failure during execution of \'curl_init\''
        );
    }

    /**
     * @param string $host
     * @param string $get
     * @throws LinkPubConsumerException
     */
    protected function fetchLinkDataUsingSocket($host, $get = '/')
    {
        $socket = @fsockopen($host, 80, $errorCode, $errorString, $this->fetchConnectTimeout);
        $outputBuffer = '';
        if ($socket) {
            @fputs($socket, "GET {$get} HTTP/1.0\r\nHost: {$host}\r\n");
            @fputs($socket, "User-Agent: {$this->fetchUserAgent}\r\n");
            if ($this->cacheLastVersionDate) {
                @fputs($socket, "If-Modified-Since: {$this->cacheLastVersionDate}\r\n");
            }
            @fputs($socket, "\r\n");
            while (!@feof($socket)) {
                $outputBuffer .= @fgets($socket, 128);
            }
            @fclose($socket);

            // Failure if we have no data received
            if ($outputBuffer == false) {
                throw new LinkPubConsumerException(
                    self::RETRIEVAL_CONCLUSION_FAIL,
                    'Failure during execution of \'curl_exec\''
                );
            }

            $data = explode("\r\n\r\n", $outputBuffer);
            $headers = explode("\r\n", $data[0]);
            $statusCode = null;
            $lastVersionDate = null;
            array_walk($headers, function (&$header) use (&$statusCode, &$lastVersionDate) {
                if (($statusCode === null) && preg_match('/^HTTP\/\d\.\d (\d{3}).*$/', $header, $matches)) {
                    $statusCode = $matches[1];
                } elseif (($lastVersionDate === null) && preg_match('/^Last\-Modified: (.*)$/', $header, $matches)) {
                    $lastVersionDate = $matches[1];
                    if ($lastVersionDate[strlen($lastVersionDate) - 1] == "\r") {
                        $lastVersionDate = substr($lastVersionDate, 0, strlen($lastVersionDate) - 1);
                    }
                }
            });
            $data = implode("\r\n\r\n", array_slice($data, 1));

            // Failure if no status code received
            if ($statusCode === null) {
                throw new LinkPubConsumerException(
                    self::RETRIEVAL_CONCLUSION_FAIL,
                    'There is no status code received'
                );
            }

            // Failure if we received unexpected status code
            if (($statusCode != '200') && ($statusCode != '304')) {
                throw new LinkPubConsumerException(
                    self::RETRIEVAL_CONCLUSION_FAIL,
                    sprintf('Unexpected HTTP status code: %s', $statusCode)
                );
            }

            // Do not update data if server reported 'Not modified'
            if ($statusCode == '304') {
                throw new LinkPubConsumerException(
                    self::RETRIEVAL_CONCLUSION_SUCCESS,
                    'No changes on server detected'
                );
            }

            throw new LinkPubConsumerException(
                self::RETRIEVAL_CONCLUSION_SUCCESS,
                'Got new data', $data, $lastVersionDate
            );
        }

        throw new LinkPubConsumerException(
            self::RETRIEVAL_CONCLUSION_FAIL,
            'Failure during execution of \'fsockopen\''
        );
    }

    protected function detectFetchMethod()
    {
        if (function_exists('file_get_contents') && (ini_get('allow_url_fopen') == 1)) {
            $this->fetchMethod = self::FETCH_METHOD_FILE_GET_CONTENTS;
        } elseif (function_exists('curl_init')) {
            $this->fetchMethod = self::FETCH_METHOD_CURL;
        } elseif (function_exists('fsockopen')) {
            $this->fetchMethod = self::FETCH_METHOD_SOCKET;
        }
    }

    /**
     * @param string $host
     * @param string $get
     * @return bool
     */
    protected function fetch($host, $get = '/')
    {
        if (!$this->fetchMethod) {
            $this->detectFetchMethod();
        }

        try {
            switch ($this->fetchMethod) {
                case self::FETCH_METHOD_FILE_GET_CONTENTS:
                    $this->fetchLinkDataUsingFileGetContents($host, $get);
                    break;
                case self::FETCH_METHOD_CURL:
                    $this->fetchLinkDataUsingCurl($host, $get);
                    break;
                case self::FETCH_METHOD_SOCKET:
                    $this->fetchLinkDataUsingSocket($host, $get);
                    break;
            }
        } catch (LinkPubConsumerException $exception) {
            $this->cacheLastRetrieveDate = new \DateTime('now');
            if ($exception->getCode() == self::RETRIEVAL_CONCLUSION_SUCCESS) {
                $this->cacheLastRetrieveConclusion = self::RETRIEVAL_CONCLUSION_SUCCESS;
                if ($exception->getData() !== null) {
                    $linkData = json_decode($exception->getData(), true);
                    if (is_array($linkData)) {
                        $this->cacheLastVersionDate = $exception->getLastVersionDate();
                        $this->linkData = $linkData;
                        $this->cacheChanged = true;
                    } else {
                        $this->cacheLastRetrieveConclusion = self::RETRIEVAL_CONCLUSION_FAIL;
                    }
                }

                return true;
            } else {
                $this->cacheLastRetrieveConclusion = self::RETRIEVAL_CONCLUSION_FAIL;

                return false;
            }
        }

        return false;
    }

    /**
     * @return bool
     */
    protected function fetchLinkData()
    {
        $get = '/?';
        if ($this->getInstanceGUID()) {
            $get .= 'guid=' . $this->getInstanceGUID();
        } elseif ($this->getSiteGUID()) {
            $get .= 'site_guid=' . $this->getSiteGUID() . '&consumer_guid=' . $this->getConsumerGUID();
        }
        $linkData = $this->fetch($this->getDispenserHost(), $get);
        return $linkData;
    }

    /**
     * @return string
     */
    protected function getCacheFilename()
    {
        return __DIR__ . '/db.dat';
    }

    protected function saveCache()
    {
        @file_put_contents(
            $this->getCacheFilename(),
            serialize(array(
                'data' => $this->linkData,
                'version' => $this->cacheLastVersionDate,
                'retrieve_date' => $this->cacheLastRetrieveDate,
                'retrieve_status' => $this->cacheLastRetrieveConclusion,
            ))
        );
    }

    protected function loadCache()
    {
        $data = @unserialize(@file_get_contents($this->getCacheFilename()));

        if (is_array($data)) {
            $this->linkData = $data['data'];
            $this->cacheLastVersionDate = $data['version'];
            $this->cacheLastRetrieveDate = $data['retrieve_date'];
            $this->cacheLastRetrieveConclusion = $data['retrieve_status'];
        }
    }

    protected function ensureCacheIsFresh()
    {
        if (($this->cacheLastVersionDate === null) || !($this->cacheLastRetrieveDate instanceof \DateTime)) {
            $this->fetchLinkData();
        } else {
            // If previous fetch was failed
            if ($this->cacheLastRetrieveConclusion == self::RETRIEVAL_CONCLUSION_FAIL) {
                $d = new \DateTime();
                $d->modify('-' . $this->cacheRetryTimeout . ' seconds');
                // If retry timeout is expired
                if ($d > $this->cacheLastRetrieveDate) {
                    $this->fetchLinkData();
                }
            } else {
                $d = new \DateTime();
                $d->modify('-' . $this->cacheLifetime . ' seconds');
                // If cache lifetime is expired
                if ($d > $this->cacheLastRetrieveDate) {
                    $this->fetchLinkData();
                }
            }
        }
    }

    protected function selectLinks($limit = null)
    {
        $this->ensureCacheIsFresh();

        static $links = null;
        if ($links === null) {
            $url = $this->getPageUrl();
            if (isset($this->linkData[$url])) {
                $links = $this->linkData[$url];
            } else {
                $links = null;
            }
        }

        if ($limit === null) {
            $result = $links;
            $links = array();
        } else {
            $result = array_slice($links, 0, $limit);
            if (count($links) < $limit) {
                $links = array();
            } else {
                $links = array_slice($links, $limit);
            }
        }

        return $result;
    }

    public function getLinks($limit = null) {
        return implode('', array_map(function ($link) {
            return '<a href="' . $link['url'] . '">' . htmlspecialchars($link['title']) . '</a>';
        }, $this->selectLinks($limit)));
    }
}
