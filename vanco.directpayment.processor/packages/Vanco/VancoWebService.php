<?php 

class VancoPaymentService
{
	protected $_url = null;
	protected $_protocol = null;
	protected $_host = null;
	protected $_port = null;
	protected $_timeout = null;
	protected $_sessionID = null;
	private $offline = null;
	protected $transaction_log = null;
    
    function __construct( $vanco="ssl://www.vancodev.com", $port=443, $timeout=15 )  {
        //Code for setting the variable containing path of custom
        //extension directory
        require_once 'CRM/Utils/System.php';
     
        $config = CRM_Core_Config::singleton();
        $customExt = $config->extensionsDir;		
        $this->customExt = rtrim( $customExt,"/");
        //

        $this->transaction_log = true;
        if( is_array( $vanco ) ) {
            $url = $vanco['url_api'];
        } else {
            $url = $vanco;
        }
        
        $this->offline = false;
        $this->_url		= strtolower($url);
		$this->_port 	= $port;
        $this->_timeout = $timeout;  
		
		
		$x = stripos($this->_url,'www');
		$this->_host 		= substr( $url, $x, stripos( $url, '/cgi-bin/' ) - $x );
		$this->_protocol 	= substr( $url, 0, $x );
		
		if( $this->_protocol=='https://' )
		{
			$this->_protocol = "ssl://";
			$this->_port =443;
		}
        $this->target = substr( $url, stripos( $url, '/cgi-bin/' ) );
    }
	
	function _ProcessRequest($xml_obj)
	{
		$xml = $xml_obj->asXML();
              
		$this->log('Request',$xml);
		
		$socket = fsockopen($this->_protocol.$this->_host,  $this->_port, $errno, $errstr, $this->_timeout);
		$response_obj = null;
		if (!$socket) 
		{ 
			$response_obj['status'] = 'FAILED';
			$response_obj['error'] = $errno;
			$response_obj['desc'] = $errstr;

		} else 
		{ 
			$ReqHeader  = "POST ". $this->target ." HTTP/1.1\n"; 
			$ReqHeader .= "Host: " . $_SERVER['HTTP_HOST'] . "\n"; 
			$ReqHeader .= "User-Agent: " . $_SERVER['HTTP_USER_AGENT'] . "\n"; 
			$ReqHeader .= "Content-Type: application/x-www-form-urlencoded\n"; 
			$ReqHeader .= "Content-length: " . strlen($xml) . "\n"; 
			$ReqHeader .= "Connection: close\n\n"; 
			$ReqHeader .= $xml . "\n\n";
			fwrite($socket, $ReqHeader); 
			$response = '';
			while (!feof($socket))
			{ 
				$response .= fgets($socket, 4096);
			}
			$xml_response = strstr($response,'<?xml');
            $response_obj = simplexml_load_string($xml_response);
            
            if ( !empty( $response_obj ) ) {           
                $this->log('Response',$response_obj->asXML());
            }
		}
		return $response_obj;
	}

	function Login($params)
	{
		if($this->_sessionID != null)
		{
			$this->Logout();
		}
        $xml_login = simplexml_load_file("$this->customExt/vanco.directpayment.processor/packages/Vanco/xml/login.xml");
        
        $xml_login->Auth->RequestTime = date('Y-m-d H:m:s');
		$xml_login->Request->RequestVars->UserID = $params['username'];
		$xml_login->Request->RequestVars->Password = $params['password'];
        
		if(!$this->offline)
		{
			$response_obj = $this->_ProcessRequest($xml_login);
		}else
		{
			//testing offline
			$result =NULL;
			$result['status']		= 'SUCCESS';
			$result['sessionID']	= $this->_sessionID = 'afb479b7a690f62d6e8b7e5ffb5dc22fa10727a0';
			return $result;
		}
		$this->_sessionID = (string)$response_obj->Response->SessionID;  
		
		if( $this->_sessionID != null )
		{
			$result['status']		= 'SUCCESS';
			$result['sessionID']	= $this->_sessionID;
			
		}else
		{
			$result['status']	= 'FAILED';
			$result['error']	= (string) $response_obj->Response->Errors->Error->ErrorCode;
			$result['desc']		= (string)$response_obj->Response->Errors->Error->ErrorDescription;	
			$this->_sessionID = null;
		}
		
		return $result;
       

	}
	
