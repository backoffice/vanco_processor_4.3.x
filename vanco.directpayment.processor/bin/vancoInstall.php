<?php
require_once '../civicrm.config.php';
require_once 'CRM/Core/Config.php';

class bin_vancoInstall{
    function __construct( ) {
        require_once 'CRM/Utils/System.php';
//        CRM_Utils_System::loadBootStrap(  );
        $config = CRM_Core_Config::singleton();
        require_once 'CRM/Core/DAO.php';
        $data = array( );
        
        $customPhp = $config->customPHPPathDir;
        $customTpl = $config->customTemplateDir;
        $customExt = $config->extensionsDir;
        
        //remove trailing slashes
        $customPhp = rtrim( $customPhp,"/");
        $customTpl = rtrim( $customTpl,"/");
        $customExt = rtrim( $customExt,"/");
		$configPath = explode( 'sites', $config->configAndLogDir );
		$modulePath = $configPath[0]."sites/all/modules/civicrm/drupal/modules/vanco_payment";

        //Check if no custom folders are defined display error with a link to the civi page to set the paths.
        if ( !$customPhp || !$customTpl || !$customExt ) {
            $url   = CRM_Utils_System::href( 'here', "civicrm/admin/setting/path", "reset=1" );
            $error = "Your custom directories are not set. Click ".$url." to set the directories.";
            CRM_Core_Error::fatal( $error );
        } else {
            //Copying custom php files to civicrm
            $data = $this->recurse_copy( $customExt."/vanco.directpayment.processor/civi_custom/php", $customPhp, $data );
            //Copying custom template files to civicrm
            $data = $this->recurse_copy( $customExt."/vanco.directpayment.processor/civi_custom/templates", $customTpl, $data );
        }
        
        //Copying Vanco module to civicrm
        $data = $this->recurse_copy( $customExt."/vanco.directpayment.processor/drupal/modules/vanco_payment", $modulePath, $data);
        
        //Check if custom files already exist then display an error
        if (  $data['error'] ) {
            $error = "Following Custom Files already exists: <br/>".implode('<br/>', $data['error']);
            CRM_Core_Error::fatal( $error ); 
        } else {
            foreach ( $data as $key => $value ) {
                $chk = copy( $value['src'], $value['dest'] ); 
                if ( !$chk ) {
                    CRM_Core_Error::fatal( ts('You donot have writable permission on custom directories') ); 
                } 
                chmod( $value['dest'], 0775 );
            }
        }
    }
    
    function recurse_copy( $src, $dst, &$data) { 
        $dir = opendir( $src );
        @mkdir( $dst ); 
        if ( !is_writable( $src ) )  {
            CRM_Core_Error::fatal( $src.' directory doesnot have writable permissions' );
        }
        if ( !is_writable( $dst ) )  {
            CRM_Core_Error::fatal( $dst.' directory doesnot have writable permissions' );
        }
        while( false !== ( $file = readdir( $dir ) ) ) { 
            if ( ( $file != '.' ) && ( $file != '..' ) ) { 
                if ( is_dir( $src . '/' . $file ) ) { 
                    $data = $this->recurse_copy( $src . '/' . $file, $dst . '/' . $file, $data );
                    
                } 
                else { 
                    if ( file_exists( $dst . '/' . $file ) ) {
                        $data['error'][] = $dst . '/' . $file;
                    } 
                    $data[$dst.'/'.$file]['src']  = $src . '/' . $file;
                    $data[$dst.'/'.$file]['dest'] = $dst . '/' . $file;
                }
            } 
        } 
        closedir( $dir ); 
        return $data;
    }
}
$vancoInstall = new bin_vancoInstall( );
