<?php
namespace ValuSo\Controller;

use ValuSo\Command\Command;
use ValuSo\Exception\OperationNotFoundException;
use ValuSo\Exception\ServiceNotFoundException;
use ValuSo\Exception\NotFoundException;
use ValuSo\Exception\PermissionDeniedException;
use ValuSo\Exception\ServiceException;
use ValuSo\Json\Encoder as JsonEncoder;
use ValuSo\Mvc\ResponseSender\StreamResponseSender;
use Zend\Mvc\Controller\AbstractActionController;
use Zend\Json\Json;
use Zend\Json\Decoder as JsonDecoder;
use Zend\Http\Header\HeaderInterface;
use Zend\Http\PhpEnvironment\Response;
use Zend\Console\Request as ConsoleRequest;
use Zend\Console\Response as ConsoleResponse;
use Exception;
use Zend\Json\Exception\RecursionException;
use Zend\Http\Response\Stream;
use Zend\Mvc\ResponseSender\SendResponseEvent;

/**
 * Service controller
 *
 * Service controller provides an RPC style HTTP API.
 * 
 * Common HTTP route to service broker is in form:
 * <code>
 * path/to/api/&lt;service&gt;/&lt;operation&gt;?&lt;parameters&gt;
 * </code>
 * 
 * When using this route syntax, each parameter in query string is
 * passed to the operation.
 *
 * RPC API supports also an alternative syntax, where
 * parameters for an operation are passed in special <b>q</b> param
 * using query string. The value for <b>q</b> param must be <b>JSON</b> encoded.
 *
 * RPC API supports both GET and POST methods. GET should be used only
 * for operations that don't change the application state, usually these
 * are find/get operations.
 * 
 * RPC API responses are mostly in JSON format. Key <b>d</b> contains the
 * actual response data and key <b>e</b> contains error information.
 * 
 * HTTP response's status code is 200 for succesful operation.
 * When service or operation is missing or cannot be found a 404 status
 * code is returned. Further, some exceptions thrown by the actual service
 * are translated into special status codes, e.g. PermissionDeniedException
 * translates into status code 403.
 * 
 * You may control how verbose the error messages should be using
 * <strong>X-VALU-ERRORS</strong> header. It accepts values <strong>default</strong>
 * and <strong>verbose</strong> When using value <strong>verbose</strong>, error message 
 * is returned in raw format, that is it may contain variables, such as %MESSAGE%.
 * Also variables are returned in an array.
 * 
 * <strong>Example of using RPC API with HTTP POST:</strong>
 * <code>
 * POST /filesystem.file/upload HTTP/1.1
 * Host: api.valu.fi
 *
 * q={"url":“http://files.com/pic1.pdf”,“target”:“/page1/gallery”,“specs”:{"description":“New York 2012”}}
 * </code>
 * 
 * <strong>Example of a valid response:</strong>
 * <code>
 * {d:true}
 * </code>
 * 
 * <strong>Example of a response with error information:</strong>
 * <code>
 * {d:null, e:{m:"File not found", c:1001}}
 * </code>
 * 
 * <strong>Example of request and response with verbose error:</strong>
 * <code>
 * POST /filesystem.file/update HTTP/1.1
 * Host: api.valu.fi
 *
 * q={"query":“/pic1.pdf”,“specs”:{"description":“New York 2012”}}
 * </code>
 * 
 * Response data:
 * <code>
 * {"d":null,"e":{"m":"Permission denied for user %USER% to update %FILE%", "a":{"USER":"guest", "FILE":"/pic1.pdf"}, "c":1200}}
 * </code>
 * 
 * @author Juha Suni
 * @package Service
 */
class ServiceController extends AbstractActionController
{
    /**
     * HTTP status code for successful operation
     * @var int
     */
    const STATUS_SUCCESS = 200;
    
    /**
     * HTTP status code for malformatted request
     * @var int
     */
    const STATUS_MALFORMED_REQUEST = 400;
    
    /**
     * HTTP status code for "not found" exceptions
     * @var int
     */
    const STATUS_NOT_FOUND = 404;
    
    /**
     * HTTP status code for unknown exceptions
     * @var int
     */
    const STATUS_UNKNOWN_EXCEPTION = 500;
    
    /**
     * HTTP status code for "permission denied" exception
     * @var int
     */
    const STATUS_PERMISSION_DENIED = 403;
    
    /**
     * HTTP header for operation name
     * @var string
     */
    const HEADER_OPERATION = 'X-VALU-OPERATION';
    
