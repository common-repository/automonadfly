<?php
/*
Plugin Name: AutoMonAdFly
Plugin URI: http://inhs.web.id/automonadfly
Description: Automatic Shorten a URL in content post using AdFly and masked with other Shortner Services
Version: 1.0.3.5
Author: Harry Sudana, I Nym
Author URI: http://inhs.web.id
*/

add_filter('the_content','adfly_filter', 99);
add_action('admin_menu', 'adfly_admin_menu');

function set_adfly_option($data){
  $newvalue = addslashes("'cache'=>'".$data['cache']."','mask_service'=>'".$data['mask_service']."',
  'adfly_account'=>array('key'=>'".$data['adflykey']."', 'uid'=>'".$data['adflyuid']."', 'type'=>'".$data['adflytype']."'),
  'exclude_link'=>'".nl2br($data['exclude_link'])."','new_window'=>'".$data['new_window']."','add_nofollow'=>'".$data['add_nofollow']."'");
  if (sizeof(get_adfly_option())<=6) {
	update_option('WPadflyshortner', $newvalue);
  } else {
	$deprecated='';
	$autoload='no';
	add_option('WPadflyshortner', $newvalue, $deprecated, $autoload);
  }	
}

function get_adfly_option(){
	$WPadflyshortner = get_option('WPadflyshortner');
	
	if($WPadflyshortner<>"")
		eval('$newvalue=array('.stripslashes($WPadflyshortner).');');
	else
		$newvalue = array(
				'cache'=>'yes',
				'mask_service'=>"is.gd",
				'adfly_account'=>array('key'=>'43db0633c555433bd7f8ca1389771066', 'uid'=>'152085', 'type'=>'int'),
				'exclude_link'=>get_bloginfo('url').'<br />',
				'new_window'=>'yes',
				'add_nofollow'=>'yes',
				);
	return $newvalue;
}

function build_mask_short_url($service_mask_name, $url){
	$file_return = shortener_get_file(build_shortening_url(0, $service_mask_name, FALSE, $url));
	$file_data = $file_return['file'];
	if($file_data==""){
		$shorturl = $url;
	}else{
		$shorturl = $file_data;
	}
	return $shorturl;
}

function build_adfly_short_url($url){
	$cnf = get_adfly_option();
	$shorturl="";
	
	$filekey = build_shortening_url(0, "adf.ly", $cnf['adfly_account'], $url);
    // Check if a cached version already exists - if so, return it
    if (($cnf['cache']!="no")&&(function_exists('plugin_cache_read'))) {
        $return = plugin_cache_read($filekey,"","","adflysurls");
        //if ($debug=="yes") {plugin_cache_debug($return);}
        $shorturl=$return['data'];
    }
	
	if($shorturl==""){
		$file_return = shortener_get_file( $filekey );
		$file_data = $file_return['file'];
		if($file_data==""){
			$shorturl = $url;
		}else{
			$shorturl = build_mask_short_url($cnf['mask_service'], $file_data);
			 // Update the cache, if required
			if (($cnf['cache']!="no")&&($shorturl!=$url)&&(function_exists('plugin_cache_update'))) {
				if($return['cache_update']=="Y")
					$return=plugin_cache_update($filekey,$shorturl,0,"adflysurls");
				//if ($debug=="yes") {plugin_cache_debug($return);}
			}
		}
	}
	return $shorturl;
}

function build_services_array(){
	return array("is.gd"=>"http://is.gd/api.php?longurl=",
				 "bit.ly"=>"http://bit.ly/api?url=",
				 "adf.ly"=>"http://adf.ly/api.php?key={key}&uid={uid}&advert_type={type}&url=",
				);
}
	
// Function to build the shortening URL
function build_shortening_url($debug,$service_name,$service_requires,$url) {
    // Build array of services
    $shortening_services=build_services_array();
    // Get the URL of the required shortening service
    $service_url=$shortening_services[$service_name];
    if ($service_url=="") {
        if ($debug=="yes") {echo "<p>Error: Shortening service not found</p>\n";}
    } else {
		// Make any API key, login, etc changes
		if(is_array($service_requires)){
			foreach($service_requires as $key=>$value){
				$service_url=str_replace("{".$key."}",$value,$service_url);
			}
		}
        // Access the service API and get the shortened URL
        if (substr($service_url,0,1)=="#") {
            $service_url=substr($service_url,1).$url;
        } else {
            $service_url.=urlencode($url);
        }
        //if ($cache=="no") {echo "<p>".$service_url."</p>";}
    }
    return $service_url;
}

// Function to get a file using CURL or alternative (1.3)
function shortener_get_file($filein) {
    $fileout="";
    // Try to get with CURL, if installed
    if (in_array('curl',get_loaded_extensions())===true) {
        $cURL = curl_init();
        curl_setopt($cURL,CURLOPT_URL,$filein);
        curl_setopt($cURL,CURLOPT_RETURNTRANSFER,1);
        $fileout=curl_exec($cURL);
        curl_close($cURL);
        if ($fileout=="") {$rc=-1;} else {$rc=1;}
    }
    // If CURL failed and a url_fopen is allowed, use that
    if (($fileout=="")&&(ini_get('allow_url_fopen')=="off")) {
        $fileout=file_get_contents($filein);
        if ($fileout=="") {$rc=-2;} else {$rc=2;}
    }
    if ((in_array('curl',get_loaded_extensions())!==true)&&(ini_get('allow_url_fopen')==1)) {$rc==-3;}
    $file_return['file']=$fileout;
    $file_return['rc']=$rc;
    return $file_return;
}


function adfly_filter($content){
  //if($this->options['noforauth']&&is_user_logged_in())
	//return $content;
  $pattern = '/<a (.*?)href=[\"\'](.*?)\/\/(.*?)[\"\'](.*?)>(.*?)<\/a>/i';
  $content = preg_replace_callback($pattern,'adfly_external_parser',$content);
  return $content;
}

function adfly_external_parser($matches){
  $cnf = get_adfly_option();
  
  $url=($matches[2] . '//' . $matches[3]);
  
  $parts = parse_url($url);
  $total_found = preg_match ( '/'. preg_quote($parts['host'],'/') .'/iUs',  $cnf['exclude_link'] );
  
  if($total_found<=0){
  	$url=build_adfly_short_url($matches[2] . '//' . $matches[3]);
  }

  $ifblank = (($cnf['new_window']=='yes') and ($total_found<=0)) ? ' target="_blank"' : '' ;
  $ifnofollow = (($cnf['add_nofollow']=='yes') and ($total_found<=0)) ? ' rel="nofollow"' : '';
  $link='<a'.$ifblank.$ifnofollow.' href="'.$url.'" '.$matches[1].$matches[4].'>'.$matches[5].'</a>';
  return $link;
}



function adfly_admin_menu(){
global $wp_version;
  
  $file = __FILE__;
  // hack for 1.5
  if (substr($wp_version, 0, 3) == '1.5') {
	$file = 'automonadfly/wpadflyshortner.php';
  }
  
  add_submenu_page('options-general.php', __('AutoMonAdFly', 'wpadflyshortner'), 
  						__('AutoMonAdFly', 'wpadflyshortner'), 10, $file, 'adfly_admin_config_panel');
  
}



function adfly_admin_config_panel(){
  if(isset($_POST['configSave'])){
	set_adfly_option($_POST);
  }
  				
  $wpAdflyConfig = get_adfly_option();
  
  $links = str_replace("<br />",chr(10),$wpAdflyConfig['exclude_link']);
  $adflyaccount = $wpAdflyConfig["adfly_account"];

  ?>
  <div id="dropmessage" class="updated" style="display:none;"></div>
  <div class="wrap">
  
  <h2>Cofiguration</h2>
  Configuration for your blog
  
  <form name="wpcommeConfig" action="" method="post">
  <table class="form-table">
  <tr>
  <td>Description</td><td>Setting</td>
  </tr>
  <tr>
  <td>Cache (Activate this Plugins to make it works <a href="http://wordpress.org/extend/plugins/wp-plugin-cache/" target="_blank">Wp Plugin Cache</a> )</td>
  <td>
  <select name="cache">
    <option value="yes" <?php echo ($wpAdflyConfig["cache"]=="yes") ? 'selected="selected"' : ""; ?> >Yes</option>
    <option value="no" <?php echo ($wpAdflyConfig["cache"]=="no") ? 'selected="selected"' : ""; ?> >No</option>
  </select>
  </td>
  </tr>
  <tr>
  <td>Mask Shortner Services</td>
  <td>
  <select name="mask_service">
  <?php
  $services = build_services_array();  
  foreach($services as $key=>$value){
	$selected = ($wpAdflyConfig["mask_service"]==$key) ? 'selected="selected"' : "";
	if($key!='adf.ly')
	  echo "<option value='".$key."' ".$selected." >".$key."</option>";
  }
  ?>
  </select>
  </td>
  </tr>
  <tr>
  <td>New Window</td>
  <td>
  <select name="new_window">
    <option value="yes" <?php echo ($wpAdflyConfig["new_window"]=="yes") ? 'selected="selected"' : ""; ?> >Yes</option>
    <option value="no" <?php echo ($wpAdflyConfig["new_window"]=="no") ? 'selected="selected"' : ""; ?> >No</option>
  </select>
  </td>
  </tr>
  <tr>
  <td>Add No Follow</td>
  <td>
  <select name="add_nofollow">
    <option value="yes" <?php echo ($wpAdflyConfig["add_nofollow"]=="yes") ? 'selected="selected"' : ""; ?> >Yes</option>
    <option value="no" <?php echo ($wpAdflyConfig["add_nofollow"]=="no") ? 'selected="selected"' : ""; ?> >No</option>
  </select>
  </td>
  </tr>
  <tr>
  <td>Exclude domain</td>
  <td>
  <textarea name="exclude_link" cols="60" rows="10"><?php echo $links;?></textarea>
  </td>
  </tr>
  <tr>
  <td>AdFly Key</td>
  <td>
  <input name="adflykey" id="adflykey" value="<?php echo $adflyaccount['key'];?>" type="text" />
  </td>
  </tr>
  <tr>
  <td>AdFly uid</td>
  <td>
  <input name="adflyuid" id="adflyuid" value="<?php echo $adflyaccount['uid'];?>" type="text" />
  </td>
  </tr>
  <tr>
  <td>Type</td>
  <td>
  <select name="adflytype">
    <option value="int">Interstitial</option>
    <option value="banner">Banner</option>
  </select>
  </td>
  </tr>
  <tr>
  <td>&nbsp;</td>
  <td>
  <input name="configSave" value="Save" type="submit" />
  </td>
  </tr>
  </table>
  </form>
  
  <h2>Help?</h2>
  <p><a href="http://adf.ly/?id=152085" target="_blank" rel="nofollow" >Register here</a> if You don't have AdFly account.</p>
  <p>How can i get the AdFly key and uid?<br />
  <img src="<?php echo get_bloginfo('url'); ?>/wp-content/plugins/automonadfly/stupidwayknowingapi.jpg" />
  </p>
  
  </div>
  <?php
}
?>