	function Logout()
	{ 
        $xml_logout = simplexml_load_file("$this->customExt/vanco.directpayment.processor/packages/Vanco/xml/logout.xml");
        $xml_logout->Auth->RequestTime 		= date('Y-m-d H:m:s');
		$xml_logout->Auth->SessionID 		= $this->_sessionID;
		
		if(!$this->offline)
		{
			$response_obj = $this->_ProcessRequest($xml_logout);
		}else
		{	//MAKING OFFLINE TESTING
			$result =NULL;
			$result['status']	= 'SUCCESS';
			$this->_sessionID = null;
			return $result;
		}
		$logout = (string)$response_obj->Response->Logout;
		
		if($logout == 'Successful')
		{
		$result['status']	= 'SUCCESS';
			$this->_sessionID = null;
		}else
		{
			$result['status']	= 'FAILED';
			$result['error']	= (string)$response_obj->Response->Errors->Error->ErrorCode;
			$result['desc']		= (string)$response_obj->Response->Errors->Error->ErrorDescription;	
		}
				
		return $result;

	}
	
	function EFTAddCompleteTransaction($session_id, $params)
	{	
		$result =null;
   
        if($this->_sessionID == null || $session_id != $this->_sessionID)
            {
                $result['status'] = 'FAILED';
                
                $result['error'] = 'Not Authorized';
                $result['desc']  = 'Either not logged-in or invalid session ID parameter ';
                return $result;
            }
        $xml_obj   = simplexml_load_file("$this->customExt/vanco.directpayment.processor/packages/Vanco/xml/transaction.xml");
        
		$xml_obj->Auth->RequestTime 	=  date('Y-m-d H:m:s');
		$xml_obj->Auth->SessionID 		= $this->_sessionID;
    
		foreach( $params as $field => $value )
            {			
                $xml_obj->Request->RequestVars->$field = $value;
            }
			//exit;
        if(!$this->offline)
            {
				$response_obj = $this->_ProcessRequest($xml_obj);    
				$errors = (array)$response_obj->Response->Errors->Error;
				//Added code to handle Invalid start date and increase the day by 1
				
				$errdes = @$response_obj->Response->Errors->Error->ErrorDescription; //error description
								
				$flagerror = false;
                if( $errdes == 'Invalid Start Date' )
                    {  //increment of date by one day 
                        $xml_obj->Request->RequestVars->StartDate = date("Y-m-d",strtotime($xml_obj->Request->RequestVars->StartDate .'+ 1 day'));
                        if ( $xml_obj->Request->RequestVars->EndDate != '' ) {
							$xml_obj->Request->RequestVars->EndDate   = date("Y-m-d",strtotime($xml_obj->Request->RequestVars->EndDate .'+ 1 day'));
						} else {
							$xml_obj->Request->RequestVars->EndDate   = '';
						}
                        //re-submission of data 
                        $response_obj = $this->_ProcessRequest($xml_obj); 
                        $flagerror = 'true';
                    }
                //End of Invaid start date code

				$result = null ;
				if($errors !=null && $flagerror != 'true')
                    {
                        $result['status'] =  'FAILED';
                        $result['error']	= (string)$response_obj->Response->Errors->Error->ErrorCode;
                        $result['desc']		= (string)$response_obj->Response->Errors->Error->ErrorDescription;	
                    }else
                    {
                        $result['status'] =  'SUCCESS';
                        $result['StartDate'] 		= (string)$response_obj->Response->StartDate;
                        $result['CustomerRef']		= (string)$response_obj->Response->CustomerRef;
                        $result['PaymentMethodRef'] = (string)$response_obj->Response->PaymentMethodRef;
                        $result['TransactionRef'] 	= (string)$response_obj->Response->TransactionRef;
                        $result['TransactionFee'] 	= (string)$response_obj->Response->TransactionFee;
                    }
            }else
            {
				$failed = true;
				if($failed)
                    {
                        $result['status'] =  'FAILED';
                        $result['error']	= 167;
                        $result['desc']		= 'Payment Method Not Found';	
                    }else{	
					$result['StartDate'] 		= '2010-07-11';
					$result['CustomerRef']		= 7396712;
					$result['PaymentMethodRef'] = 7437936;
					$result['TransactionRef'] 	= 164027053;
					$result['TransactionFee'] 	= 27.98;
				}
            }
		return $result;
	}
    