    /**
     * HTTP header to describe how verbose the
     * errors should be
     * @var string
     */
    const HEADER_ERRORS = 'X-VALU-ERRORS';
    
    /**
     * Header value for verbose error messages
     * @var string
     */
    const HEADER_ERRORS_VERBOSE = 'verbose';
    
    /**
     * Header value for verbose normal error messages
     * @var string
     */
    const HEADER_ERRORS_DEFAULT = 'default';
    
    /**
     * Header that indicates that the response's content type should be
     * forced to text/html
     * @var string
     */
    const HEADER_FORCE_CONTENT_HTML = 'X-VALU-CONTENT-TYPE-HTML';
    
    /**
     * Error message for cyclic references
     * @var string
     */
    const CYCLIC_DATA_ERROR = 'Response data contains cyclic references that cannot be presented in JSON format';
	
	/**
	 * PREG pattern to validate service name against
	 * 
	 * @var string
	 */
	protected $servicePattern = '/^[a-z][a-z0-9]+([\.\-][a-z0-9]+)*$/i';
	
	/**
	 * PREG pattern to validate operation name against
	 * 
	 * @var string
	 */
	protected $operationPattern = '/^[a-z][a-z0-9]+([\-][a-z0-9]+)*$/i';
	
	/**
	 * Performs service operation matched by HTTP router
	 * 
	 * @throws \Exception
	 * @return Response
	 */
	public function httpAction()
	{
	    $status		= self::STATUS_SUCCESS;
	    $responses	= array();
	    $exception	= null;
	    $data       = null;
        $debug      = $this->getRouteParam('debug', false); 
        
        try {
            $service	= $this->fetchService();
            $operation	= $service ? $this->fetchOperation() : false;
            
            if(!$service){
                throw new ServiceNotFoundException("Route doesn't contain service information");
            } elseif (!$operation) {
                throw new OperationNotFoundException("Route doesn't contain operation information");
            }
            
            try {
                $params = $this->fetchParams();
            } catch(\Exception $e) {
                $status = self::STATUS_MALFORMED_REQUEST;
                $exception = $e;
            }
            
            if ($status === self::STATUS_SUCCESS) {
                if (isset($params[self::HEADER_FORCE_CONTENT_HTML])) {
                    
                    if ($params[self::HEADER_FORCE_CONTENT_HTML]) {
                        $this->getRequest()->getHeaders()->addHeaders([
                                self::HEADER_FORCE_CONTENT_HTML => true
                                ]);
                    }
                    unset($params[self::HEADER_FORCE_CONTENT_HTML]);
                }
                
                $data = $this->exec(
                        $service,
                        $operation,
                        $params,
                        Command::CONTEXT_HTTP);
            }
            
        } catch (PermissionDeniedException $exception) {
            $status = self::STATUS_PERMISSION_DENIED;
        } catch (NotFoundException $exception) {
            $status = self::STATUS_NOT_FOUND;
        } catch (ServiceException $exception) {
            $status = self::STATUS_UNKNOWN_EXCEPTION;
        } catch (\Exception $exception) {
            $status = self::STATUS_UNKNOWN_EXCEPTION;
        }
	    
        // Log error
	    if ($exception) {
	        error_log($exception->getMessage() . ' (' . $exception->getFile().':'.$exception->getLine().')');
	        
	        if ($debug) {
	            throw $exception;
	        }
	    }
    
		return $this->prepareHttpResponse(
			$data, 
	        $status,
			$exception);
	}
	
	/**
	 * Performs service operation routed by console router
	 */
	public function consoleAction()
	{
	    $request   = $this->getRequest();
	    $service   = $this->fetchService();
	    $operation = $this->fetchOperation();
	    $query     = $this->fetchConsoleQuery();
	    
	    if (!$request instanceof ConsoleRequest) {
	        throw new \RuntimeException('You can only use this action from a console!');
	    }
	    
	    // Check flags
	    $verbose = $request->getParam('verbose') || $request->getParam('v');
	    $silent = $request->getParam('silent') || $request->getParam('s');
	    
	    $params = Json::decode(
            $query,
            Json::TYPE_ARRAY
        );
	    
	    try {
	        $data = $this->exec($service, $operation, $params, Command::CONTEXT_CLI);
	        return $this->prepareConsoleResponse($data, null, $verbose, $silent);
	    } catch (\Exception $exception) {
	        error_log($exception->getMessage() . ' (' . $exception->getFile().':'.$exception->getLine().')');
	        
	        return $this->prepareConsoleResponse(null, $exception, $verbose, $silent);
	    }
	}
	
