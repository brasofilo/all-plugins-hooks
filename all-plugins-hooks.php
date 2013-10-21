<?php
/**
 * Plugin Name: All Themes/Plugins Hooks
 * Plugin URI: http://wordpress.org/extend/plugins/
 * Description: Shows all the hooks of a given plugin, separated by file and hook type.
 * Version: 1.1
 * Author: Rodolfo Buaiz
 * Author URI: http://wordpress.stackexchange.com/users/12615/brasofilo
 * License: GPLv2 or later
 *  
 *
 * 
 * This program is free software; you can redistribute it and/or modify it 
 * under the terms of the GNU General Public License version 2, 
 * as published by the Free Software Foundation.  You may NOT assume 
 * that you can use any other version of the GPL.
 *
 * This program is distributed in the hope that it will be useful, 
 * but WITHOUT ANY WARRANTY; without even the implied warranty 
 * of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
 */

register_activation_hook( __FILE__, array( 'B5F_Get_All_Plugin_Hooks', 'on_activation' ) );

if( is_admin() )
    add_action(
        'plugins_loaded',
        array ( B5F_Get_All_Plugin_Hooks::get_instance(), 'plugin_setup' )
    );

class B5F_Get_All_Plugin_Hooks
{
	/**
	 * Plugin instance.
	 * @see get_instance()
	 * @type object
	 */
	protected static $instance = NULL;
	
    
	/**
	 * Saved options
	 * @type array
	 */
    private $options;

    
    /**
	 * Plugin option name
	 * @type string
	 */
	public $option_name = 'all_plugins_hooks';

    
    /**
	 * All plugins names and paths
	 * @type array
	 */
	private $current_plugins;

    
    /**
     * Caching time
     * use the filter aph_transient_time to modify the default 86400 (1 day)
     * @var integer
     */
    private $transient_time;
    
    
    /**
     * Scan type
     * plugins or themes
     * @var string
     */
    private $scan_type = 'plugins';
    
    
	/**
	 * Plugin URL.
	 * @type string
	 */
	public $plugin_url = '';

    
	/**
	 * Directory path.
	 * @type string
	 */
	public $plugin_path = '';
		

	/**
	 * Plugin file name
	 * @type string 
	 */
	public $slug;


    /**
	 * Constructor. Intentionally left empty and public.
	 *
	 * @see plugin_setup()
	 * @since 2012.09.12
	 */
    public function __construct() { }

    
	/**
	 * Access this plugin’s working instance
	 *
	 * @wp-hook plugins_loaded
	 * @return  object of this class
	 */
	public static function get_instance()
	{
		NULL === self::$instance and self::$instance = new self;
		return self::$instance;
	}

	
	/**
	 * Used for regular plugin work.
	 *
	 * @wp-hook plugins_loaded
	 * @return  void
	 */
	public function plugin_setup()
	{
		$this->plugin_url    = plugins_url( '/', __FILE__ );
		$this->plugin_path   = plugin_dir_path( __FILE__ );
		$this->slug			 = dirname( plugin_basename( __FILE__ ) );
        $this->options = get_option( $this->option_name );
        $this->current_plugins = $this->scan_plugins_directory();
        $this->transient_time = apply_filters( 'aph_transient_time', 60*60*24 );
        add_action( 'admin_menu', array( $this, 'add_plugin_page' ) );
        add_action( 'admin_init', array( $this, 'page_init' ) );
        
        global $pagenow;
        if( 'plugins.php' != $pagenow )
            return;
        add_filter( 'plugin_row_meta', array( $this, 'donate_link' ), 10, 4 );
		add_filter( 'plugin_action_links', array( $this, 'settings_plugin_link' ), 10, 2 );
    }
    
    
    /**
     * Adds our submenu to the Plugins menu
     */
    public function add_plugin_page()
    {
        $hook = add_management_page(
            'All Hooks', 
            'All Hooks', 
            'manage_options', 
            $this->slug, 
            array( $this, 'create_admin_page' )
        );
        add_action( "admin_print_scripts-$hook", array( $this, 'enqueue_prettify' ) );
    }

    
	/**
	 * Add link to settings in Plugins list page
	 * 
	 * @return Plugin link
	 */
	public function settings_plugin_link( $links, $file )
	{
		if( $file == plugin_basename( dirname( __FILE__ ) . '/' . $this->slug . '.php' ) )
		{
			$links[] = '<a href="'
					. admin_url( 'tools.php?page=all-plugins-hooks' )
					. '">'
					. __( 'Settings', 'ejmm' )
					. '</a>';
			
		}
		return $links;
	}