	function EFTTransactionHistory($session_id, $params)
	{	
		$result =null;
       
        if($this->_sessionID == null || $session_id != $this->_sessionID)
            {
                $result['status'] = 'FAILED';
                $result['error'] = 'Not Authorized';
                $result['desc'] = 'Either not logged-in or invalid session ID parameter ';
                return $result;
            }
        $xml_obj   = simplexml_load_file("$this->customExt/vanco.directpayment.processor/packages/Vanco/xml/history.xml");
        
		$xml_obj->Auth->RequestTime 	=  date('Y-m-d H:m:s');
		$xml_obj->Auth->SessionID 		= $this->_sessionID;
		foreach( $params as $field => $value )
            {
                $xml_obj->Request->RequestVars->$field = $value;
            }
		if(!$this->offline)
            {
				$response_obj = $this->_ProcessRequest($xml_obj);
               
                $errors = (array)$response_obj->Response->Errors->Error;
				$result = null ;
				if($errors !=null)
                    {
                        $result['status'] =  'FAILED';
                        $result['error']	= (string)$response_obj->Response->Errors->Error->ErrorCode;
                        $result['desc']		= (string)$response_obj->Response->Errors->Error->ErrorDescription;	
                        
                    }else
                    {
                        $result = $response_obj->Response;
                    }
            }else
            {
				$failed = true;
				if($failed)
                    {
                        $result['status'] =  'FAILED';
                        $result['error']	= 167;
                        $result['desc']		= 'Payment Method Not Found';	
                    }else{	
					$result['StartDate'] 		= '2010-07-11';
					$result['CustomerRef']		= 7396712;
					$result['PaymentMethodRef'] = 7437936;
					$result['TransactionRef'] 	= 164027053;
					$result['TransactionFee'] 	= 27.98;
				}
            }
		return $result;
	}
    
	function EFTCurrentTransactions($session_id, $params)
	{	
		$result =null;
		if($this->_sessionID == null || $session_id != $this->_sessionID)
		{
			$result['status'] = 'FAILED';
			$result['error'] = 'Not Authorized';
			$result['desc'] = 'Either not logged-in or invalid session ID parameter ';
			return $result;
		}
		$xml_obj   = simplexml_load_file("$this->customExt/vanco.directpayment.processor/packages/Vanco/xml/recur_history.xml");

		$xml_obj->Auth->RequestTime 	=  date('Y-m-d H:m:s');
		$xml_obj->Auth->SessionID 		= $this->_sessionID;
		foreach( $params as $field => $value )
            {
                $xml_obj->Request->RequestVars->$field = $value;
            }
		if(!$this->offline)
            {
				$response_obj = $this->_ProcessRequest($xml_obj);
                $errors = (array)$response_obj->Response->Errors->Error;
				$result = null ;
				if($errors !=null)
                    {
                        $result['status'] =  'FAILED';
                        $result['error']	= (string)$response_obj->Response->Errors->Error->ErrorCode;
                        $result['desc']		= (string)$response_obj->Response->Errors->Error->ErrorDescription;	
                        
                    }else
                    {
                        $result = $response_obj->Response;
                    }
            }else
            {
                $failed = true;
                if($failed)
                    {
                        $result['status'] =  'FAILED';
                        $result['error']	= 167;
                        $result['desc']		= 'Payment Method Not Found';	
                    }else{	
					$result['StartDate'] 		= '2010-07-11';
					$result['CustomerRef']		= 7396712;
					$result['PaymentMethodRef'] = 7437936;
					$result['TransactionRef'] 	= 164027053;
					$result['TransactionFee'] 	= 27.98;
				}
            }
 		return $result;
 	}


	function EFTAddEditPaymentMethod($session_id, $params)
    {	
		$result =null;
		if($this->_sessionID == null || $session_id != $this->_sessionID)
		{
			$result['status'] = 'FAILED';
			$result['error'] = 'Not Authorized';
			$result['desc'] = 'Either not logged-in or invalid session ID parameter ';
			return $result;
		}
		$xml_obj   = simplexml_load_file("$this->customExt/vanco.directpayment.processor/packages/Vanco/xml/addedit.xml");
	
		$xml_obj->Auth->RequestTime 	=  date('Y-m-d H:m:s');
		$xml_obj->Auth->SessionID 		= $this->_sessionID;
		foreach( $params as $field => $value )
            {
                $xml_obj->Request->RequestVars->$field = $value;
            }
		if(!$this->offline)
            {
				$response_obj = $this->_ProcessRequest($xml_obj);

                $errors = (array)$response_obj->Response->Errors->Error;
				$result = null ;
				if($errors !=null)
                    {
                        $result['status'] =  'FAILED';
                        $result['error']	= (string)$response_obj->Response->Errors->Error->ErrorCode;
                        $result['desc']		= (string)$response_obj->Response->Errors->Error->ErrorDescription;	
                        
                    }else
                    {
                        $result = $response_obj->Response;
                    }
            }else
            {
                $failed = true;
                if($failed)
                    {
                        $result['status'] =  'FAILED';
                        $result['error']	= 167;
                        $result['desc']		= 'Payment Method Not Found';	
                    }else{	
					$result['StartDate'] 		= '2010-07-11';
					$result['CustomerRef']		= 7396712;
					$result['PaymentMethodRef'] = 7437936;
					$result['TransactionRef'] 	= 164027053;
					$result['TransactionFee'] 	= 27.98;
				}
            }
 		return $result;
 	}	