	/**
	 * Execute operation
	 * 
	 * @param string $service
	 * @param string $operation
	 * @param array|null $params
	 * @param string $context
	 */
	protected function exec($service, $operation, $params, $context)
	{
	    // Perform operation and fetch first result
	    return $this->serviceBroker()
    	    ->service($service)
    	    ->context($context)
    	    ->until(array($this, '_break'))
    	    ->exec($operation, $params)
    	    ->first();
	}

	/**
	 * Prepares HTTP response
	 * 
	 * @param mixed $data
	 * @param int $status
	 * @param \Exception|null $exception
	 * @return Response
	 */
	protected function prepareHttpResponse($data, $status, $exception = null)
	{
	    
	    $error = $this->getRequest()->getHeader(
	            self::HEADER_ERRORS, 
	            self::HEADER_ERRORS_DEFAULT);
	    
	    $forceHtmlContentType = $this->getRequest()->getHeader(
	            self::HEADER_FORCE_CONTENT_HTML,
	            false);
	    
	    if ($error instanceof HeaderInterface) {
	        $error = $error->getFieldValue();
	    }
	    
	    $response = $this->getResponse();
	    
	    $headers = array(
	    	'Cache-Control' => 'no-cache, must-revalidate',
	        'Pragma' 		=> 'no-cache',
	    	'Expires' 		=> '2000-01-01 00:00:00',
            'Content-Type'  => 'application/json',
	    );
	    
	    if ($forceHtmlContentType) {
	        $headers['Content-Type'] = 'text/html';
	    }

	    $response->getHeaders()->addHeaders($headers);
	    
	    // update response status code
	    $response->setStatusCode(
        	$status
        );
	    
	    // update reason phrase
	    if($exception){
	        
	        if ($exception instanceof ServiceException) {
	            $message = $exception->getMessage();
	        } else {
	            $message = 'Unknown exception';
	        }
	        
	    	$response->setReasonPhrase(
    	        $message
	    	);
	    }
	    
	    if ($data instanceof \Zend\Http\Response) {
	        
    	    // Attach custom stream response sender listener
	        // to send the stream to output buffer in chunks
	        if ($data instanceof Stream) {
	            $sendReponseListener = $this->getServiceLocator()->get('SendResponseListener');
	            $sendReponseListener->getEventManager()->attach(
	                    SendResponseEvent::EVENT_SEND_RESPONSE,
	                    new StreamResponseSender(),
	                    10000);
	        }
	        
	        return $data;
	    } else {
	        
	        $responseModel = array(
                'd' => $data
	        );
	        
	        // Append exception data
	        if ($exception) {
	            
	            if ($error == self::HEADER_ERRORS_VERBOSE && $exception instanceof ServiceException) {
	                $responseModel['e'] = array(
                        'm' => $exception->getRawMessage(),
                        'c' => $exception->getCode(),
                        'a' => $exception->getVars()
	                );
	                
	            } else {
	                $responseModel['e'] = array(
                        'm' => $response->getReasonPhrase(),
                        'c' => $exception->getCode(),
	                );
	            }
	        }
	        
	        try {
	            $response->setContent(JsonEncoder::encode($responseModel, true));
	        } catch (Exception $e) {
	            
	            if ($e instanceof RecursionException) {
	                $message = self::CYCLIC_DATA_ERROR;
	            } else {
	                $message = $e->getMessage();
	            }
	            
	            $response->setReasonPhrase(
                    $message
	            );
	            
	            $response->setStatusCode(
                    self::STATUS_UNKNOWN_EXCEPTION
	            );
	            
	            $response->setContent(JsonEncoder::encode([
                    'd' => null,
                    'e' => [
	                   'm' => $message,
	                   'c' => $e->getCode()
	               ]
	            ], true));
	        }
	        
	        return $response;
	    }
	}
	
