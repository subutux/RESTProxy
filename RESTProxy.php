<?php
/*
COPYRIGHT

Copyright 2012 Stijn Van Campenhout <stijn.vancampenhout@gmail.com>

This file is part of opsviewrestapi-js.

opsviewrestapi-js is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 2 of the License, or
(at your option) any later version.

opsviewrestapi-js is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with opsviewrestapi-js; if not, write to the Free Software
Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA 02110-1301 USA
*/
/**
 * Needed package(s): php5-curl apache2 php5
 **/
/**
 * Rest API Proxy - Allowing cross domain json calls.
 * initial build for use with the opsview REST API in mind.
 */

//look to http://php.net/manual/fr/function.getallheaders.php#99814
if (!function_exists('apache_request_headers'))
{
    function apache_request_headers()
    {
       $headers = [];
       foreach ($_SERVER as $name => $value)
       {
           if (substr($name, 0, 5) == 'HTTP_')
           {
               $headers[str_replace(' ', '-', (str_replace('_', ' ', substr($name, 5))))] = $value;
           }
           else{
               $headers[$name]=$value;
           }
       }

       $_SERVER['PATH_INFO'] = str_replace($_SERVER['SCRIPT_NAME'],'',$_SERVER['REQUEST_URI']);
       $_SERVER['PATH_INFO'] = str_replace('?'.$_SERVER['QUERY_STRING'],'',$_SERVER['PATH_INFO']);
       return $headers;
    }
}