	function EFTGetPaymentMethod($session_id, $params)

    {	
		$result =null;
		if($this->_sessionID == null || $session_id != $this->_sessionID)
		{
            $result['status'] = 'FAILED';
			$result['error'] = 'Not Authorized';
			$result['desc'] = 'Either not logged-in or invalid session ID parameter ';
			return $result;
		}
		$xml_obj   = simplexml_load_file("$this->customExt/vanco.directpayment.processor/packages/Vanco/xml/getpayment.xml");
        $xml_obj->Auth->RequestTime 	=  date('Y-m-d H:m:s');
		$xml_obj->Auth->SessionID 		= $this->_sessionID;
		foreach( $params as $field => $value )
            {
                $xml_obj->Request->RequestVars->$field = $value;
            }
		if(!$this->offline)
            {
				$response_obj = $this->_ProcessRequest($xml_obj);
                
                $errors = (array)$response_obj->Response->Errors->Error;
				$result = null ;
				if($errors !=null)
                    {
                        $result['status'] =  'FAILED';
                        $result['error']	= (string)$response_obj->Response->Errors->Error->ErrorCode;
                        $result['desc']		= (string)$response_obj->Response->Errors->Error->ErrorDescription;	
                        
                    }else
                    {
                        $result = $response_obj->Response;
                    }
            }else
            {
                $failed = true;
                if($failed)
                    {
                        $result['status']   =  'FAILED';
                        $result['error']	= 167;
                        $result['desc']		= 'Payment Method Not Found';	
                    }else{	
					$result['StartDate'] 		= '2010-07-11';
					$result['CustomerRef']		= 7396712;
					$result['PaymentMethodRef'] = 7437936;
					$result['TransactionRef'] 	= 164027053;
					$result['TransactionFee'] 	= 27.98;
				}
            }
 		return $result;
 	}

	function EFTCustomerInfo($session_id, $params)
	{	
		$result =null;
        if($this->_sessionID == null || $session_id != $this->_sessionID)
            {
                $result['status'] = 'FAILED';
                $result['error'] = 'Not Authorized';
                $result['desc'] = 'Either not logged-in or invalid session ID parameter ';
                return $result;
            }
		$xml_obj   = simplexml_load_file("$this->customExt/vanco.directpayment.processor/packages/Vanco/xml/customerinfo.xml");
        $xml_obj->Auth->RequestTime 	=  date('Y-m-d H:m:s');
		$xml_obj->Auth->SessionID 		= $this->_sessionID;
		foreach( $params as $field => $value )
            {
                $xml_obj->Request->RequestVars->$field = $value;
            }
		if(!$this->offline)
            {
				$response_obj = $this->_ProcessRequest($xml_obj);
                $errors = (array)$response_obj->Response->Errors->Error;
				$result = null ;
				if($errors !=null)
                    {
                        $result['status'] =  'FAILED';
                        $result['error']	= (string)$response_obj->Response->Errors->Error->ErrorCode;
                        $result['desc']		= (string)$response_obj->Response->Errors->Error->ErrorDescription;	
                        
                    }else
                    {
                        $result = $response_obj->Response;
                    }
            }else
            {
				$failed = true;
				if($failed)
                    {
                        $result['status'] =  'FAILED';
                        $result['error']	= 167;
                        $result['desc']		= 'Payment Method Not Found';	
                    }else{	
					$result['StartDate'] 		= '2010-07-11';
					$result['CustomerRef']		= 7396712;
					$result['PaymentMethodRef'] = 7437936;
					$result['TransactionRef'] 	= 164027053;
					$result['TransactionFee'] 	= 27.98;
				}
            }
		return $result;
	
    }