	/**
	 * Prepares console response
	 * 
	 * @param mixed $data
	 * @param \Exception|null $exception
	 * @param boolean $verbose
	 * @param boolean $silent
	 * @return ConsoleResponse
	 */
	protected function prepareConsoleResponse($data, \Exception $exception = null, $verbose = false, $silent = false)
	{
	    $response = new ConsoleResponse();
	    
	    try {
	        if (is_array($data) || is_object($data)) {
	            $json = JsonEncoder::encode($data, true);
	            $data = Json::prettyPrint($json) . "\n";
	        } else if (is_scalar($data)) {
	            $data = (string)$data."\n";
	        }
	    } catch (Exception $e) {
	        $exception = $e;
	        $data = null;
        }
	    
	    if ($exception) {
	        
	        if ($verbose) {
	            $msg = $exception->getMessage() . ' (' . $exception->getFile().':'.$exception->getLine() . ')';
	        } else {
	            $msg = $exception->getMessage();
	        }
	        
	        if (!$silent) {
	            $response->setContent("Error: " . $msg . "\n");
	        }
	        
	        $response->setErrorLevel($exception->getCode());
	    }
	    
	    if (!$silent) {
	        $response->setContent($data);
	    }
	    
	    return $response;
	}
	
	/**
	 * Parse service name from request
	 * 
	 * @return string|boolean
	 */
	protected function fetchService()
	{
        $service = $this->getRouteParam('service');
        $service = $this->parseCanonicalName($service, true);
        
        if (preg_match($this->servicePattern, $service)) {
            return $service;
        } else {
            return false;
        }
    }
	
	/**
	 * Parse operation name from request
	 * 
	 * @return string|boolean
	 */
	protected function fetchOperation()
	{
        $operation = $this->getRouteParam('operation');
        
        if ($operation !== null) {
            $operation = $this->parseCanonicalName($operation);
            
            if (preg_match($this->operationPattern, $operation)) {
                return $operation;
            } else {
                return false;
            }
        } else {
            $operation = $this->getRequest()->getHeader(self::HEADER_OPERATION);
            
            if (!$operation) {
                $operation = 'http-'.$this->getRequest()->getMethod();
            } else {
                $operation = $operation->getFieldValue();
            }
            
            return $this->parseCanonicalName($operation);
        }
	}
	
	/**
	 * Parse parameters from request
	 * 
	 * @return array
	 */
	protected function fetchParams()
	{
	    $contentType = $this->getRequest()->getHeaders()->get('Content-Type');
	    if ($contentType) {
            $contentTypeParts = explode(';', $contentType->getFieldValue());
            $contentType = strtolower(trim($contentTypeParts[0]));
        }

	    if ($contentType === 'application/json') {
	        $json = $this->getRequest()->getContent();
	        $params = JsonDecoder::decode($json, JSON::TYPE_ARRAY);
	    } else {
	        // Parse parameters
	        if ($this->getRequest()->isPost()) {
	            $params = $this->getRequest()
	            ->getPost()
	            ->toArray();
	             
	            $params = array_merge(
	                    $params,
	                    $this->getRequest()
	                    ->getFiles()
	                    ->toArray()
	            );
	        } else {
	            $params = $this->getRequest()
	            ->getQuery()
	            ->toArray();
	        }
	    }
	    
	    // Use special 'q' parameters instead, if specified
	    if (isset($params['q'])) {
	        $params = Json::decode($params['q'], Json::TYPE_ARRAY);
	    
	        // Files are an exception, as they cannot be passed as part of
	        // the special q parameter
	        foreach ($this->getRequest()->getFiles() as $name => $value) {
	            if (!isset($params[$name])) {
	                $params[$name] = $value;
	            }
	        }
	    } else {
	        // Use route param 'path' and parse its parameters
	        $paramsInRoute = $this->getRouteParam('path');
	        
	        if ($paramsInRoute && $paramsInRoute !== '/') {
	            $paramsInRoute = explode('/', $paramsInRoute);
	            array_shift($paramsInRoute);
	             
	            $params = array_merge(
	                    $params,
	                    $paramsInRoute);
	        }
	    }
	    
	    return $params;
	}
	
	/**
	 * Retrieve console query
	 * 
	 * @return string
	 */
	protected function fetchConsoleQuery()
	{
	    return $this->getRouteParam('query');
	}
	
	/**
	 * @todo Does ZF provide a more convenient approach?
	 */
	protected function getRouteParam($param, $default = null)
	{
	    return $this->getEvent()->getRouteMatch()->getParam($param, $default);
	}
	
	/**
	 * Parse canonical service/operation name
	 * 
	 * @param string $name
	 * @param boolean $toUcc
	 * @return string
	 */
	protected function parseCanonicalName($name, $toUcc = false)
	{
        $array = explode('-', $name);
        
        if (sizeof($array) > 1) {
            $array = array_map('strtolower', $array);
        }
        
        $array = array_map('ucfirst', $array);
        
        $canonical = implode('', $array);
        
        return ! $toUcc ? lcfirst($canonical) : $canonical;
	}
	
	public function _break()
	{
	    return true;
	}
}