    /**
     * Print our custom Plugins > All Hooks page
     */
    public function enqueue_prettify()
    {
        $file = 'run_prettify.js?lang=css&skin='.$this->options['skin'];
        wp_enqueue_script( 
            'prettify-js', 
            'https://google-code-prettify.googlecode.com/svn/loader/' . $file, 
            array() );
        
        # Hardcode some styles
        $this->print_style();
    }
    
    
    /**
     * Print our custom Plugins > All Hooks page
     */
    public function create_admin_page()
    {
        ?>
        <div class="wrap">
            <?php screen_icon(); ?>
            <h2>All Themes & Plugins Hooks</h2>           
            <form method="post" action="options.php">
            <?php
                settings_fields( 'aph_option_group' );   
                do_settings_sections( 'aph-admin-page' );
                submit_button( 'Update' ); 
            ?>
            </form>
			<div>
			<?php
				if( !empty( $this->options['plugin_id'] ) )
				{
					$path = $this->options['plugin_id'];
					$this->do_print( $path );
				}
			?>
			<div>
        </div>
        <?php
    }

    /**
     * Register and add settings
     */
    public function page_init()
    {        
        register_setting(
            'aph_option_group',
            $this->option_name,
            array( $this, 'sanitize' )
        );

        add_settings_section(
            'aph_section_id',
            '',
            '__return_null',
            'aph-admin-page'
        );  

        add_settings_field(
            'skin',
            'Code style',
            array( $this, 'skin_callback' ),
            'aph-admin-page',
            'aph_section_id'
        );    

        add_settings_field(
            'type',
            'Scan type',
            array( $this, 'type_callback' ),
            'aph-admin-page',
            'aph_section_id'
        );
        
        $scan_type = ucfirst( rtrim( $this->get_scan_type( '', ''), "s" ) );
        add_settings_field(
            'plugin_id',
            $scan_type,
            array( $this, 'plugin_id_callback' ),
            'aph-admin-page',
            'aph_section_id'
        );    
    }


    /** 
     * Plugin selection dropdown
     */
    public function skin_callback()
    {
        $skins = array( 
            'default' => 'Default',
            'desert' => 'Desert',
            'sunburst' => 'Sunburst',
            'sons-of-obsidian' => 'Sons of Obsidian',
            'doxy' => 'Doxy'
        );
		$option = isset( $this->options['skin'] ) ? $this->options['skin'] : 'sunburst';
		echo "<select name='{$this->option_name}[skin]' id='skin_id'>";
		foreach( $skins as $key => $name )
		{
			$selected = selected( $key, $option, false );
			echo "<option value='$key' $selected>$name</option>";
		}
		echo '</select>';
    }
    

    /** 
     * Scan plugins or themes
     */
    public function type_callback()
    {
        $types = array( 
            'plugins' => 'Plugins',
            'themes' => 'Themes'
        );
		$option = isset( $this->options['type'] ) ? $this->options['type'] : 'plugins';
		echo "<select name='{$this->option_name}[type]' id='type_id'>";
		foreach( $types as $key => $name )
		{
			$selected = selected( $key, $option, false );
			echo "<option value='$key' $selected>$name</option>";
		}
		echo '</select>';
    }
    