	function EFTDeleteTransaction($session_id, $params)
    {	
		$result =null;
        if($this->_sessionID == null || $session_id != $this->_sessionID)
            {
                $result['status'] = 'FAILED';
                $result['error'] = 'Not Authorized';
                $result['desc'] = 'Either not logged-in or invalid session ID parameter ';
                return $result;
            }
		$xml_obj   = simplexml_load_file("$this->customExt/vanco.directpayment.processor/packages/Vanco/xml/delete.xml");
        $xml_obj->Auth->RequestTime 	=  date('Y-m-d H:m:s');
		$xml_obj->Auth->SessionID 		= $this->_sessionID;
		foreach( $params as $field => $value )
            {
                $xml_obj->Request->RequestVars->$field = $value;
            }
		if(!$this->offline)
            {
				$response_obj = $this->_ProcessRequest($xml_obj);
                $errors = (array)$response_obj->Response->Errors->Error;
				$result = null ;
				if($errors !=null)
                    {
                        $result['status'] =  'FAILED';
                        $result['error']	= (string)$response_obj->Response->Errors->Error->ErrorCode;
                        $result['desc']		= (string)$response_obj->Response->Errors->Error->ErrorDescription;	
                        
                    }else
                    {
                        $result = $response_obj->Response;
                    }
            }else
            {
				$failed = true;
				if($failed)
                    {
                        $result['status'] =  'FAILED';
                        $result['error']	= 167;
                        $result['desc']		= 'Payment Method Not Found';	
                    }else{	
					$result['StartDate'] 		= '2010-07-11';
					$result['CustomerRef']		= 7396712;
					$result['PaymentMethodRef'] = 7437936;
					$result['TransactionRef'] 	= 164027053;
					$result['TransactionFee'] 	= 27.98;
				}
            }
		return $result;
	}

    function EFTGetFederalHoliday($session_id, $paramsVal)
    {	
		$result =null;
		if($this->_sessionID == null || $session_id != $this->_sessionID)
		{
			$result['status'] = 'FAILED';
			$result['error']  = 'Not Authorized';
			$result['desc']   = 'Either not logged-in or invalid session ID parameter ';
			return $result;
		}
		$xml_obj   = simplexml_load_file("$this->customExt/vanco.directpayment.processor/packages/Vanco/xml/federalholiday.xml");
	
		$xml_obj->Auth->RequestTime 	=  date('Y-m-d H:m:s');
		$xml_obj->Auth->SessionID 		= $this->_sessionID;
		

		if(!$this->offline)
            {
				$response_obj = $this->_ProcessRequest($xml_obj);
                $errors = (array)$response_obj->Response->Errors->Error;
				$result = null ;
				if($errors !=null)
                    {
                        $result['status'] =  'FAILED';
                        $result['error']	= (string)$response_obj->Response->Errors->Error->ErrorCode;
                        $result['desc']		= (string)$response_obj->Response->Errors->Error->ErrorDescription;
                        
                    }else
                    {
                        $result = $response_obj->Response;
                    }
            }else
            {
                $failed = true;
                if($failed)
                    {
                        $result['status'] =  'FAILED';
                        $result['error']	= 167;
                        $result['desc']		= 'Payment Method Not Found';
                    }else{	
					$result['StartDate'] 		= '2010-07-11';
					$result['CustomerRef']		= 7396712;
					$result['PaymentMethodRef'] = 7437936;
					$result['TransactionRef'] 	= 164027053;
					$result['TransactionFee'] 	= 27.98;
				}
            }

 		return $result;
 	}

	function log($type,$xml,$fileName = null)
	{
        require_once 'CRM/Core/DAO.php';
        $fileName  = $this->customExt."/vanco.directpayment.processor/packages/Vanco/log/vanco_log_";
        if ($xml ) {
			$xmlObject = simplexml_load_string( $xml );
		}
        if( (string)@$xmlObject->Request->RequestVars->AccountNumber ) {
            $xmlObject->Request->RequestVars->AccountNumber = CRM_Utils_System::mungeCreditCard( (string)$xmlObject->Request->RequestVars->AccountNumber );
            $xmlObject->Request->RequestVars->CardCVV2 = '';

            $xml = $xmlObject->asXML();
        }
		if($this->transaction_log)
            {
                $file = fopen( $fileName.''.date('Ymd').'.xml', 'a' );
                fwrite($file,"-----------------$type START--------------\r\n");
                fwrite($file, "$type Time: ".date('d-m-y h:i:s')."\r\n");
                fwrite($file,"$type: ".$xml."\r\n");
                fwrite($file,"-----------------$type END--------------\r\n");
                fclose($file);
            }
	}
}
?>
