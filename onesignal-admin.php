<?php

class OneSignal_Admin {
	public function __construct() {
    }


	public static function init() {
		$onesignal = new self();
		if(current_user_can('update_plugins')) {
			add_action( 'admin_menu', array(__CLASS__,'add_admin_page') );
      add_action( 'add_meta_boxes_post', array( __CLASS__, 'add_onesignal_post_options' ) );
      add_action( 'save_post', array( __CLASS__, 'on_post_save' ) );
		}
    
    // Outside is_admin() to catch posts that go from future to published in the background.
    add_action( 'transition_post_status', array( __CLASS__, 'notification_on_blog_post' ), 10, 3 );
    
		return $onesignal;
	}
  
  public static function add_onesignal_post_options() {
    
      add_meta_box(
            'onesignal_notif_on_post',
            'OneSignal',
            array( __CLASS__, 'onesignal_notif_on_post_html_view' ),
            'post',
            'side',
            'high'
        );
  }
  
  public static function on_post_save($post_id) {
    if ( $_POST['send_onesignal_notification'] === "true" && get_post_status($post_id) !== "publish" ) {
      update_post_meta($post_id, 'send_onesignal_notification', "true");
    }
    else {
      update_post_meta($post_id, 'send_onesignal_notification', "false");
    }
  }
  
  public static function onesignal_notif_on_post_html_view($post) {
    $onesignal_wp_settings = OneSignal::get_onesignal_settings();
    ?>
      <input type="checkbox" name="send_onesignal_notification" value="true" <?php if ($onesignal_wp_settings['notification_on_post'] && $post->post_status != "publish") { echo checked; } ?>></input>
      <label> <?php if ($post->post_status == "publish") { echo "Send notification on update"; } else { echo "Send notification on publish"; } ?></label>
    <?php
  }
  
  public static function save_config_page($config) {
    if (!current_user_can('update_plugins'))
      return;
    
    $sdk_dir = plugin_dir_path( __FILE__ ) . 'sdk_files/';
    $onesignal_wp_settings = OneSignal::get_onesignal_settings();
    $new_app_id = $config['app_id'];
    
    // Validate the UUID
    if( preg_match('/([a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12})/', $new_app_id, $m))
      $onesignal_wp_settings['app_id'] = $new_app_id;
    
    if (is_numeric($config['gcm_sender_id'])) {
      $onesignal_wp_settings['gcm_sender_id'] = $config['gcm_sender_id'];
      $manifest_json = "{\n"
                       . "  \"start_url\": \"/\",\n"
                       . "  \"gcm_sender_id\": \"" . $onesignal_wp_settings['gcm_sender_id'] . "\",\n"
                       . "  \"gcm_user_visible_only\": true\n"
                       . "}";
      file_put_contents($sdk_dir . 'manifest.json', $manifest_json);
    }
    
    if ($config['gcm_sender_id']) {
      $onesignal_wp_settings['subdomain'] = $config['subdomain'];
    }
    
    if ($config['auto_register'] == "true") {
      $onesignal_wp_settings['auto_register'] = true;
    }
    else {
      $onesignal_wp_settings['auto_register'] = false;
    }
    
    if ($config['notification_on_post'] == "true") {
      $onesignal_wp_settings['notification_on_post'] = true;
    }
    else {
      $onesignal_wp_settings['notification_on_post'] = false;
    }
    
    $onesignal_wp_settings['default_title'] = $config['default_title'];
    $onesignal_wp_settings['default_icon'] = $config['default_icon'];
    $onesignal_wp_settings['default_url'] = $config['default_url'];
    
    $onesignal_wp_settings['app_rest_api_key'] = $config['app_rest_api_key'];
    
    OneSignal::save_onesignal_settings($onesignal_wp_settings);
    
    return $onesignal_wp_settings;
  }

	public static function add_admin_page() {
		$OneSignal_menu = add_menu_page('OneSignal Push',
                                    'OneSignal Push',
                                    'manage_options',
                                    'onesignal-push',
                                    array(__CLASS__, 'admin_menu'),
                                    plugin_dir_url( __FILE__ ) .'views/images/menu_icon.png');
                       
    add_action( 'load-' . $OneSignal_menu, array(__CLASS__, 'admin_custom_load') );                       
	}

	public static function admin_menu() {
    require_once( plugin_dir_path( __FILE__ ) . '/views/config.php' );
  }
  
  public static function admin_custom_load() {
    add_action( 'admin_enqueue_scripts', array(__CLASS__, 'admin_custom_scripts') );
  }
  
  public static function admin_custom_scripts() {
    wp_enqueue_style( 'bootstrap.min', plugin_dir_url( __FILE__ ) . 'views/css/bootstrap.min.css');
    wp_enqueue_style( 'style_onesignal', plugin_dir_url( __FILE__ ) . 'views/css/style_onesignal.css');

    wp_enqueue_script( 'bootstrap.min', plugin_dir_url( __FILE__ ) . 'views/javascript/bootstrap.min.js');
    wp_enqueue_script( 'settings', plugin_dir_url( __FILE__ ) . 'views/javascript/settings.js');
    
    wp_enqueue_script( 'intercom', plugin_dir_url( __FILE__ ) . 'views/javascript/intercom.js');

  }
  
  public static function notification_on_blog_post( $new_status, $old_status, $post ) {
    if (empty( $post )) {
        return;
    }
    
    $onesignal_wp_settings = OneSignal::get_onesignal_settings();
    
    if (isset($_POST['send_onesignal_notification'])) {
      $send_onesignal_notification = $_POST['send_onesignal_notification'];
    }
    else {
      $send_onesignal_notification = get_post_meta( $post->ID, 'send_onesignal_notification', true );
    }
    
    if ($send_onesignal_notification === "true") {
      if ( get_post_type( $post ) !== 'post' || $post->post_status !== "publish") {
          return;
      }
      
      update_post_meta($post->ID, 'send_onesignal_notification', "false");
      
      if (empty( $custom_headline ) ) {
        $notif_content = get_the_title( $post->ID );
      } else {
        $notif_content = $custom_headline;
      }
      
      $fields = array(
        'app_id' => $onesignal_wp_settings['app_id'],
        'included_segments' => array('All'),
        'isChromeWeb' => true,
        'contents' => array("en" => $notif_content)
      );
      
      $ch = curl_init();
      curl_setopt($ch, CURLOPT_URL, "https://onesignal.com/api/v1/notifications");
      curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json',
                             'Authorization: Basic ' . $onesignal_wp_settings['app_rest_api_key'])); // TODO: Get auth key from settings
      curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
      curl_setopt($ch, CURLOPT_HEADER, FALSE);
      curl_setopt($ch, CURLOPT_POST, TRUE);
      curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($fields));
      curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);

      $response = curl_exec($ch);
      curl_close($ch);
      
      return $response;
    }
  }
}

?>