<?php
/*
Plugin Name: Network Terminator
Plugin URI: http://wphug.com/plugins/network-terminator/
Description: Simple plugin to bulk add terms to taxonomies across the mutisite network.
Version: 0.1
Author: Mau
Author URI: http://wphug.com/
License: GPL2
*/

/*

TODO:


empty form shouldn't submit
settings $sanitize_callback & settings error

more test of adding to non existing taxonomy

check permissions

check intention

add i18n


*/
	

	// add the class to WP
	add_action( 'plugins_loaded', 'mau_network_terminator_init' );
	function mau_network_terminator_init() {                                                           
	    new MauNetworkTerminator();   
	}         

	class MauNetworkTerminator {
	
		var $prefix = 'mau_network_terminator';
		var $title = 'Network Terminator';
		var $ver = '0.0.1';
	
	    function __construct() {
   			# Add a menu for our option page
			add_action('admin_menu', array( $this, 'add_settings_page' ) );
			# Register and define the settings
			add_action('admin_init', array( $this, 'admin_init' ) ); 
	    }


		/**
		* Add a menu for our option page
		*
		*/
		function add_settings_page() {
			add_submenu_page( 'tools.php', $this->title, $this->title, 'manage_categories', $this->prefix, array($this, 'option_page') );
		}
		
		/**
		* Draw the option page & process form submission
		*
		*/
		function option_page() {
				
				// no multisite no fun
				if ( !is_multisite() ) {
					echo '
					<div class="wrap"><div class="error">
					This is not a multisite WordPress installation.
					</div></div>';
				} else {
				
				// process form submission
				if ( isset($_POST['Submit']) ) {

					if ( isset($_POST['sites']) && is_array($_POST['sites']) )
						$sites = array_map( 'absint', $_POST['sites'] );
					
					$taxonomies = $_POST['taxonomies'];
					
					$test_run = ( $_POST['Submit'] == 'DO A TEST RUN' ) ? true : false;
					
					$terms_to_add = array();
					
					foreach ($taxonomies as $tax => $terms) {
						if (!empty($terms))						
							$terms_to_add[$tax] = explode(',',preg_replace("'\s+'", '', $terms));
					}

					// This is where the party is!						
					$log_output = $this->mau_add_network_terms($terms_to_add, $sites, $test_run);
				} ?>

			<div class="wrap">
				<?php screen_icon(); ?>
				<h2><?php echo $this->title; ?></h2>
				
				<form action="tools.php?page=<?php echo $this->prefix; ?>" method="post">
					<?php settings_fields($this->prefix.'_options'); ?>
					<?php do_settings_sections($this->prefix); ?>
					<input name="Submit" type="submit" value="DO A TEST RUN" />
					<input name="Submit" type="submit" value="ADD TERMS" />
				</form>
				
			</div>
				<?php
			}		
		}
		
		/**
		* Register and define the settings
		*
		*/
		function admin_init(){
			register_setting(
				$this->prefix.'_options',
				$this->prefix.'_options'
			);
			add_settings_section(
				$this->prefix.'_main',
				'Add terms to your network',
				array( $this, 'section_text_main' ) ,
				$this->prefix
			);
			add_settings_field(
				$this->prefix.'_setting_sites',
				'<em>'.__('Please choose which sites in your network will be affected by this plugin.',$this->prefix).'</em>',
				array( $this, 'setting_sites' ) ,
				$this->prefix,
				$this->prefix.'_main'
			);
			add_settings_field(
				$this->prefix.'_setting_taxonomies',
				'<em>'.__('Enter the terms you want to add separated by commas.',$this->prefix).'</em>',
				array( $this, 'setting_taxonomies' ) ,
				$this->prefix,
				$this->prefix.'_main'
			);

		}
		
		/** 
		* Draw the section header
		*
		*/
		function section_text_main() {
			$out ='';
			echo $out;
		}

		
		/** 
		* Display checkbox for each site in the network.
		*
		*/
		function setting_sites() {
			global $wpdb;
			
			// get an array of blog ids
			$sql = "SELECT blog_id FROM $wpdb->blogs 
				WHERE archived = '0' AND mature = '0' 
				AND spam = '0' AND deleted = '0' ";
			$blogs = $wpdb->get_col( $wpdb->prepare( $sql ) );
			
			// check user submitted data
			$sites_input = ( isset($_POST['sites']) && is_array($_POST['sites']) ) ? array_map('absint',$_POST['sites']) : array();
			
			if ( is_array( $blogs ) ) {
				echo '<p>';
				//loop through the site IDs
				foreach ($blogs as $blog) {
					//display each site as an checkbox
					$checked = (in_array($blog,$sites_input)) ? 'checked':'';
					echo '<input type="checkbox" name="sites[]" value="' .$blog. '" '.$checked.'/> ';
					echo get_blog_details( $blog )->blogname. '<br/>';
				}
				echo '</p>';
			}
		}
		
		/*
		* Display text input with label for each available taxonomy
		*
		*/
		function setting_taxonomies() {
			$taxonomies=get_taxonomies(array('public' => true),'objects'); 
			unset($taxonomies['post_format']); // we don't want to mess with this
			echo '<p>';
			foreach ($taxonomies as $tax) {
				$tax_input = isset($_POST['taxonomies'][$tax->name]) ? esc_attr($_POST['taxonomies'][$tax->name]) : '';
				echo '<label for="'.$tax->name.'">'.$tax->labels->name.'</label><br/>';
				echo '<input name="taxonomies['.$tax->name.']" type="text" value="'.$tax_input.'" size="50"/><br/><br/>';
				}
			echo '</p>';	
		}
		
		
		
		
		/**
		* Add network terms
		*
		* Hey, This is where the party is!
		*
		* @param (array) $terms_to_add
		* @param (array) $siteids
		* @param (bool) $testrun
		*
		* @return (string) list formatted log | errors
		*/
		function mau_add_network_terms($terms_to_add, $siteids, $testrun = true) {
		
			// check if this is multisite install
			if ( !is_multisite() )
				return 'This is not a multisite WordPress installation.';
		
			// very basic input check
			if ( empty($terms_to_add) || empty($siteids) || !is_array($terms_to_add) || !is_array($siteids) )
				return 'Nah, I eat only arrays!';

			$log = '';

			// loop thru blogs
			foreach ($siteids as $blog_id) :
				
				switch_to_blog( absint($blog_id) );
				
				// get the blog name for our log
				$log .= '<h3>'.get_blog_details( $blog_id )->blogname.':</h3>';
				$log .= '<ul>';
				
				// loop thru taxonomies
				foreach ( $terms_to_add as $taxonomy => $terms ) {
				
					// check if taxonomy exists
					if ( taxonomy_exists($taxonomy) ) {
						
						//loop thru terms	
						foreach ( $terms as $term ) {
							
							// check if term exists
							if ( term_exists($term, $taxonomy) ) {
								$log .= "<li><em>$term already exists in $taxonomy taxonomy - not added!</em></li>";
								
							} else {
								
								// if it doesn't exist insert the $term to $taxonomy
								$term = strip_tags($term);
								$taxonomy = strip_tags($taxonomy);
								if (!$testrun)
									wp_insert_term( $term, $taxonomy );
								$log .= "<li><b>$term</b> successfully added to <b>$taxonomy</b> taxonomy</li>"; 
							}
						}
					} else {
						// tell our log that taxonomy doesn't exists
						$log .= "<li><em>The $taxonomy taxonomy doesn't exist! Skipping...</em></li>"; 
					}
				}
			
				$log .= '</ul>';	
			
				// we're done here
				restore_current_blog();
				
			endforeach; 
			if ($testrun) $log = 'No need to get excited. This is just the test run.<br/>'.$log;
			return $log;
		}

		
}	// end of class MauPlugin

?>