class RESTProxy {
	private $_G;
	private $_RawGET;
	private $headers = Array();
	private $Host;
	private $Port;
	private $Https;
	private $tmp;
	private $input;
	private $HTTPErrorCodes = array(
			    100 => 'Continue',
			    101 => 'Switching Protocols',
			    102 => 'Processing',
			    200 => 'OK',
			    201 => 'Created',
			    202 => 'Accepted',
			    203 => 'Non-Authoritative Information',
			    204 => 'No Content',
			    205 => 'Reset Content',
			    206 => 'Partial Content',
			    207 => 'Multi-Status',
			    300 => 'Multiple Choices',
			    301 => 'Moved Permanently',
			    302 => 'Found',
			    303 => 'See Other',
			    304 => 'Not Modified',
			    305 => 'Use Proxy',
			    306 => 'Switch Proxy',
			    307 => 'Temporary Redirect',
			    400 => 'Bad Request',
			    401 => 'Unauthorized',
			    402 => 'Payment Required',
			    403 => 'Forbidden',
			    404 => 'Not Found',
			    405 => 'Method Not Allowed',
			    406 => 'Not Acceptable',
			    407 => 'Proxy Authentication Required',
			    408 => 'Request Timeout',
			    409 => 'Conflict',
			    410 => 'Gone',
			    411 => 'Length Required',
			    412 => 'Precondition Failed',
			    413 => 'Request Entity Too Large',
			    414 => 'Request-URI Too Long',
			    415 => 'Unsupported Media Type',
			    416 => 'Requested Range Not Satisfiable',
			    417 => 'Expectation Failed',
			    418 => 'I\'m a teapot',
			    420 => 'Enhance Your Calm',
			    422 => 'Unprocessable Entity',
			    423 => 'Locked',
			    424 => 'Failed Dependency',
			    425 => 'Unordered Collection',
			    426 => 'Upgrade Required',
			    428 => 'Precondition Required',
			    429 => 'Too Many Requests',
			    449 => 'Retry With',
			    450 => 'Blocked by Windows Parental Controls',
			    500 => 'Internal Server Error',
			    501 => 'Not Implemented',
			    502 => 'Bad Gateway',
			    503 => 'Service Unavailable',
			    504 => 'Gateway Timeout',
			    505 => 'HTTP Version Not Supported',
			    506 => 'Variant Also Negotiates',
			    507 => 'Insufficient Storage',
			    509 => 'Bandwidth Limit Exceeded',
			    510 => 'Not Extended'
			);
	public function __construct(){
		// Fetch the request headers
		$this->headers = apache_request_headers();
		// Save the special x-RESTProxy-* headers
		$this->Host = $this->headers['X-RESTPROXY-HOST'];
		$this->Port = $this->headers['X-RESTPROXY-PORT'];
		$this->Https = (isset($this->headers['X-RESTPROXY-HTTPS']))? $this->headers['X-RESTPROXY-HTTPS'] : false;
		// Create a temp file in memory
		$this->fp = fopen('php://temp/maxmemory:256000','w');
		if (!$this->fp){
			$this->Error(100);
		}
		// Write the input into the in-memory tempfile
		$this->input = file_get_contents("php://input");
		fwrite($this->fp,$this->input);
		fseek($this->fp,0);

		//Get the REST Path
		if(!empty($_SERVER['PATH_INFO'])){
		     $this->_G = substr($_SERVER['PATH_INFO'], 1);
		     //$this->_G = explode('/', $_mGET);
		 }
		 // Get the raw GET request
		 $tmp = explode('?',$_SERVER['REQUEST_URI']);
		 if (isset($tmp[1])) {
		 	$this->_RawGET = $tmp[1];
		 } else {
		 	$this->_RawGET = "";
		 }
	/*	 echo "<pre>";
		 var_dump($this->headers);
		 var_dump($this->_G . '?' . $this->_RawGET);
		 var_dump($_GET);
		 var_dump($this->input);
		 var_dump($_SERVER['REQUEST_URI']);
		 var_dump($_SERVER['REQUEST_METHOD']);
		 echo "<pre>";
	*/
	}
	public function doRequest(){
		$headers = $this->headers;
		// Delete some original headers
		unset($headers['host']);
		unset($headers['Origin']);
		unset($headers['x-RESTProxy-Host']);
		unset($headers['x-RESTProxy-Port']);
		unset($headers['x-RESTProxy-HTTPS']);
		unset($headers['Referer']);
		// Currently we do not support gz encoding
		unset($headers['Accept-Encoding']);

		// Convert the headers to curl format
		$curlHeaders = array();
		foreach($headers as $h => $c){
			$curlHeaders[] = $h . ': ' . $c;
		}

		//Build the url
		$c_url = ($this->Https)? "https" : "http" . '://';
		$c_url .= $this->Host . ':' . $this->Port . '/' . $this->_G . '?' . $this->_RawGET;
		/*
		var_dump($c_url);
		var_dump($curlHeaders);
		*/
		// Initiate the curl command
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $c_url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		// Use the headers
		curl_setopt($ch, CURLOPT_HTTPHEADER, $curlHeaders);

		if ($_SERVER['REQUEST_METHOD'] != "GET"){
			if ($_SERVER['REQUEST_METHOD'] == "POST"){
				// Use the Curlopt_post instead of customrequest
				// seems to work better
				curl_setopt($ch, CURLOPT_POST,true);
			} else 	if ($_SERVER['REQUEST_METHOD'] == "PUT"){
				// The same for put
				curl_setopt($ch, CURLOPT_PUT,true);
			} else {
				// Other requlests (like DELETE), use a customrequest
				curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $_SERVER['REQUEST_METHOD']);
			}
			// Send the content of the in-memory tempfile
			curl_setopt($ch, CURLOPT_INFILE, $this->fp);
			curl_setopt($ch, CURLOPT_INFILESIZE, strlen($this->input));
		} else {
			// Just simple GET here, GET request is in the url
			curl_setopt($ch, CURLOPT_HTTPGET, true);
		}
		$output = curl_exec($ch);
		// Get the request info for headers
		$info = curl_getinfo($ch);
		// Set our headers to the headers we received from the REST API
		header('Content-Type: ' . $info['content_type']);
		(!$info['http_code'])? $info['http_code'] = "502": $info['http_code'] = $info['http_code'];
		header('HTTP/1.1 ' . $info['http_code'] . ' ' . $this->HTTPErrorCodes[$info['http_code']]);
		// Some informatic headers
		header('x-RESTProxy-url: ' . $info['url']);
		header('x-RESTProxy-total-time: ' . $info['total_time']);
		header('x-RESTProxy-namelookup-time: ' . $info['namelookup_time']);
		header('x-RESTProxy-connect-time: ' . $info['connect_time']);
		header('x-RESTProxy-size-upload: ' . $info['size_upload']);
		header('x-RESTProxy-size-download: ' . $info['size_download']);
		header('x-RESTProxy-speed-download: ' . $info['speed_download']);
		header('x-RESTProxy-speed-upload: ' . $info['speed_upload']);
		header('x-RESTProxy-download-content-lenght: ' . $info['download_content_length']);
		header('x-RESTProxy-upload-content-lenght: ' . $info['upload_content_length']);
		header('x-RESTProxy-start-transfer-time: ' . $info['starttransfer_time']);
		header('x-RESTProxy-redirect-time: ' . $info['redirect_time']);
		//var_dump($info);
		// Return the output
		echo $output;
		fclose($this->fp);
		curl_close($ch);
	}
}
$proxy = new RESTProxy();
$proxy->doRequest();
?>