    /** 
     * Plugin selection dropdown
     */
    public function plugin_id_callback()
    {
		$option = isset( $this->options['plugin_id'] ) ? $this->options['plugin_id'] : '';
		$selected = selected( '', $option, false );
        
		echo "<select name='{$this->option_name}[plugin_id]' id='plugin_id'>";
        $scan_type = rtrim( $this->get_scan_type( '', ''), "s" );
        echo "<option value='' $selected>-- choose a $scan_type --</option>";
		foreach( $this->current_plugins as $dir => $name )
		{
			$selected = selected( $dir, $option, false );
			echo "<option value='$dir' $selected>$name</option>";
		}
		echo '</select>';
    }

	/**
	 * Outputs the hooks of a Plugin
     * 
     * @author shell_exec from http://stackoverflow.com/a/18881544/1287812
     *
     * @param string $path Absolute path to a plugin folder
	 */
	private function do_print( $path )
	{
        # Controls if there are no actions or filters in a plugin
		$nothing = 0;
        
        $transient_name = $this->option_name . '_' . $this->current_plugins[$path];
        $get_files = get_transient( $transient_name );
        if( !$get_files )
        {
            $get_files = $this->scan_plugin_files( $path );
            set_transient( $transient_name, $get_files, $this->transient_time );
        }
            
        foreach( $get_files as $dir => $values )
        {
            if ( !empty( $values['get_actions'] ) || !empty( $values['get_filters'] ) ) 
            {
                echo '<div class="plugin-file plugin-radius">'; 
                echo "<h3 class='plugin-name plugin-radius'>$dir</h3>";
                $nothing++;
            }

            # Print actions
            if ( !empty( $values['get_actions'] ) ) 
            {
                echo "<h3>Actions</h3><pre class='prettyprint'>";
                echo htmlentities( $values['get_actions'] );
                echo "</pre>";
            }

            # Print filters
            if ( !empty( $values['get_filters'] ) ) 
            {
                echo "<h3>Filters</h3><pre class='prettyprint'>";
                echo htmlentities( $values['get_filters'] );
                echo "</pre>";
            }

            # Close container
            if ( !empty( $values['get_actions'] ) || !empty( $values['get_filters'] ) ) 
                echo '</div>';
        }
        
        if( $nothing == 0 )
			echo "<h1>No hooks found...</h1>";
	}


    /**
     * Scans all files on a plugin directory 
     * 
     * Returns an array with the structure 
     * 'plugin_name' => array( 'get_actions', 'get_filters' )
     * 
     * @param string $path Full plugin path
     * @return array 
     */
    private function scan_plugin_files( $path )
    {
        $get_files = array();
        $scan_type = $this->get_scan_type();

        foreach( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $path ) ) as $filename ) 
        {
            if ( substr( $filename, -3 ) == 'php' ) 
            {
                $folder_file_name = str_replace( WP_CONTENT_DIR . $scan_type, '', $filename );
                $get_actions = shell_exec( 'grep -n -C0 do_action ' . escapeshellarg( $filename ) );
                $get_filters = shell_exec( 'grep -n -C0 apply_filters ' . escapeshellarg( $filename ) );
                # Make output removing double white-spaces and tabs
                $rem_actions = preg_replace('/([\s])\1+/', ' ', $get_actions);
                $rem_filters = preg_replace('/([\s])\1+/', ' ', $get_filters);
                $get_files[$folder_file_name] = array(
                    'get_actions' => trim( preg_replace( '/\t+/', ' ', $rem_actions ) ),
                    'get_filters' => trim( preg_replace( '/\t+/', ' ', $rem_filters ) ) 
                );
            }
        }
        return $get_files;
    }
    
    
    /**
     * Get all plugins names and full paths
     * 
     * @return array
     */
    private function scan_plugins_directory()
    {
        $transient_name = $this->option_name . '_directories';
        $current_plugins = get_transient( $transient_name );
        $scan_type = $this->get_scan_type( '/', '');
        if( !$current_plugins )
        {
            $path = WP_CONTENT_DIR . $scan_type;
            $dirs = array_filter( glob( $path . '/*' , GLOB_ONLYDIR), 'is_dir' );
            $list = array();

            foreach( $dirs as $dir )
            {
                $short_name = str_replace( WP_CONTENT_DIR . $scan_type . '/', '', $dir );
                $list[$dir] = $short_name;
            }
            set_transient( $transient_name, $list, $this->transient_time );
            $current_plugins = $list;
        }
        return $current_plugins;
    }
    
    
    /**
     * Print plugin styles
     */
    private function print_style()
    {
        switch( $this->options['skin'] )
        {
            case 'default':
                $css = 'pre.prettyprint {border: 1px solid #D5D5D5 !important; line-height: 1.5em }';
                break;
            case 'desert':
            case 'sons-of-obsidian':
                $css = 'pre.prettyprint {padding: 5px; line-height: 1.5em }';
                break;
            default:
                $css = '';
                break;
        }
        echo <<<HTML
<style type="text/css">
.plugin-name {
    background-color: #000000;
    padding: 6px;color: #FFF500;
    letter-spacing: .1em;
    margin: -2px -10px 0;
} 
.plugin-file {
    background-color:#eee;
    padding: 3px 10px 5px;
    margin-bottom:30px;
    -webkit-box-shadow: inset 2px 2px 6px 2px rgba(0, 0, 0, .2);
    box-shadow: inset 2px 2px 6px 2px rgba(0, 0, 0, .2);
}
.plugin-radius {
    -moz-border-radius: 4px;
    -webkit-border-radius: 4px;
    -o-border-radius: 4px;
    -ms-border-radius: 4px;
    -khtml-border-radius: 4px;
    border-radius: 4px;
}
$css
</style>        
HTML;
    }
    
    
    /**
     * Return current scan type with optional slashing
     * 
     * @param string $prefix
     * @param string $suffix
     * @return string
     */
    private function get_scan_type( $prefix='/', $suffix='/')
    {
        $scan_type = isset( $this->options['type'] ) ? $this->options['type'] : 'plugins';
        return $prefix.$scan_type.$suffix;
    }
    
    
    /**
     * Sanitize each setting field as needed
     *
     * @param array $input Contains all settings fields as array keys
     */
    public function sanitize( $input )
    {
		$new_input = array();
		
        if( !empty( $input['plugin_id'] ) )
            $new_input['plugin_id'] = esc_sql( $input['plugin_id'] );

        if( !empty( $input['skin'] ) )
            $new_input['skin'] = esc_sql( $input['skin'] );

        if( isset( $input['type'] ) && $input['type'] != $this->options['type'] )
        {
            $new_input['type'] = esc_sql( $input['type'] );
            delete_transient( $this->option_name . '_directories' );
            $new_input['plugin_id'] = '';
        }
        elseif( isset( $input['type'] ) )
            $new_input['type'] = esc_sql( $input['type'] );
        
        return $new_input;
    }

    
    /**
     * Add donate link to plugin description in /wp-admin/plugins.php
     * 
     * @param array $plugin_meta
     * @param string $plugin_file
     * @param string $plugin_data
     * @param string $status
     * @return array
     */
    public function donate_link( $plugin_meta, $plugin_file, $plugin_data, $status ) 
	{
		if( plugin_basename( __FILE__ ) == $plugin_file )
			$plugin_meta[] = '&hearts; <a href="https://www.paypal.com/cgi-bin/webscr?cmd=_donations&business=JNJXKWBYM9JP6&lc=ES&item_name=All%20Plugins%20Hooks%20%3a%20Rodolfo%20Buaiz&currency_code=EUR&bn=PP%2dDonationsBF%3abtn_donate_SM%2egif%3aNonHosted">Buy me a beer :o)</a>';
		return $plugin_meta;
	}

    
    /**
     * Abort on Windows systems, *nix 
     */
	public static function on_activation()
	{
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') 
        {
            deactivate_plugins( basename( __FILE__ ) );
            wp_die(
                '<p>The <strong>All Plugins Hooks</strong> plugin does not work in Windows.</p>',
                'Plugin Activation Error',  
                array( 'response'=>200, 'back_link'=>TRUE ) 
               );
            exit();
        }
		
	}
}